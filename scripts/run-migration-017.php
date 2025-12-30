<?php
/**
 * TOM3 - Run Migration 017: Extended Org Relations
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
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/017_org_relations_extended_mysql.sql');
    
    // Prüfe, ob Spalten bereits existieren
    $checkStmt = $pdo->query("SHOW COLUMNS FROM org_relation LIKE 'has_voting_rights'");
    if ($checkStmt->rowCount() > 0) {
        echo "Migration 017 bereits ausgeführt: Erweiterte Felder existieren bereits.\n";
        exit(0);
    }
    
    // Führe Migration aus
    $pdo->exec($sql);
    echo "Migration 017 erfolgreich ausgeführt: Erweiterte Relationen-Felder hinzugefügt.\n";
    echo "Hinzugefügte Felder:\n";
    echo "  - has_voting_rights (Stimmberechtigt)\n";
    echo "  - is_direct (Direkt/Indirekt)\n";
    echo "  - source (Quelle/Beleg)\n";
    echo "  - confidence (Vertrauenswürdigkeit)\n";
    echo "  - tags (Tags)\n";
    echo "  - is_current (Aktuell gültig)\n";
    echo "  - Indizes für Performance\n";
    
} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}



