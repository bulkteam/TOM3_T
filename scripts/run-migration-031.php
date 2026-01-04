<?php
/**
 * TOM3 - Migration 031 ausführen
 * 
 * Erstellt person_audit_trail Tabelle
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$migrationFile = __DIR__ . '/../database/migrations/031_create_person_audit_trail_mysql.sql';

if (!file_exists($migrationFile)) {
    die("Migration-Datei nicht gefunden: $migrationFile\n");
}

echo "Führe Migration 031 aus: Person Audit Trail Tabelle\n";
echo "====================================================\n\n";

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
            if (strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "Warnung: Tabelle/Index existiert bereits (wird übersprungen)\n";
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
    echo "  - $executed SQL-Statements ausgeführt\n\n";
    
} catch (Exception $e) {
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (PDOException $rollbackError) {}
    
    echo "✗ Fehler bei Migration:\n";
    echo "  " . $e->getMessage() . "\n";
    exit(1);
}


