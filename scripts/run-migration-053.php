<?php
/**
 * Führt Migration 053 aus: Industry name_short Support
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Migration 053: Industry name_short Support ===\n\n";

$migrationFile = __DIR__ . '/../database/migrations/053_industry_name_short_mysql.sql';

if (!file_exists($migrationFile)) {
    echo "❌ Migrationsdatei nicht gefunden: $migrationFile\n";
    exit(1);
}

try {
    if (!$db->inTransaction()) {
        $db->beginTransaction();
    }
    $executed = 0;
    
    // 1. Füge name_short Spalte hinzu
    echo "1. Füge name_short Spalte hinzu...\n";
    try {
        $db->exec("ALTER TABLE industry ADD COLUMN name_short VARCHAR(120) NULL AFTER name");
        echo "   ✅ Spalte hinzugefügt\n";
        $executed++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "   ⚠️  Spalte bereits vorhanden\n";
        } else {
            throw $e;
        }
    }
    
    // 2. Erstelle Mapping-Tabelle
    echo "\n2. Erstelle Mapping-Tabelle...\n";
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS industry_code_shortname (
              code VARCHAR(10) PRIMARY KEY,
              name_short VARCHAR(120) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "   ✅ Tabelle erstellt\n";
        $executed++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "   ⚠️  Tabelle bereits vorhanden\n";
        } else {
            throw $e;
        }
    }
    
    // 3. Befülle Mapping-Tabelle
    echo "\n3. Befülle Mapping-Tabelle...\n";
    $mappings = [
        ['C10', 'Lebensmittel'], ['C11', 'Getränke'], ['C13', 'Textil'], ['C17', 'Papier/Pappe'],
        ['C20', 'Chemie'], ['C21', 'Pharma'], ['C22', 'Kunststoff/Gummi'], ['C23', 'Glas/Keramik'],
        ['C24', 'Metalle'], ['C25', 'Metallprodukte'], ['C26', 'Elektronik/IT-Hardware'], ['C27', 'Elektrotechnik'],
        ['C28', 'Maschinenbau'], ['C29', 'Automotive'], ['C30', 'Fahrzeugbau'], ['C31', 'Möbel'],
        ['C32', 'Sonstige Waren'], ['C33', 'Instandhaltung/Installation'],
        ['D35', 'Energie'], ['E36', 'Wasser'], ['E37', 'Abwasser'], ['E38', 'Abfall/Entsorgung'], ['E39', 'Umwelt/Altlasten'],
        ['F41', 'Bauträger'], ['F42', 'Tiefbau'], ['F43', 'Bauinstallation'],
        ['G45', 'Kfz-Handel/Service'], ['G46', 'Großhandel'], ['G47', 'Einzelhandel'],
        ['H49', 'Landverkehr'], ['H50', 'Schifffahrt'], ['H51', 'Luftfahrt'], ['H52', 'Logistik/Lagerei'], ['H53', 'Post/Kurier/Express'],
        ['J58', 'Verlage'], ['J59', 'Film/Video'], ['J60', 'Rundfunk'], ['J61', 'Telekommunikation'], ['J62', 'IT-Dienstleistungen'], ['J63', 'Informationsdienste'],
        ['K64', 'Finanzdienstleistungen'], ['K65', 'Versicherungen'], ['K66', 'Finanz-Services'],
        ['M69', 'Recht/Steuern'], ['M70', 'Unternehmensberatung'], ['M71', 'Architektur/Ingenieur'], ['M72', 'F&E'], ['M73', 'Werbung/Marktforschung'], ['M74', 'Sonstige Freiberufliche'], ['M75', 'Veterinär'],
        ['N77', 'Vermietung'], ['N78', 'Personal/Zeitarbeit'], ['N79', 'Reise'], ['N80', 'Sicherheit'], ['N81', 'Gebäudeservice'], ['N82', 'Backoffice-Services']
    ];
    
    $stmt = $db->prepare("INSERT INTO industry_code_shortname(code, name_short) VALUES (?, ?) ON DUPLICATE KEY UPDATE name_short = VALUES(name_short)");
    $inserted = 0;
    foreach ($mappings as $mapping) {
        $stmt->execute($mapping);
        $inserted++;
    }
    echo "   ✅ $inserted Mappings eingefügt/aktualisiert\n";
    $executed++;
    
    // 4. Update industry Tabelle
    echo "\n4. Update industry Tabelle mit Kurznamen...\n";
    $stmt = $db->prepare("
        UPDATE industry i
        INNER JOIN industry_code_shortname m ON m.code = i.code
        SET i.name_short = m.name_short
        WHERE i.code IS NOT NULL
    ");
    $stmt->execute();
    $updated = $stmt->rowCount();
    echo "   ✅ $updated Industries aktualisiert\n";
    $executed++;
    
    // 5. Level 1: Kurznamen extrahieren
    echo "\n5. Extrahiere Level 1 Kurznamen...\n";
    $stmt = $db->prepare("
        UPDATE industry
        SET name_short = TRIM(SUBSTRING_INDEX(name, '-', -1))
        WHERE parent_industry_uuid IS NULL 
          AND name LIKE '% - %'
          AND (name_short IS NULL OR name_short = '')
    ");
    $stmt->execute();
    $level1Updated = $stmt->rowCount();
    echo "   ✅ $level1Updated Level 1 Industries aktualisiert\n";
    $executed++;
    
    // 6. Bereinige Duplikate (C28)
    echo "\n6. Bereinige Duplikate...\n";
    $stmt = $db->prepare("
        DELETE i1 FROM industry i1
        INNER JOIN industry i2 ON i1.code = i2.code AND i1.parent_industry_uuid = i2.parent_industry_uuid
        WHERE i1.code = 'C28'
          AND i1.name_short IS NULL
          AND i2.name_short IS NOT NULL
          AND i1.industry_uuid != i2.industry_uuid
    ");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "   ✅ $deleted Duplikate gelöscht\n";
    $executed++;
    
    if ($db->inTransaction()) {
        $db->commit();
    }
    echo "✅ Migration erfolgreich ausgeführt ($executed Statements)\n";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

// Prüfe Ergebnis (außerhalb der Transaction)
echo "\n=== Prüfe Ergebnis ===\n";

$stmt = $db->query("SELECT COUNT(*) as count FROM industry WHERE name_short IS NOT NULL");
$withShort = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Industries mit name_short: $withShort\n";

$stmt = $db->query("SELECT COUNT(*) as count FROM industry_code_shortname");
$mappings = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "Kurzname-Mappings: $mappings\n";

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
    echo "✅ Keine Duplikate mehr vorhanden\n";
} else {
    echo "⚠️  Noch vorhandene Duplikate:\n";
    foreach ($duplicates as $dup) {
        echo "  - Code {$dup['code']} unter Parent: {$dup['count']}x\n";
    }
}

echo "\n=== Migration abgeschlossen ===\n";

