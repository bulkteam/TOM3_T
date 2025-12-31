<?php
/**
 * TOM3 - Neo4j Status Check
 * 
 * Prüft:
 * - Neo4j-Verbindung
 * - Anzahl unverarbeiteter Events
 * - Sync-Status
 * 
 * Usage:
 *   php scripts/neo4j-status-check.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Neo4j\Neo4jService;
use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3 Neo4j Status Check ===\n\n";

// Setze Working Directory
$scriptDir = __DIR__;
$projectRoot = dirname($scriptDir);
chdir($projectRoot);

$db = DatabaseConnection::getInstance();

// 1. Prüfe Neo4j-Verbindung
echo "1. Neo4j-Verbindung:\n";
try {
    $neo4j = new Neo4jService();
    if ($neo4j->testConnection()) {
        echo "   ✓ Neo4j-Verbindung erfolgreich\n";
        
        // Prüfe Anzahl Nodes
        try {
            $orgCount = $neo4j->runSingle("MATCH (o:Org) RETURN count(o) as count");
            $personCount = $neo4j->runSingle("MATCH (p:Person) RETURN count(p) as count");
            $relationCount = $neo4j->runSingle("MATCH ()-[r]->() RETURN count(r) as count");
            
            echo "   - Organisationen in Neo4j: " . ($orgCount ?? 0) . "\n";
            echo "   - Personen in Neo4j: " . ($personCount ?? 0) . "\n";
            echo "   - Relationen in Neo4j: " . ($relationCount ?? 0) . "\n";
        } catch (\Exception $e) {
            echo "   ⚠ Konnte Node-Anzahl nicht ermitteln: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ✗ Neo4j-Verbindung fehlgeschlagen\n";
    }
} catch (\Exception $e) {
    echo "   ✗ FEHLER: " . $e->getMessage() . "\n";
}

echo "\n";

// 2. Prüfe unverarbeitete Events
echo "2. Outbox-Events:\n";
try {
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN processed_at IS NULL THEN 1 END) as unprocessed,
            COUNT(CASE WHEN processed_at IS NOT NULL THEN 1 END) as processed
        FROM outbox_event
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   - Gesamt: " . ($stats['total'] ?? 0) . "\n";
    echo "   - Verarbeitet: " . ($stats['processed'] ?? 0) . "\n";
    echo "   - Unverarbeitet: " . ($stats['unprocessed'] ?? 0) . "\n";
    
    if (($stats['unprocessed'] ?? 0) > 0) {
        // Zeige Details zu unverarbeiteten Events
        $stmt = $db->query("
            SELECT 
                aggregate_type,
                event_type,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            FROM outbox_event
            WHERE processed_at IS NULL
            GROUP BY aggregate_type, event_type
            ORDER BY oldest ASC
        ");
        $unprocessed = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($unprocessed)) {
            echo "\n   Unverarbeitete Events nach Typ:\n";
            foreach ($unprocessed as $row) {
                echo "   - {$row['aggregate_type']}::{$row['event_type']}: {$row['count']} (ältestes: {$row['oldest']})\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "   ✗ FEHLER: " . $e->getMessage() . "\n";
}

echo "\n";

// 3. Prüfe MySQL-Daten
echo "3. MySQL-Daten:\n";
try {
    $orgStmt = $db->query("SELECT COUNT(*) as count FROM org");
    $orgCount = $orgStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $personStmt = $db->query("SELECT COUNT(*) as count FROM person");
    $personCount = $personStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    $relationStmt = $db->query("SELECT COUNT(*) as count FROM org_relation");
    $relationCount = $relationStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    echo "   - Organisationen in MySQL: $orgCount\n";
    echo "   - Personen in MySQL: $personCount\n";
    echo "   - Firmenrelationen in MySQL: $relationCount\n";
} catch (\Exception $e) {
    echo "   ✗ FEHLER: " . $e->getMessage() . "\n";
}

echo "\n";

// 4. Empfehlungen
echo "4. Empfehlungen:\n";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM outbox_event WHERE processed_at IS NULL");
    $unprocessed = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    if ($unprocessed > 0) {
        echo "   ⚠ Es gibt $unprocessed unverarbeitete Event(s).\n";
        echo "   → Führe aus: php scripts/sync-neo4j-worker.php\n";
    } else {
        echo "   ✓ Keine unverarbeiteten Events\n";
    }
    
    // Prüfe ob Neo4j leer ist, aber MySQL Daten hat
    try {
        $neo4j = new Neo4jService();
        if ($neo4j->testConnection()) {
            $neo4jOrgCount = $neo4j->runSingle("MATCH (o:Org) RETURN count(o) as count") ?? 0;
            if ($neo4jOrgCount == 0 && $orgCount > 0) {
                echo "   ⚠ Neo4j ist leer, aber MySQL hat Daten.\n";
                echo "   → Führe Initial-Sync aus: php scripts/sync-neo4j-initial.php\n";
            }
        }
    } catch (\Exception $e) {
        // Ignoriere Neo4j-Fehler hier
    }
} catch (\Exception $e) {
    echo "   ✗ FEHLER: " . $e->getMessage() . "\n";
}

echo "\n";
