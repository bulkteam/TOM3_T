<?php
/**
 * TOM3 - Prüfe Migrationen 048-050
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfe Migrationen 048-050 ===\n\n";

// Prüfe org_import_staging Felder
$stmt = $db->query("
    SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'org_import_staging'
    AND COLUMN_NAME IN ('industry_resolution', 'duplicate_status', 'duplicate_summary', 'commit_log')
    ORDER BY COLUMN_NAME
");

$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "✅ org_import_staging Felder:\n";
if (empty($fields)) {
    echo "   ⚠️  Keine Felder gefunden!\n";
} else {
    foreach ($fields as $field) {
        echo "   - {$field['COLUMN_NAME']} ({$field['DATA_TYPE']}, nullable: {$field['IS_NULLABLE']})\n";
    }
}

// Prüfe industry_alias Tabelle
$stmt2 = $db->query("
    SELECT COUNT(*) as cnt
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'industry_alias'
");
$exists = $stmt2->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

echo "\n";
if ($exists) {
    echo "✅ industry_alias Tabelle: EXISTIERT\n";
    
    // Prüfe Spalten
    $stmt3 = $db->query("
        SELECT COLUMN_NAME, DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'industry_alias'
        ORDER BY ORDINAL_POSITION
    ");
    $columns = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    echo "   Spalten:\n";
    foreach ($columns as $col) {
        echo "   - {$col['COLUMN_NAME']} ({$col['DATA_TYPE']})\n";
    }
} else {
    echo "❌ industry_alias Tabelle: FEHLT\n";
}

// Prüfe Indizes
$stmt4 = $db->query("
    SELECT INDEX_NAME, COLUMN_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'org_import_staging'
    AND INDEX_NAME LIKE 'idx_staging_duplicate%'
    ORDER BY INDEX_NAME, SEQ_IN_INDEX
");
$indexes = $stmt4->fetchAll(PDO::FETCH_ASSOC);

echo "\n";
if (!empty($indexes)) {
    echo "✅ Indizes für duplicate_status:\n";
    foreach ($indexes as $idx) {
        echo "   - {$idx['INDEX_NAME']} auf {$idx['COLUMN_NAME']}\n";
    }
} else {
    echo "⚠️  Keine Indizes für duplicate_status gefunden\n";
}

echo "\n=== Prüfung abgeschlossen ===\n";

