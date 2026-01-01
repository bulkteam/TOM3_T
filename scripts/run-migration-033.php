<?php
// scripts/run-migration-033.php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "Führe Migration 033 aus: Duplikaten-Prüfung Ergebnisse-Tabelle\n";
echo "============================================================\n\n";

$db = DatabaseConnection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sqlFile = __DIR__ . '/../database/migrations/033_create_duplicate_check_results_mysql.sql';
$sql = file_get_contents($sqlFile);

if ($sql === false) {
    die("Fehler: SQL-Datei nicht gefunden oder lesbar: " . $sqlFile . "\n");
}

try {
    $db->beginTransaction();
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $executedStatements = 0;

    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $db->exec($statement);
                $executedStatements++;
            } catch (PDOException $e) {
                // Ignoriere "table already exists" Warnungen
                if (strpos($e->getMessage(), 'table or view already exists') !== false || 
                    strpos($e->getMessage(), 'already exists') !== false) {
                    echo "Warnung: " . $e->getMessage() . " (wird übersprungen)\n";
                } else {
                    throw $e;
                }
            }
        }
    }
    $db->commit();
    echo "✓ Migration erfolgreich ausgeführt\n";
    echo "  - {$executedStatements} SQL-Statements ausgeführt\n\n";
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "✗ Fehler bei Migration: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "✗ Ein unerwarteter Fehler ist aufgetreten: " . $e->getMessage() . "\n";
    exit(1);
}
