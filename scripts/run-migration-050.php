<?php
/**
 * TOM3 - Run Migration 050
 * Erstellt industry_alias Tabelle für Alias-Learning
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "=== Migration 050: industry_alias Tabelle ===\n";
    
    // Prüfe, ob Tabelle bereits existiert
    $checkStmt = $db->query("
        SELECT COUNT(*) as cnt
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'industry_alias'
    ");
    $exists = $checkStmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    
    if ($exists) {
        echo "✅ Tabelle 'industry_alias' existiert bereits.\n";
        exit(0);
    }
    
    // Lade Migration
    $migrationFile = __DIR__ . '/../database/migrations/050_industry_alias_mysql.sql';
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
        
        echo "✅ Migration 050 erfolgreich ausgeführt.\n";
        echo "   - Tabelle 'industry_alias' erstellt\n";
        echo "   - Indizes erstellt\n";
        echo "   - Foreign Key erstellt\n";
        
    } catch (PDOException $e) {
        throw $e;
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "   Datei: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
