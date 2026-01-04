<?php
/**
 * TOM3 - Migration 040: User Document Access Tabelle erstellen
 * 
 * Führt die Migration 040 aus, um die user_document_access Tabelle zu erstellen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrationFile = __DIR__ . '/../database/migrations/040_create_user_document_access_mysql.sql';

echo "=== TOM3 Migration 040: User Document Access Tabelle erstellen ===\n\n";

if (!file_exists($migrationFile)) {
    echo "❌ Fehler: Migrationsdatei nicht gefunden: $migrationFile\n";
    exit(1);
}

$autoConfirm = in_array('--yes', $argv) || in_array('-y', $argv);

if (!$autoConfirm) {
    echo "Diese Migration wird:\n";
    echo "  1. Die Tabelle 'user_document_access' erstellen\n";
    echo "  2. Indizes für optimale Performance anlegen\n";
    echo "  3. Foreign Key Constraint zu 'documents' Tabelle hinzufügen\n\n";
    echo "Möchtest du fortfahren? (j/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if ($line === false || (trim(strtolower($line)) !== 'j' && trim(strtolower($line)) !== 'y')) {
        echo "Abgebrochen.\n";
        exit(0);
    }
}

echo "\n--- Führe Migration 040 aus ---\n";

try {
    $sql = file_get_contents($migrationFile);
    
    // Teile SQL in einzelne Statements (getrennt durch ;)
    $statements = [];
    $currentStatement = '';
    
    $lines = explode("\n", $sql);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Überspringe Kommentare und leere Zeilen
        if (empty($trimmed) || strpos($trimmed, '--') === 0) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Statement beendet
        if (substr(rtrim($trimmed), -1) === ';') {
            $stmt = trim($currentStatement);
            if (!empty($stmt) && strlen($stmt) > 5) {
                $statements[] = $stmt;
            }
            $currentStatement = '';
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
            // Ignoriere "already exists" Fehler
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "  ⚠️  Warnung: " . $e->getMessage() . "\n";
                echo "     (Tabelle existiert bereits - Migration möglicherweise bereits ausgeführt)\n";
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
    
    echo "\n✅ Migration 040 erfolgreich ausgeführt ($executed Statements)\n";
    echo "\n📝 Die Tabelle 'user_document_access' wurde erstellt.\n";
    echo "   Sie wird für das Tracking von Dokumentzugriffen verwendet.\n\n";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n❌ Fehler bei Migration 040: " . $e->getMessage() . "\n";
    echo "   Rollback durchgeführt.\n";
    exit(1);
}


