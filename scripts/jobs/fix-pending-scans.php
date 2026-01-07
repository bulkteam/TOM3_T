<?php
/**
 * TOM3 - Fix Pending Scans Worker
 * 
 * Prüft und behebt Blobs mit pending scan_status, die bereits verarbeitete Scan-Jobs haben.
 * Dies ist ein Fallback für Fälle, in denen der Scan-Worker den Status nicht aktualisieren konnte.
 * 
 * Usage:
 *   php scripts/jobs/fix-pending-scans.php
 * 
 * Oder als Windows Task Scheduler Job (alle 15 Minuten)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Document\ClamAvService;

class FixPendingScansWorker
{
    private \PDO $db;
    private BlobService $blobService;
    private ClamAvService $clamAvService;
    private bool $verbose;
    private int $maxFixesPerRun;
    
    public function __construct(int $maxFixesPerRun = 10, bool $verbose = false)
    {
        $this->db = DatabaseConnection::getInstance();
        $this->blobService = new BlobService($this->db);
        $this->clamAvService = new ClamAvService();
        $this->maxFixesPerRun = $maxFixesPerRun;
        $this->verbose = $verbose;
    }
    
    /**
     * Haupt-Methode: Prüft und behebt pending Blobs
     */
    public function process(): int
    {
        $fixed = 0;
        
        // Prüfe, ob ClamAV verfügbar ist
        if (!$this->clamAvService->isAvailable()) {
            $this->log("WARNUNG: ClamAV nicht verfügbar - Fix wird übersprungen");
            return 0;
        }
        
        // Finde Blobs mit pending Status, aber verarbeiteten Jobs
        $blobs = $this->findPendingBlobs();
        
        if (empty($blobs)) {
            $this->log("Keine pending Blobs mit verarbeiteten Jobs gefunden");
            return 0;
        }
        
        $this->log("Gefunden: " . count($blobs) . " pending Blob(s) mit verarbeiteten Jobs");
        
        foreach ($blobs as $blob) {
            if ($fixed >= $this->maxFixesPerRun) {
                $this->log("Max. Fixes pro Run erreicht ({$this->maxFixesPerRun})");
                break;
            }
            
            try {
                if ($this->fixBlob($blob['blob_uuid'])) {
                    $fixed++;
                }
            } catch (\Exception $e) {
                $this->log("FEHLER beim Fix für Blob {$blob['blob_uuid']}: " . $e->getMessage());
            }
        }
        
        $this->log("Behoben: {$fixed} Blob(s)");
        return $fixed;
    }
    
    /**
     * Findet Blobs mit pending Status, aber verarbeiteten Jobs
     */
    private function findPendingBlobs(): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT b.blob_uuid, b.created_at
            FROM blobs b
            INNER JOIN outbox_event o ON o.aggregate_uuid = b.blob_uuid
            WHERE b.scan_status = 'pending'
              AND o.aggregate_type = 'blob'
              AND o.event_type = 'BlobScanRequested'
              AND o.processed_at IS NOT NULL
              AND o.processed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY b.created_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':limit', $this->maxFixesPerRun, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Behebt einen einzelnen Blob
     */
    private function fixBlob(string $blobUuid): bool
    {
        $this->log("Prüfe Blob: {$blobUuid}");
        
        // Prüfe, ob Blob noch existiert
        $blob = $this->blobService->getBlob($blobUuid);
        if (!$blob) {
            $this->log("  Blob existiert nicht mehr - überspringe");
            return false;
        }
        
        // Prüfe, ob Status noch pending ist (könnte zwischenzeitlich aktualisiert worden sein)
        if ($blob['scan_status'] !== 'pending') {
            $this->log("  Blob hat bereits Status '{$blob['scan_status']}' - überspringe");
            return false;
        }
        
        // Prüfe, ob Datei existiert
        $filePath = $this->blobService->getBlobFilePath($blobUuid);
        if (!$filePath || !file_exists($filePath)) {
            $this->log("  Datei nicht gefunden - markiere als Error");
            $this->markBlobAsError($blobUuid, "Datei nicht gefunden");
            return true;
        }
        
        // Führe Scan durch
        try {
            $this->log("  Starte Scan...");
            $scanResult = $this->clamAvService->scan($filePath);
            $this->log("  Scan erfolgreich: {$scanResult['status']}");
            
            // Update Blob-Status
            $this->updateBlobStatus($blobUuid, $scanResult);
            $this->log("  ✓ Blob-Status aktualisiert: {$scanResult['status']}");
            
            return true;
            
        } catch (\Exception $e) {
            $this->log("  ⚠️  Fehler beim Scan: " . $e->getMessage());
            
            // Markiere als Error
            try {
                $this->markBlobAsError($blobUuid, $e->getMessage());
                $this->log("  ✓ Blob als Error markiert");
                return true;
            } catch (\Exception $updateError) {
                $this->log("  FEHLER: Konnte Blob nicht als Error markieren: " . $updateError->getMessage());
                return false;
            }
        }
    }
    
    /**
     * Aktualisiert Blob-Status
     */
    private function updateBlobStatus(string $blobUuid, array $scanResult): void
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
            throw new \RuntimeException("UPDATE fehlgeschlagen: " . json_encode($errorInfo));
        }
        
        $rowsAffected = $stmt->rowCount();
        if ($rowsAffected === 0) {
            throw new \RuntimeException("UPDATE hat keine Zeilen aktualisiert");
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
        
        $stmt->execute([
            'blob_uuid' => $blobUuid,
            'result' => json_encode([
                'status' => 'error',
                'error' => $errorMessage,
                'timestamp' => date('Y-m-d H:i:s'),
                'fixed_by' => 'fix-pending-scans-worker'
            ])
        ]);
    }
    
    /**
     * Logging
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [FixPendingScans] {$message}\n";
        
        if ($this->verbose || php_sapi_name() === 'cli') {
            echo $logMessage;
        }
        
        // Optional: In Datei loggen
        // error_log($logMessage, 3, __DIR__ . '/../../logs/fix-pending-scans.log');
    }
}

// CLI-Handler
if (php_sapi_name() === 'cli') {
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
    $maxFixes = 10;
    
    // Parse --max-fixes Parameter
    foreach ($argv as $arg) {
        if (strpos($arg, '--max-fixes=') === 0) {
            $maxFixes = (int)substr($arg, 12);
        }
    }
    
    $worker = new FixPendingScansWorker($maxFixes, $verbose);
    $fixed = $worker->process();
    
    exit($fixed > 0 ? 0 : 0); // Exit-Code 0 = Erfolg
}

