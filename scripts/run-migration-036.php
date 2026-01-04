<?php
/**
 * Migration 036: Activity-Log-ID zu Audit-Trail-Tabellen hinzufügen
 * 
 * Führt die Migration 036_add_activity_log_id_to_audit_trails_mysql.sql aus
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Führe Migration 036 aus: Activity-Log-ID zu Audit-Trail-Tabellen hinzufügen...\n";
    
    $migrationFile = __DIR__ . '/../database/migrations/036_add_activity_log_id_to_audit_trails_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new \RuntimeException("Migration-Datei nicht gefunden: $migrationFile");
    }
    
    $statement = file_get_contents($migrationFile);
    
    // Entferne Kommentare, die Probleme verursachen könnten
    $statement = preg_replace('/--.*$/m', '', $statement);
    
    // Führe die Migration aus
    $db->exec($statement);
    
    echo "✓ Migration 036 erfolgreich ausgeführt.\n";
    echo "  - Spalte 'activity_log_id' zu 'org_audit_trail' hinzugefügt.\n";
    echo "  - Spalte 'activity_log_id' zu 'person_audit_trail' hinzugefügt.\n";
    echo "  - Indizes wurden erstellt.\n";
    
} catch (\Exception $e) {
    echo "✗ Fehler bei Migration 036: " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


