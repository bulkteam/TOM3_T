<?php
/**
 * TOM3 - Debug Excel Structure
 * Zeigt die komplette Struktur der Excel-Datei
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$filePath = __DIR__ . '/../externe Daten/wzw-2026-01-02 18-22-33.xlsx';

echo "========================================\n";
echo "Excel-Struktur-Analyse\n";
echo "========================================\n\n";

try {
    $spreadsheet = IOFactory::load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    
    $highestRow = $worksheet->getHighestDataRow();
    $highestCol = $worksheet->getHighestDataColumn();
    
    echo "📊 Höchste Zeile: $highestRow\n";
    echo "📊 Höchste Spalte: $highestCol\n\n";
    
    // Konvertiere Spaltenbuchstabe zu Zahl
    $highestColNum = 0;
    for ($i = 0; $i < strlen($highestCol); $i++) {
        $highestColNum = $highestColNum * 26 + (ord($highestCol[$i]) - ord('A') + 1);
    }
    echo "📊 Höchste Spalte (als Zahl): $highestColNum\n\n";
    
    echo "========================================\n";
    echo "ERSTE 10 ZEILEN (alle Spalten)\n";
    echo "========================================\n\n";
    
    // Zeige erste 10 Zeilen
    for ($row = 1; $row <= min(10, $highestRow); $row++) {
        echo "--- Zeile $row ---\n";
        
        // Prüfe alle Spalten bis zur höchsten
        for ($colNum = 1; $colNum <= $highestColNum; $colNum++) {
            $col = '';
            $temp = $colNum;
            while ($temp > 0) {
                $col = chr(65 + (($temp - 1) % 26)) . $col;
                $temp = intval(($temp - 1) / 26);
            }
            
            $cell = $worksheet->getCell($col . $row);
            $value = trim($cell->getFormattedValue());
            $calculatedValue = trim($cell->getCalculatedValue());
            
            if (!empty($value) || !empty($calculatedValue)) {
                $displayValue = $value ?: $calculatedValue;
                echo sprintf("  %-5s: %s\n", $col, mb_substr($displayValue, 0, 60));
            }
        }
        echo "\n";
    }
    
    echo "========================================\n";
    echo "SPALTEN-ANALYSE (Zeile 1-5)\n";
    echo "========================================\n\n";
    
    // Analysiere jede Spalte
    for ($colNum = 1; $colNum <= $highestColNum; $colNum++) {
        $col = '';
        $temp = $colNum;
        while ($temp > 0) {
            $col = chr(65 + (($temp - 1) % 26)) . $col;
            $temp = intval(($temp - 1) / 26);
        }
        
        $hasData = false;
        $values = [];
        
        for ($row = 1; $row <= min(5, $highestRow); $row++) {
            $cell = $worksheet->getCell($col . $row);
            $value = trim($cell->getFormattedValue());
            if (!empty($value)) {
                $hasData = true;
                $values[] = $value;
            }
        }
        
        if ($hasData) {
            echo "Spalte $col:\n";
            foreach ($values as $idx => $val) {
                echo sprintf("  Zeile %d: %s\n", $idx + 1, mb_substr($val, 0, 60));
            }
            echo "\n";
        }
    }
    
    echo "========================================\n";
    echo "ZELLEN-TYP-ANALYSE (Zeile 1)\n";
    echo "========================================\n\n";
    
    for ($colNum = 1; $colNum <= $highestColNum; $colNum++) {
        $col = '';
        $temp = $colNum;
        while ($temp > 0) {
            $col = chr(65 + (($temp - 1) % 26)) . $col;
            $temp = intval(($temp - 1) / 26);
        }
        
        $cell = $worksheet->getCell($col . '1');
        $value = $cell->getFormattedValue();
        $calculatedValue = $cell->getCalculatedValue();
        $dataType = $cell->getDataType();
        
        if (!empty($value) || !empty($calculatedValue)) {
            echo sprintf("%-5s: Type=%s, Value='%s', Calculated='%s'\n", 
                $col, 
                $dataType,
                mb_substr($value, 0, 40),
                mb_substr($calculatedValue, 0, 40)
            );
        }
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

