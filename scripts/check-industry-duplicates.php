<?php
/**
 * Prüft Industry-Tabelle auf Duplikate und Inkonsistenzen
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfe Industry-Tabelle auf Duplikate ===\n\n";

// 1. Prüfe Duplikate nach Code
echo "1. Duplikate nach Code:\n";
echo str_repeat("-", 80) . "\n";
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
    echo "✅ Keine Duplikate nach Code gefunden\n\n";
} else {
    echo "⚠️  Gefundene Duplikate:\n";
    foreach ($duplicates as $dup) {
        echo "  Code: {$dup['code']} - {$dup['count']}x vorhanden\n";
        
        // Zeige Details
        $stmt2 = $db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid, created_at
            FROM industry
            WHERE code = ?
            ORDER BY created_at
        ");
        $stmt2->execute([$dup['code']]);
        $details = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($details as $detail) {
            $parent = $detail['parent_industry_uuid'] ? ' (Parent: ' . substr($detail['parent_industry_uuid'], 0, 8) . '...)' : ' (Level 1)';
            echo "    - {$detail['name']} [{$detail['industry_uuid']}]{$parent}\n";
        }
        echo "\n";
    }
}

// 2. Prüfe Duplikate nach Name (innerhalb gleicher Hierarchie-Ebene)
echo "\n2. Duplikate nach Name (gleiche Hierarchie-Ebene):\n";
echo str_repeat("-", 80) . "\n";

// Level 1 (ohne Parent)
$stmt = $db->query("
    SELECT name, COUNT(*) as count
    FROM industry
    WHERE parent_industry_uuid IS NULL
    GROUP BY name
    HAVING COUNT(*) > 1
    ORDER BY name
");
$duplicatesL1 = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicatesL1)) {
    echo "✅ Keine Duplikate in Level 1 gefunden\n";
} else {
    echo "⚠️  Level 1 Duplikate:\n";
    foreach ($duplicatesL1 as $dup) {
        echo "  Name: {$dup['name']} - {$dup['count']}x vorhanden\n";
    }
}

// Level 2 (mit Level 1 Parent)
$stmt = $db->query("
    SELECT i2.name, COUNT(*) as count
    FROM industry i2
    INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
    WHERE i1.parent_industry_uuid IS NULL
    GROUP BY i2.name
    HAVING COUNT(*) > 1
    ORDER BY i2.name
");
$duplicatesL2 = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($duplicatesL2)) {
    echo "✅ Keine Duplikate in Level 2 gefunden\n";
} else {
    echo "⚠️  Level 2 Duplikate:\n";
    foreach ($duplicatesL2 as $dup) {
        echo "  Name: {$dup['name']} - {$dup['count']}x vorhanden\n";
    }
}

// 3. Prüfe speziell C20 und C28
echo "\n3. Details zu C20 und C28:\n";
echo str_repeat("-", 80) . "\n";

$codes = ['C20', 'C28'];
foreach ($codes as $code) {
    $stmt = $db->prepare("
        SELECT 
            i.industry_uuid,
            i.name,
            i.code,
            i.parent_industry_uuid,
            i1.name as parent_name,
            i.created_at
        FROM industry i
        LEFT JOIN industry i1 ON i.parent_industry_uuid = i1.industry_uuid
        WHERE i.code = ?
        ORDER BY i.created_at
    ");
    $stmt->execute([$code]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Code: $code - " . count($rows) . " Einträge\n";
    foreach ($rows as $row) {
        $level = $row['parent_industry_uuid'] ? ($row['parent_name'] ? 'Level 2 (Parent: ' . $row['parent_name'] . ')' : 'Level 2/3') : 'Level 1';
        echo "  - {$row['name']} [$level] [{$row['industry_uuid']}]\n";
    }
    echo "\n";
}

// 4. Zeige Struktur-Statistik
echo "\n4. Struktur-Statistik:\n";
echo str_repeat("-", 80) . "\n";

$stmt = $db->query("
    SELECT 
        CASE 
            WHEN parent_industry_uuid IS NULL THEN 'Level 1'
            WHEN EXISTS (
                SELECT 1 FROM industry i1 
                WHERE i1.industry_uuid = industry.parent_industry_uuid 
                AND i1.parent_industry_uuid IS NULL
            ) THEN 'Level 2'
            ELSE 'Level 3'
        END as level,
        COUNT(*) as count
    FROM industry
    GROUP BY level
    ORDER BY level
");
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($stats as $stat) {
    echo "  {$stat['level']}: {$stat['count']} Einträge\n";
}

// 5. Prüfe Inkonsistenzen in der Hierarchie
echo "\n5. Hierarchie-Inkonsistenzen:\n";
echo str_repeat("-", 80) . "\n";

// Level 2 mit falschem Parent (Parent ist nicht Level 1)
$stmt = $db->query("
    SELECT i2.industry_uuid, i2.name, i2.code, i2.parent_industry_uuid
    FROM industry i2
    INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
    WHERE i1.parent_industry_uuid IS NOT NULL
");
$inconsistent = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($inconsistent)) {
    echo "✅ Keine Hierarchie-Inkonsistenzen gefunden\n";
} else {
    echo "⚠️  Level 2 Industries mit Level 2/3 Parent (sollten Level 1 Parent haben):\n";
    foreach ($inconsistent as $row) {
        echo "  - {$row['name']} [{$row['code']}] - Parent ist nicht Level 1\n";
    }
}

echo "\n=== Prüfung abgeschlossen ===\n";

