<?php
/**
 * TOM3 - Testdaten-Generator
 * 
 * Erstellt realistische Testdaten für TOM3 basierend auf den TOM-Konzept-Dokumenten.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\OrgService;
use TOM\Service\PersonService;
use TOM\Service\ProjectService;
use TOM\Service\CaseService;
use TOM\Service\TaskService;
use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3 Testdaten-Generator ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    // Services initialisieren
    $orgService = new OrgService($db);
    $personService = new PersonService($db);
    $projectService = new ProjectService($db);
    $caseService = new CaseService($db);
    $taskService = new TaskService($db);
    
    echo "📦 Erstelle Testdaten...\n\n";
    
    // ============================================================================
    // 1. Organisationen
    // ============================================================================
    echo "1. Organisationen erstellen...\n";
    
    $orgs = [];
    
    // Kunden
    $orgs['acme'] = $orgService->createOrg([
        'name' => 'ACME Corporation',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-001',
        'website' => 'https://www.acme-corp.com',
        'revenue_range' => 'large',
        'employee_count' => 5000,
        'status' => 'customer'
    ]);
    echo "  ✓ ACME Corporation (Kunde)\n";
    
    $orgs['techcorp'] = $orgService->createOrg([
        'name' => 'TechCorp Industries',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-002',
        'website' => 'https://www.techcorp.de',
        'revenue_range' => 'medium',
        'employee_count' => 250,
        'status' => 'customer'
    ]);
    echo "  ✓ TechCorp Industries (Kunde)\n";
    
    $orgs['global'] = $orgService->createOrg([
        'name' => 'Global Solutions GmbH',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-003',
        'website' => 'https://www.global-solutions.de',
        'revenue_range' => 'enterprise',
        'employee_count' => 12000,
        'status' => 'customer'
    ]);
    echo "  ✓ Global Solutions GmbH (Kunde)\n";
    
    $orgs['innovate'] = $orgService->createOrg([
        'name' => 'Innovate Systems AG',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-004',
        'website' => 'https://www.innovate-systems.com',
        'revenue_range' => 'small',
        'employee_count' => 50,
        'status' => 'lead'
    ]);
    echo "  ✓ Innovate Systems AG (Kunde)\n";
    
    $orgs['mega'] = $orgService->createOrg([
        'name' => 'Mega Manufacturing GmbH',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-005',
        'website' => 'https://www.mega-manufacturing.de',
        'revenue_range' => 'large',
        'employee_count' => 3000,
        'status' => 'customer'
    ]);
    echo "  ✓ Mega Manufacturing GmbH (Kunde)\n";
    
    $orgs['startup'] = $orgService->createOrg([
        'name' => 'Startup Dynamics e.K.',
        'org_kind' => 'customer',
        'external_ref' => 'CUST-006',
        'website' => 'https://www.startup-dynamics.de',
        'revenue_range' => 'micro',
        'employee_count' => 5,
        'status' => 'lead'
    ]);
    echo "  ✓ Startup Dynamics e.K. (Kunde)\n";
    
    // Lieferanten
    $orgs['supplier1'] = $orgService->createOrg([
        'name' => 'Premium Components Ltd.',
        'org_kind' => 'supplier',
        'external_ref' => 'SUP-001',
        'website' => 'https://www.premium-components.com',
        'revenue_range' => 'medium',
        'employee_count' => 200
    ]);
    echo "  ✓ Premium Components Ltd. (Lieferant)\n";
    
    $orgs['supplier2'] = $orgService->createOrg([
        'name' => 'Quality Parts AG',
        'org_kind' => 'supplier',
        'external_ref' => 'SUP-002',
        'website' => 'https://www.quality-parts.de',
        'revenue_range' => 'small',
        'employee_count' => 80
    ]);
    echo "  ✓ Quality Parts AG (Lieferant)\n";
    
    $orgs['supplier3'] = $orgService->createOrg([
        'name' => 'Global Supply Chain GmbH',
        'org_kind' => 'supplier',
        'external_ref' => 'SUP-003',
        'website' => 'https://www.global-supply.de',
        'revenue_range' => 'large',
        'employee_count' => 1500
    ]);
    echo "  ✓ Global Supply Chain GmbH (Lieferant)\n";
    
    // Berater
    $orgs['consultant'] = $orgService->createOrg([
        'name' => 'Engineering Consultants GmbH',
        'org_kind' => 'consultant',
        'external_ref' => 'CON-001'
    ]);
    echo "  ✓ Engineering Consultants GmbH (Berater)\n";
    
    // Interne
    $orgs['internal'] = $orgService->createOrg([
        'name' => 'Eigene Firma',
        'org_kind' => 'internal',
        'external_ref' => 'INT-001'
    ]);
    echo "  ✓ Eigene Firma (Intern)\n";
    
    echo "\n";
    
    // ============================================================================
    // 2. Personen
    // ============================================================================
    echo "2. Personen erstellen...\n";
    
    $persons = [];
    
    // Personen bei ACME
    $persons['acme_ceo'] = $personService->createPerson([
        'first_name' => 'Max',
        'last_name' => 'Mustermann',
        'email' => 'max.mustermann@acme-corp.com',
        'phone' => '+49 30 12345678'
    ]);
    echo "  ✓ Max Mustermann (ACME)\n";
    
    $persons['acme_procurement'] = $personService->createPerson([
        'first_name' => 'Anna',
        'last_name' => 'Schmidt',
        'email' => 'anna.schmidt@acme-corp.com',
        'phone' => '+49 30 12345679'
    ]);
    echo "  ✓ Anna Schmidt (ACME)\n";
    
    $persons['acme_cto'] = $personService->createPerson([
        'first_name' => 'Michael',
        'last_name' => 'Bauer',
        'title' => 'Dr.',
        'email' => 'michael.bauer@acme-corp.com',
        'phone' => '+49 30 12345680'
    ]);
    echo "  ✓ Dr. Michael Bauer (ACME)\n";
    
    // Personen bei TechCorp
    $persons['techcorp_cto'] = $personService->createPerson([
        'first_name' => 'Thomas',
        'last_name' => 'Weber',
        'title' => 'Dr.',
        'email' => 'thomas.weber@techcorp.de',
        'phone' => '+49 40 98765432'
    ]);
    echo "  ✓ Dr. Thomas Weber (TechCorp)\n";
    
    $persons['techcorp_pm'] = $personService->createPerson([
        'first_name' => 'Lisa',
        'last_name' => 'Müller',
        'email' => 'lisa.mueller@techcorp.de',
        'phone' => '+49 40 98765433'
    ]);
    echo "  ✓ Lisa Müller (TechCorp)\n";
    
    $persons['techcorp_sales'] = $personService->createPerson([
        'first_name' => 'Julia',
        'last_name' => 'Fischer',
        'email' => 'julia.fischer@techcorp.de',
        'phone' => '+49 40 98765434'
    ]);
    echo "  ✓ Julia Fischer (TechCorp)\n";
    
    // Personen bei Global Solutions
    $persons['global_manager'] = $personService->createPerson([
        'first_name' => 'Peter',
        'last_name' => 'Hoffmann',
        'email' => 'peter.hoffmann@global-solutions.de',
        'phone' => '+49 89 55555555'
    ]);
    echo "  ✓ Peter Hoffmann (Global Solutions)\n";
    
    $persons['global_procurement'] = $personService->createPerson([
        'first_name' => 'Sabine',
        'last_name' => 'Wagner',
        'email' => 'sabine.wagner@global-solutions.de',
        'phone' => '+49 89 55555556'
    ]);
    echo "  ✓ Sabine Wagner (Global Solutions)\n";
    
    // Personen bei Innovate Systems
    $persons['innovate_ceo'] = $personService->createPerson([
        'first_name' => 'Alexander',
        'last_name' => 'Neumann',
        'email' => 'alexander.neumann@innovate-systems.com',
        'phone' => '+49 211 77777777'
    ]);
    echo "  ✓ Alexander Neumann (Innovate Systems)\n";
    
    // Personen bei Mega Manufacturing
    $persons['mega_procurement'] = $personService->createPerson([
        'first_name' => 'Robert',
        'last_name' => 'Schneider',
        'email' => 'robert.schneider@mega-manufacturing.de',
        'phone' => '+49 69 88888888'
    ]);
    echo "  ✓ Robert Schneider (Mega Manufacturing)\n";
    
    $persons['mega_engineer'] = $personService->createPerson([
        'first_name' => 'Daniel',
        'last_name' => 'Richter',
        'email' => 'daniel.richter@mega-manufacturing.de',
        'phone' => '+49 69 88888889'
    ]);
    echo "  ✓ Daniel Richter (Mega Manufacturing)\n";
    
    // Personen bei Startup Dynamics
    $persons['startup_founder'] = $personService->createPerson([
        'first_name' => 'Maria',
        'last_name' => 'Becker',
        'email' => 'maria.becker@startup-dynamics.de',
        'phone' => '+49 711 99999999'
    ]);
    echo "  ✓ Maria Becker (Startup Dynamics)\n";
    
    // Personen bei Lieferanten
    $persons['supplier1_contact'] = $personService->createPerson([
        'first_name' => 'Klaus',
        'last_name' => 'Zimmermann',
        'email' => 'klaus.zimmermann@premium-components.com',
        'phone' => '+49 421 11111111'
    ]);
    echo "  ✓ Klaus Zimmermann (Premium Components)\n";
    
    $persons['supplier2_contact'] = $personService->createPerson([
        'first_name' => 'Nicole',
        'last_name' => 'Schulz',
        'email' => 'nicole.schulz@quality-parts.de',
        'phone' => '+49 351 22222222'
    ]);
    echo "  ✓ Nicole Schulz (Quality Parts)\n";
    
    // Interne Personen
    $persons['internal_sales'] = $personService->createPerson([
        'first_name' => 'Sarah',
        'last_name' => 'Klein',
        'email' => 'sarah.klein@eigene-firma.de',
        'phone' => '+49 221 11111111'
    ]);
    echo "  ✓ Sarah Klein (Intern)\n";
    
    $persons['internal_ops'] = $personService->createPerson([
        'first_name' => 'Thomas',
        'last_name' => 'Meier',
        'email' => 'thomas.meier@eigene-firma.de',
        'phone' => '+49 221 11111112'
    ]);
    echo "  ✓ Thomas Meier (Intern)\n";
    
    $persons['internal_manager'] = $personService->createPerson([
        'first_name' => 'Jennifer',
        'last_name' => 'Lange',
        'email' => 'jennifer.lange@eigene-firma.de',
        'phone' => '+49 221 11111113'
    ]);
    echo "  ✓ Jennifer Lange (Intern)\n";
    
    echo "\n";
    
    // ============================================================================
    // 3. Projekte
    // ============================================================================
    echo "3. Projekte erstellen...\n";
    
    $projects = [];
    
    $projects['project1'] = $projectService->createProject([
        'name' => 'ACME Modernisierung 2025',
        'status' => 'active',
        'priority' => 1,
        'target_date' => '2025-06-30',
        'sponsor_org_uuid' => $orgs['acme']['org_uuid']
    ]);
    echo "  ✓ ACME Modernisierung 2025\n";
    
    $projects['project2'] = $projectService->createProject([
        'name' => 'TechCorp System-Integration',
        'status' => 'active',
        'priority' => 2,
        'target_date' => '2025-08-15',
        'sponsor_org_uuid' => $orgs['techcorp']['project_uuid'] ?? $orgs['techcorp']['org_uuid']
    ]);
    echo "  ✓ TechCorp System-Integration\n";
    
    $projects['project3'] = $projectService->createProject([
        'name' => 'Global Solutions Pilot',
        'status' => 'on_hold',
        'priority' => 3,
        'target_date' => '2025-09-30',
        'sponsor_org_uuid' => $orgs['global']['org_uuid']
    ]);
    echo "  ✓ Global Solutions Pilot\n";
    
    echo "\n";
    
    // ============================================================================
    // 4. Vorgänge (Cases)
    // ============================================================================
    echo "4. Vorgänge erstellen...\n";
    
    $cases = [];
    
    // Customer Inbound - Neue Anfrage
    $cases['case1'] = $caseService->createCase([
        'case_type' => 'anfrage',
        'engine' => 'customer_inbound',
        'phase' => 'CI-A',
        'owner_role' => 'customer_inbound',
        'title' => 'Anfrage: Preis für Komponente XYZ',
        'description' => 'Kunde fragt nach Preis und Verfügbarkeit für Komponente XYZ. Dringend benötigt für Projektstart.',
        'org_uuid' => $orgs['acme']['org_uuid'],
        'project_uuid' => $projects['project1']['project_uuid'],
        'priority' => 1
    ]);
    echo "  ✓ Anfrage: Preis für Komponente XYZ (CI-A)\n";
    
    // Customer Inbound - Klassifiziert, bereit für Übergabe
    $cases['case2'] = $caseService->createCase([
        'case_type' => 'reklamation',
        'engine' => 'customer_inbound',
        'phase' => 'CI-B',
        'owner_role' => 'customer_inbound',
        'title' => 'Reklamation: Defektes Bauteil',
        'description' => 'Kunde meldet defektes Bauteil in Charge 2024-12-15. Seriennummer: SN-12345',
        'org_uuid' => $orgs['techcorp']['org_uuid'],
        'priority' => 2
    ]);
    echo "  ✓ Reklamation: Defektes Bauteil (CI-B)\n";
    
    // OPS - In Bearbeitung
    $cases['case3'] = $caseService->createCase([
        'case_type' => 'anfrage',
        'engine' => 'ops',
        'phase' => 'OPS-A',
        'owner_role' => 'ops',
        'title' => 'Technische Klärung: Spezifikationen',
        'description' => 'Kunde benötigt detaillierte technische Spezifikationen für Produktlinie ABC',
        'org_uuid' => $orgs['global']['org_uuid'],
        'project_uuid' => $projects['project3']['project_uuid'],
        'priority' => 1
    ]);
    echo "  ✓ Technische Klärung: Spezifikationen (OPS-A)\n";
    
    // OPS - Entscheidungsreife
    $cases['case4'] = $caseService->createCase([
        'case_type' => 'anfrage',
        'engine' => 'ops',
        'phase' => 'OPS-C',
        'owner_role' => 'ops',
        'title' => 'Angebotsvorbereitung: Großauftrag',
        'description' => 'Vorbereitung Angebot für Großauftrag. Alle technischen Details geklärt, Preisgestaltung erforderlich.',
        'org_uuid' => $orgs['acme']['org_uuid'],
        'project_uuid' => $projects['project1']['project_uuid'],
        'priority' => 1
    ]);
    echo "  ✓ Angebotsvorbereitung: Großauftrag (OPS-C)\n";
    
    // Outside Sales
    $cases['case5'] = $caseService->createCase([
        'case_type' => 'angebot',
        'engine' => 'outside_sales',
        'phase' => 'OS-B',
        'owner_role' => 'outside_sales',
        'title' => 'Verhandlung: Rahmenvertrag 2025',
        'description' => 'Verhandlung über Rahmenvertrag für 2025. Entscheidung steht bevor.',
        'org_uuid' => $orgs['techcorp']['org_uuid'],
        'project_uuid' => $projects['project2']['project_uuid'],
        'priority' => 1
    ]);
    echo "  ✓ Verhandlung: Rahmenvertrag 2025 (OS-B)\n";
    
    // Order Admin
    $cases['case6'] = $caseService->createCase([
        'case_type' => 'auftrag',
        'engine' => 'order_admin',
        'phase' => 'OA-A',
        'owner_role' => 'order_admin',
        'title' => 'Auftragsbearbeitung: PO-2025-001',
        'description' => 'Formale Prüfung und Anlage des Auftrags PO-2025-001',
        'org_uuid' => $orgs['acme']['org_uuid'],
        'project_uuid' => $projects['project1']['project_uuid'],
        'priority' => 1
    ]);
    echo "  ✓ Auftragsbearbeitung: PO-2025-001 (OA-A)\n";
    
    echo "\n";
    
    // ============================================================================
    // 5. Aufgaben (Tasks)
    // ============================================================================
    echo "5. Aufgaben erstellen...\n";
    
    // Tasks für Case 1
    $taskService->createTask([
        'case_uuid' => $cases['case1']['case_uuid'],
        'title' => 'Preisliste aktualisieren',
        'assignee_role' => 'ops',
        'due_at' => date('Y-m-d H:i:s', strtotime('+2 days'))
    ]);
    echo "  ✓ Aufgabe: Preisliste aktualisieren\n";
    
    // Tasks für Case 3
    $taskService->createTask([
        'case_uuid' => $cases['case3']['case_uuid'],
        'title' => 'Technische Dokumentation prüfen',
        'assignee_role' => 'ops',
        'due_at' => date('Y-m-d H:i:s', strtotime('+1 day'))
    ]);
    echo "  ✓ Aufgabe: Technische Dokumentation prüfen\n";
    
    $taskService->createTask([
        'case_uuid' => $cases['case3']['case_uuid'],
        'title' => 'Rückfrage an Kunde: Zusatzanforderungen',
        'assignee_role' => 'ops',
        'due_at' => date('Y-m-d H:i:s', strtotime('+3 days'))
    ]);
    echo "  ✓ Aufgabe: Rückfrage an Kunde\n";
    
    echo "\n";
    
    // ============================================================================
    // 6. Notizen
    // ============================================================================
    echo "6. Notizen hinzufügen...\n";
    
    $caseService->addNote($cases['case1']['case_uuid'], 'Kunde hat bereits ähnliche Anfrage im letzten Quartal gestellt. Referenz: Case-2024-Q4-123');
    echo "  ✓ Notiz zu Case 1\n";
    
    $caseService->addNote($cases['case3']['case_uuid'], 'Kunde benötigt Zertifikate und Compliance-Nachweise für den Einsatz in kritischen Systemen.');
    echo "  ✓ Notiz zu Case 3\n";
    
    $caseService->addNote($cases['case4']['case_uuid'], 'Empfehlung: Option A (Standard) oder Option B (Premium) mit erweitertem Support. Option B hat höhere Marge.');
    echo "  ✓ Notiz zu Case 4\n";
    
    echo "\n";
    
    // ============================================================================
    // 7. Projekt-Verknüpfungen
    // ============================================================================
    echo "7. Projekt-Verknüpfungen erstellen...\n";
    
    // Verknüpfe Cases mit Projekten
    $projectService->linkCase($projects['project1']['project_uuid'], $cases['case1']['case_uuid']);
    echo "  ✓ Case 1 mit Projekt 1 verknüpft\n";
    
    $projectService->linkCase($projects['project1']['project_uuid'], $cases['case4']['case_uuid']);
    echo "  ✓ Case 4 mit Projekt 1 verknüpft\n";
    
    $projectService->linkCase($projects['project1']['project_uuid'], $cases['case6']['case_uuid']);
    echo "  ✓ Case 6 mit Projekt 1 verknüpft\n";
    
    $projectService->linkCase($projects['project2']['project_uuid'], $cases['case5']['case_uuid']);
    echo "  ✓ Case 5 mit Projekt 2 verknüpft\n";
    
    $projectService->linkCase($projects['project3']['project_uuid'], $cases['case3']['case_uuid']);
    echo "  ✓ Case 3 mit Projekt 3 verknüpft\n";
    
    echo "\n";
    
    // ============================================================================
    // Zusammenfassung
    // ============================================================================
    echo "=== Zusammenfassung ===\n\n";
    echo "Erstellt:\n";
    echo "  - " . count($orgs) . " Organisationen (6 Kunden, 3 Lieferanten, 1 Berater, 1 Intern)\n";
    echo "  - " . count($persons) . " Personen\n";
    echo "  - 3 Projekte\n";
    echo "  - 6 Vorgänge (verschiedene Engines und Phasen)\n";
    echo "  - 3 Aufgaben\n";
    echo "  - 3 Notizen\n";
    echo "  - 5 Projekt-Verknüpfungen\n";
    echo "\n";
    echo "✓ Testdaten erfolgreich erstellt!\n";
    echo "\n";
    echo "📊 Dashboard: http://localhost/TOM3/public/\n";
    echo "📋 Vorgänge: http://localhost/TOM3/public/#cases\n";
    echo "📁 Projekte: http://localhost/TOM3/public/#projects\n";
    
} catch (Exception $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}



