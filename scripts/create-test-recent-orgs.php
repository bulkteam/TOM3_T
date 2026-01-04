<?php
/**
 * TOM3 - Erstelle Testdaten für "Zuletzt verwendet"
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== Erstelle Testdaten: Zuletzt verwendet ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    $orgService = new OrgService($db);
    
    // Hole alle Organisationen
    $orgs = $orgService->listOrgs();
    
    if (empty($orgs)) {
        echo "❌ Keine Organisationen gefunden. Bitte erst Testdaten erstellen.\n";
        exit(1);
    }
    
    $userId = 'default_user';
    
    // Track Zugriff auf die ersten 3-5 Organisationen
    $orgsToTrack = array_slice($orgs, 0, min(5, count($orgs)));
    
    echo "📝 Tracke Zugriffe für " . count($orgsToTrack) . " Organisationen...\n\n";
    
    foreach ($orgsToTrack as $org) {
        $orgService->trackAccess($userId, $org['org_uuid'], 'recent');
        echo "  ✓ {$org['name']}\n";
    }
    
    echo "\n✅ Testdaten erstellt!\n";
    echo "\n📊 Prüfe 'Zuletzt verwendet' Sektion in der UI\n";
    
} catch (Exception $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    exit(1);
}





