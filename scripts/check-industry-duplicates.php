<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$stmt = $db->query('SELECT industry_uuid, name, code, parent_industry_uuid FROM industry WHERE parent_industry_uuid IS NULL ORDER BY name');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Anzahl Hauptbranchen: " . count($rows) . "\n\n";

$names = [];
foreach ($rows as $row) {
    $name = $row['name'];
    if (!isset($names[$name])) {
        $names[$name] = [];
    }
    $names[$name][] = $row['industry_uuid'];
}

echo "Eindeutige Namen: " . count($names) . "\n";
echo "Duplikate:\n";

foreach ($names as $name => $uuids) {
    if (count($uuids) > 1) {
        echo "- $name (" . count($uuids) . "x): " . implode(', ', $uuids) . "\n";
    }
}
