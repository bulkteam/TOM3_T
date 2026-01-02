<?php
/**
 * TOM3 - Blob Scan Worker
 * 
 * Verarbeitet Scan-Jobs aus outbox_event und scannt Blobs mit ClamAV
 * 
 * Usage:
 *   php scripts/jobs/scan-blob-worker.php
 * 
 * Oder als Windows Task Scheduler Job (alle 5 Minuten)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Document\ClamAvService;
use PDO;

class BlobScanWorker
{
    private PDO $db;
    private BlobService $blobService;
    private ClamAvService $clamAvService;
    private int $maxJobsPerRun;
    private bool $verbose;
    
    public function __construct(int $maxJobsPerRun = 10, bool $verbose = false)
    {
        $this->db = DatabaseConnection::getInstance();
        $this->blobService = new BlobService($this->db);
        $this->clamAvService = new ClamAvService();
        $this->maxJobsPerRun = $maxJobsPerRun;
        $this->verbose = $verbose;
    }
    
    /**
     * Haupt-Methode: Verarbeitet alle ausstehenden Scan-Jobs
     */
    public function processJobs(): int
    {
        $processed = 0;
        
        // Prüfe, ob ClamAV verfügbar ist
        if (!$this->clamAvService->isAvailable()) {
            $this->log("WARNUNG: ClamAV nicht verfügbar - Scan-Jobs werden übersprungen");
            return 0;
        }
        
        // Hole ausstehende Jobs
        $jobs = $this->getPendingJobs();
        
        if (empty($jobs)) {
            $this->log("Keine ausstehenden Scan-Jobs");
            return 0;
        }
        
        $this->log("Gefunden: " . count($jobs) . " Scan-Job(s)");
        
        foreach ($jobs as $job) {
            if ($processed >= $this->maxJobsPerRun) {
                $this->log("Max. Jobs pro Run erreicht ({$this->maxJobsPerRun})");
                break;
            }
            
            try {
                $this->processJob($job);
                $processed++;
            } catch (\Exception $e) {
                $this->log("FEHLER bei Job {$job['event_uuid']}: " . $e->getMessage());
                // Job bleibt unprocessed für Retry
            }
        }
        
        $this->log("Verarbeitet: {$processed} Job(s)");
        return $processed;
    }
    
    /**
     * Holt ausstehende Scan-Jobs aus outbox_event
     */
    private function getPendingJobs(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                event_uuid,
                aggregate_uuid AS blob_uuid,
                payload
            FROM outbox_event
            WHERE aggregate_type = 'blob'
              AND event_type = 'BlobScanRequested'
              AND processed_at IS NULL
            ORDER BY created_at ASC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':limit', $this->maxJobsPerRun, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verarbeitet einen einzelnen Scan-Job
     */
    private function processJob(array $job): void
    {
        $blobUuid = $job['blob_uuid'];
        $eventUuid = $job['event_uuid'];
        
        $this->log("Verarbeite Scan-Job für Blob: {$blobUuid}");
        
        try {
            // 1) Hole Blob-Informationen
            $blob = $this->getBlob($blobUuid);
            if (!$blob) {
                $this->log("FEHLER: Blob nicht gefunden: {$blobUuid}");
                $this->markBlobAsError($blobUuid, "Blob nicht gefunden");
                $this->markJobProcessed($eventUuid);
                return;
            }
            
            // 2) Idempotency: Prüfe, ob bereits gescannt
            if (in_array($blob['scan_status'], ['clean', 'infected', 'unsupported', 'error'])) {
                $this->log("Blob bereits gescannt (Status: {$blob['scan_status']}) - überspringe");
                $this->markJobProcessed($eventUuid);
                return;
            }
            
            // 3) Hole Datei-Pfad
            $filePath = $this->blobService->getBlobFilePath($blobUuid);
            if (!file_exists($filePath)) {
                $this->log("FEHLER: Datei nicht gefunden: {$filePath}");
                $this->markBlobAsError($blobUuid, "Datei nicht gefunden: {$filePath}");
                $this->markJobProcessed($eventUuid);
                return;
            }
            
            // 4) Scan durchführen
            $this->log("Starte Scan für: {$filePath}");
            $scanResult = $this->clamAvService->scan($filePath);
            
            // 5) Update Blob-Status
            $this->updateBlobScanStatus($blobUuid, $scanResult);
            
            // 6) Wenn infected: Blockiere alle zugehörigen Documents
            if ($scanResult['status'] === 'infected') {
                $this->blockDocumentsForBlob($blobUuid, $scanResult);
            }
            
            // 7) Job als verarbeitet markieren
            $this->markJobProcessed($eventUuid);
            
            $this->log("Scan abgeschlossen: Status = {$scanResult['status']}");
            
        } catch (\Exception $e) {
            // Bei Fehler: Markiere Blob als Error und logge ausführlich
            $errorMsg = $e->getMessage();
            $this->log("FEHLER beim Scan für Blob {$blobUuid}: {$errorMsg}");
            $this->log("Stack Trace: " . $e->getTraceAsString());
            
            try {
                $this->markBlobAsError($blobUuid, $errorMsg);
            } catch (\Exception $updateError) {
                $this->log("FEHLER beim Markieren des Blobs als Error: " . $updateError->getMessage());
            }
            
            // Job trotzdem als verarbeitet markieren, damit er nicht endlos wiederholt wird
            $this->markJobProcessed($eventUuid);
            
            // Exception weiterwerfen, damit sie in processJobs() geloggt wird
            throw $e;
        }
    }
    
    /**
     * Holt Blob-Informationen
     */
    private function getBlob(string $blobUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                blob_uuid,
                scan_status,
                storage_key
            FROM blobs
            WHERE blob_uuid = :blob_uuid
        ");
        
        $stmt->execute(['blob_uuid' => $blobUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Aktualisiert Scan-Status im Blob
     */
    private function updateBlobScanStatus(string $blobUuid, array $scanResult): void
    {
        $stmt = $this->db->prepare("
            UPDATE blobs
            SET scan_status = :status,
                scan_engine = 'clamav',
                scan_at = NOW(),
                scan_result = :result
            WHERE blob_uuid = :blob_uuid
        ");
        
        $result = $stmt->execute([
            'blob_uuid' => $blobUuid,
            'status' => $scanResult['status'],
            'result' => json_encode($scanResult)
        ]);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new \RuntimeException("UPDATE fehlgeschlagen für Blob {$blobUuid}: " . json_encode($errorInfo));
        }
        
        $rowsAffected = $stmt->rowCount();
        if ($rowsAffected === 0) {
            throw new \RuntimeException("UPDATE hat keine Zeilen aktualisiert für Blob {$blobUuid} - Blob existiert möglicherweise nicht");
        }
        
        $this->log("Blob-Status aktualisiert: {$blobUuid} → {$scanResult['status']} ({$rowsAffected} Zeile(n))");
    }
    
    /**
     * Blockiert alle Documents, die auf einen infizierten Blob verweisen
     * 
     * Hinweis: Nur aktive Dokumente werden blockiert (gelöschte werden ignoriert)
     */
    private function blockDocumentsForBlob(string $blobUuid, array $scanResult): void
    {
        $stmt = $this->db->prepare("
            UPDATE documents
            SET status = 'blocked'
            WHERE current_blob_uuid = :blob_uuid
              AND status = 'active'
        ");
        
        $stmt->execute(['blob_uuid' => $blobUuid]);
        
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            $this->log("WARNUNG: {$affected} Document(s) blockiert wegen infiziertem Blob");
            
            // TODO: Admin-Benachrichtigung
            // TODO: Audit-Log
        }
    }
    
    /**
     * Markiert Blob als Error
     */
    private function markBlobAsError(string $blobUuid, string $errorMessage): void
    {
        $stmt = $this->db->prepare("
            UPDATE blobs
            SET scan_status = 'error',
                scan_engine = 'clamav',
                scan_at = NOW(),
                scan_result = :result
            WHERE blob_uuid = :blob_uuid
        ");
        
        $result = $stmt->execute([
            'blob_uuid' => $blobUuid,
            'result' => json_encode([
                'status' => 'error',
                'error' => $errorMessage,
                'timestamp' => date('Y-m-d H:i:s')
            ])
        ]);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            $this->log("WARNUNG: Fehler beim Markieren des Blobs als Error: " . json_encode($errorInfo));
        } else {
            $rowsAffected = $stmt->rowCount();
            if ($rowsAffected === 0) {
                $this->log("WARNUNG: UPDATE hat keine Zeilen aktualisiert beim Markieren als Error für Blob {$blobUuid}");
            }
        }
    }
    
    /**
     * Markiert Job als verarbeitet
     */
    private function markJobProcessed(string $eventUuid): void
    {
        $stmt = $this->db->prepare("
            UPDATE outbox_event
            SET processed_at = NOW()
            WHERE event_uuid = :event_uuid
        ");
        
        $stmt->execute(['event_uuid' => $eventUuid]);
    }
    
    /**
     * Logging
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        if ($this->verbose || php_sapi_name() === 'cli') {
            echo $logMessage;
        }
        
        // Optional: In Datei loggen
        $logFile = __DIR__ . '/../../logs/scan-blob-worker.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}

// CLI-Ausführung
if (php_sapi_name() === 'cli') {
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
    $maxJobs = 10;
    
    // Parse --max-jobs Parameter
    foreach ($argv as $arg) {
        if (strpos($arg, '--max-jobs=') === 0) {
            $maxJobs = (int)substr($arg, 11);
        }
    }
    
    try {
        $worker = new BlobScanWorker($maxJobs, $verbose);
        $processed = $worker->processJobs();
        
        // Exit 0 = Erfolg (Jobs verarbeitet ODER keine Jobs vorhanden - beides ist OK)
        exit(0);
    } catch (\Exception $e) {
        // Exit 1 = Echter Fehler
        error_log("BlobScanWorker Fehler: " . $e->getMessage());
        exit(1);
    }
}
