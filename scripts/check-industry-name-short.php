<?php
/**
 * Prüft Industry name_short Implementierung
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfe Industry name_short ===\n\n";

// 1. Zeige Beispiele für Level 2
echo "1. Level 2 Industries mit name_short:\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT i.industry_uuid, i.name, i.name_short, i.code, i1.name as parent_name
    FROM industry i
    LEFT JOIN industry i1 ON i.parent_industry_uuid = i1.industry_uuid
    WHERE i.code IN ('C20', 'C21', 'C28', 'C10', 'H49')
    ORDER BY i.code
");
$examples = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($examples as $ex) {
    $display = $ex['name_short'] ?? $ex['name'];
    echo "Code: {$ex['code']}\n";
    echo "  Kurzname: " . ($ex['name_short'] ?? 'N/A') . "\n";
    echo "  Langname: {$ex['name']}\n";
    echo "  Parent: " . ($ex['parent_name'] ?? 'N/A') . "\n";
    echo "  Display (name_short ?? name): $display\n";
    echo "\n";
}

// 2. Zeige Level 1 Beispiele
echo "\n2. Level 1 Industries:\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT industry_uuid, name, name_short, code
    FROM industry
    WHERE parent_industry_uuid IS NULL
    ORDER BY code
    LIMIT 5
");
$level1 = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($level1 as $l1) {
    $display = $l1['name_short'] ?? $l1['name'];
    echo "Code: {$l1['code']}\n";
    echo "  Kurzname: " . ($l1['name_short'] ?? 'N/A') . "\n";
    echo "  Langname: {$l1['name']}\n";
    echo "  Display: $display\n";
    echo "\n";
}

// 3. Statistik
echo "\n3. Statistik:\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("SELECT COUNT(*) as count FROM industry WHERE name_short IS NOT NULL");
$withShort = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Industries mit name_short: $withShort\n";

$stmt = $db->query("SELECT COUNT(*) as count FROM industry WHERE name_short IS NULL");
$withoutShort = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Industries ohne name_short: $withoutShort\n";

$stmt = $db->query("SELECT COUNT(*) as count FROM industry_code_shortname");
$mappings = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Mappings in industry_code_shortname: $mappings\n";

// 4. Prüfe Duplikate
echo "\n4. Duplikate-Prüfung:\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT parent_industry_uuid, code, COUNT(*) as count
    FROM industry
    WHERE code IS NOT NULL
    GROUP BY parent_industry_uuid, code
    HAVING COUNT(*) > 1
");
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "✅ Keine Duplikate\n";
} else {
    echo "⚠️  Duplikate gefunden:\n";
    foreach ($duplicates as $dup) {
        echo "  Code {$dup['code']}: {$dup['count']}x\n";
    }
}

echo "\n=== Prüfung abgeschlossen ===\n";
