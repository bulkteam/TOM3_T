<?php
/**
 * TOM3 - Run Migration 045: CRM Validation Rules
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Running migration 045: CRM Validation Rules...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/045_crm_validation_rules_mysql.sql');
    
    $db->exec($sql);
    
    echo "✅ Migration 045 completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
