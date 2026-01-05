<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "Prüfe priority_stars Werte in der Datenbank:\n";
echo "==========================================\n\n";

// Prüfe NEW Leads
$stmt = $db->query("
    SELECT case_uuid, stage, priority_stars, 
           CASE WHEN priority_stars IS NULL THEN 'NULL' ELSE priority_stars END as stars_display
    FROM case_item 
    WHERE engine = 'inside_sales' AND stage = 'NEW' 
    LIMIT 10
");
$results = $stmt->fetchAll();

echo "NEW Leads (inside_sales):\n";
foreach ($results as $row) {
    echo "  UUID: " . substr($row['case_uuid'], 0, 8) . "... | stage: {$row['stage']} | priority_stars: {$row['stars_display']}\n";
}

echo "\n";

// Prüfe DEFAULT-Wert
$stmt = $db->query("
    SELECT COLUMN_DEFAULT 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'case_item' 
    AND COLUMN_NAME = 'priority_stars'
");
$default = $stmt->fetch();
echo "DEFAULT-Wert in Datenbank: " . ($default['COLUMN_DEFAULT'] ?? 'NULL') . "\n";

echo "\n";

// Prüfe ob es Leads mit priority_stars > 0 gibt
$stmt = $db->query("
    SELECT COUNT(*) as count 
    FROM case_item 
    WHERE engine = 'inside_sales' 
    AND stage = 'NEW' 
    AND (priority_stars IS NULL OR priority_stars = 0)
");
$zeroOrNull = $stmt->fetch();

$stmt = $db->query("
    SELECT COUNT(*) as count 
    FROM case_item 
    WHERE engine = 'inside_sales' 
    AND stage = 'NEW' 
    AND priority_stars > 0
");
$greaterThanZero = $stmt->fetch();

echo "Statistik:\n";
echo "  NEW Leads mit 0 oder NULL: {$zeroOrNull['count']}\n";
echo "  NEW Leads mit > 0: {$greaterThanZero['count']}\n";

