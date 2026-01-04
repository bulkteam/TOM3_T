<?php
/**
 * TOM3 - Run Migration 046: Extend Document Entity Type
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Running migration 046: Extend Document Entity Type...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/046_extend_document_entity_type_mysql.sql');
    
    $db->exec($sql);
    
    echo "✅ Migration 046 completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

