<?php
/**
 * Migration 035: Activity Log Tabelle erstellen
 * 
 * Führt die Migration 035_create_activity_log_mysql.sql aus
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Führe Migration 035 aus: Activity Log Tabelle erstellen...\n";
    
    $migrationFile = __DIR__ . '/../database/migrations/035_create_activity_log_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new \RuntimeException("Migration-Datei nicht gefunden: $migrationFile");
    }
    
    $statement = file_get_contents($migrationFile);
    
    // Entferne Kommentare, die Probleme verursachen könnten
    $statement = preg_replace('/--.*$/m', '', $statement);
    
    // Führe die Migration aus
    // CREATE TABLE IF NOT EXISTS kann Probleme mit Transaktionen verursachen
    // Daher führen wir es direkt aus
    $db->exec($statement);
    
    echo "✓ Migration 035 erfolgreich ausgeführt.\n";
    echo "  - Tabelle 'activity_log' wurde erstellt.\n";
    echo "  - Indizes wurden erstellt.\n";
    
} catch (\Exception $e) {
    echo "✗ Fehler bei Migration 035: " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
