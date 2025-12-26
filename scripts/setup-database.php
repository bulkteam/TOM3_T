#!/usr/bin/env php
<?php
/**
 * TOM3 - Database Setup Script
 * 
 * Führt die SQL-Migrationen aus und richtet die Datenbank ein.
 * 
 * Usage:
 *   php scripts/setup-database.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Lade Konfiguration
$configFile = __DIR__ . '/../config/database.php';
if (!file_exists($configFile)) {
    echo "❌ Fehler: config/database.php nicht gefunden.\n";
    echo "   Kopiere config/database.php.example nach config/database.php und passe die Werte an.\n";
    exit(1);
}

$config = require $configFile;
$dbConfig = $config['postgresql'] ?? null;

if (!$dbConfig) {
    echo "❌ Fehler: PostgreSQL-Konfiguration nicht gefunden.\n";
    exit(1);
}

// Verbinde zur Datenbank (ohne dbname, um die DB zu erstellen)
try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%d',
        $dbConfig['host'],
        $dbConfig['port']
    );
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "❌ Fehler beim Verbinden zur PostgreSQL: " . $e->getMessage() . "\n";
    exit(1);
}

// Erstelle Datenbank falls nicht vorhanden
$dbname = $dbConfig['dbname'];
try {
    $pdo->exec("CREATE DATABASE {$dbname}");
    echo "✅ Datenbank '{$dbname}' erstellt.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "ℹ️  Datenbank '{$dbname}' existiert bereits.\n";
    } else {
        echo "❌ Fehler beim Erstellen der Datenbank: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Verbinde zur erstellten Datenbank
try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbname
    );
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "❌ Fehler beim Verbinden zur Datenbank: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n📦 Führe Migrationen aus...\n\n";

// Führe Migrationen aus
$migrationsDir = __DIR__ . '/../database/migrations';
$migrations = glob($migrationsDir . '/*.sql');
sort($migrations);

if (empty($migrations)) {
    echo "⚠️  Keine Migrationen gefunden.\n";
    exit(1);
}

foreach ($migrations as $migration) {
    $filename = basename($migration);
    echo "  → {$filename}... ";
    
    try {
        $sql = file_get_contents($migration);
        $pdo->exec($sql);
        echo "✅\n";
    } catch (PDOException $e) {
        echo "❌\n";
        echo "   Fehler: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n✅ Datenbank-Setup abgeschlossen!\n";
echo "\n📝 Nächste Schritte:\n";
echo "   1. Prüfe die Datenbank-Verbindung in config/database.php\n";
echo "   2. Starte den Neo4j Sync-Worker: php scripts/sync-worker.php --daemon\n";
echo "   3. Öffne die UI: http://localhost/TOM3/public/\n";


