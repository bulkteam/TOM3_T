<?php
/**
 * Prüft Telefonnummer für eine spezifische Org
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

// Suche nach "Jänecke + Schneemann"
$stmt = $db->prepare("
    SELECT org_uuid, name
    FROM org
    WHERE name LIKE ?
    LIMIT 1
");
$stmt->execute(['%Jänecke%']);
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo "Org nicht gefunden\n";
    exit(1);
}

echo "=== Org gefunden ===\n";
echo "Name: {$org['name']}\n";
echo "UUID: {$org['org_uuid']}\n\n";

// Prüfe alle Kommunikationskanäle
$stmt = $db->prepare("
    SELECT 
        channel_uuid,
        channel_type,
        country_code,
        area_code,
        number,
        extension,
        is_primary,
        created_at
    FROM org_communication_channel
    WHERE org_uuid = ?
    ORDER BY created_at DESC
");
$stmt->execute([$org['org_uuid']]);
$channels = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Kommunikationskanäle ===\n";
echo "Anzahl: " . count($channels) . "\n\n";

foreach ($channels as $channel) {
    echo "Channel Type: {$channel['channel_type']}\n";
    echo "  country_code: " . ($channel['country_code'] ?? 'NULL') . "\n";
    echo "  area_code: " . ($channel['area_code'] ?? 'NULL') . "\n";
    echo "  number: " . ($channel['number'] ?? 'NULL') . "\n";
    echo "  extension: " . ($channel['extension'] ?? 'NULL') . "\n";
    echo "  is_primary: {$channel['is_primary']}\n";
    echo "  created_at: {$channel['created_at']}\n";
    
    // Baue Telefonnummer zusammen
    $phone = trim(
        ($channel['country_code'] ?? '') . 
        ($channel['area_code'] ?? '') . 
        ($channel['number'] ?? '') . 
        ($channel['extension'] ? '-' . $channel['extension'] : '')
    );
    echo "  Zusammengesetzt: '{$phone}'\n";
    echo "\n";
}

// Teste die SQL-Query aus WorkItemCrudService
echo "=== Test SQL-Query (WorkItemCrudService) ===\n";
$testStmt = $db->prepare("
    SELECT (
        SELECT CONCAT_WS('',
            IFNULL(occ1.country_code, ''),
            IFNULL(occ1.area_code, ''),
            IFNULL(occ1.number, ''),
            IF(occ1.extension IS NOT NULL AND occ1.extension != '', CONCAT('-', occ1.extension), '')
        )
        FROM org_communication_channel occ1
        WHERE occ1.org_uuid = ?
          AND (occ1.channel_type = 'phone_main' OR occ1.channel_type = 'phone')
        ORDER BY 
            occ1.is_primary DESC,
            CASE WHEN EXISTS (
                SELECT 1 FROM org_address oa2 
                WHERE oa2.org_uuid = occ1.org_uuid 
                AND oa2.address_type = 'headquarters'
            ) THEN 1 ELSE 0 END DESC,
            occ1.created_at DESC
        LIMIT 1
    ) as company_phone
");
$testStmt->execute([$org['org_uuid']]);
$result = $testStmt->fetch(PDO::FETCH_ASSOC);
echo "Ergebnis: " . ($result['company_phone'] ?? 'NULL') . "\n";


