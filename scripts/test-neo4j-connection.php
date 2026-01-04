<?php
/**
 * TOM3 - Neo4j Connection Test
 * 
 * Testet die Neo4j-Verbindung mit verschiedenen Konfigurationen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Neo4j\Neo4jService;

echo "=== TOM3 Neo4j Connection Test ===\n\n";

// Setze Working Directory
$scriptDir = __DIR__;
$projectRoot = dirname($scriptDir);
chdir($projectRoot);

// Lade Konfiguration
$dbConfig = require __DIR__ . '/../config/database.php';
$neo4jConfig = $dbConfig['neo4j'] ?? null;

if (!$neo4jConfig) {
    echo "✗ FEHLER: Neo4j-Konfiguration nicht gefunden\n";
    exit(1);
}

echo "Konfiguration:\n";
echo "  URI: " . $neo4jConfig['uri'] . "\n";
echo "  User: " . $neo4jConfig['user'] . "\n";
echo "  Password: " . (empty($neo4jConfig['password']) ? '(leer)' : str_repeat('*', strlen($neo4jConfig['password']))) . "\n\n";

// Test 1: DNS-Auflösung
echo "1. DNS-Auflösung:\n";
$host = parse_url($neo4jConfig['uri'], PHP_URL_HOST);
if (!$host) {
    // Versuche Host aus URI zu extrahieren
    preg_match('/neo4j\+?s?:\/\/([^@\/]+)/', $neo4jConfig['uri'], $matches);
    $host = $matches[1] ?? null;
}

if ($host) {
    echo "  Host: $host\n";
    $ip = gethostbyname($host);
    if ($ip === $host) {
        echo "  ✗ DNS-Auflösung fehlgeschlagen\n";
    } else {
        echo "  ✓ DNS-Auflösung erfolgreich: $ip\n";
    }
} else {
    echo "  ⚠ Konnte Host nicht extrahieren\n";
}

echo "\n";

// Test 2: URI-Formatierung
echo "2. URI-Formatierung:\n";
$uri = $neo4jConfig['uri'];

// Prüfe ob URI bereits Credentials enthält
if (strpos($uri, '@') === false) {
    echo "  URI ohne Credentials, füge sie hinzu...\n";
    
    // Extrahiere Schema und Host
    preg_match('/^([a-z+]+:\/\/)/', $uri, $matches);
    $schema = $matches[1] ?? 'neo4j+s://';
    $host = str_replace($schema, '', $uri);
    
    $fullUri = $schema . $neo4jConfig['user'] . ':' . $neo4jConfig['password'] . '@' . $host;
    echo "  Original: $uri\n";
    echo "  Vollständig: $schema" . str_repeat('*', strlen($neo4jConfig['user'])) . ':' . str_repeat('*', strlen($neo4jConfig['password'])) . "@$host\n";
} else {
    echo "  URI enthält bereits Credentials\n";
    $fullUri = $uri;
}

echo "\n";

// Test 3: Verbindungstest
echo "3. Verbindungstest:\n";
try {
    $neo4j = new Neo4jService();
    
    echo "  Versuche Verbindung...\n";
    $startTime = microtime(true);
    
    if ($neo4j->testConnection()) {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        echo "  ✓ Verbindung erfolgreich! (Dauer: {$duration}ms)\n";
        
        // Test-Query
        echo "\n4. Test-Query:\n";
        try {
            $result = $neo4j->run('RETURN 1 as test, datetime() as timestamp');
            echo "  ✓ Query erfolgreich\n";
            if (!empty($result)) {
                echo "  Ergebnis: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
            }
        } catch (\Exception $e) {
            echo "  ✗ Query fehlgeschlagen: " . $e->getMessage() . "\n";
        }
        
        // Prüfe bestehende Daten
        echo "\n5. Bestehende Daten:\n";
        try {
            $orgCount = $neo4j->runSingle("MATCH (o:Org) RETURN count(o) as count");
            $personCount = $neo4j->runSingle("MATCH (p:Person) RETURN count(p) as count");
            echo "  - Organisationen: " . ($orgCount ?? 0) . "\n";
            echo "  - Personen: " . ($personCount ?? 0) . "\n";
        } catch (\Exception $e) {
            echo "  ⚠ Konnte Daten nicht abrufen: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "  ✗ Verbindung fehlgeschlagen\n";
    }
} catch (\Exception $e) {
    echo "  ✗ FEHLER: " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n";
    $trace = $e->getTraceAsString();
    $lines = explode("\n", $trace);
    foreach (array_slice($lines, 0, 5) as $line) {
        echo "    $line\n";
    }
}

echo "\n";

// Test 4: Alternative Verbindungsmethoden
echo "6. Alternative Verbindungsmethoden:\n";
$alternatives = [
    'bolt+s://' => str_replace('neo4j+s://', 'bolt+s://', $uri),
    'bolt://' => str_replace(['neo4j+s://', 'neo4j://'], 'bolt://', $uri),
    'neo4j://' => str_replace('neo4j+s://', 'neo4j://', $uri),
];

foreach ($alternatives as $type => $altUri) {
    if ($altUri === $uri) continue; // Überspringe wenn gleich
    
    echo "  Teste $type...\n";
    try {
        $testConfig = [
            'uri' => $altUri,
            'user' => $neo4jConfig['user'],
            'password' => $neo4jConfig['password']
        ];
        $testNeo4j = new Neo4jService($testConfig);
        if ($testNeo4j->testConnection()) {
            echo "  ✓ $type funktioniert!\n";
            echo "  → Empfehlung: Ändere URI in config/database.php zu: $altUri\n";
            break;
        }
    } catch (\Exception $e) {
        echo "  ✗ $type fehlgeschlagen: " . $e->getMessage() . "\n";
    }
}

echo "\n";


