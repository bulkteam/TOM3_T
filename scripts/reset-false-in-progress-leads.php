<?php
/**
 * Setzt Leads zurück, die fälschlicherweise auf IN_PROGRESS gesetzt wurden
 * 
 * Ein Lead sollte nur IN_PROGRESS sein, wenn:
 * - Der Benutzer tatsächlich Änderungen vorgenommen hat (Sterne, Daten, etc.)
 * 
 * Leads, die nur geöffnet wurden (ohne Änderungen), werden zurückgesetzt:
 * - Wenn stage = 'IN_PROGRESS' UND last_touch_at IS NULL UND priority_stars = 0 → zurück auf NEW
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

// Finde Leads, die wahrscheinlich fälschlicherweise auf IN_PROGRESS gesetzt wurden
$stmt = $db->query("
    SELECT 
        c.case_uuid,
        c.stage,
        c.owner_user_id,
        c.created_at,
        c.last_touch_at,
        c.priority_stars,
        o.name as company_name
    FROM case_item c
    LEFT JOIN org o ON c.org_uuid = o.org_uuid
    WHERE c.case_type = 'LEAD'
      AND c.engine = 'inside_sales'
      AND c.stage = 'IN_PROGRESS'
      AND (
          -- Keine Änderungen (last_touch_at = NULL)
          c.last_touch_at IS NULL
          -- UND keine Priorität gesetzt (priority_stars = 0 oder NULL)
          AND (c.priority_stars IS NULL OR c.priority_stars = 0)
      )
    ORDER BY c.created_at DESC
");

$leadsToReset = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Leads, die zurückgesetzt werden ===\n";
echo "Anzahl: " . count($leadsToReset) . "\n\n";

if (count($leadsToReset) > 0) {
    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare("
            UPDATE case_item 
            SET stage = 'NEW'
            WHERE case_uuid = :uuid
        ");
        
        foreach ($leadsToReset as $lead) {
            echo "Setze zurück: " . ($lead['company_name'] ?? 'Unbekannt') . " (UUID: " . $lead['case_uuid'] . ")\n";
            $updateStmt->execute(['uuid' => $lead['case_uuid']]);
        }
        
        $db->commit();
        echo "\n=== Erfolgreich zurückgesetzt: " . count($leadsToReset) . " Leads ===\n";
    } catch (Exception $e) {
        $db->rollBack();
        echo "\n=== FEHLER beim Zurücksetzen: " . $e->getMessage() . " ===\n";
        exit(1);
    }
} else {
    echo "Keine Leads gefunden, die zurückgesetzt werden müssen.\n";
}



