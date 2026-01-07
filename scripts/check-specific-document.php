<?php
/**
 * Prüft ein spezifisches Dokument auf Scan-Status und Jobs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

// Suche nach dem Dokument
$stmt = $db->query("
    SELECT 
        d.document_uuid, 
        d.title, 
        b.blob_uuid, 
        b.scan_status, 
        b.scan_at, 
        d.created_at,
        d.extraction_status
    FROM documents d
    JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
    WHERE d.title LIKE '%Konto_0990055558%'
      AND d.status = 'active'
    ORDER BY d.created_at DESC
    LIMIT 1
");

$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    echo "Dokument nicht gefunden.\n";
    exit(1);
}

echo "=== Dokument-Details ===\n";
echo "Document UUID: {$doc['document_uuid']}\n";
echo "Blob UUID: {$doc['blob_uuid']}\n";
echo "Titel: {$doc['title']}\n";
echo "Scan Status: {$doc['scan_status']}\n";
echo "Scan At: " . ($doc['scan_at'] ?: 'NULL') . "\n";
echo "Extraction Status: {$doc['extraction_status']}\n";
echo "Created At: {$doc['created_at']}\n";
echo "\n";

// Prüfe Scan-Jobs
echo "=== Scan-Jobs für diesen Blob ===\n";
$stmt2 = $db->prepare("
    SELECT 
        event_uuid, 
        event_type, 
        created_at, 
        processed_at
    FROM outbox_event
    WHERE aggregate_type = 'blob'
      AND aggregate_uuid = ?
      AND event_type = 'BlobScanRequested'
    ORDER BY created_at DESC
");
$stmt2->execute([$doc['blob_uuid']]);
$jobs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
    echo "⚠️  KEIN SCAN-JOB GEFUNDEN!\n";
    echo "   Das bedeutet, dass beim Upload kein Scan-Job erstellt wurde.\n";
} else {
    foreach ($jobs as $job) {
        echo "Job: {$job['event_type']}\n";
        echo "  Erstellt: {$job['created_at']}\n";
        echo "  Verarbeitet: " . ($job['processed_at'] ?: 'NICHT VERARBEITET') . "\n";
        if (!$job['processed_at']) {
            echo "  ⚠️  Job wurde noch nicht verarbeitet!\n";
        }
    }
}
echo "\n";

// Prüfe Extraction-Jobs
echo "=== Extraction-Jobs für dieses Document ===\n";
$stmt3 = $db->prepare("
    SELECT 
        event_uuid, 
        event_type, 
        created_at, 
        processed_at
    FROM outbox_event
    WHERE aggregate_type = 'document'
      AND aggregate_uuid = ?
      AND event_type = 'DocumentExtractionRequested'
    ORDER BY created_at DESC
");
$stmt3->execute([$doc['document_uuid']]);
$extractionJobs = $stmt3->fetchAll(PDO::FETCH_ASSOC);

if (empty($extractionJobs)) {
    echo "⚠️  KEIN EXTRACTION-JOB GEFUNDEN!\n";
    echo "   Das bedeutet, dass beim Upload kein Extraction-Job erstellt wurde.\n";
} else {
    foreach ($extractionJobs as $job) {
        echo "Job: {$job['event_type']}\n";
        echo "  Erstellt: {$job['created_at']}\n";
        echo "  Verarbeitet: " . ($job['processed_at'] ?: 'NICHT VERARBEITET') . "\n";
        if (!$job['processed_at']) {
            echo "  ⚠️  Job wurde noch nicht verarbeitet!\n";
        }
    }
}
echo "\n";

// Prüfe Attachments
echo "=== Document Attachments ===\n";
$stmt4 = $db->prepare("
    SELECT 
        attachment_uuid,
        entity_type,
        entity_uuid,
        created_at
    FROM document_attachments
    WHERE document_uuid = ?
    ORDER BY created_at DESC
");
$stmt4->execute([$doc['document_uuid']]);
$attachments = $stmt4->fetchAll(PDO::FETCH_ASSOC);

if (empty($attachments)) {
    echo "⚠️  KEINE ATTACHMENTS GEFUNDEN!\n";
} else {
    foreach ($attachments as $attachment) {
        echo "Attachment: {$attachment['entity_type']} -> {$attachment['entity_uuid']}\n";
        echo "  Erstellt: {$attachment['created_at']}\n";
    }
}

