<?php
/**
 * TOM3 - Duplikaten-Housekeeper
 * Prüft auf potenzielle Duplikate in Organisationen und Personen
 * Wird täglich per Windows Task Scheduler ausgeführt
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'org_duplicates' => [],
    'person_duplicates' => [],
    'summary' => [
        'org_count' => 0,
        'person_count' => 0,
        'total_pairs' => 0
    ]
];

echo "=== TOM3 Duplikaten-Prüfung ===\n";
echo "Datum: " . date('Y-m-d H:i:s') . "\n\n";

// ============================================================================
// Organisationen: Name + PLZ (Hauptadresse)
// ============================================================================
echo "Prüfe Organisationen: Name + PLZ...\n";
try {
    $stmt = $db->query("
        SELECT 
            o1.org_uuid as org_uuid_1,
            o1.name as name_1,
            o2.org_uuid as org_uuid_2,
            o2.name as name_2,
            a1.postal_code as postal_code,
            'name_plz' as match_type
        FROM org o1
        JOIN org_address a1 ON a1.org_uuid = o1.org_uuid AND a1.is_primary = 1
        JOIN org o2 ON o2.org_uuid != o1.org_uuid
        JOIN org_address a2 ON a2.org_uuid = o2.org_uuid AND a2.is_primary = 1
        WHERE LOWER(TRIM(o1.name)) = LOWER(TRIM(o2.name))
          AND a1.postal_code = a2.postal_code
          AND a1.postal_code IS NOT NULL
          AND a1.postal_code != ''
          AND o1.is_active = 1
          AND o2.is_active = 1
          AND o1.org_uuid < o2.org_uuid
    ");
    $orgNamePlz = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['org_duplicates'] = array_merge($results['org_duplicates'], $orgNamePlz);
    echo "  Gefunden: " . count($orgNamePlz) . " Duplikat-Paare\n";
} catch (Exception $e) {
    echo "  Fehler: " . $e->getMessage() . "\n";
}

// ============================================================================
// Organisationen: E-Mail (Kommunikationskanal)
// ============================================================================
echo "Prüfe Organisationen: E-Mail...\n";
try {
    $stmt = $db->query("
        SELECT DISTINCT
            o1.org_uuid as org_uuid_1,
            o1.name as name_1,
            o2.org_uuid as org_uuid_2,
            o2.name as name_2,
            c1.value as match_value,
            'email' as match_type
        FROM org o1
        JOIN org_communication_channel c1 ON c1.org_uuid = o1.org_uuid AND c1.channel_type = 'email'
        JOIN org o2 ON o2.org_uuid != o1.org_uuid
        JOIN org_communication_channel c2 ON c2.org_uuid = o2.org_uuid AND c2.channel_type = 'email'
        WHERE LOWER(TRIM(c1.value)) = LOWER(TRIM(c2.value))
          AND c1.value IS NOT NULL
          AND c1.value != ''
          AND o1.is_active = 1
          AND o2.is_active = 1
          AND o1.org_uuid < o2.org_uuid
    ");
    $orgEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['org_duplicates'] = array_merge($results['org_duplicates'], $orgEmail);
    echo "  Gefunden: " . count($orgEmail) . " Duplikat-Paare\n";
} catch (Exception $e) {
    echo "  Fehler: " . $e->getMessage() . "\n";
}

// ============================================================================
// Organisationen: Telefonnummer (Kommunikationskanal)
// ============================================================================
echo "Prüfe Organisationen: Telefonnummer...\n";
try {
    $stmt = $db->query("
        SELECT DISTINCT
            o1.org_uuid as org_uuid_1,
            o1.name as name_1,
            o2.org_uuid as org_uuid_2,
            o2.name as name_2,
            c1.value as match_value,
            'phone' as match_type
        FROM org o1
        JOIN org_communication_channel c1 ON c1.org_uuid = o1.org_uuid AND c1.channel_type = 'phone'
        JOIN org o2 ON o2.org_uuid != o1.org_uuid
        JOIN org_communication_channel c2 ON c2.org_uuid = o2.org_uuid AND c2.channel_type = 'phone'
        WHERE LOWER(TRIM(c1.value)) = LOWER(TRIM(c2.value))
          AND c1.value IS NOT NULL
          AND c1.value != ''
          AND o1.is_active = 1
          AND o2.is_active = 1
          AND o1.org_uuid < o2.org_uuid
    ");
    $orgPhone = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['org_duplicates'] = array_merge($results['org_duplicates'], $orgPhone);
    echo "  Gefunden: " . count($orgPhone) . " Duplikat-Paare\n";
} catch (Exception $e) {
    echo "  Fehler: " . $e->getMessage() . "\n";
}

// ============================================================================
// Organisationen: Website
// ============================================================================
echo "Prüfe Organisationen: Website...\n";
try {
    $stmt = $db->query("
        SELECT 
            o1.org_uuid as org_uuid_1,
            o1.name as name_1,
            o2.org_uuid as org_uuid_2,
            o2.name as name_2,
            o1.website as match_value,
            'website' as match_type
        FROM org o1
        JOIN org o2 ON o2.org_uuid != o1.org_uuid
        WHERE LOWER(TRIM(o1.website)) = LOWER(TRIM(o2.website))
          AND o1.website IS NOT NULL
          AND o1.website != ''
          AND o1.is_active = 1
          AND o2.is_active = 1
          AND o1.org_uuid < o2.org_uuid
    ");
    $orgWebsite = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['org_duplicates'] = array_merge($results['org_duplicates'], $orgWebsite);
    echo "  Gefunden: " . count($orgWebsite) . " Duplikat-Paare\n";
} catch (Exception $e) {
    echo "  Fehler: " . $e->getMessage() . "\n";
}

// ============================================================================
// Personen: E-Mail
// ============================================================================
echo "Prüfe Personen: E-Mail...\n";
try {
    $stmt = $db->query("
        SELECT 
            p1.person_uuid as person_uuid_1,
            CONCAT(p1.first_name, ' ', p1.last_name) as name_1,
            p2.person_uuid as person_uuid_2,
            CONCAT(p2.first_name, ' ', p2.last_name) as name_2,
            p1.email as match_value,
            'email' as match_type
        FROM person p1
        JOIN person p2 ON p2.person_uuid != p1.person_uuid
        WHERE LOWER(TRIM(p1.email)) = LOWER(TRIM(p2.email))
          AND p1.email IS NOT NULL
          AND p1.email != ''
          AND p1.is_active = 1
          AND p2.is_active = 1
          AND p1.person_uuid < p2.person_uuid
    ");
    $personEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['person_duplicates'] = array_merge($results['person_duplicates'], $personEmail);
    echo "  Gefunden: " . count($personEmail) . " Duplikat-Paare\n";
} catch (Exception $e) {
    echo "  Fehler: " . $e->getMessage() . "\n";
}

// ============================================================================
// Personen: Name + E-Mail (Kombination)
// ============================================================================
echo "Prüfe Personen: Name + E-Mail...\n";
try {
    $stmt = $db->query("
        SELECT 
            p1.person_uuid as person_uuid_1,
            CONCAT(p1.first_name, ' ', p1.last_name) as name_1,
            p2.person_uuid as person_uuid_2,
            CONCAT(p2.first_name, ' ', p2.last_name) as name_2,
            p1.email as match_value,
            'name_email' as match_type
        FROM person p1
        JOIN person p2 ON p2.person_uuid != p1.person_uuid
        WHERE LOWER(TRIM(p1.first_name)) = LOWER(TRIM(p2.first_name))
          AND LOWER(TRIM(p1.last_name)) = LOWER(TRIM(p2.last_name))
          AND LOWER(TRIM(p1.email)) = LOWER(TRIM(p2.email))
          AND p1.email IS NOT NULL
          AND p1.is_active = 1
          AND p2.is_active = 1
          AND p1.person_uuid < p2.person_uuid
    ");
    $personNameEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Nur hinzufügen, wenn nicht bereits durch E-Mail-Prüfung gefunden
    $existingEmails = array_column($personEmail, 'match_value');
    foreach ($personNameEmail as $dup) {
        if (!in_array($dup['match_value'], $existingEmails)) {
            $results['person_duplicates'][] = $dup;
        }
    }
    echo "  Gefunden: " . count($personNameEmail) . " Duplikat-Paare (zusätzlich)\n";
} catch (Exception $e) {
    echo "  Fehler: " . $e->getMessage() . "\n";
}

// ============================================================================
// Zusammenfassung
// ============================================================================
$results['summary']['org_count'] = count($results['org_duplicates']);
$results['summary']['person_count'] = count($results['person_duplicates']);
$results['summary']['total_pairs'] = $results['summary']['org_count'] + $results['summary']['person_count'];

echo "\n=== Zusammenfassung ===\n";
echo "Org-Duplikate: " . $results['summary']['org_count'] . "\n";
echo "Person-Duplikate: " . $results['summary']['person_count'] . "\n";
echo "Gesamt: " . $results['summary']['total_pairs'] . " Duplikat-Paare\n\n";

// ============================================================================
// Speichere Ergebnisse in Datenbank
// ============================================================================
try {
    $stmt = $db->prepare("
        INSERT INTO duplicate_check_results (
            check_date, org_duplicates, person_duplicates, total_pairs, results_json
        ) VALUES (
            NOW(), :org_count, :person_count, :total_pairs, :results_json
        )
    ");
    $stmt->execute([
        'org_count' => $results['summary']['org_count'],
        'person_count' => $results['summary']['person_count'],
        'total_pairs' => $results['summary']['total_pairs'],
        'results_json' => json_encode($results, JSON_UNESCAPED_UNICODE)
    ]);
    echo "✓ Ergebnisse in Datenbank gespeichert\n";
} catch (Exception $e) {
    echo "✗ Fehler beim Speichern: " . $e->getMessage() . "\n";
    exit(1);
}

// Ausgabe für Logging (optional, wenn als Cronjob ausgeführt)
if (php_sapi_name() === 'cli') {
    echo "\n=== Vollständige Ergebnisse ===\n";
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

exit(0);


