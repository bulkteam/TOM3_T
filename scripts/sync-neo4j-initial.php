<?php
/**
 * TOM3 - Neo4j Initial Sync
 * 
 * Synchronisiert alle bestehenden Daten aus MySQL nach Neo4j
 * Nützlich für den ersten Start oder nach Problemen
 * 
 * Usage:
 *   php scripts/sync-neo4j-initial.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Sync\Neo4jSyncService;
use TOM\Infrastructure\Neo4j\Neo4jService;

echo "=== TOM3 Neo4j Initial Sync ===\n\n";

// Prüfe Neo4j-Verbindung
try {
    $neo4j = new Neo4jService();
    if (!$neo4j->testConnection()) {
        echo "✗ FEHLER: Neo4j-Verbindung fehlgeschlagen\n";
        exit(1);
    }
    echo "✓ Neo4j-Verbindung erfolgreich\n\n";
} catch (\Exception $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    exit(1);
}

$syncService = new Neo4jSyncService();

echo "Starte Initial-Sync...\n";
echo "Dies kann einige Minuten dauern, je nach Datenmenge.\n\n";

$startTime = microtime(true);
$stats = $syncService->initialSync();
$duration = round(microtime(true) - $startTime, 2);

echo "\n=== Zusammenfassung ===\n";
echo "Organisationen: {$stats['orgs']}\n";
echo "Personen: {$stats['persons']}\n";
echo "Firmenrelationen: {$stats['relations']}\n";
echo "Person-Affiliationen: {$stats['affiliations']}\n";
echo "Dauer: {$duration}s\n";

$total = array_sum($stats);
if ($total > 0) {
    echo "\n✓ Initial-Sync abgeschlossen\n";
} else {
    echo "\n⚠ Keine Daten gefunden - ist die MySQL-Datenbank befüllt?\n";
}





