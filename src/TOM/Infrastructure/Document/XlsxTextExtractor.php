<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Document;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

/**
 * Extrahiert Text aus Excel-Dateien (XLSX, XLS)
 */
class XlsxTextExtractor
{
    /**
     * Extrahiert Text aus einer Excel-Datei
     * 
     * @param string $filePath Pfad zur Excel-Datei
     * @return string Extrahierter Text
     * @throws \Exception Bei Fehlern
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Datei nicht gefunden: {$filePath}");
        }
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            $textParts = [];
            
            // Iteriere über alle Worksheets
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheetName = $worksheet->getTitle();
                $textParts[] = "=== {$sheetName} ===";
                
                // Iteriere über alle Zellen
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                
                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = [];
                    
                    for ($col = 'A'; $col <= $highestColumn; $col++) {
                        $cell = $worksheet->getCell($col . $row);
                        $value = $cell->getFormattedValue();
                        
                        // Überspringe leere Zellen
                        if (trim($value) !== '') {
                            $rowData[] = trim($value);
                        }
                    }
                    
                    // Füge Zeile hinzu, wenn sie Daten enthält
                    if (!empty($rowData)) {
                        $textParts[] = implode(' | ', $rowData);
                    }
                }
                
                $textParts[] = ''; // Leerzeile zwischen Sheets
            }
            
            $text = implode("\n", $textParts);
            
            // Normalisiere
            $text = preg_replace('/\n{3,}/u', "\n\n", $text);
            $text = trim($text);
            
            return $text;
        } catch (ReaderException $e) {
            throw new \RuntimeException("Fehler beim Lesen der Excel-Datei: " . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new \RuntimeException("Fehler bei Excel-Extraktion: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Holt Metadaten aus der Excel-Datei
     * 
     * @param string $filePath Pfad zur Excel-Datei
     * @return array Metadaten (sheets, cells, etc.)
     */
    public function getMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            
            $metadata = [
                'sheets' => $spreadsheet->getSheetCount(),
                'sheet_names' => []
            ];
            
            $totalCells = 0;
            $totalRows = 0;
            
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheetName = $worksheet->getTitle();
                $metadata['sheet_names'][] = $sheetName;
                
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                
                // Zähle nicht-leere Zellen
                $nonEmptyCells = 0;
                for ($row = 1; $row <= $highestRow; $row++) {
                    $hasData = false;
                    for ($col = 'A'; $col <= $highestColumn; $col++) {
                        $cell = $worksheet->getCell($col . $row);
                        if (trim($cell->getFormattedValue()) !== '') {
                            $nonEmptyCells++;
                            $hasData = true;
                        }
                    }
                    if ($hasData) {
                        $totalRows++;
                    }
                }
                
                $totalCells += $nonEmptyCells;
            }
            
            $metadata['total_cells'] = $totalCells;
            $metadata['total_rows'] = $totalRows;
            
            // Versuche Dokument-Eigenschaften zu lesen
            $properties = $spreadsheet->getProperties();
            if ($properties->getTitle()) {
                $metadata['title'] = $properties->getTitle();
            }
            if ($properties->getCreator()) {
                $metadata['author'] = $properties->getCreator();
            }
            if ($properties->getSubject()) {
                $metadata['subject'] = $properties->getSubject();
            }
            
            return $metadata;
        } catch (\Exception $e) {
            return [];
        }
    }
}
