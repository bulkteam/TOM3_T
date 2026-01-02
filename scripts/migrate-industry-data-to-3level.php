<?php
/**
 * Migriert bestehende industry_main_uuid und industry_sub_uuid Daten
 * zu industry_level1_uuid und industry_level2_uuid
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Migriere Branchen-Daten zu 3-stufiger Hierarchie ===\n\n";

// Prüfe, ob Spalten existieren
$stmt = $db->query("SHOW COLUMNS FROM org LIKE 'industry_level1_uuid'");
if ($stmt->rowCount() == 0) {
    echo "❌ Spalten industry_level* existieren nicht! Bitte Migration 047 zuerst ausführen.\n";
    exit(1);
}

$db->beginTransaction();

try {
    // Migriere Level 1
    $stmt = $db->prepare("
        UPDATE org 
        SET industry_level1_uuid = industry_main_uuid
        WHERE industry_main_uuid IS NOT NULL 
        AND industry_level1_uuid IS NULL
    ");
    $stmt->execute();
    $level1Updated = $stmt->rowCount();
    echo "✓ Level 1 migriert: $level1Updated Organisationen\n";
    
    // Migriere Level 2
    $stmt = $db->prepare("
        UPDATE org 
        SET industry_level2_uuid = industry_sub_uuid
        WHERE industry_sub_uuid IS NOT NULL 
        AND industry_level2_uuid IS NULL
    ");
    $stmt->execute();
    $level2Updated = $stmt->rowCount();
    echo "✓ Level 2 migriert: $level2Updated Organisationen\n";
    
    $db->commit();
    
    echo "\n=== Erfolgreich abgeschlossen ===\n";
    
    // Statistik
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(industry_level1_uuid) as has_level1,
            COUNT(industry_level2_uuid) as has_level2,
            COUNT(industry_level3_uuid) as has_level3
        FROM org
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\n=== Finale Statistik ===\n";
    echo "Gesamt Organisationen: " . $stats['total'] . "\n";
    echo "Mit Level 1: " . $stats['has_level1'] . "\n";
    echo "Mit Level 2: " . $stats['has_level2'] . "\n";
    echo "Mit Level 3: " . $stats['has_level3'] . "\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Fehler: " . $e->getMessage() . "\n";
    echo "Rollback durchgeführt.\n";
    exit(1);
}
