<?php
/**
 * Prüft, ob Branchendaten in Staging-Rows vorhanden sind
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$batchUuid = $argv[1] ?? '6310c30d-e897-11f0-9caa-06db59a42104';

echo "=== Prüfe Branchendaten in Staging-Rows ===\n\n";
echo "Batch UUID: $batchUuid\n\n";

$stmt = $db->prepare("
    SELECT 
        staging_uuid,
        row_number,
        raw_data,
        mapped_data,
        industry_resolution
    FROM org_import_staging
    WHERE import_batch_uuid = ?
    ORDER BY row_number
    LIMIT 3
");

$stmt->execute([$batchUuid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo "--- Row {$row['row_number']} ---\n";
    
    $rawData = json_decode($row['raw_data'], true);
    $mappedData = json_decode($row['mapped_data'], true);
    $industryResolution = json_decode($row['industry_resolution'], true);
    
    // Prüfe raw_data
    echo "Raw Data - Oberkategorie: " . ($rawData['Oberkategorie'] ?? 'N/A') . "\n";
    echo "Raw Data - Kategorie: " . ($rawData['Kategorie'] ?? 'N/A') . "\n";
    
    // Prüfe mapped_data
    echo "Mapped Data - excel_level2_label: " . ($mappedData['industry']['excel_level2_label'] ?? 'N/A') . "\n";
    echo "Mapped Data - excel_level3_label: " . ($mappedData['industry']['excel_level3_label'] ?? 'N/A') . "\n";
    
    // Prüfe industry_resolution
    echo "Industry Resolution - level2_label: " . ($industryResolution['excel']['level2_label'] ?? 'N/A') . "\n";
    echo "Industry Resolution - level3_label: " . ($industryResolution['excel']['level3_label'] ?? 'N/A') . "\n";
    echo "Industry Resolution - level2_candidates: " . count($industryResolution['suggestions']['level2_candidates'] ?? []) . "\n";
    echo "Industry Resolution - level3_candidates: " . count($industryResolution['suggestions']['level3_candidates'] ?? []) . "\n";
    
    echo "\n";
}

echo "=== Prüfung abgeschlossen ===\n";
