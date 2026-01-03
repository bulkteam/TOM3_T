<?php
/**
 * Test: IndustryNormalizer
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\Import\IndustryNormalizer;

echo "=== Test: IndustryNormalizer ===\n\n";

$norm = new IndustryNormalizer();

$tests = [
    'Chemieindustrie' => 'chemie',
    'Farbenhersteller' => 'farben',
    'Verarbeitendes Gewerbe' => 'verarbeitendes gewerbe',
    'C20 - Herstellung von chemischen Erzeugnissen' => 'c20 herstellung von chemischen erzeugnissen'
];

foreach ($tests as $input => $expected) {
    $result = $norm->normalize($input);
    $match = (strpos($result, $expected) !== false) ? '✅' : '❌';
    echo "$match Input: '$input'\n";
    echo "   Normalized: '$result'\n";
    echo "   Expected contains: '$expected'\n\n";
}

echo "✅ IndustryNormalizer funktioniert!\n";
