<?php
/**
 * TOM3 - Migration 039: Vereinheitlichung document_audit_trail
 * 
 * Führt die Migration 039 aus, um document_audit_trail an die Standard-Struktur anzupassen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrationFile = __DIR__ . '/../database/migrations/039_unify_document_audit_trail_mysql.sql';

echo "=== TOM3 Migration 039: Vereinheitlichung document_audit_trail ===\n\n";

if (!file_exists($migrationFile)) {
    echo "❌ Fehler: Migrationsdatei nicht gefunden: $migrationFile\n";
    exit(1);
}

$autoConfirm = in_array('--yes', $argv) || in_array('-y', $argv);

if (!$autoConfirm) {
    echo "Diese Migration wird:\n";
    echo "  1. Backup der document_audit_trail Tabelle erstellen\n";
    echo "  2. Alte Tabelle löschen\n";
    echo "  3. Neue vereinheitlichte Struktur erstellen\n";
    echo "  4. Daten migrieren\n\n";
    echo "⚠️  WICHTIG: Stelle sicher, dass du ein Backup der Datenbank hast!\n\n";
    echo "Möchtest du fortfahren? (j/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if ($line === false || (trim(strtolower($line)) !== 'j' && trim(strtolower($line)) !== 'y')) {
        echo "Abgebrochen.\n";
        exit(0);
    }
}

echo "\n--- Führe Migration 039 aus ---\n";

try {
    $sql = file_get_contents($migrationFile);
    
    // Teile SQL in einzelne Statements (getrennt durch ;)
    // Beachte: CREATE TABLE ... AS SELECT muss als ein Statement behandelt werden
    $statements = [];
    $currentStatement = '';
    $inCreateTableAs = false;
    
    $lines = explode("\n", $sql);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Überspringe Kommentare und leere Zeilen
        if (empty($trimmed) || strpos($trimmed, '--') === 0) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Prüfe auf CREATE TABLE ... AS SELECT
        if (preg_match('/CREATE\s+TABLE.*AS\s+SELECT/i', $currentStatement)) {
            $inCreateTableAs = true;
        }
        
        // Statement beendet (nur wenn nicht in CREATE TABLE AS)
        if (!$inCreateTableAs && substr(rtrim($trimmed), -1) === ';') {
            $stmt = trim($currentStatement);
            if (!empty($stmt) && strlen($stmt) > 5) {
                $statements[] = $stmt;
            }
            $currentStatement = '';
        } elseif ($inCreateTableAs && substr(rtrim($trimmed), -1) === ';') {
            // CREATE TABLE AS SELECT beendet
            $stmt = trim($currentStatement);
            if (!empty($stmt) && strlen($stmt) > 5) {
                $statements[] = $stmt;
            }
            $currentStatement = '';
            $inCreateTableAs = false;
        }
    }
    
    // Füge letztes Statement hinzu, falls vorhanden
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }
    
    $db->beginTransaction();
    $executed = 0;
    
    foreach ($statements as $index => $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            echo "  Führe Statement " . ($index + 1) . " von " . count($statements) . " aus...\n";
            $db->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            // Ignoriere "already exists" Fehler bei Backup-Tabelle
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "  ⚠️  Warnung: " . $e->getMessage() . "\n";
                continue;
            }
            
            // Bei anderen Fehlern: Rollback
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
    
    if ($db->inTransaction()) {
        $db->commit();
    }
    
    echo "\n✅ Migration 039 erfolgreich ausgeführt ($executed Statements)\n";
    echo "\n📝 Hinweis: Die Backup-Tabelle 'document_audit_trail_backup' wurde erstellt.\n";
    echo "   Du kannst sie nach erfolgreicher Verifikation manuell löschen:\n";
    echo "   DROP TABLE IF EXISTS document_audit_trail_backup;\n\n";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n❌ Fehler bei Migration 039: " . $e->getMessage() . "\n";
    echo "   Rollback durchgeführt.\n";
    exit(1);
}


