<?php
/**
 * Prüft, welche Import-Tabellen bereits existieren
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$tables = [
    'org_import_batch',
    'org_import_staging',
    'import_duplicate_candidates',
    'person_import_staging',
    'employment_import_staging',
    'validation_rule_set'
];

echo "Prüfe Import-Tabellen...\n\n";

foreach ($tables as $table) {
    $stmt = $db->query("SHOW TABLES LIKE '$table'");
    $exists = $stmt->rowCount() > 0;
    echo sprintf("%-35s: %s\n", $table, $exists ? '✅ EXISTS' : '❌ MISSING');
}
