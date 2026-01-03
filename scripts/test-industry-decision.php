<?php
/**
 * Testet Industry-Decision Service
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Import\IndustryDecisionService;
use TOM\Service\Import\ImportStagingService;

$db = DatabaseConnection::getInstance();

echo "=== Test Industry-Decision Service ===\n\n";

// Hole eine Staging-Row
$stmt = $db->query("
    SELECT staging_uuid, industry_resolution
    FROM org_import_staging
    WHERE industry_resolution IS NOT NULL
    LIMIT 1
");
$staging = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staging) {
    echo "❌ Keine Staging-Row mit industry_resolution gefunden\n";
    exit(1);
}

echo "Staging UUID: {$staging['staging_uuid']}\n";
$resolution = json_decode($staging['industry_resolution'], true);
echo "Aktuelle Resolution:\n";
print_r($resolution);
echo "\n";

// Test: Bestätige Level 2
$decisionService = new IndustryDecisionService();
$userId = '5'; // Test-User

echo "Test: Bestätige Level 2...\n";
echo str_repeat("-", 80) . "\n";

// Hole eine Level 2 UUID
$stmt = $db->query("
    SELECT i2.industry_uuid, i2.name, i2.code, i1.industry_uuid as level1_uuid
    FROM industry i2
    INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
    WHERE i1.parent_industry_uuid IS NULL
    LIMIT 1
");
$level2 = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$level2) {
    echo "❌ Keine Level 2 Industry gefunden\n";
    exit(1);
}

echo "Level 2: {$level2['name']} [{$level2['industry_uuid']}]\n";
echo "Level 1: [{$level2['level1_uuid']}]\n\n";

try {
    $result = $decisionService->applyDecision(
        $staging['staging_uuid'],
        [
            'level1_uuid' => $level2['level1_uuid'],
            'level2_uuid' => $level2['industry_uuid'],
            'confirm_level2' => true
        ],
        $userId
    );
    
    echo "✅ Decision erfolgreich angewendet\n";
    echo "Result:\n";
    print_r($result);
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== Test abgeschlossen ===\n";
