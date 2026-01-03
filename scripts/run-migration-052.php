<?php
/**
 * Migration 052: Import Batch Template-Felder
 * 
 * Fügt Felder für Template-Matching-Ergebnisse hinzu
 */

declare(strict_types=1);

// Autoloader einbinden
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "Migration 052: Import Batch Template-Felder\n";
echo "==========================================\n\n";

try {
    // Lade SQL-Datei
    $sqlFile = __DIR__ . '/../database/migrations/052_import_batch_template_fields_mysql.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Entferne Kommentare
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Teile SQL in einzelne Statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt);
        }
    );
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) {
            continue;
        }
        
        // Überspringe IF NOT EXISTS Checks
        if (preg_match('/^ALTER\s+TABLE.*ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i', $statement)) {
            $statement = preg_replace('/IF\s+NOT\s+EXISTS\s+/i', '', $statement);
        }
        if (preg_match('/^CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS/i', $statement)) {
            $statement = preg_replace('/IF\s+NOT\s+EXISTS\s+/i', '', $statement);
        }
        
        echo "Executing: " . substr($statement, 0, 60) . "...\n";
        
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            // Ignoriere "already exists" Fehler
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false) {
                echo "  (bereits vorhanden, übersprungen)\n";
                continue;
            }
            throw $e;
        }
    }
    
    echo "\n✅ Migration 052 erfolgreich abgeschlossen!\n";
    echo "\nHinzugefügt:\n";
    echo "  - detected_header_row (automatisch erkannte Header-Zeile)\n";
    echo "  - detected_template_uuid (UUID des erkannten Templates)\n";
    echo "  - detected_template_score (Fit-Score 0.00-1.00)\n";
    
} catch (Exception $e) {
    echo "\n❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
