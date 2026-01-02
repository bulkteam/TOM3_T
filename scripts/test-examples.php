<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\Import\OrgImportService;

$importService = new OrgImportService();
$filePath = __DIR__ . '/../externe Daten/wzw-2026-01-02 18-22-33.xlsx';

$analysis = $importService->analyzeExcel($filePath);

echo "Sample Rows: " . count($analysis['sample_rows']) . "\n";

if (!empty($analysis['sample_rows'])) {
    echo "First row keys: " . implode(', ', array_keys($analysis['sample_rows'][0])) . "\n";
    echo "Column G (Firma1) value: " . ($analysis['sample_rows'][0]['G'] ?? 'NOT FOUND') . "\n";
    echo "Column I (Strasse) value: " . ($analysis['sample_rows'][0]['I'] ?? 'NOT FOUND') . "\n";
    
    echo "\nMapping examples:\n";
    $mapping = $analysis['mapping_suggestion'];
    $byField = $mapping['by_field'] ?? [];
    
    foreach ($byField as $tomField => $candidates) {
        foreach ($candidates as $candidate) {
            $examples = $candidate['examples'] ?? [];
            echo "$tomField (Spalte {$candidate['excel_column']}): " . count($examples) . " Beispiele\n";
            if (!empty($examples)) {
                echo "  " . implode(", ", array_slice($examples, 0, 3)) . "\n";
            }
        }
    }
}
