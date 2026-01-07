<?php
/**
 * Prüft warum approved Rows nicht importiert wurden
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  Prüfe Pending Imports\n";
echo "========================================\n\n";

// Finde alle Batches
$stmt = $db->query("
    SELECT batch_uuid, status, created_at, stats_json
    FROM org_import_batch
    ORDER BY created_at DESC
    LIMIT 5
");
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($batches as $batch) {
    echo "Batch: {$batch['batch_uuid']} (Status: {$batch['status']}, Erstellt: {$batch['created_at']})\n";
    
    $stats = json_decode($batch['stats_json'] ?? '{}', true);
    if ($stats) {
        echo "  Stats: " . json_encode($stats, JSON_PRETTY_PRINT) . "\n";
    }
    
    // Prüfe Staging-Rows
    $stmt2 = $db->prepare("
        SELECT 
            staging_uuid,
            row_number,
            disposition,
            import_status,
            JSON_EXTRACT(mapped_data, '$.org.name') as org_name,
            validation_status,
            JSON_EXTRACT(industry_resolution, '$.decision.level1_uuid') as level1_uuid,
            JSON_EXTRACT(industry_resolution, '$.decision.level2_uuid') as level2_uuid
        FROM org_import_staging
        WHERE import_batch_uuid = :batch_uuid
        ORDER BY row_number
    ");
    $stmt2->execute(['batch_uuid' => $batch['batch_uuid']]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo "  Staging-Rows: " . count($rows) . "\n";
    
    $approvedNotImported = [];
    $pending = [];
    $imported = [];
    
    foreach ($rows as $row) {
        $orgName = json_decode($row['org_name'] ?? 'null', true);
        $level1 = json_decode($row['level1_uuid'] ?? 'null', true);
        $level2 = json_decode($row['level2_uuid'] ?? 'null', true);
        
        if ($row['disposition'] === 'approved' && $row['import_status'] !== 'imported') {
            $approvedNotImported[] = [
                'row' => $row['row_number'],
                'name' => $orgName,
                'level1' => $level1,
                'level2' => $level2,
                'validation' => $row['validation_status']
            ];
        } elseif ($row['disposition'] === 'pending') {
            $pending[] = [
                'row' => $row['row_number'],
                'name' => $orgName
            ];
        } elseif ($row['import_status'] === 'imported') {
            $imported[] = [
                'row' => $row['row_number'],
                'name' => $orgName
            ];
        }
    }
    
    if (!empty($approvedNotImported)) {
        echo "\n  ⚠️  APPROVED aber NICHT IMPORTIERT (" . count($approvedNotImported) . "):\n";
        foreach ($approvedNotImported as $item) {
            echo "     Zeile {$item['row']}: {$item['name']}\n";
            if (!$item['level1'] || !$item['level2']) {
                echo "       ❌ FEHLT: Level1: " . ($item['level1'] ? 'OK' : 'FEHLT') . ", Level2: " . ($item['level2'] ? 'OK' : 'FEHLT') . "\n";
            }
            if ($item['validation'] !== 'valid') {
                echo "       ⚠️  Validation: {$item['validation']}\n";
            }
        }
    }
    
    if (!empty($pending)) {
        echo "\n  ⏳ PENDING (" . count($pending) . "):\n";
        foreach ($pending as $item) {
            echo "     Zeile {$item['row']}: {$item['name']}\n";
        }
    }
    
    if (!empty($imported)) {
        echo "\n  ✅ IMPORTIERT (" . count($imported) . "):\n";
        foreach ($imported as $item) {
            echo "     Zeile {$item['row']}: {$item['name']}\n";
        }
    }
    
    echo "\n" . str_repeat('-', 60) . "\n\n";
}


