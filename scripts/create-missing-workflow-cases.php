<?php
/**
 * Erstellt fehlende Workflow-Cases für importierte Organisationen
 * die noch keinen case_item haben
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "Erstelle fehlende Workflow-Cases für importierte Organisationen\n";
echo "==============================================================\n\n";

// Finde importierte Organisationen ohne case_item
$stmt = $db->query("
    SELECT DISTINCT o.org_uuid, o.name, o.status, o.created_at
    FROM org o
    INNER JOIN org_import_staging s ON o.org_uuid = s.imported_org_uuid
    WHERE s.import_status = 'imported'
    AND o.status = 'lead'
    AND NOT EXISTS (
        SELECT 1 
        FROM case_item c 
        WHERE c.org_uuid = o.org_uuid 
        AND c.engine = 'inside_sales'
        AND c.case_type = 'LEAD'
    )
    ORDER BY o.created_at DESC
");

$orgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orgs)) {
    echo "✓ Keine fehlenden Cases gefunden. Alle importierten Organisationen haben bereits einen Workflow-Case.\n";
    exit(0);
}

echo "Gefunden: " . count($orgs) . " Organisationen ohne Workflow-Case\n\n";

$created = 0;
$errors = 0;

foreach ($orgs as $org) {
    try {
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
        $description = "Automatisch erstellter Qualifizierungs-Vorgang für importierte Organisation";
        
        $stmt->execute([
            'case_uuid' => $caseUuid,
            'org_uuid' => $org['org_uuid'],
            'title' => $title,
            'description' => $description
        ]);
        
        echo "✓ Case erstellt für: {$org['name']} (UUID: " . substr($org['org_uuid'], 0, 8) . "...)\n";
        $created++;
        
    } catch (Exception $e) {
        echo "✗ Fehler bei {$org['name']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Zusammenfassung ===\n";
echo "Erstellt: $created\n";
echo "Fehler: $errors\n";

if ($created > 0) {
    echo "\n✓ Die Organisationen sollten jetzt in der Inside Sales Queue unter 'Neu' erscheinen.\n";
}

