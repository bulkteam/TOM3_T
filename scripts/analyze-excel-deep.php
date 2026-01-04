<?php
/**
 * Tiefe Analyse der Excel-Datei - sucht auch nach versteckten/formatieren Zellen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$filePath = __DIR__ . '/../externe Daten/wzw-2026-01-02 18-22-33.xlsx';

if (!file_exists($filePath)) {
    die("Datei nicht gefunden: $filePath\n");
}

try {
    $spreadsheet = IOFactory::load($filePath);
    
    // Prüfe alle Worksheets
    foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
        $sheetName = $worksheet->getTitle();
        echo "=== WORKSHEET: $sheetName ===\n\n";
        
        // Verwende getHighestDataRow/getHighestDataColumn statt getHighestRow/getHighestColumn
        // Diese Methoden finden nur Zellen mit tatsächlichen Daten
        $highestDataRow = $worksheet->getHighestDataRow();
        $highestDataColumn = $worksheet->getHighestDataColumn();
        
        echo "Zeilen mit Daten: $highestDataRow\n";
        echo "Spalten mit Daten: $highestDataColumn\n\n";
        
        // Lese alle Zeilen mit Daten
        echo "=== ALLE ZEILEN MIT DATEN ===\n";
        for ($row = 1; $row <= $highestDataRow; $row++) {
            $rowData = [];
            $hasData = false;
            
            // Iteriere über alle Spalten mit Daten
            $colIndex = 0;
            for ($col = 'A'; $col <= $highestDataColumn; $col++) {
                // Prüfe sowohl getValue() als auch getFormattedValue()
                $value = $worksheet->getCell($col . $row)->getValue();
                $formatted = $worksheet->getCell($col . $row)->getFormattedValue();
                
                // Prüfe auch auf leere Strings, die als Daten erkannt werden könnten
                if ($value !== null && $value !== '') {
                    $rowData[$col] = [
                        'value' => $value,
                        'formatted' => $formatted,
                        'type' => $worksheet->getCell($col . $row)->getDataType()
                    ];
                    $hasData = true;
                }
            }
            
            if ($hasData) {
                echo "\n--- Zeile $row ---\n";
                foreach ($rowData as $col => $data) {
                    $valueStr = is_array($data['value']) ? json_encode($data['value']) : (string)$data['value'];
                    $formattedStr = (string)$data['formatted'];
                    
                    echo sprintf(
                        "  %-5s | Type: %-10s | Value: %-40s | Formatted: %s\n",
                        $col,
                        $data['type'],
                        substr($valueStr, 0, 40),
                        substr($formattedStr, 0, 60)
                    );
                }
            }
        }
        
        // Zusätzlich: Suche nach Merged Cells
        echo "\n=== MERGED CELLS ===\n";
        $mergedCells = $worksheet->getMergeCells();
        if (!empty($mergedCells)) {
            foreach ($mergedCells as $mergedRange) {
                echo "  Merged: $mergedRange\n";
            }
        } else {
            echo "  Keine merged cells gefunden\n";
        }
        
        // Zusätzlich: Prüfe auf versteckte Zeilen/Spalten
        echo "\n=== VERSTECKTE ZEILEN/SPALTEN ===\n";
        $hiddenRows = [];
        $hiddenCols = [];
        
        for ($row = 1; $row <= $highestDataRow; $row++) {
            if ($worksheet->getRowDimension($row)->getVisible() === false) {
                $hiddenRows[] = $row;
            }
        }
        
        if (!empty($hiddenRows)) {
            echo "  Versteckte Zeilen: " . implode(', ', $hiddenRows) . "\n";
        }
        
        $colIndex = 0;
        for ($col = 'A'; $col <= $highestDataColumn; $col++) {
            if ($worksheet->getColumnDimension($col)->getVisible() === false) {
                $hiddenCols[] = $col;
            }
        }
        
        if (!empty($hiddenCols)) {
            echo "  Versteckte Spalten: " . implode(', ', $hiddenCols) . "\n";
        }
        
        if (empty($hiddenRows) && empty($hiddenCols)) {
            echo "  Keine versteckten Zeilen/Spalten\n";
        }
        
        echo "\n";
    }
    
} catch (\Exception $e) {
    die("Fehler: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
}

