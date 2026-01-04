<?php
/**
 * TOM3 - Run Migration 018: Users and Roles
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
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/018_users_and_roles_mysql.sql');
    
    // Prüfe, ob Tabelle bereits existiert
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($checkStmt->rowCount() > 0) {
        echo "Migration 018 bereits ausgeführt: Users-Tabelle existiert bereits.\n";
        exit(0);
    }
    
    // Führe Migration aus
    $pdo->exec($sql);
    echo "Migration 018 erfolgreich ausgeführt: Users und Roles Tabellen erstellt.\n";
    echo "Erstellt:\n";
    echo "  - role Tabelle (Rollen-Definitionen)\n";
    echo "  - users Tabelle (Benutzer)\n";
    echo "  - user_role Tabelle (User ↔ Role M:N Beziehung)\n";
    echo "  - 4 Standard-Rollen (admin, user, readonly, manager)\n";
    echo "  - 4 Dev-User für Entwicklung\n";
    
} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}





