<?php
/**
 * Testet Industry-Matching mit verschiedenen Begriffen
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Service\Import\IndustryNormalizer;
use TOM\Service\Import\IndustryResolver;
use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$normalizer = new IndustryNormalizer();
$resolver = new IndustryResolver($db);

echo "=== Test Industry-Matching ===\n\n";

// Test-Begriffe aus Excel
$testTerms = [
    'Chemieindustrie',
    'Chemie',
    'Maschinenbau',
    'Anlagenbau',
    'Pharma',
    'Lebensmittel',
    'Farbenhersteller'
];

foreach ($testTerms as $term) {
    echo "Test: '$term'\n";
    echo str_repeat("-", 80) . "\n";
    
    // Normalisiere
    $normalized = $normalizer->normalize($term);
    echo "Normalisiert: '$normalized'\n";
    
    // Suche Level 2 Kandidaten
    $candidates = $resolver->suggestLevel2($term, 5);
    
    if (empty($candidates)) {
        echo "❌ Keine Kandidaten gefunden\n";
    } else {
        echo "✅ Gefundene Kandidaten:\n";
        foreach ($candidates as $candidate) {
            echo "  - {$candidate['name']} [{$candidate['code']}] - Score: {$candidate['score']}\n";
        }
    }
    
    echo "\n";
}

// Teste speziell C20
echo "\n=== Spezieller Test: C20 ===\n";
echo str_repeat("-", 80) . "\n";

$stmt = $db->prepare("
    SELECT industry_uuid, name, code
    FROM industry
    WHERE code = 'C20'
");
$stmt->execute();
$c20Entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "C20 Einträge in DB:\n";
foreach ($c20Entries as $entry) {
    echo "  - {$entry['name']} [{$entry['industry_uuid']}]\n";
    
    // Teste Matching
    $normalizedEntry = $normalizer->normalize($entry['name']);
    $normalizedQuery = $normalizer->normalize('Chemieindustrie');
    
    echo "    Normalisiert: '$normalizedEntry'\n";
    echo "    Query normalisiert: '$normalizedQuery'\n";
    
    // Teste Similarity
    $candidates = $resolver->suggestLevel2('Chemieindustrie', 5);
    $found = false;
    foreach ($candidates as $candidate) {
        if ($candidate['industry_uuid'] === $entry['industry_uuid']) {
            echo "    ✅ Gefunden in Kandidaten mit Score: {$candidate['score']}\n";
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "    ❌ Nicht in Kandidaten gefunden\n";
    }
    echo "\n";
}

echo "=== Test abgeschlossen ===\n";
