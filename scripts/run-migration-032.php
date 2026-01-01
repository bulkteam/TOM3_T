<?php
// scripts/run-migration-032.php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "Führe Migration 032 aus: E-Mail UNIQUE Constraint für Personen\n";
echo "==========================================================\n\n";

$db = DatabaseConnection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Prüfe zuerst auf bestehende Duplikate
echo "Prüfe auf bestehende E-Mail-Duplikate...\n";
$stmt = $db->query("
    SELECT email, COUNT(*) as count 
    FROM person 
    WHERE email IS NOT NULL AND email != '' 
    GROUP BY email 
    HAVING count > 1
");
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($duplicates)) {
    echo "⚠️  WARNUNG: Es wurden E-Mail-Duplikate gefunden:\n";
    foreach ($duplicates as $dup) {
        echo "  - E-Mail: {$dup['email']} (Anzahl: {$dup['count']})\n";
    }
    echo "\nBitte bereinigen Sie die Duplikate, bevor Sie fortfahren.\n";
    echo "Möchten Sie trotzdem fortfahren? (j/n): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($line) !== 'j' && strtolower($line) !== 'ja' && strtolower($line) !== 'y' && strtolower($line) !== 'yes') {
        echo "Migration abgebrochen.\n";
        exit(1);
    }
}

$sqlFile = __DIR__ . '/../database/migrations/032_enable_person_email_unique_mysql.sql';
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
                // Ignoriere "Duplicate key name" Warnungen (Constraint existiert bereits)
                if (strpos($e->getMessage(), 'Duplicate key name') !== false || 
                    strpos($e->getMessage(), 'already exists') !== false) {
                    echo "Warnung: " . $e->getMessage() . " (wird übersprungen)\n";
                } else {
                    throw $e; // Re-throw other exceptions
                }
            }
        }
    }
    $db->commit();
    echo "✓ Migration erfolgreich ausgeführt\n";
    echo "  - {$executedStatements} SQL-Statements ausgeführt\n";
    echo "  - UNIQUE Constraint auf person.email aktiviert\n\n";
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
