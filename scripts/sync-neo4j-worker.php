<?php
/**
 * TOM3 - Neo4j Sync Worker
 * 
 * Verarbeitet Events aus der Outbox und synchronisiert sie nach Neo4j
 * 
 * Usage:
 *   php scripts/sync-neo4j-worker.php          # Einmalig ausführen
 *   php scripts/sync-neo4j-worker.php --daemon # Daemon-Modus (läuft kontinuierlich)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Sync\Neo4jSyncService;
use TOM\Infrastructure\Neo4j\Neo4jService;

$daemonMode = in_array('--daemon', $argv);

echo "=== TOM3 Neo4j Sync Worker ===\n\n";

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

if ($daemonMode) {
    echo "Daemon-Modus aktiviert (läuft kontinuierlich)\n";
    echo "Drücke Ctrl+C zum Beenden\n\n";
    
    $iteration = 0;
    while (true) {
        $iteration++;
        $processed = $syncService->processOutbox(100);
        
        if ($processed > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Iteration $iteration: $processed Event(s) verarbeitet\n";
        }
        
        // Warte 5 Sekunden vor nächster Iteration
        sleep(5);
    }
} else {
    echo "Einmalige Verarbeitung...\n\n";
    
    $totalProcessed = 0;
    $iterations = 0;
    
    do {
        $iterations++;
        $processed = $syncService->processOutbox(100);
        $totalProcessed += $processed;
        
        if ($processed > 0) {
            echo "Iteration $iterations: $processed Event(s) verarbeitet\n";
        }
    } while ($processed > 0 && $iterations < 100); // Max 100 Iterationen
    
    echo "\n=== Zusammenfassung ===\n";
    echo "Verarbeitete Events: $totalProcessed\n";
    echo "Iterationen: $iterations\n";
    
    if ($totalProcessed === 0) {
        echo "\n✓ Keine unverarbeiteten Events gefunden\n";
    } else {
        echo "\n✓ Synchronisation abgeschlossen\n";
    }
}



