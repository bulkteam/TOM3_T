<?php
/**
 * TOM3 - Run Migration 019: Workflow Roles and Account Team Roles
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
    
    $sql = file_get_contents(__DIR__ . '/../database/migrations/019_workflow_roles_mysql.sql');
    
    // Prüfe, ob Tabelle bereits existiert
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'workflow_role'");
    if ($checkStmt->rowCount() > 0) {
        echo "Migration 019 bereits ausgeführt: Workflow-Rollen-Tabellen existieren bereits.\n";
        exit(0);
    }
    
    // Führe Migration aus
    $pdo->exec($sql);
    echo "Migration 019 erfolgreich ausgeführt: Workflow-Rollen und Account-Team-Rollen Tabellen erstellt.\n";
    echo "Erstellt:\n";
    echo "  - workflow_role Tabelle (Workflow-Rollen-Definitionen)\n";
    echo "  - user_workflow_role Tabelle (User ↔ Workflow-Rolle M:N Beziehung)\n";
    echo "  - account_team_role Tabelle (Account-Team-Rollen-Definitionen)\n";
    echo "  - 5 Standard-Workflow-Rollen\n";
    echo "  - 4 Standard-Account-Team-Rollen\n";
    
} catch (PDOException $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
    exit(1);
}





