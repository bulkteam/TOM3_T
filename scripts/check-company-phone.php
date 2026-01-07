<?php
/**
 * Prüft, ob Telefonnummern für Leads vorhanden sind
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

// Hole einen Lead mit org_uuid
$stmt = $db->query("
    SELECT c.case_uuid, c.org_uuid, o.name as company_name
    FROM case_item c
    LEFT JOIN org o ON c.org_uuid = o.org_uuid
    WHERE c.engine = 'inside_sales'
    LIMIT 5
");

$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Prüfe Telefonnummern für Leads ===\n\n";

foreach ($leads as $lead) {
    echo "Lead: {$lead['company_name']} (UUID: {$lead['case_uuid']})\n";
    echo "Org UUID: {$lead['org_uuid']}\n";
    
    // Prüfe Telefonnummern
    $phoneStmt = $db->prepare("
        SELECT 
            channel_type,
            country_code,
            area_code,
            number,
            extension,
            is_primary,
            created_at
        FROM org_communication_channel
        WHERE org_uuid = ?
          AND channel_type = 'phone_main'
        ORDER BY 
            is_primary DESC,
            created_at DESC
    ");
    $phoneStmt->execute([$lead['org_uuid']]);
    $phones = $phoneStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($phones) > 0) {
        echo "Telefonnummern gefunden: " . count($phones) . "\n";
        foreach ($phones as $phone) {
            $phoneNumber = trim(
                ($phone['country_code'] ?? '') . 
                ($phone['area_code'] ?? '') . 
                ($phone['number'] ?? '') . 
                ($phone['extension'] ? '-' . $phone['extension'] : '')
            );
            echo "  - {$phoneNumber} (is_primary: {$phone['is_primary']}, created_at: {$phone['created_at']})\n";
        }
        
        // Teste die SQL-Query
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
                  AND occ1.channel_type = 'phone_main'
                ORDER BY 
                    occ1.is_primary DESC,
                    occ1.created_at DESC
                LIMIT 1
            ) as company_phone
        ");
        $testStmt->execute([$lead['org_uuid']]);
        $result = $testStmt->fetch(PDO::FETCH_ASSOC);
        echo "SQL-Query Ergebnis: " . ($result['company_phone'] ?? 'NULL') . "\n";
    } else {
        echo "Keine Telefonnummern gefunden\n";
    }
    echo "\n";
}



