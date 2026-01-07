<?php
/**
 * Führt Migration 047 aus (Monitoring Metrics)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$sql = file_get_contents(__DIR__ . '/../database/migrations/047_monitoring_metrics_mysql.sql');

try {
    $db->exec($sql);
    echo "Migration 047 erfolgreich ausgeführt\n";
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
