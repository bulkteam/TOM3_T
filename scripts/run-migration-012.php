<?php
/**
 * TOM3 - Run Migration 012
 * Vereinfacht USt-ID-Verwaltung: address_uuid wird optional
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Migration 012 - Simplify VAT Registration ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    $migrationFile = __DIR__ . '/../database/migrations/012_org_vat_registration_simplify_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Entferne Kommentare
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Teile SQL in einzelne Statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strlen(trim($stmt)) > 10;
        }
    );
    
    $count = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) {
            continue;
        }
        
        try {
            echo "Executing: " . substr($statement, 0, 60) . "...\n";
            $db->exec($statement);
            $count++;
            echo "✓ Success\n\n";
        } catch (PDOException $e) {
            // Ignoriere Fehler wenn Spalte bereits existiert
            if (strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠ Already exists, skipping\n\n";
                continue;
            }
            throw $e;
        }
    }
    
    echo "=== Migration 012 completed: $count statements executed ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



