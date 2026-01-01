<?php
/**
 * TOM3 - Personen-Modul Migrationen ausführen
 * 
 * Führt alle Migrationen für das Personen-Modul in der richtigen Reihenfolge aus:
 * - 024: Person Tabelle erweitern
 * - 025: Org Unit Tabelle erstellen
 * - 026: Person Affiliation erweitern
 * - 027: Person Affiliation Reporting erstellen
 * - 028: Person Org Role erstellen
 * - 029: Person Org Shareholding erstellen
 * - 030: Person Relationship erstellen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrations = [
    '024' => '024_extend_person_table_mysql.sql',
    '025' => '025_create_org_unit_table_mysql.sql',
    '026' => '026_extend_person_affiliation_mysql.sql',
    '027' => '027_create_person_affiliation_reporting_mysql.sql',
    '028' => '028_create_person_org_role_mysql.sql',
    '029' => '029_create_person_org_shareholding_mysql.sql',
    '030' => '030_create_person_relationship_mysql.sql',
];

echo "=== TOM3 Personen-Modul Migrationen ===\n\n";

$autoConfirm = in_array('--yes', $argv) || in_array('-y', $argv);

if (!$autoConfirm) {
    echo "Dies führt folgende Migrationen aus:\n";
    foreach ($migrations as $num => $file) {
        echo "  - Migration $num: $file\n";
    }
    echo "\nMöchtest du fortfahren? (j/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'j' && trim(strtolower($line)) !== 'y') {
        echo "Abgebrochen.\n";
        exit(0);
    }
}

$successCount = 0;
$errorCount = 0;

foreach ($migrations as $num => $file) {
    $migrationFile = __DIR__ . '/../database/migrations/' . $file;
    
    if (!file_exists($migrationFile)) {
        echo "✗ Migration $num: Datei nicht gefunden: $file\n";
        $errorCount++;
        continue;
    }
    
    echo "\n--- Migration $num: " . basename($file, '.sql') . " ---\n";
    
    try {
        $sql = file_get_contents($migrationFile);
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
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
            if (empty(trim($statement))) continue;
            
            try {
                $db->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                // Ignoriere "already exists" Fehler
                if (strpos($e->getMessage(), 'already exists') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false ||
                    strpos($e->getMessage(), 'Duplicate key') !== false) {
                    echo "  Warnung: " . $e->getMessage() . "\n";
                    continue;
                }
                $transactionActive = false;
                throw $e;
            }
        }
        
        if ($transactionActive && $db->inTransaction()) {
            $db->commit();
        }
        
        echo "✓ Migration $num erfolgreich ($executed Statements)\n";
        $successCount++;
        
    } catch (Exception $e) {
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (PDOException $rollbackError) {}
        
        echo "✗ Migration $num fehlgeschlagen:\n";
        echo "  " . $e->getMessage() . "\n";
        $errorCount++;
    }
}

echo "\n=== Zusammenfassung ===\n";
echo "Erfolgreich: $successCount\n";
echo "Fehler: $errorCount\n";

if ($errorCount > 0) {
    exit(1);
}

echo "\n✓ Alle Migrationen erfolgreich ausgeführt!\n";
