<?php
/**
 * Erstellt fehlende case_item Einträge für manuell angelegte Organisationen mit Status 'lead'
 * die noch keinen case_item für die Inside Sales Queue haben
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  Erstelle fehlende Lead-Cases\n";
echo "========================================\n\n";

// Finde alle Organisationen mit Status 'lead', die noch keinen case_item haben
$stmt = $db->prepare("
    SELECT o.org_uuid, o.name, o.status, o.created_at
    FROM org o
    LEFT JOIN case_item c ON c.org_uuid = o.org_uuid 
        AND c.case_type = 'LEAD' 
        AND c.engine = 'inside_sales'
    WHERE o.status = 'lead'
      AND o.archived_at IS NULL
      AND c.case_uuid IS NULL
    ORDER BY o.created_at DESC
");

$stmt->execute();
$orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orgs)) {
    echo "✅ Keine fehlenden Lead-Cases gefunden.\n";
    exit(0);
}

echo "Gefunden: " . count($orgs) . " Organisationen mit Status 'lead' ohne case_item\n\n";

$created = 0;
foreach ($orgs as $org) {
    echo "Erstelle case_item für: {$org['name']} (UUID: {$org['org_uuid']})\n";
    
    // Generiere UUID
    $uuidStmt = $db->query("SELECT UUID() as uuid");
    $caseUuid = $uuidStmt->fetch()['uuid'];
    
    // Erstelle case_item
    $stmt = $db->prepare("
        INSERT INTO case_item (
            case_uuid, case_type, engine, phase, stage, status,
            org_uuid, title, description,
            owner_role, priority_stars, 
            created_at, opened_at
        )
        VALUES (
            :case_uuid, 'LEAD', 'inside_sales', 'QUALIFY-A', 'NEW', 'neu',
            :org_uuid, :title, :description,
            'inside_sales', 0,
            NOW(), NOW()
        )
    ");
    
    $title = "Qualifizierung: " . $org['name'];
    $description = "Automatisch erstellter Qualifizierungs-Vorgang für manuell angelegte Organisation";
    
    try {
        $stmt->execute([
            'case_uuid' => $caseUuid,
            'org_uuid' => $org['org_uuid'],
            'title' => $title,
            'description' => $description
        ]);
        
        echo "  ✓ Case erstellt: {$caseUuid}\n";
        $created++;
    } catch (Exception $e) {
        echo "  ✗ Fehler: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Zusammenfassung ===\n";
echo "Erstellt: {$created} case_items\n";
echo "Fehler: " . (count($orgs) - $created) . "\n";

if ($created > 0) {
    echo "\n✅ Die Organisationen sollten jetzt in der Inside Sales Queue unter 'Neu' erscheinen.\n";
}


