<?php
/**
 * Prüft, ob alle Tabellen-Referenzen im Code auch in der Datenbank existieren
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  TOM3 - Tabellen-Konsistenz-Prüfung\n";
echo "========================================\n\n";

// 1. Hole alle Tabellen aus der Datenbank
echo "1. Lade Tabellen aus der Datenbank...\n";
$stmt = $db->query("SHOW TABLES");
$dbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "   ✓ " . count($dbTables) . " Tabellen gefunden\n\n";

// 2. Finde alle Tabellen-Referenzen im Code
echo "2. Suche Tabellen-Referenzen im Code...\n";

$codeTables = [];
$searchPaths = [
    __DIR__ . '/../src/TOM/Service',
    __DIR__ . '/../public/api',
    __DIR__ . '/../scripts'
];

foreach ($searchPaths as $path) {
    if (!is_dir($path)) continue;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path)
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            
            // Suche nach SQL-Statements - nur in SQL-Kontext
            // Ignoriere Kommentare und Strings
            $sqlKeywords = ['FROM', 'INTO', 'UPDATE', 'DELETE', 'JOIN', 'REFERENCES', 'TABLE'];
            $ignoreWords = ['select', 'where', 'inner', 'left', 'right', 'outer', 'full', 'cross', 
                           'on', 'as', 'and', 'or', 'not', 'in', 'like', 'is', 'null', 'desc', 'asc',
                           'order', 'by', 'group', 'having', 'limit', 'offset', 'set', 'values',
                           'insert', 'create', 'alter', 'drop', 'if', 'exists', 'not', 'table',
                           'current_timestamp', 'now', 'information_schema', 'pdo', 'query'];
            
            // FROM table_name (nur nach SELECT oder DELETE)
            preg_match_all('/(?:SELECT|DELETE)\s+.*?\s+FROM\s+`?([a-z_]+)`?/i', $content, $matches);
            foreach ($matches[1] as $table) {
                $tableLower = strtolower(trim($table));
                if (!in_array($tableLower, $ignoreWords) && preg_match('/^[a-z_]+$/', $tableLower)) {
                    $codeTables[$tableLower] = true;
                }
            }
            
            // INSERT INTO table_name
            preg_match_all('/INSERT\s+INTO\s+`?([a-z_]+)`?/i', $content, $matches);
            foreach ($matches[1] as $table) {
                $tableLower = strtolower(trim($table));
                if (!in_array($tableLower, $ignoreWords) && preg_match('/^[a-z_]+$/', $tableLower)) {
                    $codeTables[$tableLower] = true;
                }
            }
            
            // UPDATE table_name
            preg_match_all('/UPDATE\s+`?([a-z_]+)`?/i', $content, $matches);
            foreach ($matches[1] as $table) {
                $tableLower = strtolower(trim($table));
                if (!in_array($tableLower, $ignoreWords) && preg_match('/^[a-z_]+$/', $tableLower)) {
                    $codeTables[$tableLower] = true;
                }
            }
            
            // JOIN table_name (mit Alias-Handling)
            preg_match_all('/(?:INNER|LEFT|RIGHT|FULL|CROSS)?\s*JOIN\s+`?([a-z_]+)`?\s+(?:AS\s+)?`?[a-z_]*`?/i', $content, $matches);
            foreach ($matches[1] as $table) {
                $tableLower = strtolower(trim($table));
                if (!in_array($tableLower, $ignoreWords) && preg_match('/^[a-z_]+$/', $tableLower)) {
                    $codeTables[$tableLower] = true;
                }
            }
            
            // REFERENCES table_name
            preg_match_all('/REFERENCES\s+`?([a-z_]+)`?/i', $content, $matches);
            foreach ($matches[1] as $table) {
                $tableLower = strtolower(trim($table));
                if (!in_array($tableLower, $ignoreWords) && preg_match('/^[a-z_]+$/', $tableLower)) {
                    $codeTables[$tableLower] = true;
                }
            }
        }
    }
}

$codeTables = array_keys($codeTables);
sort($codeTables);
echo "   ✓ " . count($codeTables) . " Tabellen-Referenzen im Code gefunden\n\n";

// 3. Vergleiche
echo "3. Vergleiche Code-Referenzen mit Datenbank...\n\n";

$dbTablesLower = array_map('strtolower', $dbTables);
$missingInDb = [];
$extraInDb = [];

foreach ($codeTables as $table) {
    if (!in_array($table, $dbTablesLower)) {
        $missingInDb[] = $table;
    }
}

foreach ($dbTablesLower as $table) {
    if (!in_array($table, $codeTables)) {
        $extraInDb[] = $table;
    }
}

// 4. Ausgabe
if (empty($missingInDb) && empty($extraInDb)) {
    echo "✅ Alle Tabellen-Referenzen sind konsistent!\n";
} else {
    if (!empty($missingInDb)) {
        echo "❌ Tabellen im Code, die NICHT in der Datenbank existieren:\n";
        foreach ($missingInDb as $table) {
            echo "   - {$table}\n";
        }
        echo "\n";
    }
    
    if (!empty($extraInDb)) {
        echo "ℹ️  Tabellen in der Datenbank, die NICHT im Code referenziert werden:\n";
        foreach ($extraInDb as $table) {
            echo "   - {$table}\n";
        }
        echo "\n";
    }
}

// 5. Prüfe auch Migrations-Dateien
echo "4. Prüfe Migrations-Dateien...\n";
$migrationTables = [];
$migrationPath = __DIR__ . '/../database/migrations';
if (is_dir($migrationPath)) {
    $files = glob($migrationPath . '/*.sql');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $content, $matches);
        foreach ($matches[1] as $table) {
            $migrationTables[strtolower($table)] = true;
        }
    }
}

$migrationTables = array_keys($migrationTables);
sort($migrationTables);
echo "   ✓ " . count($migrationTables) . " Tabellen in Migrations gefunden\n\n";

// Prüfe ob Migrations-Tabellen in DB existieren
$missingMigrations = [];
foreach ($migrationTables as $table) {
    if (!in_array($table, $dbTablesLower)) {
        $missingMigrations[] = $table;
    }
}

if (!empty($missingMigrations)) {
    echo "⚠️  Tabellen in Migrations, die NICHT in der Datenbank existieren:\n";
    foreach ($missingMigrations as $table) {
        echo "   - {$table}\n";
    }
    echo "\n";
    echo "   💡 Mögliche Lösung: Migrations ausführen\n";
} else {
    echo "✅ Alle Migrations-Tabellen existieren in der Datenbank\n";
}

echo "\n=== Zusammenfassung ===\n";
echo "Code-Referenzen: " . count($codeTables) . "\n";
echo "Datenbank-Tabellen: " . count($dbTables) . "\n";
echo "Migrations-Tabellen: " . count($migrationTables) . "\n";

if (!empty($missingInDb)) {
    echo "\n❌ FEHLER: " . count($missingInDb) . " Tabellen im Code fehlen in der Datenbank!\n";
    exit(1);
} else {
    echo "\n✅ Keine fehlenden Tabellen gefunden.\n";
}

