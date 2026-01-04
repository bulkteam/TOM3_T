<?php
/**
 * TOM3 - Run All Import Migrations (041-045)
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "========================================\n";
echo "TOM3 - CRM Import Migrations\n";
echo "========================================\n\n";

$migrations = [
    '041' => 'CRM Import Batch',
    '042' => 'CRM Import Staging',
    '043' => 'CRM Import Duplicates',
    '044' => 'CRM Import Persons',
    '045' => 'CRM Validation Rules'
];

foreach ($migrations as $num => $name) {
    echo "Running migration $num: $name...\n";
    
    try {
        $script = __DIR__ . "/run-migration-{$num}.php";
        if (file_exists($script)) {
            require $script;
        } else {
            echo "⚠️  Script not found: $script\n";
            exit(1);
        }
    } catch (Exception $e) {
        echo "❌ Error in migration $num: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    echo "\n";
}

echo "========================================\n";
echo "✅ All migrations completed!\n";
echo "========================================\n";

