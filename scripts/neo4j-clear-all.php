<?php
/**
 * TOM3 - Neo4j Clear All
 * 
 * Löscht alle Nodes und Relationships aus Neo4j
 * 
 * Usage:
 *   php scripts/neo4j-clear-all.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Neo4j\Neo4jService;

echo "=== TOM3 Neo4j Clear All ===\n\n";

// Setze Working Directory
$scriptDir = __DIR__;
$projectRoot = dirname($scriptDir);
chdir($projectRoot);

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

// Zeige aktuelle Statistiken
echo "Aktuelle Daten:\n";
try {
    $orgCount = $neo4j->runSingle("MATCH (o:Org) RETURN count(o) as count") ?? 0;
    $personCount = $neo4j->runSingle("MATCH (p:Person) RETURN count(p) as count") ?? 0;
    $relationCount = $neo4j->runSingle("MATCH ()-[r]->() RETURN count(r) as count") ?? 0;
    
    echo "  - Organisationen: $orgCount\n";
    echo "  - Personen: $personCount\n";
    echo "  - Relationen: $relationCount\n";
    
    if ($orgCount == 0 && $personCount == 0 && $relationCount == 0) {
        echo "\n✓ Neo4j ist bereits leer\n";
        exit(0);
    }
} catch (\Exception $e) {
    echo "  ⚠ Konnte Statistiken nicht abrufen: " . $e->getMessage() . "\n";
}

echo "\n";

// Bestätigung (außer wenn --yes Flag gesetzt ist)
$autoConfirm = in_array('--yes', $argv) || in_array('-y', $argv);

if (!$autoConfirm) {
    echo "⚠ WARNUNG: Dies löscht ALLE Daten aus Neo4j!\n";
    echo "Möchtest du fortfahren? (ja/n): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if (strtolower($line) !== 'ja') {
        echo "Abgebrochen.\n";
        exit(0);
    }
} else {
    echo "Automatische Bestätigung (--yes Flag gesetzt)\n";
}

echo "\nLösche alle Daten...\n";

try {
    // Lösche alle Relationships
    echo "  - Lösche Relationships...\n";
    $neo4j->run("MATCH ()-[r]->() DELETE r");
    
    // Lösche alle Nodes
    echo "  - Lösche Nodes...\n";
    $neo4j->run("MATCH (n) DELETE n");
    
    // Prüfe ob alles gelöscht wurde
    $orgCount = $neo4j->runSingle("MATCH (o:Org) RETURN count(o) as count") ?? 0;
    $personCount = $neo4j->runSingle("MATCH (p:Person) RETURN count(p) as count") ?? 0;
    $relationCount = $neo4j->runSingle("MATCH ()-[r]->() RETURN count(r) as count") ?? 0;
    
    if ($orgCount == 0 && $personCount == 0 && $relationCount == 0) {
        echo "\n✓ Neo4j erfolgreich geleert!\n";
    } else {
        echo "\n⚠ Warnung: Nicht alle Daten wurden gelöscht:\n";
        echo "  - Organisationen: $orgCount\n";
        echo "  - Personen: $personCount\n";
        echo "  - Relationen: $relationCount\n";
    }
} catch (\Exception $e) {
    echo "\n✗ FEHLER beim Löschen: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";


