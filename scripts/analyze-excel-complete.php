<?php
/**
 * Vollständige Analyse - alle Spalten bis BL
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$filePath = __DIR__ . '/../externe Daten/wzw-2026-01-02 18-22-33.xlsx';

try {
    $spreadsheet = IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    
    $highestDataRow = $worksheet->getHighestDataRow();
    $highestDataColumn = $worksheet->getHighestDataColumn();
    
    echo "=== VOLLSTÄNDIGE ANALYSE ===\n";
    echo "Worksheet: " . $worksheet->getTitle() . "\n";
    echo "Zeilen mit Daten: $highestDataRow\n";
    echo "Spalten mit Daten: $highestDataColumn\n\n";
    
    // Erstelle Spalten-Array bis BL
    $columns = [];
    for ($col = 'A'; $col <= $highestDataColumn; $col++) {
        $columns[] = $col;
    }
    
    echo "=== HEADER-ZEILE (Row 1) - ALLE SPALTEN ===\n";
    $headerRow = [];
    foreach ($columns as $col) {
        $value = trim($worksheet->getCell($col . '1')->getFormattedValue());
        if (!empty($value)) {
            $headerRow[$col] = $value;
            echo sprintf("%-5s: %s\n", $col, $value);
        }
    }
    
    if (empty($headerRow)) {
        echo "Keine Header gefunden in Zeile 1\n";
    }
    
    echo "\n=== ERSTE 3 DATENZEILEN - ALLE SPALTEN MIT DATEN ===\n";
    for ($row = 2; $row <= min(4, $highestDataRow); $row++) {
        echo "\n--- Zeile $row ---\n";
        $rowData = [];
        
        foreach ($columns as $col) {
            $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
            if (!empty($value)) {
                $header = $headerRow[$col] ?? '';
                echo sprintf("  %-5s [%-30s]: %s\n", $col, substr($header, 0, 30), substr($value, 0, 60));
                $rowData[$col] = $value;
            }
        }
        
        if (empty($rowData)) {
            echo "  (keine Daten)\n";
        }
    }
    
    // Suche nach der Zeile mit den meisten gefüllten Zellen (wahrscheinlich Header)
    echo "\n=== HEADER-SUCHE (Zeile mit meisten Daten) ===\n";
    $maxFilled = 0;
    $headerRowNum = 1;
    
    for ($row = 1; $row <= min(5, $highestDataRow); $row++) {
        $filled = 0;
        foreach ($columns as $col) {
            $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
            if (!empty($value)) {
                $filled++;
            }
        }
        
        echo "Zeile $row: $filled gefüllte Zellen\n";
        
        if ($filled > $maxFilled) {
            $maxFilled = $filled;
            $headerRowNum = $row;
        }
    }
    
    echo "\n→ Wahrscheinliche Header-Zeile: $headerRowNum (mit $maxFilled gefüllten Zellen)\n";
    
    // Zeige diese Zeile
    echo "\n=== WAHRSCHEINLICHE HEADER-ZEILE (Row $headerRowNum) ===\n";
    foreach ($columns as $col) {
        $value = trim($worksheet->getCell($col . $headerRowNum)->getFormattedValue());
        if (!empty($value)) {
            echo sprintf("%-5s: %s\n", $col, $value);
        }
    }
    
} catch (\Exception $e) {
    die("Fehler: " . $e->getMessage() . "\n");
}
