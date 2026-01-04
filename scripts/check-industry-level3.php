<?php
/**
 * Prüft Level 3 Industries (Unterbranchen)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfe Level 3 Industries ===\n\n";

// 1. Zeige alle Level 3 Industries
echo "1. Level 3 Industries (Unterbranchen):\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->query("
    SELECT 
        i3.industry_uuid,
        i3.name,
        i3.name_short,
        i3.code,
        i2.name as level2_name,
        i2.code as level2_code,
        i1.name as level1_name,
        i1.code as level1_code
    FROM industry i3
    INNER JOIN industry i2 ON i3.parent_industry_uuid = i2.industry_uuid
    INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
    WHERE i1.parent_industry_uuid IS NULL
    ORDER BY i1.code, i2.code, i3.name
");
$level3 = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($level3)) {
    echo "⚠️  Keine Level 3 Industries gefunden\n";
    echo "\nLevel 3 Industries werden normalerweise:\n";
    echo "  - Manuell im CRM erstellt (z.B. 'Farbenhersteller' unter C20)\n";
    echo "  - Oder automatisch beim Import erstellt (wenn CREATE_NEW gewählt)\n";
    echo "  - Format: code = NULL, parent_industry_uuid = Level 2 UUID, name = User-Label\n";
} else {
    echo "Gefundene Level 3 Industries: " . count($level3) . "\n\n";
    foreach ($level3 as $l3) {
        $displayName = $l3['name_short'] ?? $l3['name'];
        echo "  - {$displayName}\n";
        echo "    Code: " . ($l3['code'] ?? 'NULL') . "\n";
        echo "    Parent: {$l3['level2_name']} ({$l3['level2_code']})\n";
        echo "    Level 1: {$l3['level1_name']} ({$l3['level1_code']})\n";
        echo "    UUID: {$l3['industry_uuid']}\n";
        echo "\n";
    }
}

// 2. Prüfe, wie Level 3 erstellt wird
echo "\n2. Level 3 Erstellung:\n";
echo str_repeat("-", 80) . "\n";
echo "Level 3 Industries werden erstellt:\n";
echo "  a) Manuell im CRM (Admin-UI)\n";
echo "  b) Automatisch beim Import (wenn CREATE_NEW gewählt)\n";
echo "  c) Über API: POST /api/industries mit parent_industry_uuid\n";
echo "\n";

// 3. Beispiel: Wie würde "Farbenhersteller" unter C20 erstellt?
echo "3. Beispiel: 'Farbenhersteller' unter C20:\n";
echo str_repeat("-", 80) . "\n";

$stmt = $db->prepare("
    SELECT industry_uuid, name, name_short, code
    FROM industry
    WHERE code = 'C20'
    LIMIT 1
");
$stmt->execute();
$c20 = $stmt->fetch(PDO::FETCH_ASSOC);

if ($c20) {
    echo "Level 2 Parent (C20): {$c20['name']} [{$c20['industry_uuid']}]\n";
    echo "\n";
    echo "Um 'Farbenhersteller' als Level 3 zu erstellen:\n";
    echo "  INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid)\n";
    echo "  VALUES (UUID(), 'Farbenhersteller', 'Farbenhersteller', NULL, '{$c20['industry_uuid']}');\n";
    echo "\n";
    echo "Oder über API:\n";
    echo "  POST /api/industries\n";
    echo "  {\n";
    echo "    \"name\": \"Farbenhersteller\",\n";
    echo "    \"name_short\": \"Farbenhersteller\",\n";
    echo "    \"parent_industry_uuid\": \"{$c20['industry_uuid']}\"\n";
    echo "  }\n";
}

// 4. Prüfe, ob es bereits "Farbenhersteller" gibt
echo "\n4. Suche nach 'Farbenhersteller':\n";
echo str_repeat("-", 80) . "\n";
$stmt = $db->prepare("
    SELECT 
        i3.industry_uuid,
        i3.name,
        i3.name_short,
        i2.name as parent_name,
        i2.code as parent_code
    FROM industry i3
    LEFT JOIN industry i2 ON i3.parent_industry_uuid = i2.industry_uuid
    WHERE i3.name LIKE '%Farben%' OR i3.name_short LIKE '%Farben%'
");
$stmt->execute();
$farben = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($farben)) {
    echo "⚠️  Keine 'Farbenhersteller' Einträge gefunden\n";
    echo "   Diese müsste als Level 3 unter C20 erstellt werden\n";
} else {
    echo "Gefundene Einträge:\n";
    foreach ($farben as $f) {
        echo "  - {$f['name']} (Parent: {$f['parent_name']} [{$f['parent_code']}])\n";
    }
}

echo "\n=== Prüfung abgeschlossen ===\n";

