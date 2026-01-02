<?php
/**
 * Vollständige Analyse der Excel-Datei
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$filePath = __DIR__ . '/../externe Daten/wzw-2026-01-02 18-22-33.xlsx';

if (!file_exists($filePath)) {
    die("Datei nicht gefunden: $filePath\n");
}

try {
    $spreadsheet = IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    
    $highestRow = $worksheet->getHighestRow();
    $highestColumn = $worksheet->getHighestColumn();
    
    echo "=== VOLLSTÄNDIGE EXCEL-ANALYSE ===\n";
    echo "Datei: " . basename($filePath) . "\n";
    echo "Zeilen: $highestRow\n";
    echo "Spalten: $highestColumn\n\n";
    
    // Finde die erste Zeile mit mehreren gefüllten Zellen (wahrscheinlich Header)
    echo "=== HEADER-SUCHE ===\n";
    for ($row = 1; $row <= min(10, $highestRow); $row++) {
        $filledCells = 0;
        $cellValues = [];
        
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
            if (!empty($value)) {
                $filledCells++;
                $cellValues[] = "$col: $value";
            }
        }
        
        if ($filledCells > 0) {
            echo "Zeile $row: $filledCells gefüllte Zellen\n";
            if ($filledCells <= 20) {
                echo "  " . implode(", ", array_slice($cellValues, 0, 20)) . "\n";
            } else {
                echo "  " . implode(", ", array_slice($cellValues, 0, 10)) . " ... (weitere)\n";
            }
        }
    }
    
    echo "\n=== ALLE ZEILEN MIT DATEN ===\n";
    for ($row = 1; $row <= $highestRow; $row++) {
        $rowData = [];
        $hasData = false;
        
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
            if (!empty($value)) {
                $rowData[$col] = $value;
                $hasData = true;
            }
        }
        
        if ($hasData) {
            echo "\n--- Zeile $row ---\n";
            foreach ($rowData as $col => $value) {
                echo sprintf("  %-5s: %s\n", $col, substr($value, 0, 80));
            }
        }
    }
    
} catch (\Exception $e) {
    die("Fehler: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
}
