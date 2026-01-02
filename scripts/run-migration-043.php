<?php
/**
 * TOM3 - Run Migration 043: CRM Import Duplicates
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Running migration 043: CRM Import Duplicates...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/043_crm_import_duplicates_mysql.sql');
    
    $db->exec($sql);
    
    echo "✅ Migration 043 completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
