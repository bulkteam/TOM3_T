<?php
/**
 * Setzt Leads zurück, die fälschlicherweise auf IN_PROGRESS gesetzt wurden
 * 
 * Ein Lead sollte nur IN_PROGRESS sein, wenn:
 * - Der Benutzer tatsächlich Änderungen vorgenommen hat (Sterne, Daten, etc.)
 * - ODER der Lead explizit auf IN_PROGRESS gesetzt wurde
 * 
 * Leads, die nur geöffnet wurden (ohne Änderungen), sollten zurückgesetzt werden:
 * - Wenn stage = 'IN_PROGRESS' UND last_touch_at = created_at → zurück auf NEW
 * - ODER wenn stage = 'IN_PROGRESS' UND last_touch_at IS NULL → zurück auf NEW
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
          -- Keine Änderungen (last_touch_at = created_at oder NULL)
          (c.last_touch_at IS NULL OR c.last_touch_at = c.created_at)
          -- UND keine Priorität gesetzt (priority_stars = 0 oder NULL)
          AND (c.priority_stars IS NULL OR c.priority_stars = 0)
      )
    ORDER BY c.created_at DESC
");

$leadsToReset = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Leads, die zurückgesetzt werden sollen ===\n";
echo "Anzahl: " . count($leadsToReset) . "\n\n";

if (count($leadsToReset) > 0) {
    echo "Details:\n";
    foreach ($leadsToReset as $lead) {
        echo "  - " . ($lead['company_name'] ?? 'Unbekannt') . " (UUID: " . $lead['case_uuid'] . ")\n";
        echo "    owner_user_id: " . ($lead['owner_user_id'] ?? 'NULL') . "\n";
        echo "    priority_stars: " . ($lead['priority_stars'] ?? 'NULL') . "\n";
        echo "    created_at: " . $lead['created_at'] . "\n";
        echo "    last_touch_at: " . ($lead['last_touch_at'] ?? 'NULL') . "\n";
    }
    
    echo "\n=== Möchten Sie diese Leads auf NEW zurücksetzen? (j/n) ===\n";
    // In einem echten Skript würde man hier auf Eingabe warten
    // Für jetzt: nur anzeigen, nicht automatisch zurücksetzen
    echo "HINWEIS: Dieses Skript setzt die Leads NICHT automatisch zurück.\n";
    echo "Führen Sie manuell aus:\n";
    echo "UPDATE case_item SET stage = 'NEW' WHERE case_uuid IN (\n";
    foreach ($leadsToReset as $lead) {
        echo "  '" . $lead['case_uuid'] . "',\n";
    }
    echo ");\n";
} else {
    echo "Keine Leads gefunden, die zurückgesetzt werden müssen.\n";
}


