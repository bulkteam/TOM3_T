<?php
/**
 * TOM3 - Run Migration 015: Add address_additional field
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

// Lade DB-Config direkt
$dbConfig = require __DIR__ . '/../config/database.php';
$mysqlConfig = $dbConfig['mysql'];

try {
    $pdo = new PDO(
        "mysql:host={$mysqlConfig['host']};port={$mysqlConfig['port']};dbname={$mysqlConfig['dbname']};charset={$mysqlConfig['charset']}",
        $mysqlConfig['user'],
        $mysqlConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/015_org_address_add_additional_field_mysql.sql');
    
    // Prüfe, ob Spalte bereits existiert
    $checkStmt = $pdo->query("SHOW COLUMNS FROM org_address LIKE 'address_additional'");
    if ($checkStmt->rowCount() > 0) {
        echo "Spalte 'address_additional' existiert bereits.\n";
        exit(0);
    }
    
    $pdo->exec($sql);
    echo "Migration 015 erfolgreich ausgeführt: address_additional Feld hinzugefügt.\n";
    
} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}

