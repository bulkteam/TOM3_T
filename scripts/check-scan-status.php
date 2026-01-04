<?php
/**
 * Prüft Scan-Status für Dokumente
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Scan-Status Prüfung ===\n\n";

// Prüfe ausstehende Scan-Jobs
$stmt = $db->query("
    SELECT COUNT(*) as count 
    FROM outbox_event 
    WHERE aggregate_type = 'blob' 
      AND event_type = 'BlobScanRequested' 
      AND processed_at IS NULL
");
$pendingJobs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Ausstehende Scan-Jobs: {$pendingJobs}\n\n";

// Prüfe Dokumente mit "pending" Status
$stmt = $db->query("
    SELECT 
        d.document_uuid,
        d.title,
        b.scan_status,
        b.scan_at,
        b.created_at as blob_created_at,
        o.event_type,
        o.processed_at,
        o.created_at as event_created_at
    FROM documents d
    INNER JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
    LEFT JOIN outbox_event o ON o.aggregate_uuid = b.blob_uuid 
        AND o.event_type = 'BlobScanRequested'
    WHERE (d.title LIKE '%CRM_Konzept%' 
        OR d.title LIKE '%Entgelte%' 
        OR d.title LIKE '%ansicht%')
      AND d.status != 'deleted'
    ORDER BY d.created_at DESC
    LIMIT 10
");

$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Gefundene Dokumente:\n";
echo str_repeat("-", 100) . "\n";

foreach ($documents as $doc) {
    echo "Titel: {$doc['title']}\n";
    echo "  Scan-Status: {$doc['scan_status']}\n";
    echo "  Scan-Zeit: " . ($doc['scan_at'] ?? 'N/A') . "\n";
    echo "  Blob erstellt: {$doc['blob_created_at']}\n";
    echo "  Event-Typ: " . ($doc['event_type'] ?? 'KEIN EVENT') . "\n";
    echo "  Event erstellt: " . ($doc['event_created_at'] ?? 'N/A') . "\n";
    echo "  Event verarbeitet: " . ($doc['processed_at'] ?? 'NICHT VERARBEITET') . "\n";
    echo "\n";
}

// Prüfe ClamAV-Verfügbarkeit
echo "\n=== ClamAV Status ===\n";
try {
    $clamAv = new \TOM\Infrastructure\Document\ClamAvService();
    if ($clamAv->isAvailable()) {
        echo "ClamAV: Verfügbar\n";
        $version = $clamAv->getVersion();
        echo "Version: " . ($version ?? 'Unbekannt') . "\n";
    } else {
        echo "ClamAV: NICHT verfügbar\n";
    }
} catch (Exception $e) {
    echo "ClamAV: Fehler - " . $e->getMessage() . "\n";
}

echo "\n=== Scan-Worker Status ===\n";
echo "Prüfe Windows Task Scheduler...\n";
$task = shell_exec('schtasks /query /tn "TOM3-ClamAV-Scan-Worker" /fo LIST 2>&1');
if (strpos($task, 'TOM3-ClamAV-Scan-Worker') !== false) {
    echo "Task gefunden:\n";
    echo $task . "\n";
} else {
    echo "WARNUNG: Task 'TOM3-ClamAV-Scan-Worker' nicht gefunden!\n";
    echo "Bitte einrichten mit: powershell -ExecutionPolicy Bypass -File scripts\\setup-clamav-scan-worker.ps1\n";
}


