<?php
/**
 * Prüft Branchen in der DB: Welche haben Codes, welche nicht?
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Hauptbranchen (parent_industry_uuid IS NULL) ===\n\n";

$stmt = $db->query("
    SELECT industry_uuid, name, code, parent_industry_uuid, created_at
    FROM industry 
    WHERE parent_industry_uuid IS NULL 
    ORDER BY name
");

$industries = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo sprintf("%-60s | %-10s | %-15s\n", "Name", "Code", "Erstellt");
echo str_repeat("-", 100) . "\n";

$withCode = [];
$withoutCode = [];

foreach ($industries as $industry) {
    $code = $industry['code'] ?? 'NULL';
    $created = substr($industry['created_at'], 0, 10);
    
    echo sprintf("%-60s | %-10s | %-15s\n", 
        substr($industry['name'], 0, 60), 
        $code, 
        $created
    );
    
    if ($code === 'NULL') {
        $withoutCode[] = $industry;
    } else {
        $withCode[] = $industry;
    }
}

echo "\n\n=== Zusammenfassung ===\n";
echo "Branchen MIT Code (WZ 2008): " . count($withCode) . "\n";
echo "Branchen OHNE Code (manuell hinzugefügt): " . count($withoutCode) . "\n";

if (!empty($withoutCode)) {
    echo "\n=== Branchen OHNE Code ===\n";
    foreach ($withoutCode as $industry) {
        echo "  - " . $industry['name'] . "\n";
    }
}
