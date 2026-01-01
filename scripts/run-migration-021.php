<?php
/**
 * TOM3 - Migration 021 ausführen
 * 
 * Erstellt neue Tabellen:
 * - project_party (Projektparteien)
 * - project_person (Projektpersonen)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrationFile = __DIR__ . '/../database/migrations/021_project_party_and_person_mysql.sql';

if (!file_exists($migrationFile)) {
    die("Migration-Datei nicht gefunden: $migrationFile\n");
}

echo "Führe Migration 021 aus: Project Party und Project Person\n";
echo "===========================================================\n\n";

try {
    // Lese SQL-Datei
    $sql = file_get_contents($migrationFile);
    
    // Teile in einzelne Statements (getrennt durch ;)
    // Entferne Kommentare und leere Zeilen
    // Wichtig: Behandle mehrzeilige Kommentare und entferne sie
    $sql = preg_replace('/--.*$/m', '', $sql); // Entferne einzeilige Kommentare
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Entferne mehrzeilige Kommentare
    
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            $stmt = trim($stmt);
            return !empty($stmt) && strlen($stmt) > 5; // Mindestens 5 Zeichen (z.B. "USE x")
        }
    );
    
    $db->beginTransaction();
    $transactionActive = true;
    
    $executed = 0;
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        try {
            $db->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            // Ignoriere "Table already exists" Fehler
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "Warnung: Tabelle existiert bereits (wird übersprungen)\n";
                continue;
            }
            // Ignoriere "Duplicate key" Fehler (Constraint existiert bereits)
            if (strpos($e->getMessage(), 'Duplicate key') !== false || 
                strpos($e->getMessage(), 'errno: 121') !== false) {
                echo "Warnung: Constraint existiert bereits (wird übersprungen)\n";
                continue;
            }
            $transactionActive = false;
            throw $e;
        }
    }
    
    if ($transactionActive && $db->inTransaction()) {
        $db->commit();
    }
    
    echo "✓ Migration erfolgreich ausgeführt\n";
    echo "  - $executed SQL-Statements ausgeführt\n";
    echo "\n";
    echo "Neue Tabellen:\n";
    echo "  - project_party\n";
    echo "  - project_person\n";
    echo "\n";
    echo "Hinweis: Die alten Tabellen (project_partner, project_stakeholder) bleiben erhalten.\n";
    echo "Führe Migration 022 aus, um bestehende Daten zu migrieren.\n";
    
} catch (Exception $e) {
    // Rollback nur wenn Transaktion aktiv ist
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (PDOException $rollbackError) {
        // Ignoriere Rollback-Fehler
    }
    echo "✗ Fehler bei Migration:\n";
    echo "  " . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo "  Ursache: " . $e->getPrevious()->getMessage() . "\n";
    }
    exit(1);
}
