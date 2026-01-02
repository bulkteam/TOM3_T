<?php
/**
 * Analysiert Excel-Datei für Import-Mapping
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
    
    echo "=== Excel-Analyse ===\n";
    echo "Datei: " . basename($filePath) . "\n";
    echo "Zeilen: $highestRow\n";
    echo "Spalten: $highestColumn\n";
    
    // Prüfe, ob es mehrere Worksheets gibt
    $sheetCount = $spreadsheet->getSheetCount();
    echo "Worksheets: $sheetCount\n";
    if ($sheetCount > 1) {
        echo "Sheet-Namen: ";
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            echo "$sheetName ";
        }
        echo "\n";
    }
    echo "\n";
    
    // Header-Zeile (Row 1)
    echo "=== HEADER (Row 1) ===\n";
    $headers = [];
    $colIndex = 0;
    
    // Konvertiere Spaltenbuchstaben zu Zahlen für Iteration
    $colLetters = [];
    for ($col = 'A'; $col <= $highestColumn; $col++) {
        $colLetters[] = $col;
    }
    
    foreach ($colLetters as $col) {
        $value = $worksheet->getCell($col . '1')->getValue();
        $headers[$col] = $value;
        echo sprintf("%-5s: %s\n", $col, $value ?: '(leer)');
    }
    echo "\n";
    
    // Erste Datenzeile (Row 2)
    echo "=== ERSTE DATENZEILE (Row 2) ===\n";
    foreach ($colLetters as $col) {
        $value = $worksheet->getCell($col . '2')->getFormattedValue();
        $header = $headers[$col] ?? '';
        if (!empty($header) || !empty($value)) {
            echo sprintf("%-5s [%-40s]: %s\n", $col, substr($header, 0, 40), substr($value, 0, 50) ?: '(leer)');
        }
    }
    echo "\n";
    
    // Zweite Datenzeile (Row 3) - falls vorhanden
    if ($highestRow >= 3) {
        echo "=== ZWEITE DATENZEILE (Row 3) ===\n";
        foreach ($colLetters as $col) {
            $value = $worksheet->getCell($col . '3')->getFormattedValue();
            $header = $headers[$col] ?? '';
            if (!empty($header) || !empty($value)) {
                echo sprintf("%-5s [%-40s]: %s\n", $col, substr($header, 0, 40), substr($value, 0, 50) ?: '(leer)');
            }
        }
        echo "\n";
    }
    
    // Zusammenfassung: Alle Spalten mit Beispielwerten
    echo "=== SPALTEN-ÜBERSICHT (nur gefüllte) ===\n";
    foreach ($colLetters as $col) {
        $header = $headers[$col] ?? '';
        $example1 = $worksheet->getCell($col . '2')->getFormattedValue();
        $example2 = $highestRow >= 3 ? $worksheet->getCell($col . '3')->getFormattedValue() : '';
        
        // Nur anzeigen, wenn Header oder Daten vorhanden
        if (!empty($header) || !empty($example1) || !empty($example2)) {
            echo sprintf(
                "%-5s | %-50s | Beispiel 1: %s\n",
                $col,
                substr($header, 0, 50),
                substr($example1, 0, 40) ?: '(leer)'
            );
            if (!empty($example2)) {
                echo sprintf(
                    "      | %-50s | Beispiel 2: %s\n",
                    '',
                    substr($example2, 0, 40)
                );
            }
        }
    }
    
    // Zusätzlich: Alle Zeilen durchgehen, um Struktur zu verstehen
    echo "\n=== ZEILEN-ÜBERSICHT (erste 5 Zeilen) ===\n";
    $maxRows = min(5, $highestRow);
    for ($row = 1; $row <= $maxRows; $row++) {
        echo "\n--- Zeile $row ---\n";
        $hasData = false;
        $dataCount = 0;
        foreach ($colLetters as $col) {
            $value = $worksheet->getCell($col . $row)->getFormattedValue();
            if (!empty(trim($value))) {
                $header = $headers[$col] ?? '';
                echo sprintf("  %-5s [%-30s]: %s\n", $col, substr($header, 0, 30), substr($value, 0, 60));
                $hasData = true;
                $dataCount++;
            }
        }
        if (!$hasData) {
            echo "  (leer)\n";
        } else {
            echo "  → $dataCount Spalten mit Daten\n";
        }
    }
    
    // Prüfe alle Spalten, auch wenn sie leer erscheinen
    echo "\n=== ALLE SPALTEN (erste 20) ===\n";
    $colCount = 0;
    foreach ($colLetters as $col) {
        if ($colCount >= 20) {
            echo "... (weitere Spalten bis $highestColumn)\n";
            break;
        }
        $header = $worksheet->getCell($col . '1')->getValue();
        $value2 = $worksheet->getCell($col . '2')->getFormattedValue();
        $value3 = $worksheet->getCell($col . '3')->getFormattedValue();
        
        echo sprintf("%-5s | Header: %-30s | Row2: %-30s | Row3: %-30s\n",
            $col,
            substr($header ?: '(leer)', 0, 30),
            substr($value2 ?: '(leer)', 0, 30),
            substr($value3 ?: '(leer)', 0, 30)
        );
        $colCount++;
    }
    
} catch (\Exception $e) {
    die("Fehler: " . $e->getMessage() . "\n");
}
