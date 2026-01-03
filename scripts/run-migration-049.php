<?php
/**
 * TOM3 - Run Migration 049
 * Fügt optionale Felder zu org_import_staging hinzu (duplicate_status, duplicate_summary, commit_log)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "=== Migration 049: Optionale Felder ===\n";
    
    // Prüfe, welche Felder bereits existieren
    $checkStmt = $db->query("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'org_import_staging'
        AND COLUMN_NAME IN ('duplicate_status', 'duplicate_summary', 'commit_log')
    ");
    $existing = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($existing) === 3) {
        echo "✅ Alle Felder existieren bereits.\n";
        exit(0);
    }
    
    // Lade Migration
    $migrationFile = __DIR__ . '/../database/migrations/049_staging_optional_fields_mysql.sql';
    if (!file_exists($migrationFile)) {
        throw new RuntimeException("Migration-Datei nicht gefunden: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Entferne Kommentare und leere Zeilen
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*$/m', '', $sql);
    $sql = trim($sql);
    
    echo "Führe Migration aus...\n";
    
    try {
        // Teile SQL in einzelne Statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s)
        );
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $db->exec($statement);
            }
        }
        
        echo "✅ Migration 049 erfolgreich ausgeführt.\n";
        echo "   - duplicate_status hinzugefügt\n";
        echo "   - duplicate_summary hinzugefügt\n";
        echo "   - commit_log hinzugefügt\n";
        echo "   - Index erstellt\n";
        
    } catch (PDOException $e) {
        throw $e;
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "   Datei: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
