<?php
/**
 * TOM3 - Run Migration 009
 * Entfernt die redundante org_industry Tabelle
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Migration 009 - Remove org_industry Redundancy ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    $migrationFile = __DIR__ . '/../database/migrations/009_remove_org_industry_redundancy_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Teile SQL in einzelne Statements (getrennt durch ;)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        echo "Executing: " . substr($statement, 0, 60) . "...\n";
        $db->exec($statement);
        echo "✓ Success\n\n";
    }
    
    echo "=== Migration 009 completed successfully ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}





