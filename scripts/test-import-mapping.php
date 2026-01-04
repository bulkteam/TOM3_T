<?php
/**
 * TOM3 - Test Import Mapping
 * Analysiert Excel-Datei und zeigt gefundenes Mapping
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\Import\OrgImportService;
use TOM\Infrastructure\Database\DatabaseConnection;

echo "========================================\n";
echo "TOM3 - Import Mapping Test\n";
echo "========================================\n\n";

// Finde Excel-Datei
$dataDir = __DIR__ . '/../externe Daten';
$files = glob($dataDir . '/*.{xlsx,xls}', GLOB_BRACE);

if (empty($files)) {
    echo "❌ Keine Excel-Datei gefunden in: $dataDir\n";
    exit(1);
}

$filePath = $files[0];
echo "📄 Datei: " . basename($filePath) . "\n";
echo "📁 Pfad: $filePath\n\n";

try {
    $importService = new OrgImportService();
    
    echo "🔍 Analysiere Excel-Datei...\n\n";
    
    $analysis = $importService->analyzeExcel($filePath);
    
    echo "✅ Analyse abgeschlossen!\n\n";
    echo "========================================\n";
    echo "ERGEBNISSE\n";
    echo "========================================\n\n";
    
    echo "📊 Header-Zeile: " . $analysis['header_row'] . "\n";
    echo "📋 Anzahl Spalten: " . count($analysis['columns']) . "\n\n";
    
    echo "========================================\n";
    echo "GEFUNDENE SPALTEN\n";
    echo "========================================\n\n";
    
    foreach ($analysis['columns'] as $col => $header) {
        echo sprintf("%-5s: %s\n", $col, $header ?: "(leer)");
    }
    
    echo "\n";
    echo "========================================\n";
    echo "MAPPING-VORSCHLÄGE (nach TOM-Feld gruppiert)\n";
    echo "========================================\n\n";
    
    $mapping = $analysis['mapping_suggestion'];
    $byField = $mapping['by_field'] ?? [];
    $byColumn = $mapping['by_column'] ?? [];
    
    // Zeige Mapping pro TOM-Feld
    foreach ($byField as $tomField => $candidates) {
        echo "📌 TOM-Feld: $tomField\n";
        echo "   Kandidaten:\n";
        
        foreach ($candidates as $candidate) {
            $col = $candidate['excel_column'];
            $header = $candidate['excel_header'];
            $confidence = $candidate['confidence'];
            $examples = $candidate['examples'] ?? [];
            
            // Zeige Spalte und Header
            echo sprintf("   - Spalte %-5s | %-30s | %3d%%\n",
                $col,
                mb_substr($header, 0, 30),
                $confidence
            );
            
            // Zeige Beispiele
            if (!empty($examples)) {
                echo "     Beispiele: ";
                $exampleList = [];
                foreach (array_slice($examples, 0, 3) as $example) {
                    $exampleList[] = mb_substr($example, 0, 40);
                }
                echo implode(" | ", $exampleList);
                if (count($examples) > 3) {
                    echo " ... (+" . (count($examples) - 3) . " weitere)";
                }
                echo "\n";
            } else {
                echo "     (keine Beispiele verfügbar)\n";
            }
        }
        echo "\n";
    }
    
    echo "\n";
    echo "========================================\n";
    echo "MAPPING-VORSCHLÄGE (nach Spalte)\n";
    echo "========================================\n\n";
    
    // Sortiere nach Konfidenz (höchste zuerst)
    uasort($byColumn, function($a, $b) {
        return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
    });
    
    printf("%-8s | %-30s | %-25s | %-10s | %-50s | %s\n", 
        "Spalte", "Excel-Header", "TOM-Feld", "Konfidenz", "Beispiele", "Status");
    echo str_repeat("-", 140) . "\n";
    
    foreach ($byColumn as $col => $suggestion) {
        $header = $suggestion['excel_header'] ?? $col;
        $tomField = $suggestion['tom_field'] ?? "(kein Mapping)";
        $confidence = $suggestion['confidence'] ?? 0;
        $ignore = $suggestion['ignore'] ?? false;
        $status = ($ignore || $tomField === "(kein Mapping)") ? "⚠️" : "✅";
        
        // Beispiele formatieren
        $examples = $suggestion['examples'] ?? [];
        $exampleStr = "";
        if (!empty($examples)) {
            $exampleList = [];
            foreach (array_slice($examples, 0, 2) as $example) {
                $exampleList[] = mb_substr($example, 0, 20);
            }
            $exampleStr = implode(", ", $exampleList);
            if (count($examples) > 2) {
                $exampleStr .= " ...";
            }
        } else {
            $exampleStr = "(keine)";
        }
        
        printf("%-8s | %-30s | %-25s | %-10s | %-50s | %s\n",
            $col,
            mb_substr($header, 0, 30),
            mb_substr($tomField, 0, 25),
            $confidence . "%",
            mb_substr($exampleStr, 0, 50),
            $status
        );
    }
    
    echo "\n";
    echo "========================================\n";
    echo "BEISPIEL-DATEN (erste 3 Zeilen)\n";
    echo "========================================\n\n";
    
    if (!empty($analysis['sample_rows'])) {
        foreach ($analysis['sample_rows'] as $idx => $row) {
            echo "Zeile " . ($analysis['header_row'] + $idx + 1) . ":\n";
            foreach ($row as $col => $value) {
                $header = $analysis['columns'][$col] ?? $col;
                echo sprintf("  %-5s (%s): %s\n", $col, mb_substr($header, 0, 20), mb_substr($value, 0, 50));
            }
            echo "\n";
        }
    } else {
        echo "Keine Beispieldaten gefunden.\n";
    }
    
    echo "\n";
    echo "========================================\n";
    echo "ZUSAMMENFASSUNG\n";
    echo "========================================\n\n";
    
    $mapped = count(array_filter($byColumn, fn($s) => !empty($s['tom_field']) && !($s['ignore'] ?? false)));
    $ignored = count(array_filter($byColumn, fn($s) => $s['ignore'] ?? false));
    $total = count($byColumn);
    $unmapped = $total - $mapped - $ignored;
    
    echo "Gesamt Spalten: $total\n";
    echo "Gemappt: $mapped ✅\n";
    echo "Ignoriert: $ignored 🚫\n";
    echo "Nicht gemappt: $unmapped ⚠️\n";
    
    if ($unmapped > 0) {
        echo "\n⚠️  Nicht gemappte Spalten:\n";
        foreach ($byColumn as $col => $suggestion) {
            if (empty($suggestion['tom_field']) && !($suggestion['ignore'] ?? false)) {
                $header = $suggestion['excel_header'] ?? $col;
                echo "  - $col: $header\n";
            }
        }
    }
    
    echo "\n✅ Test abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

