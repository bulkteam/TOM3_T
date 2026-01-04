<?php
/**
 * Führt Migration 054 aus: Industry Tabelle komplett neu aufsetzen
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Migration 054: Industry Tabelle komplett neu aufsetzen ===\n\n";

// Prüfe Force-Flag
$force = isset($argv[1]) && $argv[1] === '--force';

if (!$force) {
    echo "⚠️  WICHTIG: Diese Migration löscht ALLE Industry-Daten und setzt sie neu auf!\n";
    echo "   Bitte bestätigen Sie, dass keine Referenzen vorhanden sind.\n";
    echo "   Verwenden Sie --force, um die Bestätigung zu überspringen.\n\n";
}

// Prüfe Referenzen
echo "Prüfe Referenzen...\n";
$stmt = $db->query("SELECT COUNT(*) as count FROM org WHERE industry_level1_uuid IS NOT NULL OR industry_level2_uuid IS NOT NULL OR industry_level3_uuid IS NOT NULL OR industry_main_uuid IS NOT NULL OR industry_sub_uuid IS NOT NULL");
$orgRefs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM org_import_staging WHERE industry_resolution IS NOT NULL");
$stagingRefs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

if ($orgRefs > 0 || $stagingRefs > 0) {
    echo "⚠️  WARNUNG: Es gibt noch Referenzen!\n";
    echo "   - Organisationen mit Industries: $orgRefs\n";
    echo "   - Staging-Rows mit Industry-Resolution: $stagingRefs\n";
    
    if (!$force) {
        echo "\nMöchten Sie trotzdem fortfahren? (ja/nein): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($line) !== 'ja') {
            echo "Abgebrochen.\n";
            exit(0);
        }
    } else {
        echo "   ⚠️  --force Flag gesetzt, fahre fort...\n";
    }
} else {
    echo "✅ Keine Referenzen gefunden - sicher fortzufahren\n";
}
echo "\n";

use TOM\Infrastructure\Utils\UuidHelper;

$db->beginTransaction();

try {
    echo "\n1. Lösche alle bestehenden Industry-Daten...\n";
    $db->exec("DELETE FROM industry");
    echo "   ✅ Gelöscht\n";
    
    echo "\n2. Füge Level 1 (Branchenbereiche) ein...\n";
    
    // Level 1 Daten
    $level1Data = [
        ['A', 'A - Land- und Forstwirtschaft, Fischerei', 'Land- und Forstwirtschaft, Fischerei'],
        ['B', 'B - Bergbau und Gewinnung von Steinen und Erden', 'Bergbau und Gewinnung von Steinen und Erden'],
        ['C', 'C - Verarbeitendes Gewerbe', 'Verarbeitendes Gewerbe'],
        ['D', 'D - Energieversorgung', 'Energieversorgung'],
        ['E', 'E - Wasserversorgung; Abwasser- und Abfallentsorgung', 'Wasserversorgung; Abwasser- und Abfallentsorgung'],
        ['F', 'F - Baugewerbe', 'Baugewerbe'],
        ['G', 'G - Handel; Instandhaltung und Reparatur von Kraftfahrzeugen', 'Handel; Instandhaltung und Reparatur von Kraftfahrzeugen'],
        ['H', 'H - Verkehr und Lagerei', 'Verkehr und Lagerei'],
        ['I', 'I - Gastgewerbe', 'Gastgewerbe'],
        ['J', 'J - Information und Kommunikation', 'Information und Kommunikation'],
        ['K', 'K - Erbringung von Finanz- und Versicherungsdienstleistungen', 'Erbringung von Finanz- und Versicherungsdienstleistungen'],
        ['L', 'L - Grundstücks- und Wohnungswesen', 'Grundstücks- und Wohnungswesen'],
        ['M', 'M - Erbringung von freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'Erbringung von freiberuflichen, wissenschaftlichen und technischen Dienstleistungen'],
        ['N', 'N - Erbringung von sonstigen Dienstleistungen', 'Erbringung von sonstigen Dienstleistungen'],
        ['O', 'O - Öffentliche Verwaltung, Verteidigung; Sozialversicherung', 'Öffentliche Verwaltung, Verteidigung; Sozialversicherung'],
        ['P', 'P - Erziehung und Unterricht', 'Erziehung und Unterricht'],
        ['Q', 'Q - Gesundheits- und Sozialwesen', 'Gesundheits- und Sozialwesen'],
        ['R', 'R - Kunst, Unterhaltung und Erholung', 'Kunst, Unterhaltung und Erholung'],
        ['S', 'S - Erbringung von sonstigen Dienstleistungen', 'Erbringung von sonstigen Dienstleistungen'],
        ['T', 'T - Private Haushalte mit Hauspersonal', 'Private Haushalte mit Hauspersonal'],
        ['U', 'U - Exterritoriale Organisationen und Körperschaften', 'Exterritoriale Organisationen und Körperschaften']
    ];
    
    $parentUuids = [];
    $stmt = $db->prepare("INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid) VALUES (?, ?, ?, ?, NULL)");
    
    foreach ($level1Data as $data) {
        $uuid = UuidHelper::generate($db);
        $stmt->execute([$uuid, $data[1], $data[2], $data[0]]);
        $parentUuids[$data[0]] = $uuid;
    }
    echo "   ✅ " . count($level1Data) . " Level 1 Industries eingefügt\n";
    
    echo "\n3. Füge Level 2 (Branchen) ein...\n";
    
    // Level 2 Daten
    $level2Data = [
        // Verarbeitendes Gewerbe (C)
        ['C10', 'C10 - Herstellung von Nahrungs- und Futtermitteln', 'Lebensmittel', 'C'],
        ['C11', 'C11 - Herstellung von Getränken', 'Getränke', 'C'],
        ['C13', 'C13 - Herstellung von Textilien', 'Textil', 'C'],
        ['C17', 'C17 - Herstellung von Papier, Pappe und Waren daraus', 'Papier/Pappe', 'C'],
        ['C20', 'C20 - Herstellung von chemischen Erzeugnissen', 'Chemie', 'C'],
        ['C21', 'C21 - Herstellung von pharmazeutischen Erzeugnissen', 'Pharma', 'C'],
        ['C22', 'C22 - Herstellung von Gummi- und Kunststoffwaren', 'Kunststoff/Gummi', 'C'],
        ['C23', 'C23 - Herstellung von Glas und Glaswaren, Keramik, Verarbeitung von Steinen und Erden', 'Glas/Keramik', 'C'],
        ['C24', 'C24 - Herstellung von Metallen', 'Metalle', 'C'],
        ['C25', 'C25 - Herstellung von Metallerzeugnissen', 'Metallprodukte', 'C'],
        ['C26', 'C26 - Herstellung von Datenverarbeitungsgeräten, elektronischen und optischen Erzeugnissen', 'Elektronik/IT-Hardware', 'C'],
        ['C27', 'C27 - Herstellung von elektrischen Ausrüstungen', 'Elektrotechnik', 'C'],
        ['C28', 'C28 - Maschinenbau', 'Maschinenbau', 'C'],
        ['C29', 'C29 - Herstellung von Kraftwagen und Kraftwagenteilen', 'Automotive', 'C'],
        ['C30', 'C30 - Herstellung von sonstigen Fahrzeugen', 'Fahrzeugbau', 'C'],
        ['C31', 'C31 - Herstellung von Möbeln', 'Möbel', 'C'],
        ['C32', 'C32 - Herstellung von sonstigen Waren', 'Sonstige Waren', 'C'],
        ['C33', 'C33 - Reparatur und Installation von Maschinen und Ausrüstungen', 'Instandhaltung/Installation', 'C'],
        
        // Energie/Wasser/Abfall (D/E)
        ['D35', 'D35 - Energieversorgung', 'Energie', 'D'],
        ['E36', 'E36 - Wasserversorgung', 'Wasser', 'E'],
        ['E37', 'E37 - Abwasserentsorgung', 'Abwasser', 'E'],
        ['E38', 'E38 - Sammlung, Behandlung und Beseitigung von Abfällen; Rückgewinnung', 'Abfall/Entsorgung', 'E'],
        ['E39', 'E39 - Beseitigung von Umweltverschmutzungen und sonstige Entsorgung', 'Umwelt/Altlasten', 'E'],
        
        // Bau (F)
        ['F41', 'F41 - Erschließung von Grundstücken; Bauträger', 'Bauträger', 'F'],
        ['F42', 'F42 - Hochbau', 'Hochbau', 'F'],
        ['F43', 'F43 - Tiefbau', 'Tiefbau', 'F'],
        
        // Handel (G)
        ['G45', 'G45 - Handel mit Kraftfahrzeugen; Instandhaltung und Reparatur von Kraftfahrzeugen', 'Kfz-Handel/Service', 'G'],
        ['G46', 'G46 - Großhandel (ohne Handel mit Kraftfahrzeugen)', 'Großhandel', 'G'],
        ['G47', 'G47 - Einzelhandel (ohne Handel mit Kraftfahrzeugen)', 'Einzelhandel', 'G'],
        
        // Verkehr/Logistik (H) - KORRIGIERT
        ['H49', 'H49 - Landverkehr und Transport in Rohrfernleitungen', 'Landverkehr', 'H'],
        ['H50', 'H50 - Schifffahrt', 'Schifffahrt', 'H'],
        ['H51', 'H51 - Luftfahrt', 'Luftfahrt', 'H'],
        ['H52', 'H52 - Lagerei sowie Erbringung von sonstigen Dienstleistungen für den Verkehr', 'Logistik/Lagerei', 'H'],
        ['H53', 'H53 - Post-, Kurier- und Expressdienste', 'Post/Kurier/Express', 'H'],
        
        // Information/Kommunikation (J)
        ['J58', 'J58 - Verlagsaktivitäten', 'Verlage', 'J'],
        ['J59', 'J59 - Herstellung, Verleih und Vertrieb von Filmen und Fernsehprogrammen; Kino', 'Film/Video', 'J'],
        ['J60', 'J60 - Rundfunkveranstalter und -programmanbieter', 'Rundfunk', 'J'],
        ['J61', 'J61 - Telekommunikation', 'Telekommunikation', 'J'],
        ['J62', 'J62 - Erbringung von Dienstleistungen der Informationstechnologie', 'IT-Dienstleistungen', 'J'],
        ['J63', 'J63 - Informationsdienstleistungen', 'Informationsdienste', 'J'],
        
        // Finanzdienstleistungen (K)
        ['K64', 'K64 - Erbringung von Finanzdienstleistungen', 'Finanzdienstleistungen', 'K'],
        ['K65', 'K65 - Versicherungen, Rückversicherungen und Pensionskassen (ohne Sozialversicherung)', 'Versicherungen', 'K'],
        ['K66', 'K66 - Erbringung von Finanz- und Versicherungsdienstleistungen a. n. g.', 'Finanz-Services', 'K'],
        
        // Freiberufliche/Technische (M)
        ['M69', 'M69 - Erbringung von Rechts- und Steuerberatung, wirtschaftlicher Beratung und Unternehmensführung', 'Recht/Steuern', 'M'],
        ['M70', 'M70 - Erbringung von Dienstleistungen der Unternehmensführung und Managementberatung', 'Unternehmensberatung', 'M'],
        ['M71', 'M71 - Erbringung von Dienstleistungen der Architektur und Ingenieurbüros; technische, physikalische und chemische Untersuchung', 'Architektur/Ingenieur', 'M'],
        ['M72', 'M72 - Forschung und Entwicklung', 'F&E', 'M'],
        ['M73', 'M73 - Werbung und Marktforschung', 'Werbung/Marktforschung', 'M'],
        ['M74', 'M74 - Erbringung von sonstigen freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'Sonstige Freiberufliche', 'M'],
        ['M75', 'M75 - Veterinärwesen', 'Veterinär', 'M'],
        
        // Dienstleistungen (N)
        ['N77', 'N77 - Vermietung von beweglichen Sachen', 'Vermietung', 'N'],
        ['N78', 'N78 - Vermittlung und Überlassung von Arbeitskräften', 'Personal/Zeitarbeit', 'N'],
        ['N79', 'N79 - Reisebüros, Reiseveranstalter und Erbringung von sonstigen Reservierungsdienstleistungen', 'Reise', 'N'],
        ['N80', 'N80 - Erbringung von Sicherheits- und Bewachungsdienstleistungen', 'Sicherheit', 'N'],
        ['N81', 'N81 - Erbringung von Dienstleistungen für Gebäude und Grünanlagen', 'Gebäudeservice', 'N'],
        ['N82', 'N82 - Erbringung von wirtschaftlichen Dienstleistungen für Unternehmen und Privatpersonen a. n. g.', 'Backoffice-Services', 'N']
    ];
    
    $stmt = $db->prepare("INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid) VALUES (?, ?, ?, ?, ?)");
    $level2Count = 0;
    
    foreach ($level2Data as $data) {
        $parentCode = $data[3];
        if (!isset($parentUuids[$parentCode])) {
            echo "   ⚠️  Parent '$parentCode' nicht gefunden für {$data[0]}\n";
            continue;
        }
        
        $uuid = UuidHelper::generate($db);
        $stmt->execute([$uuid, $data[1], $data[2], $data[0], $parentUuids[$parentCode]]);
        $level2Count++;
    }
    
    echo "   ✅ $level2Count Level 2 Industries eingefügt\n";
    
    echo "  ✅ $level2Count Level 2 Industries eingefügt\n";
    
    // Erstelle Unique Index
    echo "\n4. Erstelle Unique Index...\n";
    try {
        // Prüfe, ob Index bereits existiert
        $stmt = $db->query("SHOW INDEX FROM industry WHERE Key_name = 'uq_industry_parent_code'");
        if ($stmt->rowCount() > 0) {
            echo "   ⚠️  Index bereits vorhanden, lösche und erstelle neu...\n";
            $db->exec("DROP INDEX uq_industry_parent_code ON industry");
        }
        $db->exec("CREATE UNIQUE INDEX uq_industry_parent_code ON industry(parent_industry_uuid, code)");
        echo "   ✅ Unique Index erstellt\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key') === false && strpos($e->getMessage(), 'already exists') === false) {
            throw $e;
        }
        echo "   ⚠️  Index bereits vorhanden\n";
    }
    
    if ($db->inTransaction()) {
        $db->commit();
    }
    echo "\n✅ Migration erfolgreich ausgeführt\n";
    
    // Prüfe Ergebnis (außerhalb der Transaction)
    echo "\n=== Prüfe Ergebnis ===\n";
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM industry WHERE parent_industry_uuid IS NULL");
    $level1 = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "Level 1 Industries: $level1\n";
    
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM industry i2
        INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
        WHERE i1.parent_industry_uuid IS NULL
    ");
    $level2 = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "Level 2 Industries: $level2\n";
    
    // Prüfe Duplikate
    $stmt = $db->query("
        SELECT parent_industry_uuid, code, COUNT(*) as count
        FROM industry
        WHERE code IS NOT NULL
        GROUP BY parent_industry_uuid, code
        HAVING COUNT(*) > 1
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($duplicates)) {
        echo "✅ Keine Duplikate\n";
    } else {
        echo "⚠️  Noch vorhandene Duplikate:\n";
        foreach ($duplicates as $dup) {
            echo "  - Code {$dup['code']}: {$dup['count']}x\n";
        }
    }
    
    // Prüfe name_short für Level 1
    $stmt = $db->query("
        SELECT industry_uuid, name, name_short
        FROM industry
        WHERE parent_industry_uuid IS NULL
          AND name_short LIKE 'und %'
    ");
    $badShort = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($badShort)) {
        echo "✅ Alle Level 1 name_short korrekt\n";
    } else {
        echo "⚠️  Level 1 mit falschem name_short:\n";
        foreach ($badShort as $bad) {
            echo "  - {$bad['name']} → {$bad['name_short']}\n";
        }
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "\n❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Migration abgeschlossen ===\n";

