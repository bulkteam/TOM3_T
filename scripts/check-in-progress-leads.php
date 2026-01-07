<?php
/**
 * Prüft IN_PROGRESS Leads und deren owner_user_id
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

// Alle IN_PROGRESS Leads
$stmt = $db->query("
    SELECT 
        c.case_uuid,
        c.stage,
        c.owner_user_id,
        c.created_at,
        c.last_touch_at,
        o.name as company_name
    FROM case_item c
    LEFT JOIN org o ON c.org_uuid = o.org_uuid
    WHERE c.case_type = 'LEAD'
      AND c.engine = 'inside_sales'
      AND c.stage = 'IN_PROGRESS'
    ORDER BY c.created_at DESC
");

$allInProgress = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Alle IN_PROGRESS Leads ===\n";
echo "Gesamt: " . count($allInProgress) . "\n\n";

// Gruppiert nach owner_user_id
$byOwner = [];
foreach ($allInProgress as $lead) {
    $owner = $lead['owner_user_id'] ?? 'NULL';
    if (!isset($byOwner[$owner])) {
        $byOwner[$owner] = [];
    }
    $byOwner[$owner][] = $lead;
}

echo "=== Gruppiert nach owner_user_id ===\n";
foreach ($byOwner as $owner => $leads) {
    echo "owner_user_id: " . ($owner === 'NULL' ? 'NULL' : $owner) . " - Anzahl: " . count($leads) . "\n";
}
echo "\n";

// Leads ohne owner_user_id
$nullOwner = array_filter($allInProgress, function($lead) {
    return $lead['owner_user_id'] === null;
});

echo "=== Leads ohne owner_user_id (NULL) ===\n";
echo "Anzahl: " . count($nullOwner) . "\n";
foreach ($nullOwner as $lead) {
    echo "  - " . ($lead['company_name'] ?? 'Unbekannt') . " (UUID: " . $lead['case_uuid'] . ")\n";
}
echo "\n";

// Aktueller Benutzer (aus Session oder als Parameter)
$currentUserId = $_GET['user_id'] ?? null;
if ($currentUserId) {
    echo "=== Leads für Benutzer: $currentUserId ===\n";
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as count
        FROM case_item
        WHERE case_type = 'LEAD'
          AND engine = 'inside_sales'
          AND stage = 'IN_PROGRESS'
          AND (owner_user_id IS NULL OR owner_user_id = :user_id)
    ");
    $stmt->execute(['user_id' => $currentUserId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Anzahl: " . ($result['count'] ?? 0) . "\n";
}



