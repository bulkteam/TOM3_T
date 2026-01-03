<?php
/**
 * Beispiel: Erstellt "Farbenhersteller" als Level 3 unter C20
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

$db = DatabaseConnection::getInstance();

echo "=== Beispiel: Erstelle Level 3 'Farbenhersteller' unter C20 ===\n\n";

// Hole C20 UUID
$stmt = $db->prepare("SELECT industry_uuid, name FROM industry WHERE code = 'C20' LIMIT 1");
$stmt->execute();
$c20 = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$c20) {
    echo "❌ C20 nicht gefunden!\n";
    exit(1);
}

echo "Level 2 Parent: {$c20['name']} [{$c20['industry_uuid']}]\n\n";

// Prüfe, ob bereits existiert
$stmt = $db->prepare("
    SELECT industry_uuid, name, name_short
    FROM industry
    WHERE parent_industry_uuid = ?
      AND (name LIKE '%Farben%' OR name_short LIKE '%Farben%')
");
$stmt->execute([$c20['industry_uuid']]);
$existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($existing)) {
    echo "⚠️  Bereits vorhandene 'Farbenhersteller' Einträge:\n";
    foreach ($existing as $ex) {
        echo "  - {$ex['name']} [{$ex['industry_uuid']}]\n";
    }
    echo "\n";
} else {
    echo "✅ Keine 'Farbenhersteller' Einträge gefunden\n";
    echo "   Kann erstellt werden\n\n";
}

echo "=== Erstellung (Dry-Run) ===\n";
echo "SQL:\n";
echo "INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid)\n";
echo "VALUES (UUID(), 'Farbenhersteller', 'Farbenhersteller', NULL, '{$c20['industry_uuid']}');\n\n";

echo "Oder über API:\n";
echo "POST /api/industries\n";
echo "{\n";
echo "  \"name\": \"Farbenhersteller\",\n";
echo "  \"name_short\": \"Farbenhersteller\",\n";
echo "  \"parent_industry_uuid\": \"{$c20['industry_uuid']}\"\n";
echo "}\n\n";

echo "=== Beispiel abgeschlossen ===\n";
