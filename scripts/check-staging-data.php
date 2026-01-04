<?php
/**
 * Debug-Skript: Prüft Staging-Daten in der Datenbank
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Staging-Daten Prüfung ===\n\n";

// 1. Prüfe, ob Tabelle existiert
try {
    $stmt = $db->query("SHOW TABLES LIKE 'org_import_staging'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        echo "❌ Tabelle 'org_import_staging' existiert nicht!\n";
        exit(1);
    }
    echo "✅ Tabelle 'org_import_staging' existiert\n\n";
} catch (Exception $e) {
    echo "❌ Fehler beim Prüfen der Tabelle: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Zähle alle Staging-Rows
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM org_import_staging");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalCount = $result['count'] ?? 0;
    
    echo "📊 Gesamtanzahl Staging-Rows: $totalCount\n\n";
} catch (Exception $e) {
    echo "❌ Fehler beim Zählen: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Zeige alle Batches mit Staging-Daten
try {
    $stmt = $db->query("
        SELECT 
            import_batch_uuid,
            COUNT(*) as row_count,
            MIN(row_number) as min_row,
            MAX(row_number) as max_row,
            MIN(created_at) as first_import,
            MAX(created_at) as last_import
        FROM org_import_staging
        GROUP BY import_batch_uuid
        ORDER BY first_import DESC
    ");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($batches)) {
        echo "⚠️  Keine Staging-Daten gefunden!\n";
    } else {
        echo "📦 Batches mit Staging-Daten:\n";
        echo str_repeat("-", 80) . "\n";
        foreach ($batches as $batch) {
            echo "Batch UUID: " . $batch['import_batch_uuid'] . "\n";
            echo "  - Rows: " . $batch['row_count'] . "\n";
            echo "  - Row-Nummern: " . $batch['min_row'] . " - " . $batch['max_row'] . "\n";
            echo "  - Erster Import: " . $batch['first_import'] . "\n";
            echo "  - Letzter Import: " . $batch['last_import'] . "\n";
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Fehler beim Abfragen der Batches: " . $e->getMessage() . "\n";
    exit(1);
}

// 4. Zeige Details der letzten 5 Staging-Rows
try {
    $stmt = $db->query("
        SELECT 
            staging_uuid,
            import_batch_uuid,
            row_number,
            validation_status,
            disposition,
            import_status,
            created_at
        FROM org_import_staging
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        echo "📋 Letzte 5 Staging-Rows:\n";
        echo str_repeat("-", 80) . "\n";
        foreach ($rows as $row) {
            echo "Staging UUID: " . $row['staging_uuid'] . "\n";
            echo "  - Batch UUID: " . $row['import_batch_uuid'] . "\n";
            echo "  - Row Nummer: " . $row['row_number'] . "\n";
            echo "  - Validation Status: " . ($row['validation_status'] ?? 'NULL') . "\n";
            echo "  - Disposition: " . ($row['disposition'] ?? 'NULL') . "\n";
            echo "  - Import Status: " . ($row['import_status'] ?? 'NULL') . "\n";
            echo "  - Erstellt: " . $row['created_at'] . "\n";
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Fehler beim Abfragen der Rows: " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Prüfe Spalten-Struktur
try {
    $stmt = $db->query("DESCRIBE org_import_staging");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "🔍 Tabellen-Struktur:\n";
    echo str_repeat("-", 80) . "\n";
    foreach ($columns as $col) {
        $null = $col['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['Default'] !== null ? " DEFAULT '{$col['Default']}'" : '';
        echo "  - {$col['Field']} ({$col['Type']}) $null$default\n";
    }
} catch (Exception $e) {
    echo "❌ Fehler beim Prüfen der Struktur: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Prüfung abgeschlossen ===\n";

