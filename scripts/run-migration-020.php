<?php
/**
 * TOM3 - Migration 020: Users Audit Fields
 * 
 * Fügt Audit-Felder zur users Tabelle hinzu:
 * - created_by_user_id
 * - disabled_at
 * - disabled_by_user_id
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    $migrationFile = __DIR__ . '/../database/migrations/020_users_audit_fields_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration-Datei nicht gefunden: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Führe Migration aus
    $db->exec($sql);
    
    echo "✓ Migration 020 erfolgreich ausgeführt\n";
    echo "  - created_by_user_id hinzugefügt\n";
    echo "  - disabled_at hinzugefügt\n";
    echo "  - disabled_by_user_id hinzugefügt\n";
    
} catch (Exception $e) {
    echo "✗ Fehler bei Migration 020: " . $e->getMessage() . "\n";
    exit(1);
}



