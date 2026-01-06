<?php
/**
 * Prüft Import-Status für "Francia Mozzarella GmbH" (Kdnr 119)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "Prüfe Import-Status für 'Francia Mozzarella GmbH' (Kdnr 119)\n";
echo "===========================================================\n\n";

// 1. Prüfe in orgs-Tabelle
echo "1. Prüfe in orgs-Tabelle:\n";
$stmt = $db->prepare("
    SELECT org_uuid, name, status, created_at
    FROM org
    WHERE name LIKE '%Francia Mozzarella%'
");
$stmt->execute();
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if ($org) {
    echo "   ✓ Organisation gefunden:\n";
    echo "     - UUID: {$org['org_uuid']}\n";
    echo "     - Name: {$org['name']}\n";
    echo "     - Status: {$org['status']}\n";
    echo "     - Erstellt: {$org['created_at']}\n\n";
    
    $orgUuid = $org['org_uuid'];
    
    // 2. Prüfe in Staging-Tabelle
    echo "2. Prüfe in org_import_staging:\n";
    $stmt = $db->prepare("
        SELECT staging_uuid, import_status, imported_at, imported_org_uuid, disposition
        FROM org_import_staging
        WHERE imported_org_uuid = :org_uuid
    ");
    $stmt->execute(['org_uuid' => $orgUuid]);
    $staging = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($staging) {
        echo "   ✓ Staging-Row gefunden:\n";
        echo "     - Import Status: {$staging['import_status']}\n";
        echo "     - Disposition: {$staging['disposition']}\n";
        echo "     - Importiert am: {$staging['imported_at']}\n\n";
    } else {
        echo "   ✗ Keine Staging-Row gefunden\n\n";
    }
    
    // 3. Prüfe case_item (Inside Sales Queue)
    echo "3. Prüfe case_item (Inside Sales Queue):\n";
    $stmt = $db->prepare("
        SELECT case_uuid, case_type, engine, stage, owner_user_id, created_at
        FROM case_item
        WHERE org_uuid = :org_uuid
        AND engine = 'inside_sales'
        AND case_type = 'LEAD'
    ");
    $stmt->execute(['org_uuid' => $orgUuid]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($case) {
        echo "   ✓ Case gefunden:\n";
        echo "     - UUID: {$case['case_uuid']}\n";
        echo "     - Type: {$case['case_type']}\n";
        echo "     - Engine: {$case['engine']}\n";
        echo "     - Stage: {$case['stage']}\n";
        echo "     - Owner: {$case['owner_user_id']}\n";
        echo "     - Erstellt: {$case['created_at']}\n\n";
    } else {
        echo "   ✗ KEIN Case gefunden - Das ist das Problem!\n";
        echo "     Die Organisation wurde importiert, aber kein Workflow-Case wurde erstellt.\n";
        echo "     Daher erscheint sie nicht in der Inside Sales Queue.\n\n";
    }
    
} else {
    echo "   ✗ Organisation NICHT gefunden in orgs-Tabelle\n";
    echo "     Der Import war möglicherweise nicht erfolgreich.\n\n";
    
    // Prüfe in Staging-Tabelle
    echo "2. Prüfe in org_import_staging:\n";
    $stmt = $db->prepare("
        SELECT staging_uuid, import_status, imported_at, mapped_data
        FROM org_import_staging
        WHERE mapped_data LIKE '%Francia Mozzarella%'
        OR mapped_data LIKE '%119%'
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $stagingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($stagingRows) {
        echo "   Gefundene Staging-Rows:\n";
        foreach ($stagingRows as $row) {
            echo "     - UUID: {$row['staging_uuid']}\n";
            echo "     - Import Status: {$row['import_status']}\n";
            echo "     - Importiert am: " . ($row['imported_at'] ?? 'N/A') . "\n";
        }
    } else {
        echo "   ✗ Keine Staging-Rows gefunden\n";
    }
}

echo "\n=== Zusammenfassung ===\n";
if ($org && !$case) {
    echo "❌ Problem: Organisation wurde importiert, aber kein Workflow-Case wurde erstellt.\n";
    echo "   Lösung: Workflow-Erstellung im ImportCommitService implementieren.\n";
} elseif ($org && $case) {
    echo "✅ Alles OK: Organisation und Case existieren.\n";
    if ($case['stage'] !== 'NEW') {
        echo "   ⚠️  Aber: Stage ist '{$case['stage']}', nicht 'NEW'.\n";
    }
} else {
    echo "❌ Organisation wurde nicht importiert.\n";
}

