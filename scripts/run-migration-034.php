<?php
// scripts/run-migration-034.php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "Führe Migration 034 aus: User Person Access Tabelle\n";
echo "====================================================\n\n";

$db = DatabaseConnection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sqlFile = __DIR__ . '/../database/migrations/034_create_user_person_access_mysql.sql';
$sql = file_get_contents($sqlFile);

if ($sql === false) {
    die("Fehler: SQL-Datei nicht gefunden oder lesbar: " . $sqlFile . "\n");
}

try {
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $executedStatements = 0;

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $db->exec($statement);
                $executedStatements++;
            } catch (PDOException $e) {
                // Ignoriere "table already exists" Warnungen für CREATE TABLE IF NOT EXISTS
                if (strpos($e->getMessage(), 'table or view already exists') !== false || 
                    strpos($e->getMessage(), 'already exists') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false) {
                    echo "Warnung: " . $e->getMessage() . " (wird übersprungen)\n";
                } else {
                    throw $e; // Re-throw other exceptions
                }
            }
        }
    }
    echo "✓ Migration erfolgreich ausgeführt\n";
    echo "  - {$executedStatements} SQL-Statements ausgeführt\n\n";
} catch (PDOException $e) {
    echo "✗ Fehler bei Migration: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "✗ Ein unerwarteter Fehler ist aufgetreten: " . $e->getMessage() . "\n";
    exit(1);
}
