<?php
/**
 * TOM3 - Migration 023 ausführen
 * 
 * Löscht alte Tabellen:
 * - project_partner
 * - project_stakeholder
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrationFile = __DIR__ . '/../database/migrations/023_drop_old_project_tables_mysql.sql';

if (!file_exists($migrationFile)) {
    die("Migration-Datei nicht gefunden: $migrationFile\n");
}

echo "Führe Migration 023 aus: Alte Projekt-Tabellen löschen\n";
echo "======================================================\n\n";

// Prüfe, ob neue Tabellen existieren
$checkNewTables = $db->query("
    SELECT COUNT(*) as count
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('project_party', 'project_person')
")->fetch();

if ($checkNewTables['count'] < 2) {
    die("✗ Fehler: Migration 021 muss zuerst ausgeführt werden!\n");
}

// Zeige Status
echo "Aktuelle Tabellen:\n";
$oldTables = $db->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('project_partner', 'project_stakeholder', 'project_party', 'project_person')
    ORDER BY table_name
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($oldTables as $table) {
    echo "  - $table\n";
}
echo "\n";

// Frage Bestätigung (außer wenn --yes Flag gesetzt ist)
$autoConfirm = in_array('--yes', $argv) || in_array('-y', $argv);

if (!$autoConfirm) {
    echo "Warnung: Diese Migration löscht die Tabellen project_partner und project_stakeholder.\n";
    echo "Möchtest du fortfahren? (j/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);

    if (trim(strtolower($line)) !== 'j' && trim(strtolower($line)) !== 'y' && trim(strtolower($line)) !== 'ja') {
        echo "Migration abgebrochen.\n";
        exit(0);
    }
} else {
    echo "Automatische Bestätigung (--yes Flag gesetzt)\n";
}

try {
    // Lese SQL-Datei
    $sql = file_get_contents($migrationFile);
    
    // Entferne Kommentare
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Teile in einzelne Statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            $stmt = trim($stmt);
            return !empty($stmt) && strlen($stmt) > 5;
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
            // Ignoriere "Table doesn't exist" Fehler
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), 'Unknown table') !== false) {
                echo "Hinweis: Tabelle existiert nicht (wird übersprungen)\n";
                continue;
            }
            $transactionActive = false;
            throw $e;
        }
    }
    
    if ($transactionActive && $db->inTransaction()) {
        $db->commit();
    }
    
    echo "\n✓ Migration erfolgreich ausgeführt\n";
    echo "  - $executed SQL-Statements ausgeführt\n";
    echo "\n";
    
    // Zeige Status nach Migration
    echo "Tabellen nach Migration:\n";
    $newTables = $db->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ('project_partner', 'project_stakeholder', 'project_party', 'project_person')
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($newTables)) {
        echo "  (keine Projekt-Tabellen gefunden)\n";
    } else {
        foreach ($newTables as $table) {
            echo "  - $table\n";
        }
    }
    
    echo "\n";
    echo "Die alten Tabellen wurden gelöscht.\n";
    echo "Verwende jetzt die neuen Tabellen: project_party und project_person\n";
    
} catch (Exception $e) {
    // Rollback nur wenn Transaktion aktiv ist
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (PDOException $rollbackError) {
        // Ignoriere Rollback-Fehler
    }
    echo "\n✗ Fehler bei Migration:\n";
    echo "  " . $e->getMessage() . "\n";
    if ($e->getPrevious()) {
        echo "  Ursache: " . $e->getPrevious()->getMessage() . "\n";
    }
    exit(1);
}


