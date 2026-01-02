<?php
/**
 * TOM3 - Run Migration 044: CRM Import Persons
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Running migration 044: CRM Import Persons...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/044_crm_import_persons_mysql.sql');
    
    $db->exec($sql);
    
    echo "✅ Migration 044 completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
