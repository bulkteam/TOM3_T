<?php
/**
 * TOM3 - Run Migration 041: CRM Import Batch
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Running migration 041: CRM Import Batch...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/041_crm_import_batch_mysql.sql');
    
    // Führe Migration aus
    $db->exec($sql);
    
    echo "✅ Migration 041 completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
