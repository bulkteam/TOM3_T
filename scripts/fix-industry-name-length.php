<?php
/**
 * Erweitert die industry.name Spalte für längere Namen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Erweitere industry.name Spalte...\n";
    
    // Erweitere name Spalte auf VARCHAR(255)
    $db->exec("ALTER TABLE industry MODIFY COLUMN name VARCHAR(255) NOT NULL");
    
    echo "✓ Spalte erfolgreich erweitert\n";
    
} catch (Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
