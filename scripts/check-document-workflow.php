<?php
/**
 * TOM3 - Document Workflow Diagnose
 * 
 * Prüft, ob Dokumente korrekt verarbeitet werden:
 * - Werden Jobs in outbox_event erstellt?
 * - Werden Jobs verarbeitet?
 * - Gibt es hängende Jobs?
 * 
 * Usage:
 *   php scripts/check-document-workflow.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use PDO;

$db = DatabaseConnection::getInstance();

echo "=== TOM3 Document Workflow Diagnose ===\n\n";

// 1. Prüfe ausstehende Scan-Jobs
echo "1. Scan-Jobs (BlobScanRequested):\n";
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN processed_at IS NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN processed_at IS NOT NULL THEN 1 ELSE 0 END) as processed,
        MIN(created_at) as oldest_pending,
        MAX(created_at) as newest_pending
    FROM outbox_event
    WHERE aggregate_type = 'blob'
      AND event_type = 'BlobScanRequested'
");
$stmt->execute();
$scanStats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Gesamt: " . ($scanStats['total'] ?? 0) . "\n";
echo "   Ausstehend: " . ($scanStats['pending'] ?? 0) . "\n";
echo "   Verarbeitet: " . ($scanStats['processed'] ?? 0) . "\n";
if ($scanStats['pending'] > 0) {
    echo "   Ältester ausstehender Job: " . ($scanStats['oldest_pending'] ?? 'N/A') . "\n";
    echo "   Neuester ausstehender Job: " . ($scanStats['newest_pending'] ?? 'N/A') . "\n";
}
echo "\n";

// 2. Prüfe ausstehende Extraction-Jobs
echo "2. Extraction-Jobs (DocumentExtractionRequested):\n";
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN processed_at IS NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN processed_at IS NOT NULL THEN 1 ELSE 0 END) as processed,
        MIN(created_at) as oldest_pending,
        MAX(created_at) as newest_pending
    FROM outbox_event
    WHERE aggregate_type = 'document'
      AND event_type = 'DocumentExtractionRequested'
");
$stmt->execute();
$extractionStats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Gesamt: " . ($extractionStats['total'] ?? 0) . "\n";
echo "   Ausstehend: " . ($extractionStats['pending'] ?? 0) . "\n";
echo "   Verarbeitet: " . ($extractionStats['processed'] ?? 0) . "\n";
if ($extractionStats['pending'] > 0) {
    echo "   Ältester ausstehender Job: " . ($extractionStats['oldest_pending'] ?? 'N/A') . "\n";
    echo "   Neuester ausstehender Job: " . ($extractionStats['newest_pending'] ?? 'N/A') . "\n";
}
echo "\n";

// 3. Prüfe Dokumente mit pending scan_status
echo "3. Dokumente mit pending scan_status:\n";
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        MIN(d.created_at) as oldest,
        MAX(d.created_at) as newest
    FROM documents d
    JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
    WHERE b.scan_status = 'pending'
      AND d.status = 'active'
");
$stmt->execute();
$pendingDocs = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Gesamt: " . ($pendingDocs['total'] ?? 0) . "\n";
if ($pendingDocs['total'] > 0) {
    echo "   Ältestes Dokument: " . ($pendingDocs['oldest'] ?? 'N/A') . "\n";
    echo "   Neuestes Dokument: " . ($pendingDocs['newest'] ?? 'N/A') . "\n";
    
    // Zeige die letzten 5 Dokumente
    $stmt2 = $db->prepare("
        SELECT 
            d.document_uuid,
            d.title,
            d.created_at,
            b.scan_status,
            d.extraction_status
        FROM documents d
        JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
        WHERE b.scan_status = 'pending'
          AND d.status = 'active'
        ORDER BY d.created_at DESC
        LIMIT 5
    ");
    $stmt2->execute();
    $recentDocs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($recentDocs)) {
        echo "   Letzte 5 Dokumente:\n";
        foreach ($recentDocs as $doc) {
            echo "     - {$doc['title']} (erstellt: {$doc['created_at']}, scan: {$doc['scan_status']}, extraction: {$doc['extraction_status']})\n";
        }
    }
}
echo "\n";

// 4. Prüfe, ob es Jobs gibt, die nicht verarbeitet wurden (älter als 10 Minuten)
echo "4. Hängende Jobs (älter als 10 Minuten):\n";
$stmt = $db->prepare("
    SELECT 
        event_uuid,
        aggregate_type,
        aggregate_uuid,
        event_type,
        created_at,
        TIMESTAMPDIFF(MINUTE, created_at, NOW()) as age_minutes
    FROM outbox_event
    WHERE processed_at IS NULL
      AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ORDER BY created_at ASC
    LIMIT 10
");
$stmt->execute();
$stuckJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($stuckJobs)) {
    echo "   Keine hängenden Jobs gefunden.\n";
} else {
    echo "   Gefunden: " . count($stuckJobs) . " hängende Job(s):\n";
    foreach ($stuckJobs as $job) {
        echo "     - {$job['event_type']} für {$job['aggregate_type']} {$job['aggregate_uuid']} (erstellt: {$job['created_at']}, Alter: {$job['age_minutes']} Min)\n";
    }
}
echo "\n";

// 5. Prüfe Personen, die mit Import-Batches verknüpft sind
echo "5. Personen, die mit Import-Batches verknüpft sind:\n";
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT p.person_uuid) as total_persons,
        COUNT(DISTINCT pis.import_batch_uuid) as total_batches
    FROM person p
    INNER JOIN person_import_staging pis ON p.person_uuid = pis.imported_person_uuid
    WHERE pis.import_status = 'imported'
");
$stmt->execute();
$personImportStats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Personen mit Import-Verknüpfung: " . ($personImportStats['total_persons'] ?? 0) . "\n";
echo "   Anzahl verschiedener Batches: " . ($personImportStats['total_batches'] ?? 0) . "\n";

// Prüfe, ob es Personen gibt, die manuell erstellt wurden, aber trotzdem mit Import verknüpft sind
// (Das sollte nicht passieren, da PersonService::createPerson() keine import_batch_uuid setzt)
// Aber wir können prüfen, ob es Personen gibt, die NICHT über Import erstellt wurden, aber trotzdem in person_import_staging sind
$stmt2 = $db->prepare("
    SELECT 
        p.person_uuid,
        p.first_name,
        p.last_name,
        p.created_at as person_created_at,
        pis.imported_at,
        pis.import_batch_uuid
    FROM person p
    INNER JOIN person_import_staging pis ON p.person_uuid = pis.imported_person_uuid
    WHERE pis.import_status = 'imported'
      AND pis.imported_at IS NOT NULL
      AND p.created_at < pis.imported_at
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt2->execute();
$suspiciousPersons = $stmt2->fetchAll(PDO::FETCH_ASSOC);
if (!empty($suspiciousPersons)) {
    echo "   ⚠️  Verdächtige Personen (erstellt VOR Import):\n";
    foreach ($suspiciousPersons as $person) {
        echo "     - {$person['first_name']} {$person['last_name']} (Person erstellt: {$person['person_created_at']}, Import: {$person['imported_at']})\n";
    }
} else {
    echo "   ✓ Keine verdächtigen Personen gefunden.\n";
}
echo "\n";

// 6. Prüfe Dokumente, die mit Personen verknüpft sind, aber auch mit import_batch
echo "6. Dokumente, die sowohl mit Personen als auch mit import_batch verknüpft sind:\n";
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT d.document_uuid) as total_docs
    FROM documents d
    INNER JOIN document_attachments da1 ON d.document_uuid = da1.document_uuid
    INNER JOIN document_attachments da2 ON d.document_uuid = da2.document_uuid
    WHERE da1.entity_type = 'person'
      AND da2.entity_type = 'import_batch'
      AND d.status = 'active'
");
$stmt->execute();
$dualDocs = $stmt->fetch(PDO::FETCH_ASSOC);
echo "   Gesamt: " . ($dualDocs['total_docs'] ?? 0) . "\n";
if ($dualDocs['total_docs'] > 0) {
    echo "   ⚠️  Diese Dokumente sind sowohl mit Personen als auch mit Import-Batches verknüpft.\n";
}
echo "\n";

echo "=== Diagnose abgeschlossen ===\n";

