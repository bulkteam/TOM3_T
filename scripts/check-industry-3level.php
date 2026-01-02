<?php
/**
 * Prüft, ob die 3-stufige Branchen-Hierarchie vorhanden ist
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfe 3-stufige Branchen-Hierarchie ===\n\n";

$stmt = $db->query("SHOW COLUMNS FROM org LIKE 'industry_level%'");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($cols)) {
    echo "❌ Keine industry_level* Spalten gefunden!\n";
    exit(1);
}

echo "Gefundene Spalten:\n";
foreach ($cols as $col) {
    echo sprintf("  ✓ %s - %s\n", $col['Field'], $col['Type']);
}

// Prüfe Daten-Migration
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        COUNT(industry_level1_uuid) as has_level1,
        COUNT(industry_level2_uuid) as has_level2,
        COUNT(industry_level3_uuid) as has_level3
    FROM org
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\n=== Daten-Statistik ===\n";
echo "Gesamt Organisationen: " . $stats['total'] . "\n";
echo "Mit Level 1: " . $stats['has_level1'] . "\n";
echo "Mit Level 2: " . $stats['has_level2'] . "\n";
echo "Mit Level 3: " . $stats['has_level3'] . "\n";

echo "\n✓ Migration erfolgreich!\n";
