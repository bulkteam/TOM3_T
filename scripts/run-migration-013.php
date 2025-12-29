<?php
/**
 * TOM3 - Run Migration 013: Audit Trail
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Migration 013 - Audit Trail ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    $sqlFile = __DIR__ . '/../database/migrations/013_org_audit_trail_mysql.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Teile SQL in einzelne Statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        echo "Executing: " . substr($statement, 0, 50) . "...\n";
        $db->exec($statement);
    }
    
    echo "\n✓ Migration 013 completed successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


