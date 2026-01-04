<?php
/**
 * TOM3 - Text Extraction Worker
 * 
 * Verarbeitet Text-Extraktions-Jobs aus outbox_event und extrahiert Text aus Dokumenten
 * 
 * Usage:
 *   php scripts/jobs/extract-text-worker.php
 * 
 * Oder als Windows Task Scheduler Job (alle 5 Minuten)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Document\BlobService;
use TOM\Service\DocumentService;
use TOM\Infrastructure\Document\PdfTextExtractor;
use TOM\Infrastructure\Document\DocxTextExtractor;
use TOM\Infrastructure\Document\TextFileExtractor;
use TOM\Infrastructure\Document\XlsxTextExtractor;
use TOM\Infrastructure\Document\DocTextExtractor;
use TOM\Infrastructure\Document\OcrExtractor;

class ExtractTextWorker
{
    private PDO $db;
    private BlobService $blobService;
    private DocumentService $documentService;
    private PdfTextExtractor $pdfExtractor;
    private DocxTextExtractor $docxExtractor;
    private TextFileExtractor $textFileExtractor;
    private XlsxTextExtractor $xlsxExtractor;
    private DocTextExtractor $docExtractor;
    private ?OcrExtractor $ocrExtractor = null;
    private int $maxJobsPerRun;
    private bool $verbose;
    
    public function __construct(int $maxJobsPerRun = 10, bool $verbose = false)
    {
        $this->db = DatabaseConnection::getInstance();
        $this->blobService = new BlobService($this->db);
        $this->documentService = new DocumentService($this->db);
        $this->pdfExtractor = new PdfTextExtractor();
        $this->docxExtractor = new DocxTextExtractor();
        $this->textFileExtractor = new TextFileExtractor();
        $this->xlsxExtractor = new XlsxTextExtractor();
        $this->docExtractor = new DocTextExtractor();
        
        // OCR nur initialisieren, wenn verfügbar
        try {
            $ocr = new OcrExtractor();
            if ($ocr->isAvailable()) {
                $this->ocrExtractor = $ocr;
            }
        } catch (\Exception $e) {
            // OCR nicht verfügbar - ignorieren
        }
        
        $this->maxJobsPerRun = $maxJobsPerRun;
        $this->verbose = $verbose;
    }
    
    /**
     * Haupt-Methode: Verarbeitet alle ausstehenden Extraction-Jobs
     */
    public function processJobs(): int
    {
        $processed = 0;
        
        // Hole ausstehende Jobs
        $jobs = $this->getPendingJobs();
        
        if (empty($jobs)) {
            $this->log("Keine ausstehenden Extraction-Jobs");
            return 0;
        }
        
        $this->log("Gefunden: " . count($jobs) . " Extraction-Job(s)");
        
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
     * Holt ausstehende Extraction-Jobs aus outbox_event
     * 
     * Hinweis: Filtert automatisch gelöschte Dokumente heraus (Soft Delete)
     */
    private function getPendingJobs(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                oe.event_uuid,
                oe.aggregate_uuid AS document_uuid,
                oe.payload
            FROM outbox_event oe
            INNER JOIN documents d ON oe.aggregate_uuid = d.document_uuid
            WHERE oe.aggregate_type = 'document'
              AND oe.event_type = 'DocumentExtractionRequested'
              AND oe.processed_at IS NULL
              AND d.status = 'active'  -- Nur aktive Dokumente (Soft Delete)
            ORDER BY oe.created_at ASC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':limit', $this->maxJobsPerRun, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verarbeitet einen einzelnen Extraction-Job
     */
    private function processJob(array $job): void
    {
        $documentUuid = $job['document_uuid'];
        $eventUuid = $job['event_uuid'];
        
        $this->log("Verarbeite Extraction-Job für Document: {$documentUuid}");
        
        // 1) Hole Document-Informationen
        $document = $this->documentService->getDocument($documentUuid);
        if (!$document) {
            throw new \RuntimeException("Document nicht gefunden: {$documentUuid}");
        }
        
        // 2) Prüfe, ob Document gelöscht ist (Soft Delete)
        if (isset($document['status']) && $document['status'] === 'deleted') {
            $this->log("Document ist gelöscht (Soft Delete) - überspringe");
            $this->markJobProcessed($eventUuid);
            return;
        }
        
        // 3) Idempotency: Prüfe, ob bereits extrahiert
        if ($document['extraction_status'] === 'done') {
            $this->log("Document bereits extrahiert - überspringe");
            $this->markJobProcessed($eventUuid);
            return;
        }
        
        // 4) Prüfe, ob Blob infiziert ist (nur clean/pending Blobs extrahieren)
        if ($document['scan_status'] === 'infected') {
            $this->log("Blob ist infiziert - überspringe Extraktion aus Sicherheitsgründen");
            $this->markJobProcessed($eventUuid);
            return;
        }
        
        // Hinweis: Wir extrahieren auch "pending" Blobs, da die Extraktion ungefährlich ist
        // und der Scan parallel laufen kann. Infizierte Blobs werden nicht extrahiert.
        
        // 4) Hole Datei-Pfad
        $filePath = $this->blobService->getBlobFilePath($document['current_blob_uuid']);
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Datei nicht gefunden: {$filePath}");
        }
        
        // 5) Extraktion durchführen
        $this->log("Starte Extraktion für: {$filePath} (MIME: {$document['mime_detected']})");
        
        $text = '';
        $metadata = [];
        
        try {
            $mime = $document['mime_detected'] ?? '';
            
            if ($mime === 'application/pdf') {
                $text = $this->pdfExtractor->extract($filePath);
                $metadata = $this->pdfExtractor->getMetadata($filePath);
            } elseif ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                // DOCX
                $text = $this->docxExtractor->extract($filePath);
                $metadata = $this->docxExtractor->getMetadata($filePath);
            } elseif ($mime === 'application/msword') {
                // DOC (altes Format)
                $text = $this->docExtractor->extract($filePath);
                $metadata = $this->docExtractor->getMetadata($filePath);
            } elseif (in_array($mime, [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel'
            ])) {
                // XLSX oder XLS
                $text = $this->xlsxExtractor->extract($filePath);
                $metadata = $this->xlsxExtractor->getMetadata($filePath);
            } elseif (in_array($mime, ['text/plain', 'text/csv', 'text/html'])) {
                // Text-Dateien (TXT, CSV, HTML)
                $text = $this->textFileExtractor->extract($filePath, $mime);
                $metadata = $this->textFileExtractor->getMetadata($filePath, $mime);
            } elseif (in_array($mime, ['image/png', 'image/jpeg', 'image/jpg', 'image/tiff', 'image/tif', 'image/gif'])) {
                // Bilder - OCR
                if ($this->ocrExtractor === null) {
                    $this->log("WARNUNG: OCR nicht verfügbar - überspringe Bild");
                    $this->updateExtractionStatus($documentUuid, 'failed', ['error' => 'OCR (Tesseract) nicht verfügbar']);
                    $this->markJobProcessed($eventUuid);
                    return;
                }
                
                $text = $this->ocrExtractor->extract($filePath);
                $metadata = $this->ocrExtractor->getMetadata($filePath);
                $metadata['language'] = $this->ocrExtractor->detectLanguage($text);
            } else {
                // Andere Formate - nicht unterstützt
                $this->log("WARNUNG: MIME-Type {$mime} wird nicht unterstützt - überspringe");
                $this->updateExtractionStatus($documentUuid, 'failed', ['error' => "MIME-Type {$mime} nicht unterstützt"]);
                $this->markJobProcessed($eventUuid);
                return;
            }
            
            // 6) Update Document mit extrahiertem Text
            $this->updateExtractionStatus($documentUuid, 'done', $metadata, $text);
            
            // 7) Job als verarbeitet markieren
            $this->markJobProcessed($eventUuid);
            
            $textLength = mb_strlen($text);
            $this->log("Extraktion abgeschlossen: {$textLength} Zeichen extrahiert");
            
        } catch (\Exception $e) {
            // Bei Fehlern: Status auf 'failed' setzen
            $this->updateExtractionStatus($documentUuid, 'failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Job trotzdem als verarbeitet markieren (kein Retry bei nicht unterstützten Formaten)
            $this->markJobProcessed($eventUuid);
            
            throw $e;
        }
    }
    
    /**
     * Aktualisiert Extraction-Status im Document
     */
    private function updateExtractionStatus(string $documentUuid, string $status, array $metadata = [], ?string $text = null): void
    {
        $sql = "
            UPDATE documents
            SET extraction_status = :status,
                extraction_meta = :metadata";
        
        if ($text !== null) {
            $sql .= ", extracted_text = :text";
        }
        
        $sql .= " WHERE document_uuid = :document_uuid";
        
        $stmt = $this->db->prepare($sql);
        
        $params = [
            'document_uuid' => $documentUuid,
            'status' => $status,
            'metadata' => !empty($metadata) ? json_encode($metadata) : null
        ];
        
        if ($text !== null) {
            $params['text'] = $text;
        }
        
        $stmt->execute($params);
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
        $logFile = __DIR__ . '/../../logs/extract-text-worker.log';
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
        $worker = new ExtractTextWorker($maxJobs, $verbose);
        $processed = $worker->processJobs();
        
        // Exit 0 = Erfolg (Jobs verarbeitet ODER keine Jobs vorhanden - beides ist OK)
        exit(0);
    } catch (\Exception $e) {
        // Exit 1 = Echter Fehler
        error_log("ExtractTextWorker Fehler: " . $e->getMessage());
        exit(1);
    }
}


