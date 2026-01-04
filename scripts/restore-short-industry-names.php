<?php
/**
 * Stellt kurze Industry-Namen wieder her und entfernt lange
 * 
 * Strategie:
 * - Füge kurze Namen wieder hinzu (z.B. "Chemie" für C20)
 * - Entferne lange offizielle WZ-Namen
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

$db = DatabaseConnection::getInstance();

echo "=== Stelle kurze Industry-Namen wieder her ===\n\n";

// Dry-Run Modus
$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';

if ($dryRun) {
    echo "⚠️  DRY-RUN MODUS - Keine Änderungen werden durchgeführt\n";
    echo "   Führe mit --execute aus, um Änderungen anzuwenden\n\n";
} else {
    echo "✅ EXECUTE MODUS - Änderungen werden durchgeführt\n\n";
}

// Hole Level 1 Parent (C - Verarbeitendes Gewerbe)
$stmt = $db->prepare("
    SELECT industry_uuid 
    FROM industry 
    WHERE code = 'C' AND parent_industry_uuid IS NULL
    LIMIT 1
");
$stmt->execute();
$level1Parent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$level1Parent) {
    echo "❌ Level 1 Parent 'C - Verarbeitendes Gewerbe' nicht gefunden!\n";
    exit(1);
}

$parentUuid = $level1Parent['industry_uuid'];
echo "Level 1 Parent: {$parentUuid}\n\n";

// Mapping: Code => Kurzer Name
$shortNames = [
    'C10' => 'Lebensmittel',
    'C20' => 'Chemie',
    'C21' => 'Pharma',
    'C28' => 'Maschinenbau', // Bevorzuge Maschinenbau über Anlagenbau
    'H49' => 'Logistik'
];

// Hole Level 1 Parent für H49 (Transport)
$stmt = $db->prepare("
    SELECT industry_uuid 
    FROM industry 
    WHERE code = 'H' AND parent_industry_uuid IS NULL
    LIMIT 1
");
$stmt->execute();
$hParent = $stmt->fetch(PDO::FETCH_ASSOC);
$hParentUuid = $hParent ? $hParent['industry_uuid'] : $parentUuid;

$toDelete = [];
$toCreate = [];

foreach ($shortNames as $code => $shortName) {
    echo "Code: $code\n";
    echo str_repeat("-", 80) . "\n";
    
    // Prüfe, ob bereits ein Eintrag mit diesem Code existiert
    $stmt = $db->prepare("
        SELECT industry_uuid, name, code
        FROM industry
        WHERE code = ?
    ");
    $stmt->execute([$code]);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($existing)) {
        echo "❌ Kein Eintrag mit Code $code gefunden\n";
        continue;
    }
    
    $parentUuidForCode = ($code === 'H49') ? $hParentUuid : $parentUuid;
    
    foreach ($existing as $entry) {
        // Prüfe, ob es ein langer Name ist (Format: "CXX - Beschreibung")
        if (preg_match('/^' . preg_quote($code, '/') . '\s*-\s*/', $entry['name'])) {
            echo "  ❌ Zu löschender langer Name: {$entry['name']} [{$entry['industry_uuid']}]\n";
            $toDelete[] = $entry['industry_uuid'];
        } elseif ($entry['name'] === $shortName) {
            echo "  ✅ Kurzer Name bereits vorhanden: {$entry['name']} [{$entry['industry_uuid']}]\n";
        }
    }
    
    // Prüfe, ob kurzer Name bereits existiert
    $stmt = $db->prepare("
        SELECT industry_uuid 
        FROM industry 
        WHERE code = ? AND name = ?
    ");
    $stmt->execute([$code, $shortName]);
    $shortExists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shortExists) {
        echo "  ➕ Zu erstellender kurzer Name: $shortName\n";
        $toCreate[] = [
            'code' => $code,
            'name' => $shortName,
            'parent_uuid' => $parentUuidForCode
        ];
    }
    
    echo "\n";
}

// Führe Änderungen durch
if (!$dryRun) {
    $db->beginTransaction();
    
    try {
        // 1. Lösche lange Namen
        if (!empty($toDelete)) {
            echo "\n=== Lösche lange Namen ===\n";
            echo str_repeat("-", 80) . "\n";
            
            $deleted = 0;
            foreach ($toDelete as $uuid) {
                $stmt = $db->prepare("DELETE FROM industry WHERE industry_uuid = ?");
                $stmt->execute([$uuid]);
                $deleted += $stmt->rowCount();
            }
            echo "✅ $deleted lange Namen gelöscht\n";
        }
        
        // 2. Erstelle kurze Namen
        if (!empty($toCreate)) {
            echo "\n=== Erstelle kurze Namen ===\n";
            echo str_repeat("-", 80) . "\n";
            
            $created = 0;
            foreach ($toCreate as $entry) {
                $uuid = UuidHelper::generate($db);
                $stmt = $db->prepare("
                    INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $uuid,
                    $entry['name'],
                    $entry['code'],
                    $entry['parent_uuid']
                ]);
                $created++;
                echo "✅ Erstellt: {$entry['name']} [{$entry['code']}]\n";
            }
            echo "\n✅ $created kurze Namen erstellt\n";
        }
        
        $db->commit();
        echo "\n=== Änderungen erfolgreich durchgeführt ===\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "\n❌ Fehler: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "\n=== Zusammenfassung (Dry-Run) ===\n";
    echo "Zu löschende lange Namen: " . count($toDelete) . "\n";
    echo "Zu erstellende kurze Namen: " . count($toCreate) . "\n";
}

echo "\n=== Abgeschlossen ===\n";

