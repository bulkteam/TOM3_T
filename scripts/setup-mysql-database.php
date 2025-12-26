<?php
/**
 * TOM3 - MySQL Database Setup Script
 * 
 * Führt die SQL-Migrationen für MySQL/MariaDB aus.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Lade Konfiguration
$configFile = __DIR__ . '/../config/database.php';
if (!file_exists($configFile)) {
    echo "❌ Fehler: config/database.php nicht gefunden.\n";
    exit(1);
}

$config = require $configFile;
$dbConfig = $config['mysql'] ?? null;

if (!$dbConfig) {
    echo "❌ Fehler: MySQL-Konfiguration nicht gefunden.\n";
    exit(1);
}

// Verbinde zur Datenbank
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname'],
        $dbConfig['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Verbindung zur Datenbank erfolgreich.\n\n";
} catch (PDOException $e) {
    echo "❌ Fehler beim Verbinden zur MySQL: " . $e->getMessage() . "\n";
    exit(1);
}

echo "📦 Führe Migrationen aus...\n\n";

// Führe MySQL-Migrationen aus
$migrationsDir = __DIR__ . '/../database/migrations';
$migrations = [
    $migrationsDir . '/001_tom_core_schema_mysql.sql',
    $migrationsDir . '/002_workflow_definitions_mysql.sql',
    $migrationsDir . '/003_org_addresses_and_relations_mysql.sql',
    $migrationsDir . '/004_org_metadata_mysql.sql',
    $migrationsDir . '/005_org_classification_mysql.sql',
    $migrationsDir . '/006_org_account_ownership_mysql.sql'
];

foreach ($migrations as $migration) {
    if (!file_exists($migration)) {
        echo "⚠️  Migration nicht gefunden: " . basename($migration) . "\n";
        continue;
    }
    
    $filename = basename($migration);
    echo "  → {$filename}... ";
    
    try {
        $sql = file_get_contents($migration);
        
        // Entferne Kommentare (-- am Zeilenanfang)
        $lines = explode("\n", $sql);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Ignoriere leere Zeilen und Kommentare
            if (!empty($trimmed) && !preg_match('/^--/', $trimmed) && !preg_match('/^\/\//', $trimmed)) {
                $cleanLines[] = $line;
            }
        }
        $sql = implode("\n", $cleanLines);
        
        // Teile SQL in einzelne Statements (getrennt durch ;)
        // Verwende einen besseren Parser, der auch mehrzeilige Statements handhabt
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            $current .= $char;
            
            // String-Handling
            if (($char === '"' || $char === "'") && ($i === 0 || $sql[$i-1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                }
            }
            
            // Statement-Ende (nur außerhalb von Strings)
            if (!$inString && $char === ';') {
                $stmt = trim($current);
                if (!empty($stmt) && strlen($stmt) > 1) {
                    $statements[] = $stmt;
                }
                $current = '';
            }
        }
        
        // Führe Statements aus
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && strlen($statement) > 1) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Ignoriere "already exists" Fehler
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                }
            }
        }
        
        echo "✅\n";
    } catch (PDOException $e) {
        echo "❌\n";
        echo "   Fehler: " . $e->getMessage() . "\n";
        // Stoppe bei Fehlern
        exit(1);
    }
}

// Prüfe Tabellen
echo "\n📊 Prüfe erstellte Tabellen...\n";
try {
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   Gefundene Tabellen: " . count($tables) . "\n";
    if (count($tables) > 0) {
        foreach (array_slice($tables, 0, 10) as $table) {
            echo "   - $table\n";
        }
        if (count($tables) > 10) {
            echo "   ... und " . (count($tables) - 10) . " weitere\n";
        }
    }
} catch (PDOException $e) {
    echo "   ⚠️  Fehler beim Prüfen der Tabellen: " . $e->getMessage() . "\n";
}

echo "\n✅ Datenbank-Setup abgeschlossen!\n";

