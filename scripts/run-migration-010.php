<?php
/**
 * TOM3 - Run Migration 010
 * Fügt wichtige Unterklassen für die Branchen hinzu (WZ 2008)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Migration 010 - Industry Subclasses (WZ 2008) ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    $migrationFile = __DIR__ . '/../database/migrations/010_industry_subclasses_wz2008_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Entferne Kommentare (-- und /* */)
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Teile SQL in einzelne Statements (getrennt durch ;)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strlen(trim($stmt)) > 10; // Mindestens 10 Zeichen
        }
    );
    
    $count = 0;
    $errors = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) {
            continue;
        }
        
        try {
            $db->exec($statement);
            $count++;
        } catch (PDOException $e) {
            // Ignoriere Duplikat-Fehler (falls bereits vorhanden)
            if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                strpos($e->getMessage(), '1062') !== false) {
                // Duplikat - ignorieren
                continue;
            }
            $errors++;
            if ($errors <= 5) { // Zeige nur die ersten 5 Fehler
                echo "WARNING: " . substr($statement, 0, 80) . "...\n";
                echo "  Error: " . $e->getMessage() . "\n\n";
            }
        }
    }
    
    echo "=== Migration 010 completed: $count Unterklassen hinzugefügt ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

