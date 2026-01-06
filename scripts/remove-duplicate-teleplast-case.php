<?php
/**
 * Entfernt doppelte Case für TELEPLAST
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  Entferne doppelte TELEPLAST Cases\n";
echo "========================================\n\n";

// Finde TELEPLAST Org
$stmt = $db->prepare("
    SELECT org_uuid, name
    FROM org
    WHERE name LIKE '%TELEPLAST%'
    LIMIT 1
");
$stmt->execute();
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo "❌ TELEPLAST Organisation nicht gefunden\n";
    exit(1);
}

echo "Organisation gefunden: {$org['name']} (UUID: {$org['org_uuid']})\n\n";

// Finde alle Cases für diese Org
$stmt = $db->prepare("
    SELECT case_uuid, created_at, stage, status
    FROM case_item
    WHERE org_uuid = :org_uuid
      AND case_type = 'LEAD'
      AND engine = 'inside_sales'
    ORDER BY created_at ASC
");
$stmt->execute(['org_uuid' => $org['org_uuid']]);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($cases) <= 1) {
    echo "✅ Keine doppelten Cases gefunden\n";
    exit(0);
}

echo "Gefunden: " . count($cases) . " Cases\n";
foreach ($cases as $i => $case) {
    echo "  " . ($i + 1) . ". Case: {$case['case_uuid']} (Erstellt: {$case['created_at']}, Stage: {$case['stage']})\n";
}

// Behalte den ersten, lösche die restlichen
$firstCase = array_shift($cases);
echo "\n✅ Behalte ersten Case: {$firstCase['case_uuid']}\n";
echo "🗑️  Lösche " . count($cases) . " doppelte Case(s)...\n";

foreach ($cases as $case) {
    $deleteStmt = $db->prepare("DELETE FROM case_item WHERE case_uuid = :case_uuid");
    $deleteStmt->execute(['case_uuid' => $case['case_uuid']]);
    echo "   ✓ Gelöscht: {$case['case_uuid']}\n";
}

echo "\n✅ Doppelte Cases entfernt\n";

