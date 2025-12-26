<?php
/**
 * Neo4j Constraints Setup für TOM3
 * Erstellt die notwendigen Constraints in der Neo4j-Datenbank
 */

require __DIR__ . '/../vendor/autoload.php';

use Laudis\Neo4j\ClientBuilder;

error_reporting(E_ALL);
ini_set('display_errors', 1);

$config = require __DIR__ . '/../config/database.php';
$neo4j = $config['neo4j'] ?? null;

if (!$neo4j) {
    echo "✗ FEHLER: Neo4j-Konfiguration nicht gefunden in config/database.php\n";
    exit(1);
}

echo "=== Neo4j Constraints Setup ===\n\n";
echo "Konfiguration:\n";
echo "  URI: " . $neo4j['uri'] . "\n";
echo "  User: " . $neo4j['user'] . "\n";
echo "  Password: " . (empty($neo4j['password']) ? '(leer)' : str_repeat('*', strlen($neo4j['password']))) . "\n\n";

try {
    echo "Versuche Verbindung zu Neo4j...\n";
    
    // Erstelle vollständige URI mit Credentials für Remote-Verbindungen
    $uri = $neo4j['uri'];
    if (strpos($uri, '@') === false) {
        preg_match('/^([a-z+]+:\/\/)/', $uri, $matches);
        $schema = $matches[1] ?? 'neo4j+s://';
        $host = str_replace($schema, '', $uri);
        $uri = $schema . $neo4j['user'] . ':' . $neo4j['password'] . '@' . $host;
    }
    
    // Bestimme Driver-Typ basierend auf URI-Schema
    $driverType = 'default';
    if (strpos($uri, 'bolt://') === 0 || strpos($uri, 'bolt+s://') === 0) {
        $driverType = 'bolt';
    } elseif (strpos($uri, 'neo4j://') === 0 || strpos($uri, 'neo4j+s://') === 0) {
        $driverType = 'neo4j';
    }
    
    $client = ClientBuilder::create()
        ->withDriver($driverType, $uri)
        ->build();
    
    echo "✓ Verbindung erfolgreich!\n\n";
    
    // Prüfe bestehende Constraints
    echo "Prüfe bestehende Constraints...\n";
    $existingConstraints = $client->run('SHOW CONSTRAINTS');
    $existingNames = [];
    foreach ($existingConstraints as $record) {
        $name = $record->get('name') ?? null;
        if ($name) {
            $existingNames[] = $name;
        }
    }
    
    if (count($existingNames) > 0) {
        echo "Gefundene Constraints:\n";
        foreach ($existingNames as $name) {
            echo "  - $name\n";
        }
        echo "\n";
    } else {
        echo "Keine Constraints gefunden.\n\n";
    }
    
    // Lade Constraints aus Datei
    $constraintsFile = __DIR__ . '/../database/neo4j/constraints.cypher';
    if (!file_exists($constraintsFile)) {
        throw new \RuntimeException("Constraints-Datei nicht gefunden: $constraintsFile");
    }
    
    $constraintsContent = file_get_contents($constraintsFile);
    
    // Definiere die Constraints direkt (einfacher als Parsing)
    $constraints = [
        [
            'name' => 'org_uuid',
            'statement' => 'CREATE CONSTRAINT org_uuid IF NOT EXISTS FOR (o:Org) REQUIRE o.uuid IS UNIQUE'
        ],
        [
            'name' => 'person_uuid',
            'statement' => 'CREATE CONSTRAINT person_uuid IF NOT EXISTS FOR (p:Person) REQUIRE p.uuid IS UNIQUE'
        ],
        [
            'name' => 'project_uuid',
            'statement' => 'CREATE CONSTRAINT project_uuid IF NOT EXISTS FOR (pr:Project) REQUIRE pr.uuid IS UNIQUE'
        ],
        [
            'name' => 'case_uuid',
            'statement' => 'CREATE CONSTRAINT case_uuid IF NOT EXISTS FOR (c:Case) REQUIRE c.uuid IS UNIQUE'
        ]
    ];
    
    echo "Erstelle Constraints...\n\n";
    $created = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($constraints as $constraint) {
        $constraintName = $constraint['name'];
        $statement = $constraint['statement'];
        
        // Prüfe ob bereits vorhanden
        if (in_array($constraintName, $existingNames)) {
            echo "  ⏭  $constraintName - bereits vorhanden, überspringe\n";
            $skipped++;
            continue;
        }
        
        try {
            echo "  → Erstelle $constraintName...\n";
            $client->run($statement);
            echo "  ✓ $constraintName - erfolgreich erstellt\n";
            $created++;
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            // IF NOT EXISTS könnte einen Fehler werfen, wenn bereits vorhanden - das ist OK
            if (strpos($errorMsg, 'already exists') !== false || 
                strpos($errorMsg, 'equivalent constraint') !== false) {
                echo "  ⏭  $constraintName - bereits vorhanden (durch IF NOT EXISTS erkannt)\n";
                $skipped++;
            } else {
                echo "  ✗ FEHLER bei $constraintName: $errorMsg\n";
                $errors++;
            }
        }
    }
    
    echo "\n=== Zusammenfassung ===\n";
    echo "Erstellt: $created\n";
    echo "Übersprungen: $skipped\n";
    if ($errors > 0) {
        echo "Fehler: $errors\n";
    }
    
    // Prüfe finale Constraints
    echo "\nPrüfe finale Constraints...\n";
    $finalConstraints = $client->run('SHOW CONSTRAINTS');
    $finalCount = count($finalConstraints);
    echo "✓ Insgesamt $finalCount Constraint(s) vorhanden:\n";
    foreach ($finalConstraints as $record) {
        $name = $record->get('name') ?? 'unnamed';
        $type = $record->get('type') ?? 'unknown';
        echo "  - $name ($type)\n";
    }
    
    echo "\n✓ Constraints-Setup abgeschlossen!\n";
    
} catch (\Exception $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    echo "\nMögliche Ursachen:\n";
    echo "  1. Neo4j-Verbindung fehlgeschlagen\n";
    echo "  2. Credentials falsch (prüfe config/database.php)\n";
    echo "  3. Netzwerkprobleme (bei Remote-DB)\n";
    exit(1);
}

