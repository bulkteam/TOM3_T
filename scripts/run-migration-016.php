<?php
/**
 * TOM3 - Run Migration 016: Add geodata fields to org_address
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
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/016_org_address_add_geodata_mysql.sql');
    
    // Prüfe, ob Spalten bereits existieren
    $checkStmt = $pdo->query("SHOW COLUMNS FROM org_address LIKE 'latitude'");
    if ($checkStmt->rowCount() > 0) {
        echo "Spalten 'latitude' und 'longitude' existieren bereits.\n";
        exit(0);
    }
    
    $pdo->exec($sql);
    echo "Migration 016 erfolgreich ausgeführt: Geodaten-Felder hinzugefügt.\n";
    
} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}





