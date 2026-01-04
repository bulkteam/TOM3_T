<?php
/**
 * Bereinigt Duplikate in der Industry-Tabelle
 * 
 * Strategie:
 * - Behalte die offiziellen WZ-Namen (Format: "CXX - Beschreibung")
 * - Entferne die kurzen Namen (z.B. "Chemie", "Maschinenbau")
 * - Prüfe, ob die kurzen Namen in Verwendung sind (Referenzen)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Bereinige Industry-Duplikate ===\n\n";

// Dry-Run Modus (nur anzeigen, nicht löschen)
$dryRun = !isset($argv[1]) || $argv[1] !== '--execute';

if ($dryRun) {
    echo "⚠️  DRY-RUN MODUS - Keine Änderungen werden durchgeführt\n";
    echo "   Führe mit --execute aus, um Änderungen anzuwenden\n\n";
} else {
    echo "✅ EXECUTE MODUS - Änderungen werden durchgeführt\n\n";
}

// Finde alle Duplikate nach Code
$stmt = $db->query("
    SELECT code, COUNT(*) as count
    FROM industry
    WHERE code IS NOT NULL AND code != ''
    GROUP BY code
    HAVING COUNT(*) > 1
    ORDER BY code
");
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "✅ Keine Duplikate gefunden\n";
    exit(0);
}

echo "Gefundene Duplikate: " . count($duplicates) . "\n\n";

$toDelete = [];
$toKeep = [];

foreach ($duplicates as $dup) {
    $code = $dup['code'];
    echo "Code: $code\n";
    echo str_repeat("-", 80) . "\n";
    
    // Hole alle Einträge mit diesem Code
    $stmt = $db->prepare("
        SELECT 
            industry_uuid,
            name,
            code,
            parent_industry_uuid,
            created_at
        FROM industry
        WHERE code = ?
        ORDER BY created_at
    ");
    $stmt->execute([$code]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Entscheide, welche behalten werden sollen
    // Bevorzuge: Offizielle WZ-Namen (Format: "CXX - Beschreibung")
    $officialEntries = [];
    $shortEntries = [];
    
    foreach ($entries as $entry) {
        // Prüfe, ob es ein offizieller WZ-Name ist (beginnt mit Code)
        if (preg_match('/^' . preg_quote($code, '/') . '\s*-\s*/', $entry['name'])) {
            $officialEntries[] = $entry;
        } else {
            $shortEntries[] = $entry;
        }
    }
    
    // Wenn es offizielle Einträge gibt, behalte diese
    if (!empty($officialEntries)) {
        echo "  ✅ Behalte offizielle WZ-Namen:\n";
        foreach ($officialEntries as $entry) {
            echo "    - {$entry['name']} [{$entry['industry_uuid']}]\n";
            $toKeep[] = $entry['industry_uuid'];
        }
        
        echo "  ❌ Entferne kurze Namen:\n";
        foreach ($shortEntries as $entry) {
            echo "    - {$entry['name']} [{$entry['industry_uuid']}]\n";
            $toDelete[] = $entry['industry_uuid'];
        }
    } else {
        // Wenn keine offiziellen Einträge, behalte den ersten
        echo "  ⚠️  Keine offiziellen WZ-Namen gefunden, behalte ersten Eintrag:\n";
        echo "    - {$entries[0]['name']} [{$entries[0]['industry_uuid']}]\n";
        $toKeep[] = $entries[0]['industry_uuid'];
        
        echo "  ❌ Entferne andere Einträge:\n";
        for ($i = 1; $i < count($entries); $i++) {
            echo "    - {$entries[$i]['name']} [{$entries[$i]['industry_uuid']}]\n";
            $toDelete[] = $entries[$i]['industry_uuid'];
        }
    }
    
    echo "\n";
}

// Prüfe Referenzen vor dem Löschen
echo "\n=== Prüfe Referenzen ===\n";
echo str_repeat("-", 80) . "\n";

$hasReferences = false;
foreach ($toDelete as $uuid) {
    // Prüfe org.industry_level2_uuid
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM org WHERE industry_level2_uuid = ?");
    $stmt->execute([$uuid]);
    $orgCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Prüfe industry.parent_industry_uuid
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM industry WHERE parent_industry_uuid = ?");
    $stmt->execute([$uuid]);
    $childCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Prüfe org_import_staging.industry_resolution
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM org_import_staging 
        WHERE industry_resolution LIKE ?
    ");
    $stmt->execute(["%$uuid%"]);
    $stagingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($orgCount > 0 || $childCount > 0 || $stagingCount > 0) {
        $hasReferences = true;
        $stmt = $db->prepare("SELECT name FROM industry WHERE industry_uuid = ?");
        $stmt->execute([$uuid]);
        $name = $stmt->fetch(PDO::FETCH_ASSOC)['name'];
        
        echo "⚠️  {$name} [{$uuid}]:\n";
        if ($orgCount > 0) {
            echo "    - Wird in $orgCount Organisationen verwendet\n";
        }
        if ($childCount > 0) {
            echo "    - Hat $childCount Child-Industries\n";
        }
        if ($stagingCount > 0) {
            echo "    - Wird in $stagingCount Staging-Rows verwendet\n";
        }
    }
}

if (!$hasReferences) {
    echo "✅ Keine Referenzen gefunden - sicher zu löschen\n";
}

// Führe Löschung durch (wenn nicht Dry-Run)
if (!$dryRun && !$hasReferences) {
    echo "\n=== Lösche Duplikate ===\n";
    echo str_repeat("-", 80) . "\n";
    
    $db->beginTransaction();
    
    try {
        $deleted = 0;
        foreach ($toDelete as $uuid) {
            $stmt = $db->prepare("DELETE FROM industry WHERE industry_uuid = ?");
            $stmt->execute([$uuid]);
            $deleted += $stmt->rowCount();
        }
        
        $db->commit();
        echo "✅ $deleted Duplikate erfolgreich gelöscht\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "❌ Fehler beim Löschen: " . $e->getMessage() . "\n";
        exit(1);
    }
} elseif (!$dryRun && $hasReferences) {
    echo "\n❌ ABGEBROCHEN: Es gibt Referenzen auf zu löschende Einträge\n";
    echo "   Bitte manuell bereinigen oder Referenzen zuerst migrieren\n";
    exit(1);
}

echo "\n=== Bereinigung abgeschlossen ===\n";

