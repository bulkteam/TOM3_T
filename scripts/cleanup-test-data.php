<?php
/**
 * Löscht alle Testdaten, behält nur Systemdaten (User, Roles, Permissions, Industries, etc.)
 * 
 * WICHTIG: Dieses Skript löscht ALLE Testdaten!
 * - Organisationen (org)
 * - Personen (person)
 * - Workflows/Cases (case_item)
 * - Import-Batches und Staging-Daten
 * - Alle abhängigen Daten
 * 
 * BEHÄLT:
 * - User und Rollen
 * - Berechtigungen
 * - Industries (Branchen-Referenzdaten)
 * - Workflow-Definitionen
 * - Dokumente (optional)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  TOM3 - Testdaten löschen\n";
echo "========================================\n\n";
echo "⚠️  WARNUNG: Dieses Skript löscht ALLE Testdaten!\n";
echo "   - Organisationen\n";
echo "   - Personen\n";
echo "   - Workflows/Cases\n";
echo "   - Import-Daten\n";
echo "   - Alle abhängigen Daten\n\n";
echo "BEHÄLT: User, Rollen, Berechtigungen, Industries, Workflow-Definitionen\n\n";
echo "⚠️  Cleanup startet ohne Rückfrage...\n";

echo "\n🚀 Starte Löschvorgang...\n\n";

// Helper-Funktion: Prüft ob Tabelle existiert
function tableExists($db, $tableName) {
    try {
        $stmt = $db->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Helper-Funktion: Löscht Tabelle nur wenn sie existiert
function safeDelete($db, $tableName, $stepNumber, $description) {
    echo "{$stepNumber}. {$description}...\n";
    if (tableExists($db, $tableName)) {
        try {
            $stmt = $db->query("DELETE FROM `{$tableName}`");
            $count = $stmt->rowCount();
            echo "   ✓ {$count} Einträge gelöscht\n";
        } catch (Exception $e) {
            echo "   ⚠️  Fehler: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ⚠️  Tabelle existiert nicht (übersprungen)\n";
    }
}

try {
    $db->beginTransaction();
    
    // 1. Lösche Import-Staging-Daten (muss zuerst gelöscht werden, da Foreign Keys auf org verweisen)
    safeDelete($db, 'org_import_staging', '1', 'Lösche Import-Staging-Daten');
    
    // 2. Lösche Import-Batches
    echo "2. Lösche Import-Batches...\n";
    $stmt = $db->query("DELETE FROM org_import_batch");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Batches gelöscht\n";
    
    // 3. Lösche Import-Duplicate-Candidates
    echo "3. Lösche Import-Duplicate-Candidates...\n";
    $stmt = $db->query("DELETE FROM import_duplicate_candidates");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Import-Duplicate-Candidates gelöscht\n";
    
    // 4. Lösche Duplicate-Check-Results (falls Tabelle existiert)
    echo "4. Lösche Duplicate-Check-Results...\n";
    try {
        $stmt = $db->query("DELETE FROM duplicate_check_results");
        $count = $stmt->rowCount();
        echo "   ✓ {$count} Duplicate-Check-Results gelöscht\n";
    } catch (Exception $e) {
        echo "   ⚠️  Tabelle duplicate_check_results existiert nicht (übersprungen)\n";
    }
    
    // 5. Lösche Case-Items (Workflows)
    echo "5. Lösche Case-Items (Workflows)...\n";
    $stmt = $db->query("DELETE FROM case_item");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Cases gelöscht\n";
    
    // 6. Lösche Case-Notes
    echo "6. Lösche Case-Notes...\n";
    $stmt = $db->query("DELETE FROM case_note");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Case-Notes gelöscht\n";
    
    // 7. Lösche Case-Requirements
    echo "7. Lösche Case-Requirements...\n";
    $stmt = $db->query("DELETE FROM case_requirement");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Case-Requirements gelöscht\n";
    
    // 8. Lösche Person-Relationships
    echo "8. Lösche Person-Relationships...\n";
    $stmt = $db->query("DELETE FROM person_relationship");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Person-Relationships gelöscht\n";
    
    // 9. Lösche Person-Org-Roles
    echo "9. Lösche Person-Org-Roles...\n";
    $stmt = $db->query("DELETE FROM person_org_role");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Person-Org-Roles gelöscht\n";
    
    // 10. Lösche Person-Org-Shareholdings
    echo "10. Lösche Person-Org-Shareholdings...\n";
    $stmt = $db->query("DELETE FROM person_org_shareholding");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Person-Org-Shareholdings gelöscht\n";
    
    // 11. Lösche Person-Affiliations
    echo "11. Lösche Person-Affiliations...\n";
    $stmt = $db->query("DELETE FROM person_affiliation");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Person-Affiliations gelöscht\n";
    
    // 12. Lösche Person-Affiliation-Reporting
    echo "12. Lösche Person-Affiliation-Reporting...\n";
    $stmt = $db->query("DELETE FROM person_affiliation_reporting");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Person-Affiliation-Reporting gelöscht\n";
    
    // 13. Lösche Personen
    echo "13. Lösche Personen...\n";
    $stmt = $db->query("DELETE FROM person");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Personen gelöscht\n";
    
    // 14. Lösche Org-Communication-Channels
    echo "14. Lösche Org-Communication-Channels...\n";
    $stmt = $db->query("DELETE FROM org_communication_channel");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Communication-Channels gelöscht\n";
    
    // 15. Lösche Org-VAT-Registrations
    echo "15. Lösche Org-VAT-Registrations...\n";
    $stmt = $db->query("DELETE FROM org_vat_registration");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} VAT-Registrations gelöscht\n";
    
    // 16. Lösche Org-Addresses
    echo "16. Lösche Org-Addresses...\n";
    $stmt = $db->query("DELETE FROM org_address");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Org-Addresses gelöscht\n";
    
    // 17. Lösche Org-Relations
    echo "17. Lösche Org-Relations...\n";
    $stmt = $db->query("DELETE FROM org_relation");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Org-Relations gelöscht\n";
    
    // 18. Lösche Org-Aliases
    echo "18. Lösche Org-Aliases...\n";
    $stmt = $db->query("DELETE FROM org_alias");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Org-Aliases gelöscht\n";
    
    // 19. Lösche Org-Audit-Trail
    echo "19. Lösche Org-Audit-Trail...\n";
    $stmt = $db->query("DELETE FROM org_audit_trail");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Audit-Trail-Einträge gelöscht\n";
    
    // 20. Lösche Org-Units
    echo "20. Lösche Org-Units...\n";
    $stmt = $db->query("DELETE FROM org_unit");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Org-Units gelöscht\n";
    
    // 21. Lösche Person-Audit-Trail
    echo "21. Lösche Person-Audit-Trail...\n";
    $stmt = $db->query("DELETE FROM person_audit_trail");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Person-Audit-Trail-Einträge gelöscht\n";
    
    // 22. Lösche User-Person-Access
    echo "22. Lösche User-Person-Access...\n";
    $stmt = $db->query("DELETE FROM user_person_access");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} User-Person-Access-Einträge gelöscht\n";
    
    // 23. Lösche User-Org-Access
    echo "23. Lösche User-Org-Access...\n";
    $stmt = $db->query("DELETE FROM user_org_access");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} User-Org-Access-Einträge gelöscht\n";
    
    // 24. Lösche Activity-Log (optional - könnte auch Systemdaten sein)
    echo "24. Lösche Activity-Log...\n";
    $stmt = $db->query("DELETE FROM activity_log");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Activity-Log-Einträge gelöscht\n";
    
    // 25. Lösche Project-Cases
    echo "25. Lösche Project-Cases...\n";
    $stmt = $db->query("DELETE FROM project_case");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Project-Cases gelöscht\n";
    
    // 26. Lösche Projects
    echo "26. Lösche Projects...\n";
    $stmt = $db->query("DELETE FROM project");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Projects gelöscht\n";
    
    // 27. Lösche Parties (nur wenn Tabelle existiert)
    safeDelete($db, 'party', '27', 'Lösche Parties');
    
    // 28. Lösche Organisationen (muss nach allen abhängigen Tabellen kommen)
    echo "28. Lösche Organisationen...\n";
    $stmt = $db->query("DELETE FROM org");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} Organisationen gelöscht\n";
    
    // 29. Lösche Document-Attachments (optional)
    safeDelete($db, 'document_attachments', '29', 'Lösche Document-Attachments');
    
    // 30. Lösche Documents (optional - könnte auch Systemdaten sein)
    safeDelete($db, 'documents', '30', 'Lösche Documents');
    
    // 31. Lösche User-Document-Access
    echo "31. Lösche User-Document-Access...\n";
    $stmt = $db->query("DELETE FROM user_document_access");
    $count = $stmt->rowCount();
    echo "   ✓ {$count} User-Document-Access-Einträge gelöscht\n";
    
    $db->commit();
    
    echo "\n✅ Alle Testdaten erfolgreich gelöscht!\n\n";
    echo "BEHALTEN:\n";
    echo "  ✓ User und Rollen\n";
    echo "  ✓ Berechtigungen (permissions, capabilities)\n";
    echo "  ✓ Industries (Branchen-Referenzdaten)\n";
    echo "  ✓ Workflow-Definitionen\n";
    echo "  ✓ System-Konfiguration\n\n";
    echo "Sie können jetzt einen neuen Import durchführen.\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Fehler beim Löschen: " . $e->getMessage() . "\n";
    echo "   Alle Änderungen wurden zurückgerollt.\n";
    exit(1);
}

