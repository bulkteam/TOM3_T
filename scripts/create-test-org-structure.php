<?php
/**
 * TOM3 - Test: Organisationsstruktur mit Adressen und Hierarchien
 * 
 * Demonstriert die Verwendung der neuen Adress- und Relations-Funktionen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3 Test: Organisationsstruktur ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    $orgService = new OrgService($db);
    
    // ============================================================================
    // Beispiel A: Organisation mit mehreren Adressen
    // ============================================================================
    echo "📋 Beispiel A: Organisation mit mehreren Adressen\n\n";
    
    $orgA = $orgService->createOrg([
        'name' => 'Musterfirma GmbH',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-100'
    ]);
    echo "✓ Organisation erstellt: {$orgA['name']}\n";
    
    // Standortadresse (Hauptsitz)
    $address1 = $orgService->addAddress($orgA['org_uuid'], [
        'address_type' => 'headquarters',
        'street' => 'Hauptstraße 123',
        'city' => 'Berlin',
        'postal_code' => '10115',
        'country' => 'Deutschland',
        'is_default' => true
    ]);
    echo "✓ Standortadresse hinzugefügt: {$address1['street']}, {$address1['city']}\n";
    
    // Lieferadresse
    $address2 = $orgService->addAddress($orgA['org_uuid'], [
        'address_type' => 'delivery',
        'street' => 'Lagerstraße 45',
        'city' => 'Hamburg',
        'postal_code' => '20095',
        'country' => 'Deutschland',
        'notes' => 'Zentrallager'
    ]);
    echo "✓ Lieferadresse hinzugefügt: {$address2['street']}, {$address2['city']}\n";
    
    // Rechnungsadresse
    $address3 = $orgService->addAddress($orgA['org_uuid'], [
        'address_type' => 'billing',
        'street' => 'Buchhaltung, Postfach 789',
        'city' => 'München',
        'postal_code' => '80331',
        'country' => 'Deutschland'
    ]);
    echo "✓ Rechnungsadresse hinzugefügt: {$address3['street']}, {$address3['city']}\n";
    
    // Alle Adressen abrufen
    $addresses = $orgService->getAddresses($orgA['org_uuid']);
    echo "\n📌 Alle Adressen ({$orgA['name']}):\n";
    foreach ($addresses as $addr) {
        echo "  - {$addr['address_type']}: {$addr['street']}, {$addr['city']} " . ($addr['is_default'] ? '(Standard)' : '') . "\n";
    }
    
    echo "\n";
    
    // ============================================================================
    // Beispiel B: Firmenhierarchie
    // ============================================================================
    echo "📋 Beispiel B: Firmenhierarchie\n\n";
    
    // O5 = Holding
    $orgO5 = $orgService->createOrg([
        'name' => 'Holding O5 AG',
        'org_kind' => 'other',
        'external_ref' => 'HOLD-001'
    ]);
    echo "✓ {$orgO5['name']} erstellt\n";
    
    // O1 = Hauptgesellschaft
    $orgO1 = $orgService->createOrg([
        'name' => 'Organisation O1 GmbH',
        'org_kind' => 'customer',
        'external_ref' => 'ORG-001'
    ]);
    echo "✓ {$orgO1['name']} erstellt\n";
    
    // O2 = Beteiligung (z.B. 30%)
    $orgO2 = $orgService->createOrg([
        'name' => 'Organisation O2 Ltd.',
        'org_kind' => 'customer',
        'external_ref' => 'ORG-002'
    ]);
    echo "✓ {$orgO2['name']} erstellt\n";
    
    // O3 = 100% Tochter
    $orgO3 = $orgService->createOrg([
        'name' => 'Organisation O3 GmbH',
        'org_kind' => 'customer',
        'external_ref' => 'ORG-003'
    ]);
    echo "✓ {$orgO3['name']} erstellt\n";
    
    // O4 = Niederlassung
    $orgO4 = $orgService->createOrg([
        'name' => 'Organisation O4 Branch',
        'org_kind' => 'customer',
        'external_ref' => 'ORG-004'
    ]);
    echo "✓ {$orgO4['name']} erstellt\n";
    
    // Relationen erstellen
    // O1 gehört zur Holding O5
    $rel1 = $orgService->addRelation([
        'parent_org_uuid' => $orgO5['org_uuid'],
        'child_org_uuid' => $orgO1['org_uuid'],
        'relation_type' => 'holding',
        'since_date' => '2020-01-01'
    ]);
    echo "✓ Relation: {$orgO5['name']} → {$orgO1['name']} (Holding)\n";
    
    // O1 hat Beteiligung an O2 (30%)
    $rel2 = $orgService->addRelation([
        'parent_org_uuid' => $orgO1['org_uuid'],
        'child_org_uuid' => $orgO2['org_uuid'],
        'relation_type' => 'ownership',
        'ownership_percent' => 30.00,
        'since_date' => '2021-06-15'
    ]);
    echo "✓ Relation: {$orgO1['name']} → {$orgO2['name']} (30% Beteiligung)\n";
    
    // O1 hat 100% Tochter O3
    $rel3 = $orgService->addRelation([
        'parent_org_uuid' => $orgO1['org_uuid'],
        'child_org_uuid' => $orgO3['org_uuid'],
        'relation_type' => 'subsidiary',
        'ownership_percent' => 100.00,
        'since_date' => '2019-03-01'
    ]);
    echo "✓ Relation: {$orgO1['name']} → {$orgO3['name']} (100% Tochter)\n";
    
    // O1 hat Niederlassung O4
    $rel4 = $orgService->addRelation([
        'parent_org_uuid' => $orgO1['org_uuid'],
        'child_org_uuid' => $orgO4['org_uuid'],
        'relation_type' => 'branch',
        'since_date' => '2022-09-10'
    ]);
    echo "✓ Relation: {$orgO1['name']} → {$orgO4['name']} (Niederlassung)\n";
    
    // Hierarchie abrufen
    echo "\n📊 Hierarchie von {$orgO1['name']}:\n";
    $relations = $orgService->getRelations($orgO1['org_uuid']);
    
    // Als Parent (O1 ist Kind von...)
    $asChild = array_filter($relations, function($r) use ($orgO1) {
        return $r['child_org_uuid'] === $orgO1['org_uuid'];
    });
    foreach ($asChild as $rel) {
        $parent = $orgService->getOrg($rel['parent_org_uuid']);
        echo "  ⬆️  {$orgO1['name']} gehört zu: {$parent['name']} ({$rel['relation_type']})\n";
    }
    
    // Als Child (O1 ist Parent von...)
    $asParent = array_filter($relations, function($r) use ($orgO1) {
        return $r['parent_org_uuid'] === $orgO1['org_uuid'];
    });
    foreach ($asParent as $rel) {
        $child = $orgService->getOrg($rel['child_org_uuid']);
        $percent = $rel['ownership_percent'] ? " ({$rel['ownership_percent']}%)" : '';
        echo "  ⬇️  {$orgO1['name']} hat: {$child['name']} ({$rel['relation_type']}{$percent})\n";
    }
    
    // Vollständige Org-Details abrufen
    echo "\n📋 Vollständige Details für {$orgO1['name']}:\n";
    $orgDetails = $orgService->getOrgWithDetails($orgO1['org_uuid']);
    echo "  Name: {$orgDetails['name']}\n";
    echo "  Art: {$orgDetails['org_kind']}\n";
    echo "  Adressen: " . count($orgDetails['addresses']) . "\n";
    echo "  Relationen: " . count($orgDetails['relations']) . "\n";
    
    echo "\n✅ Test erfolgreich abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}





