<?php
/**
 * TOM3 - Migration 022 ausführen
 * 
 * Migriert bestehende Daten:
 * - project_partner → project_party
 * - project_stakeholder → project_person
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$migrationFile = __DIR__ . '/../database/migrations/022_migrate_project_partner_to_party_mysql.sql';

if (!file_exists($migrationFile)) {
    die("Migration-Datei nicht gefunden: $migrationFile\n");
}

echo "Führe Migration 022 aus: Datenmigration Project Partner → Party\n";
echo "================================================================\n\n";

// Prüfe, ob Migration 021 bereits ausgeführt wurde
$checkTables = $db->query("
    SELECT COUNT(*) as count
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('project_party', 'project_person')
")->fetch();

if ($checkTables['count'] < 2) {
    die("✗ Fehler: Migration 021 muss zuerst ausgeführt werden!\n");
}

// Zeige Statistiken vor Migration
echo "Aktuelle Daten:\n";
$statsBefore = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM project_partner) as old_partners,
        (SELECT COUNT(*) FROM project_party) as new_parties,
        (SELECT COUNT(*) FROM project_stakeholder) as old_stakeholders,
        (SELECT COUNT(*) FROM project_person) as new_persons
")->fetch();

echo "  - project_partner: {$statsBefore['old_partners']} Einträge\n";
echo "  - project_party: {$statsBefore['new_parties']} Einträge\n";
echo "  - project_stakeholder: {$statsBefore['old_stakeholders']} Einträge\n";
echo "  - project_person: {$statsBefore['new_persons']} Einträge\n";
echo "\n";

// Frage Bestätigung
echo "Warnung: Diese Migration migriert bestehende Daten.\n";
echo "Möchtest du fortfahren? (j/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim(strtolower($line)) !== 'j' && trim(strtolower($line)) !== 'y' && trim(strtolower($line)) !== 'ja') {
    echo "Migration abgebrochen.\n";
    exit(0);
}

try {
    // Lese SQL-Datei
    $sql = file_get_contents($migrationFile);
    
    // Teile in einzelne Statements (getrennt durch ;)
    // Entferne Kommentare und leere Zeilen
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    $db->beginTransaction();
    
    $executed = 0;
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        try {
            $db->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            // Ignoriere "Duplicate entry" Fehler (Migration wurde bereits ausgeführt)
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "Warnung: Eintrag existiert bereits (wird übersprungen)\n";
                continue;
            }
            throw $e;
        }
    }
    
    $db->commit();
    
    echo "\n✓ Migration erfolgreich ausgeführt\n";
    echo "  - $executed SQL-Statements ausgeführt\n";
    echo "\n";
    
    // Zeige Statistiken nach Migration
    echo "Daten nach Migration:\n";
    $statsAfter = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM project_partner) as old_partners,
            (SELECT COUNT(*) FROM project_party) as new_parties,
            (SELECT COUNT(*) FROM project_stakeholder) as old_stakeholders,
            (SELECT COUNT(*) FROM project_person) as new_persons
    ")->fetch();
    
    echo "  - project_partner: {$statsAfter['old_partners']} Einträge (unverändert)\n";
    echo "  - project_party: {$statsAfter['new_parties']} Einträge\n";
    echo "  - project_stakeholder: {$statsAfter['old_stakeholders']} Einträge (unverändert)\n";
    echo "  - project_person: {$statsAfter['new_persons']} Einträge\n";
    echo "\n";
    
    // Prüfe Personen ohne project_party_uuid
    $personsWithoutParty = $db->query("
        SELECT COUNT(*) as count
        FROM project_person
        WHERE project_party_uuid IS NULL
    ")->fetch();
    
    if ($personsWithoutParty['count'] > 0) {
        echo "Hinweis: {$personsWithoutParty['count']} Projektperson(en) ohne project_party_uuid.\n";
        echo "  Diese Personen konnten keiner Projektpartei zugeordnet werden.\n";
        echo "  (Möglicherweise gehören sie zur Owner-Firma oder haben keine aktive Affiliation.)\n";
    }
    
    echo "\n";
    echo "Die alten Tabellen bleiben erhalten für Backward Compatibility.\n";
    echo "Sie können später entfernt werden, wenn alle Code-Stellen umgestellt sind.\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n✗ Fehler bei Migration:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "\n";
    echo "Rollback durchgeführt.\n";
    exit(1);
}


