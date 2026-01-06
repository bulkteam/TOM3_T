<?php
/**
 * Prüft, ob eine Beziehung zwischen Schmoll und Sternberg existiert
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/TOM/Infrastructure/Database/DatabaseConnection.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfe Beziehung zwischen Schmoll und Sternberg ===\n\n";

// Suche Personen
$stmt = $db->prepare("
    SELECT person_uuid, display_name, first_name, last_name, email
    FROM person
    WHERE display_name LIKE '%Schmoll%' 
       OR first_name LIKE '%Schmoll%' 
       OR last_name LIKE '%Schmoll%'
       OR display_name LIKE '%Sternberg%'
       OR first_name LIKE '%Sternberg%'
       OR last_name LIKE '%Sternberg%'
");
$stmt->execute();
$persons = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Gefundene Personen:\n";
foreach ($persons as $person) {
    echo "  - UUID: {$person['person_uuid']}\n";
    echo "    Name: {$person['display_name']}\n";
    echo "    Vorname: {$person['first_name']}\n";
    echo "    Nachname: {$person['last_name']}\n";
    echo "    Email: {$person['email']}\n";
    echo "\n";
}

if (count($persons) < 2) {
    echo "WARNUNG: Es wurden weniger als 2 Personen gefunden!\n";
    exit(1);
}

// Finde Schmoll und Sternberg
$schmoll = null;
$sternberg = null;

foreach ($persons as $person) {
    $name = strtolower($person['display_name'] . ' ' . $person['first_name'] . ' ' . $person['last_name']);
    if (strpos($name, 'schmoll') !== false) {
        $schmoll = $person;
    }
    if (strpos($name, 'sternberg') !== false) {
        $sternberg = $person;
    }
}

if (!$schmoll) {
    echo "FEHLER: Person 'Schmoll' nicht gefunden!\n";
    exit(1);
}

if (!$sternberg) {
    echo "FEHLER: Person 'Sternberg' nicht gefunden!\n";
    exit(1);
}

echo "Schmoll UUID: {$schmoll['person_uuid']}\n";
echo "Sternberg UUID: {$sternberg['person_uuid']}\n\n";

// Prüfe Beziehungen in beide Richtungen
echo "=== Prüfe Beziehungen ===\n\n";

$stmt = $db->prepare("
    SELECT 
        pr.*,
        pa.display_name as person_a_name,
        pb.display_name as person_b_name,
        o.name as context_org_name
    FROM person_relationship pr
    JOIN person pa ON pa.person_uuid = pr.person_a_uuid
    JOIN person pb ON pb.person_uuid = pr.person_b_uuid
    LEFT JOIN org o ON o.org_uuid = pr.context_org_uuid
    WHERE (pr.person_a_uuid = :uuid1 AND pr.person_b_uuid = :uuid2)
       OR (pr.person_a_uuid = :uuid2 AND pr.person_b_uuid = :uuid1)
");
$stmt->execute([
    'uuid1' => $schmoll['person_uuid'],
    'uuid2' => $sternberg['person_uuid']
]);
$relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($relationships)) {
    echo "KEINE Beziehung gefunden zwischen:\n";
    echo "  - {$schmoll['display_name']} ({$schmoll['person_uuid']})\n";
    echo "  - {$sternberg['display_name']} ({$sternberg['person_uuid']})\n";
} else {
    echo "Gefundene Beziehungen (" . count($relationships) . "):\n\n";
    foreach ($relationships as $rel) {
        echo "Relationship UUID: {$rel['relationship_uuid']}\n";
        echo "Person A: {$rel['person_a_name']} ({$rel['person_a_uuid']})\n";
        echo "Person B: {$rel['person_b_name']} ({$rel['person_b_uuid']})\n";
        echo "Beziehungstyp: {$rel['relation_type']}\n";
        echo "Richtung: {$rel['direction']}\n";
        echo "Stärke: " . ($rel['strength'] ?? 'N/A') . "\n";
        echo "Vertrauen: " . ($rel['confidence'] ?? 'N/A') . "\n";
        echo "Kontext Org: " . ($rel['context_org_name'] ?? 'N/A') . "\n";
        echo "Startdatum: " . ($rel['start_date'] ?? 'N/A') . "\n";
        echo "Enddatum: " . ($rel['end_date'] ?? 'N/A') . "\n";
        echo "Notizen: " . ($rel['notes'] ?? 'N/A') . "\n";
        echo "Erstellt: {$rel['created_at']}\n";
        echo "\n";
    }
}

// Prüfe auch alle Beziehungen von beiden Personen
echo "\n=== Alle Beziehungen von Schmoll ===\n";
$stmt = $db->prepare("
    SELECT 
        pr.*,
        CASE 
            WHEN pr.person_a_uuid = :person_uuid THEN pb.display_name
            ELSE pa.display_name
        END as other_person_name,
        o.name as context_org_name
    FROM person_relationship pr
    JOIN person pa ON pa.person_uuid = pr.person_a_uuid
    JOIN person pb ON pb.person_uuid = pr.person_b_uuid
    LEFT JOIN org o ON o.org_uuid = pr.context_org_uuid
    WHERE (pr.person_a_uuid = :person_uuid OR pr.person_b_uuid = :person_uuid)
    ORDER BY pr.created_at DESC
");
$stmt->execute(['person_uuid' => $schmoll['person_uuid']]);
$allSchmollRels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Anzahl: " . count($allSchmollRels) . "\n";
foreach ($allSchmollRels as $rel) {
    echo "  - {$rel['other_person_name']} ({$rel['relation_type']}, {$rel['direction']})\n";
}

echo "\n=== Alle Beziehungen von Sternberg ===\n";
$stmt->execute(['person_uuid' => $sternberg['person_uuid']]);
$allSternbergRels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Anzahl: " . count($allSternbergRels) . "\n";
foreach ($allSternbergRels as $rel) {
    echo "  - {$rel['other_person_name']} ({$rel['relation_type']}, {$rel['direction']})\n";
}

