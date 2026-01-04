<?php
/**
 * TOM3 - Run Migration 048
 * Fügt industry_resolution Feld zu org_import_staging hinzu
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "=== Migration 048: industry_resolution Feld ===\n";
    
    // Prüfe, ob Feld bereits existiert
    $checkStmt = $db->query("
        SELECT COUNT(*) as cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'org_import_staging'
        AND COLUMN_NAME = 'industry_resolution'
    ");
    $exists = $checkStmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    
    if ($exists) {
        echo "✅ Feld 'industry_resolution' existiert bereits.\n";
        exit(0);
    }
    
    // Lade Migration
    $migrationFile = __DIR__ . '/../database/migrations/048_industry_resolution_staging_mysql.sql';
    if (!file_exists($migrationFile)) {
        throw new RuntimeException("Migration-Datei nicht gefunden: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Entferne Kommentare und leere Zeilen für saubere Ausführung
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*$/m', '', $sql);
    $sql = trim($sql);
    
    echo "Führe Migration aus...\n";
    
    try {
        $db->exec($sql);
        
        echo "✅ Migration 048 erfolgreich ausgeführt.\n";
        echo "   - Feld 'industry_resolution' wurde zu 'org_import_staging' hinzugefügt.\n";
        
    } catch (PDOException $e) {
        throw $e;
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "   Datei: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

