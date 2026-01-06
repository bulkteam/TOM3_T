<?php
/**
 * Prüft fehlende Tabellen - berücksichtigt Migrationen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  TOM3 - Fehlende Tabellen-Prüfung\n";
echo "========================================\n\n";

// Tabellen, die absichtlich entfernt wurden (Migrationen)
$removedTables = [
    'project_partner' => 'Wurde durch project_party ersetzt (Migration 022, 023)',
    'project_stakeholder' => 'Wurde durch project_person ersetzt (Migration 022, 023)',
    'org_industry' => 'Wurde entfernt, direkte Felder in org Tabelle (Migration 009)',
    'document_versions' => 'Ist auskommentiert in Migration 036 (optional)',
    'party' => 'Existiert nicht - war Fehler im Cleanup-Skript'
];

// Hole alle Tabellen aus der Datenbank
$stmt = $db->query("SHOW TABLES");
$dbTables = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

echo "Datenbank-Tabellen: " . count($dbTables) . "\n\n";

// Prüfe wichtige Tabellen, die im Code verwendet werden
$importantTables = [
    'org',
    'person',
    'org_import_staging',
    'org_import_batch',
    'import_duplicate_candidates',
    'case_item',
    'org_address',
    'org_communication_channel',
    'org_vat_registration',
    'person_affiliation',
    'person_relationship',
    'work_item_timeline',
    'activity_log',
    'documents',
    'document_attachments',
    'project',
    'project_party',
    'project_person'
];

echo "Prüfe wichtige Tabellen:\n";
$missing = [];
foreach ($importantTables as $table) {
    if (in_array(strtolower($table), $dbTables)) {
        echo "  ✓ {$table}\n";
    } else {
        echo "  ✗ {$table} - FEHLT!\n";
        $missing[] = $table;
    }
}

echo "\n";

// Prüfe ob entfernte Tabellen noch in DB sind
echo "Prüfe entfernte Tabellen (sollten NICHT existieren):\n";
$stillExists = [];
foreach ($removedTables as $table => $reason) {
    if (in_array(strtolower($table), $dbTables)) {
        echo "  ⚠️  {$table} - EXISTIERT NOCH (sollte entfernt sein: {$reason})\n";
        $stillExists[] = $table;
    } else {
        echo "  ✓ {$table} - korrekt entfernt\n";
    }
}

echo "\n";

// Prüfe spezifische Tabellen aus dem Cleanup-Skript
$cleanupTables = [
    'duplicate_check_results',
    'import_duplicate_candidates',
    'org_import_staging',
    'org_import_batch'
];

echo "Prüfe Import/Duplicate-Tabellen:\n";
foreach ($cleanupTables as $table) {
    if (in_array(strtolower($table), $dbTables)) {
        echo "  ✓ {$table}\n";
    } else {
        echo "  ✗ {$table} - FEHLT!\n";
        $missing[] = $table;
    }
}

echo "\n=== Zusammenfassung ===\n";
if (empty($missing) && empty($stillExists)) {
    echo "✅ Alle wichtigen Tabellen sind vorhanden!\n";
} else {
    if (!empty($missing)) {
        echo "❌ Fehlende Tabellen: " . implode(', ', $missing) . "\n";
        echo "   → Migrations ausführen oder Tabellen erstellen\n";
    }
    if (!empty($stillExists)) {
        echo "⚠️  Tabellen die entfernt werden sollten: " . implode(', ', $stillExists) . "\n";
        echo "   → Cleanup-Migrationen ausführen\n";
    }
    exit(1);
}

