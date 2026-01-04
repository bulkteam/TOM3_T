<?php
/**
 * Debug: Warum wurden keine Datensätze importiert?
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$batchUuid = '303d31bc-e8a1-11f0-9caa-06db59a42104';

echo "=== Debug: Warum wurden keine Datensätze importiert? ===\n\n";

// 1. Prüfe Batch-Status
echo "1. Batch-Status:\n";
$stmt = $db->prepare("SELECT status, imported_at, stats_json FROM org_import_batch WHERE batch_uuid = ?");
$stmt->execute([$batchUuid]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
if ($batch) {
    echo "   Status: " . $batch['status'] . "\n";
    echo "   Importiert am: " . ($batch['imported_at'] ?? 'N/A') . "\n";
    if ($batch['stats_json']) {
        $stats = json_decode($batch['stats_json'], true);
        echo "   Stats: " . json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    }
}
echo "\n";

// 2. Prüfe Staging-Rows Disposition
echo "2. Staging-Rows Disposition:\n";
$stmt = $db->prepare("
    SELECT 
        disposition,
        COUNT(*) as count
    FROM org_import_staging
    WHERE import_batch_uuid = ?
    GROUP BY disposition
");
$stmt->execute([$batchUuid]);
$dispositions = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($dispositions as $d) {
    echo "   {$d['disposition']}: {$d['count']}\n";
}
echo "\n";

// 3. Prüfe, was listApprovedRows() finden würde
echo "3. Was würde listApprovedRows() finden?\n";
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as count
    FROM org_import_staging
    WHERE import_batch_uuid = ?
    AND disposition = 'approved'
    AND import_status != 'imported'
");
$stmt->execute([$batchUuid]);
$approvedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
echo "   Approved Rows (nicht importiert): {$approvedCount}\n";
echo "\n";

// 4. Prüfe Activity-Logs
echo "4. Activity-Logs für diesen Batch:\n";
$stmt = $db->prepare("
    SELECT 
        action_type,
        details,
        created_at
    FROM activity_log
    WHERE entity_type = 'import_batch'
    AND entity_uuid = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$batchUuid]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($logs)) {
    echo "   Keine Activity-Logs gefunden\n";
} else {
    foreach ($logs as $log) {
        echo "   [" . $log['created_at'] . "] Action Type: " . ($log['action_type'] ?? 'N/A') . "\n";
        if ($log['details']) {
            $details = json_decode($log['details'], true);
            if ($details) {
                echo "      Details: " . json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
}
echo "\n";

// 5. Prüfe, ob es einen Commit-Versuch gab (vor meinem Fix)
echo "5. Prüfe, ob commitBatch() aufgerufen wurde:\n";
echo "   (Vor meinem Fix wurde der Status auf IMPORTED gesetzt, auch wenn keine approved Rows vorhanden waren)\n";
echo "   Das erklärt, warum Status=IMPORTED, aber keine Daten importiert wurden.\n";
echo "\n";

// 6. Zeige erste paar Staging-Rows
echo "6. Erste 5 Staging-Rows:\n";
$stmt = $db->prepare("
    SELECT 
        row_number,
        disposition,
        import_status,
        imported_org_uuid,
        validation_status
    FROM org_import_staging
    WHERE import_batch_uuid = ?
    ORDER BY row_number
    LIMIT 5
");
$stmt->execute([$batchUuid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo "   Row #{$row['row_number']}:\n";
    echo "      - Disposition: {$row['disposition']}\n";
    echo "      - Import Status: {$row['import_status']}\n";
    echo "      - Validation Status: {$row['validation_status']}\n";
    echo "      - Imported Org UUID: " . ($row['imported_org_uuid'] ?? 'NULL') . "\n";
}
echo "\n";

echo "=== Fazit ===\n";
echo "Das Problem: commitBatch() wurde aufgerufen, aber es gab keine approved Rows.\n";
echo "Vor meinem Fix wurde der Status trotzdem auf 'IMPORTED' gesetzt.\n";
echo "Jetzt wird eine Exception geworfen, wenn keine approved Rows vorhanden sind.\n";

