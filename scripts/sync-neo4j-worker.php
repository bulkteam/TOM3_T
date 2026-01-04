<?php
/**
 * TOM3 - Neo4j Sync Worker
 * 
 * Verarbeitet Events aus der Outbox und synchronisiert sie nach Neo4j
 * 
 * Usage:
 *   php scripts/sync-neo4j-worker.php          # Einmalig ausführen (mit Output)
 *   php scripts/sync-neo4j-worker.php --daemon # Daemon-Modus (läuft kontinuierlich)
 *   php scripts/sync-neo4j-worker.php --quiet  # Stumm (für Task Scheduler)
 */

// Unterdrücke Deprecated-Warnungen (Laudis Neo4j Client)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Sync\Neo4jSyncService;
use TOM\Infrastructure\Neo4j\Neo4jService;

$daemonMode = in_array('--daemon', $argv);
$quietMode = in_array('--quiet', $argv);

// Log-Funktion (nur wenn nicht quiet)
function logMessage($message, $quiet = false) {
    if (!$quiet) {
        echo $message . "\n";
    }
}

if (!$quietMode) {
    echo "=== TOM3 Neo4j Sync Worker ===\n\n";
}

// Prüfe Neo4j-Verbindung
try {
    $neo4j = new Neo4jService();
    if (!$neo4j->testConnection()) {
        if (!$quietMode) {
            echo "✗ FEHLER: Neo4j-Verbindung fehlgeschlagen\n";
        }
        exit(1);
    }
    if (!$quietMode) {
        echo "✓ Neo4j-Verbindung erfolgreich\n\n";
    }
} catch (\Exception $e) {
    if (!$quietMode) {
        echo "✗ FEHLER: " . $e->getMessage() . "\n";
    }
    exit(1);
}

$syncService = new Neo4jSyncService();

if ($daemonMode) {
    if (!$quietMode) {
        echo "Daemon-Modus aktiviert (läuft kontinuierlich)\n";
        echo "Drücke Ctrl+C zum Beenden\n\n";
    }
    
    $iteration = 0;
    while (true) {
        $iteration++;
        $processed = $syncService->processOutbox(100);
        
        if ($processed > 0 && !$quietMode) {
            echo "[" . date('Y-m-d H:i:s') . "] Iteration $iteration: $processed Event(s) verarbeitet\n";
        }
        
        // Warte 5 Sekunden vor nächster Iteration
        sleep(5);
    }
} else {
    if (!$quietMode) {
        echo "Einmalige Verarbeitung...\n\n";
    }
    
    $totalProcessed = 0;
    $iterations = 0;
    
    do {
        $iterations++;
        $processed = $syncService->processOutbox(100);
        $totalProcessed += $processed;
        
        if ($processed > 0 && !$quietMode) {
            echo "Iteration $iterations: $processed Event(s) verarbeitet\n";
        }
    } while ($processed > 0 && $iterations < 100); // Max 100 Iterationen
    
    if (!$quietMode) {
        echo "\n=== Zusammenfassung ===\n";
        echo "Verarbeitete Events: $totalProcessed\n";
        echo "Iterationen: $iterations\n";
        
        if ($totalProcessed === 0) {
            echo "\n✓ Keine unverarbeiteten Events gefunden\n";
        } else {
            echo "\n✓ Synchronisation abgeschlossen\n";
        }
    }
    
    // Bei Fehlern immer Exit-Code setzen (auch im quiet mode)
    exit($totalProcessed > 0 ? 0 : 0); // 0 = Erfolg, auch wenn nichts zu tun war
}





