<?php
/**
 * Bereinigt Duplikate in der industry Tabelle:
 * - Findet Duplikate (gleicher Name + Code)
 * - Behält einen Master-Eintrag
 * - Leitet alle Verweise auf den Master um
 * - Löscht die Duplikate
 * 
 * Usage: php fix-industry-duplicates.php [--dry-run] [--execute]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$dryRun = in_array('--dry-run', $argv) || !in_array('--execute', $argv);

echo "=== Branchen-Duplikate bereinigen ===\n";
echo ($dryRun ? "🔍 DRY-RUN Modus (keine Änderungen)\n" : "⚙️  EXECUTE Modus (Änderungen werden durchgeführt)\n");
echo "\n";

// 1. Finde alle Duplikate (gleicher Name + Code)
$stmt = $db->query("
    SELECT name, code, COUNT(*) as count, GROUP_CONCAT(industry_uuid ORDER BY created_at) as uuids
    FROM industry
    GROUP BY name, code
    HAVING count > 1
    ORDER BY name, code
");

$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicates)) {
    echo "✓ Keine Duplikate gefunden!\n";
    exit(0);
}

echo "Gefundene Duplikate: " . count($duplicates) . " Gruppen\n\n";

$totalDuplicates = 0;
$updates = [];

foreach ($duplicates as $dup) {
    $uuids = explode(',', $dup['uuids']);
    $count = count($uuids);
    $totalDuplicates += ($count - 1); // -1 weil einer bleibt
    
    // Master: Der älteste Eintrag (erster in der Liste, da nach created_at sortiert)
    $masterUuid = $uuids[0];
    $duplicateUuids = array_slice($uuids, 1);
    
    echo sprintf("  %s (%s): %d Duplikate\n", $dup['name'], $dup['code'] ?? 'NULL', $count - 1);
    echo sprintf("    Master: %s\n", substr($masterUuid, 0, 8));
    echo sprintf("    Duplikate: %s\n", implode(', ', array_map(function($u) { return substr($u, 0, 8); }, $duplicateUuids)));
    
    $updates[] = [
        'name' => $dup['name'],
        'code' => $dup['code'],
        'master_uuid' => $masterUuid,
        'duplicate_uuids' => $duplicateUuids
    ];
}

echo "\n=== Zusammenfassung ===\n";
echo "Duplikat-Gruppen: " . count($duplicates) . "\n";
echo "Zu löschende Einträge: $totalDuplicates\n\n";

// 2. Prüfe Verweise auf die Duplikate
echo "=== Prüfe Verweise ===\n";

$referenceChecks = [];

foreach ($updates as $update) {
    $refs = [];
    
    // Prüfe org.industry_main_uuid
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM org 
        WHERE industry_main_uuid IN ('" . implode("','", $update['duplicate_uuids']) . "')
    ");
    $stmt->execute();
    $mainRefs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    if ($mainRefs > 0) {
        $refs['org.industry_main_uuid'] = $mainRefs;
    }
    
    // Prüfe org.industry_sub_uuid
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM org 
        WHERE industry_sub_uuid IN ('" . implode("','", $update['duplicate_uuids']) . "')
    ");
    $stmt->execute();
    $subRefs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    if ($subRefs > 0) {
        $refs['org.industry_sub_uuid'] = $subRefs;
    }
    
    // Prüfe industry.parent_industry_uuid (für Subbranchen)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM industry 
        WHERE parent_industry_uuid IN ('" . implode("','", $update['duplicate_uuids']) . "')
    ");
    $stmt->execute();
    $parentRefs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    if ($parentRefs > 0) {
        $refs['industry.parent_industry_uuid'] = $parentRefs;
    }
    
    if (!empty($refs)) {
        $referenceChecks[$update['name']] = $refs;
        echo sprintf("  %s: %s\n", $update['name'], json_encode($refs));
    }
}

if (empty($referenceChecks)) {
    echo "  ✓ Keine Verweise auf Duplikate gefunden\n";
}

echo "\n";

// 3. Führe Updates durch (wenn nicht dry-run)
if ($dryRun) {
    echo "=== DRY-RUN: Keine Änderungen durchgeführt ===\n";
    echo "Um die Änderungen durchzuführen, verwenden Sie: php fix-industry-duplicates.php --execute\n";
    exit(0);
}

echo "=== Führe Bereinigung durch...\n";

$db->beginTransaction();

try {
    $updatedRefs = 0;
    $deletedDups = 0;
    
    foreach ($updates as $update) {
        $masterUuid = $update['master_uuid'];
        $duplicateUuids = $update['duplicate_uuids'];
        
        echo sprintf("\n  %s (%s):\n", $update['name'], $update['code'] ?? 'NULL');
        
        // 1. Update org.industry_main_uuid
        $stmt = $db->prepare("
            UPDATE org 
            SET industry_main_uuid = :master 
            WHERE industry_main_uuid IN ('" . implode("','", $duplicateUuids) . "')
        ");
        $stmt->execute(['master' => $masterUuid]);
        $mainUpdated = $stmt->rowCount();
        if ($mainUpdated > 0) {
            echo sprintf("    ✓ org.industry_main_uuid: %d aktualisiert\n", $mainUpdated);
            $updatedRefs += $mainUpdated;
        }
        
        // 2. Update org.industry_sub_uuid
        $stmt = $db->prepare("
            UPDATE org 
            SET industry_sub_uuid = :master 
            WHERE industry_sub_uuid IN ('" . implode("','", $duplicateUuids) . "')
        ");
        $stmt->execute(['master' => $masterUuid]);
        $subUpdated = $stmt->rowCount();
        if ($subUpdated > 0) {
            echo sprintf("    ✓ org.industry_sub_uuid: %d aktualisiert\n", $subUpdated);
            $updatedRefs += $subUpdated;
        }
        
        // 3. Update industry.parent_industry_uuid
        $stmt = $db->prepare("
            UPDATE industry 
            SET parent_industry_uuid = :master 
            WHERE parent_industry_uuid IN ('" . implode("','", $duplicateUuids) . "')
        ");
        $stmt->execute(['master' => $masterUuid]);
        $parentUpdated = $stmt->rowCount();
        if ($parentUpdated > 0) {
            echo sprintf("    ✓ industry.parent_industry_uuid: %d aktualisiert\n", $parentUpdated);
            $updatedRefs += $parentUpdated;
        }
        
        // 4. Lösche Duplikate
        $placeholders = implode(',', array_fill(0, count($duplicateUuids), '?'));
        $stmt = $db->prepare("
            DELETE FROM industry 
            WHERE industry_uuid IN ($placeholders)
        ");
        $stmt->execute($duplicateUuids);
        $deleted = $stmt->rowCount();
        echo sprintf("    ✓ %d Duplikate gelöscht\n", $deleted);
        $deletedDups += $deleted;
    }
    
    $db->commit();
    
    echo "\n=== Erfolgreich abgeschlossen ===\n";
    echo "Verweise aktualisiert: $updatedRefs\n";
    echo "Duplikate gelöscht: $deletedDups\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Fehler: " . $e->getMessage() . "\n";
    echo "Rollback durchgeführt.\n";
    exit(1);
}

// 4. Verifiziere Ergebnis
echo "\n=== Verifikation ===\n";

$stmt = $db->query("
    SELECT name, code, COUNT(*) as count
    FROM industry
    GROUP BY name, code
    HAVING count > 1
");

$remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($remaining)) {
    echo "✓ Keine Duplikate mehr vorhanden!\n";
} else {
    echo "⚠️  Es verbleiben noch " . count($remaining) . " Duplikat-Gruppen:\n";
    foreach ($remaining as $dup) {
        echo sprintf("  - %s (%s): %d Einträge\n", $dup['name'], $dup['code'] ?? 'NULL', $dup['count']);
    }
}

// 5. Zeige finale Statistik
$stmt = $db->query("SELECT COUNT(*) as count FROM industry");
$total = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM industry WHERE parent_industry_uuid IS NULL");
$mainClasses = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $db->query("SELECT COUNT(*) as count FROM industry WHERE parent_industry_uuid IS NOT NULL");
$subClasses = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

echo "\n=== Finale Statistik ===\n";
echo "Gesamt Branchen: $total\n";
echo "Hauptklassen: $mainClasses\n";
echo "Subbranchen: $subClasses\n";
