<?php
/**
 * TOM3 - Run Migration 055
 * Setzt priority_stars DEFAULT auf 0
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Migration 055: Setze priority_stars DEFAULT auf 0\n";
    echo "================================================\n\n";
    
    // 1. Ändere DEFAULT-Wert auf 0
    echo "1. Ändere DEFAULT-Wert von priority_stars auf 0...\n";
    $db->exec("
        ALTER TABLE case_item 
        MODIFY COLUMN priority_stars INT DEFAULT 0 
        COMMENT 'Priorität 0-5 Sterne (0 = keine Priorität)'
    ");
    echo "   ✓ DEFAULT-Wert erfolgreich geändert\n\n";
    
    // 2. Setze priority_stars auf 0 für alle bestehenden NEW Leads
    echo "2. Setze priority_stars auf 0 für bestehende NEW Leads...\n";
    $stmt = $db->prepare("
        UPDATE case_item 
        SET priority_stars = 0 
        WHERE (priority_stars IS NULL OR priority_stars = 0) 
          AND stage = 'NEW' 
          AND engine = 'inside_sales'
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "   ✓ {$affected} Leads aktualisiert\n\n";
    
    // 3. Verifiziere
    echo "3. Verifiziere Änderungen...\n";
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN priority_stars = 0 THEN 1 END) as zero_stars,
            COUNT(CASE WHEN priority_stars IS NULL THEN 1 END) as null_stars,
            COUNT(CASE WHEN priority_stars > 0 THEN 1 END) as has_stars
        FROM case_item 
        WHERE engine = 'inside_sales' AND stage = 'NEW'
    ");
    $stats = $stmt->fetch();
    
    echo "   Statistik für NEW Leads (inside_sales):\n";
    echo "   - Gesamt: {$stats['total']}\n";
    echo "   - Mit 0 Sternen: {$stats['zero_stars']}\n";
    echo "   - NULL: {$stats['null_stars']}\n";
    echo "   - Mit Priorität (>0): {$stats['has_stars']}\n\n";
    
    echo "✅ Migration 055 erfolgreich abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "   Datei: " . $e->getFile() . "\n";
    echo "   Zeile: " . $e->getLine() . "\n";
    exit(1);
}


