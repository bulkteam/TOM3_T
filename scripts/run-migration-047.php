<?php
/**
 * Führt Migration 047 aus: Industry 3-Level Hierarchy
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrationFile = __DIR__ . '/../database/migrations/047_industry_3level_hierarchy_mysql.sql';

if (!file_exists($migrationFile)) {
    echo "❌ Migrationsdatei nicht gefunden: $migrationFile\n";
    exit(1);
}

echo "=== Führe Migration 047 aus: Industry 3-Level Hierarchy ===\n\n";

$sql = file_get_contents($migrationFile);

// Entferne Kommentare und teile in Statements
$sql = preg_replace('/--.*$/m', '', $sql); // Entferne -- Kommentare
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Entferne /* */ Kommentare

// Teile SQL in einzelne Statements
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) {
        $stmt = trim($stmt);
        return !empty($stmt) && strlen($stmt) > 10; // Mindestens 10 Zeichen
    }
);

$db->beginTransaction();

try {
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        echo "Führe aus: " . substr($statement, 0, 80) . "...\n";
        $db->exec($statement);
    }
    
    $db->commit();
    echo "\n✓ Migration erfolgreich abgeschlossen!\n";
    
} catch (Exception $e) {
    try {
        $db->rollBack();
        echo "\n❌ Fehler: " . $e->getMessage() . "\n";
        echo "Rollback durchgeführt.\n";
    } catch (Exception $rollbackError) {
        echo "\n❌ Fehler: " . $e->getMessage() . "\n";
        echo "⚠️  Rollback fehlgeschlagen: " . $rollbackError->getMessage() . "\n";
    }
    exit(1);
}
