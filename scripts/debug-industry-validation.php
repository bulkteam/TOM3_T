<?php
/**
 * TOM3 - Debug Industry Validation
 * Testet die Branchen-Validierungslogik mit Beispieldaten
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\Import\OrgImportService;
use TOM\Service\Import\ImportIndustryValidationService;
use TOM\Infrastructure\Database\DatabaseConnection;

echo "========================================\n";
echo "TOM3 - Debug Industry Validation\n";
echo "========================================\n\n";

// Finde Excel-Datei
$dataDir = __DIR__ . '/../externe Daten';
$files = glob($dataDir . '/*.{xlsx,xls}', GLOB_BRACE);

if (empty($files)) {
    echo "❌ Keine Excel-Datei gefunden in: $dataDir\n";
    exit(1);
}

$filePath = $files[0];
echo "📄 Datei: " . basename($filePath) . "\n\n";

try {
    $importService = new OrgImportService();
    
    echo "🔍 Analysiere Excel-Datei...\n\n";
    $analysis = $importService->analyzeExcel($filePath);
    
    echo "✅ Analyse abgeschlossen!\n\n";
    
    // Zeige Mapping
    $mapping = $analysis['mapping_suggestion'];
    echo "========================================\n";
    echo "MAPPING-KONFIGURATION\n";
    echo "========================================\n\n";
    
    $byColumn = $mapping['by_column'] ?? [];
    $level1Col = null;
    $level2Col = null;
    $level3Col = null;
    
    foreach ($byColumn as $col => $mapping) {
        $tomField = $mapping['tom_field'] ?? '';
        if ($tomField === 'industry_level1' || $tomField === 'industry_main') {
            $level1Col = $col;
            echo "Level 1 Spalte: $col → {$mapping['excel_header']}\n";
        }
        if ($tomField === 'industry_level2' || $tomField === 'industry_sub') {
            $level2Col = $col;
            echo "Level 2 Spalte: $col → {$mapping['excel_header']}\n";
        }
        if ($tomField === 'industry_level3') {
            $level3Col = $col;
            echo "Level 3 Spalte: $col → {$mapping['excel_header']}\n";
        }
    }
    
    echo "\n";
    
    // Zeige Beispieldaten
    echo "========================================\n";
    echo "BEISPIEL-DATEN\n";
    echo "========================================\n\n";
    
    $sampleRows = $analysis['sample_rows'] ?? [];
    $level1Values = [];
    $level2Values = [];
    $level3Values = [];
    
    foreach ($sampleRows as $idx => $row) {
        echo "Zeile " . ($idx + 1) . ":\n";
        
        if ($level1Col && isset($row[$level1Col])) {
            $val = trim($row[$level1Col]);
            echo "  Level 1 ($level1Col): $val\n";
            if (!empty($val) && !in_array($val, $level1Values)) {
                $level1Values[] = $val;
            }
        }
        
        if ($level2Col && isset($row[$level2Col])) {
            $val = trim($row[$level2Col]);
            echo "  Level 2 ($level2Col): $val\n";
            if (!empty($val) && !in_array($val, $level2Values)) {
                $level2Values[] = $val;
            }
        }
        
        if ($level3Col && isset($row[$level3Col])) {
            $val = trim($row[$level3Col]);
            echo "  Level 3 ($level3Col): $val\n";
            if (!empty($val) && !in_array($val, $level3Values)) {
                $level3Values[] = $val;
            }
        }
        
        echo "\n";
    }
    
    echo "Eindeutige Werte:\n";
    echo "  Level 1: " . implode(', ', $level1Values) . "\n";
    echo "  Level 2: " . implode(', ', $level2Values) . "\n";
    echo "  Level 3: " . implode(', ', $level3Values) . "\n\n";
    
    // Zeige Validierungsergebnis
    echo "========================================\n";
    echo "VALIDIERUNGSERGEBNIS\n";
    echo "========================================\n\n";
    
    $validation = $analysis['industry_validation'] ?? null;
    
    if (!$validation) {
        echo "❌ Keine Validierung durchgeführt!\n";
        exit(1);
    }
    
    echo "Level 1 (Branchenbereiche):\n";
    echo "  Gefunden: " . count($validation['main']['found'] ?? []) . "\n";
    echo "  Fehlend: " . count($validation['main']['missing'] ?? []) . "\n";
    if (!empty($validation['main']['found'])) {
        foreach ($validation['main']['found'] as $found) {
            echo "    ✅ {$found['excel_value']} → {$found['db_industry']['name']}\n";
        }
    }
    if (!empty($validation['main']['missing'])) {
        foreach ($validation['main']['missing'] as $missing) {
            echo "    ❌ {$missing['excel_value']}";
            if ($missing['suggestion']) {
                echo " (Vorschlag: {$missing['suggestion']['name']}, " . ($missing['similarity'] * 100) . "%)\n";
            } else {
                echo "\n";
            }
        }
    }
    
    echo "\nLevel 2 (Branchen):\n";
    echo "  Gefunden: " . count($validation['sub']['found'] ?? []) . "\n";
    echo "  Fehlend: " . count($validation['sub']['missing'] ?? []) . "\n";
    if (!empty($validation['sub']['found'])) {
        foreach ($validation['sub']['found'] as $found) {
            echo "    ✅ {$found['excel_value']} → {$found['db_industry']['name']}\n";
        }
    }
    if (!empty($validation['sub']['missing'])) {
        foreach ($validation['sub']['missing'] as $missing) {
            echo "    ❌ {$missing['excel_value']}";
            if ($missing['suggestion']) {
                echo " (Vorschlag: {$missing['suggestion']['name']}, " . ($missing['similarity'] * 100) . "%)\n";
            } else {
                echo "\n";
            }
        }
    }
    
    echo "\nLevel 3 (Unterbranchen):\n";
    echo "  Gefunden: " . count($validation['level3']['found'] ?? []) . "\n";
    echo "  Fehlend: " . count($validation['level3']['missing'] ?? []) . "\n";
    
    echo "\nKombinationen:\n";
    $combinations = $validation['combinations'] ?? [];
    echo "  Anzahl: " . count($combinations) . "\n";
    
    if (empty($combinations)) {
        echo "  ⚠️ Keine Kombinationen gefunden!\n\n";
        
        // Debug: Warum keine Kombinationen?
        echo "DEBUG: Warum keine Kombinationen?\n";
        echo "  Level 1 Werte vorhanden: " . (empty($level1Values) ? "NEIN" : "JA (" . implode(', ', $level1Values) . ")") . "\n";
        echo "  Level 2 Werte vorhanden: " . (empty($level2Values) ? "NEIN" : "JA (" . implode(', ', $level2Values) . ")") . "\n";
        echo "  Level 3 Werte vorhanden: " . (empty($level3Values) ? "NEIN" : "JA (" . implode(', ', $level3Values) . ")") . "\n";
        echo "  Level 1 Spalte: " . ($level1Col ?: "NICHT GEFUNDEN") . "\n";
        echo "  Level 2 Spalte: " . ($level2Col ?: "NICHT GEFUNDEN") . "\n";
        echo "  Level 3 Spalte: " . ($level3Col ?: "NICHT GEFUNDEN") . "\n";
        echo "  Level 1 gefunden: " . count($validation['main']['found'] ?? []) . "\n";
        echo "  Level 2 gefunden: " . count($validation['sub']['found'] ?? []) . "\n";
        
        // Prüfe, ob Kombinationen erstellt werden sollten
        $combinationsCreated = false;
        foreach ($sampleRows as $row) {
            $l1 = $level1Col && isset($row[$level1Col]) ? trim($row[$level1Col]) : null;
            $l2 = $level2Col && isset($row[$level2Col]) ? trim($row[$level2Col]) : null;
            if ($l1 && $l2) {
                $combinationsCreated = true;
                echo "  Kombination sollte erstellt werden: $l1 / $l2\n";
            }
        }
        
        if (!$combinationsCreated && !empty($level2Values) && !empty($validation['sub']['found'])) {
            echo "  → Fallback sollte greifen: Level 2 gefunden, aber keine Kombinationen\n";
        }
    } else {
        foreach ($combinations as $idx => $combo) {
            echo "  Kombination " . ($idx + 1) . ":\n";
            echo "    Excel Level 1: " . ($combo['excel_level1'] ?? 'N/A') . "\n";
            echo "    DB Level 1: " . ($combo['db_level1']['name'] ?? 'N/A') . "\n";
            echo "    Excel Level 2: " . ($combo['excel_level2'] ?? 'N/A') . "\n";
            if (!empty($combo['level2_matches'])) {
                foreach ($combo['level2_matches'] as $match) {
                    echo "    DB Level 2: {$match['db_industry']['name']} (" . ($match['similarity'] * 100) . "%)\n";
                }
            }
            if (!empty($combo['excel_level3s'])) {
                echo "    Excel Level 3: " . implode(', ', $combo['excel_level3s']) . "\n";
            }
        }
    }
    
    echo "\n✅ Debug abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
