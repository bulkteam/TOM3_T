<?php
/**
 * Prüft TELEPLAST Duplikat und Import-Status
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  TELEPLAST Import-Status Prüfung\n";
echo "========================================\n\n";

// 1. Prüfe Organisationen
$stmt = $db->prepare("
    SELECT org_uuid, name, status, created_at
    FROM org
    WHERE name LIKE '%TELEPLAST%'
    ORDER BY created_at DESC
");
$stmt->execute();
$orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "1. Organisationen mit TELEPLAST im Namen:\n";
if (empty($orgs)) {
    echo "   Keine gefunden\n";
} else {
    foreach ($orgs as $org) {
        echo "   - {$org['name']} (UUID: {$org['org_uuid']}, Status: {$org['status']}, Erstellt: {$org['created_at']})\n";
    }
}

// 2. Prüfe case_items
echo "\n2. Case Items (Inside Sales Queue) für TELEPLAST:\n";
if (!empty($orgs)) {
    foreach ($orgs as $org) {
        $stmt = $db->prepare("
            SELECT case_uuid, case_type, engine, stage, status, created_at
            FROM case_item
            WHERE org_uuid = :org_uuid
            AND case_type = 'LEAD'
            AND engine = 'inside_sales'
            ORDER BY created_at DESC
        ");
        $stmt->execute(['org_uuid' => $org['org_uuid']]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($cases)) {
            echo "   - Keine Cases für {$org['name']}\n";
        } else {
            foreach ($cases as $case) {
                echo "   - Case: {$case['case_uuid']} (Stage: {$case['stage']}, Status: {$case['status']}, Erstellt: {$case['created_at']})\n";
            }
        }
    }
}

// 3. Prüfe Staging-Rows
echo "\n3. Staging-Rows für TELEPLAST:\n";
$stmt = $db->prepare("
    SELECT staging_uuid, row_number, disposition, import_status, imported_org_uuid, imported_at
    FROM org_import_staging
    WHERE JSON_EXTRACT(mapped_data, '$.org.name') LIKE '%TELEPLAST%'
       OR JSON_EXTRACT(corrections_json, '$.org.name') LIKE '%TELEPLAST%'
    ORDER BY row_number
");
$stmt->execute();
$stagingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($stagingRows)) {
    echo "   Keine gefunden\n";
} else {
    foreach ($stagingRows as $row) {
        echo "   - Zeile {$row['row_number']}: Disposition: {$row['disposition']}, Import-Status: {$row['import_status']}\n";
        if ($row['imported_org_uuid']) {
            echo "     → Importiert als Org: {$row['imported_org_uuid']} am {$row['imported_at']}\n";
        }
    }
}

// 4. Prüfe alle approved Rows, die nicht importiert wurden
echo "\n4. Alle approved Rows, die NICHT importiert wurden:\n";
$stmt = $db->prepare("
    SELECT staging_uuid, row_number, disposition, import_status,
           JSON_EXTRACT(mapped_data, '$.org.name') as org_name
    FROM org_import_staging
    WHERE disposition = 'approved'
      AND import_status != 'imported'
    ORDER BY row_number
");
$stmt->execute();
$pendingApproved = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingApproved)) {
    echo "   Keine gefunden (alle approved Rows wurden importiert)\n";
} else {
    echo "   Gefunden: " . count($pendingApproved) . " Rows\n";
    foreach ($pendingApproved as $row) {
        $orgName = json_decode($row['org_name'] ?? 'null', true);
        echo "   - Zeile {$row['row_number']}: {$orgName} (Disposition: {$row['disposition']}, Import-Status: {$row['import_status']})\n";
    }
}

