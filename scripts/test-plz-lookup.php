<?php
/**
 * Test PLZ Lookup
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/plz_mapping.php';

// Test PLZ
$testPlz = '52134';

echo "Teste PLZ: $testPlz\n";
echo "================================\n";

$result = mapPlzToBundeslandAndCity($testPlz);

if ($result) {
    echo "Ergebnis:\n";
    echo "  Stadt: " . ($result['city'] ?? 'N/A') . "\n";
    echo "  Bundesland: " . ($result['bundesland'] ?? 'N/A') . "\n";
    echo "  Latitude: " . ($result['latitude'] ?? 'N/A') . "\n";
    echo "  Longitude: " . ($result['longitude'] ?? 'N/A') . "\n";
} else {
    echo "Kein Ergebnis gefunden!\n";
}

// Prüfe Dateipfad
$csvPath = __DIR__ . '/../config/plz_bundesland.CSV';
$definitionsPath = __DIR__ . '/../config/definitions/plz_bundesland.CSV';

echo "\nDateipfade:\n";
echo "  CSV direkt: " . ($csvPath . (file_exists($csvPath) ? ' (existiert)' : ' (existiert NICHT)')) . "\n";
echo "  Definitions: " . ($definitionsPath . (file_exists($definitionsPath) ? ' (existiert)' : ' (existiert NICHT)')) . "\n";



