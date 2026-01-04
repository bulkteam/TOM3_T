<?php
/**
 * TOM3 - Run Migration 042: CRM Import Staging
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Running migration 042: CRM Import Staging...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/042_crm_import_staging_mysql.sql');
    
    $db->exec($sql);
    
    echo "✅ Migration 042 completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

