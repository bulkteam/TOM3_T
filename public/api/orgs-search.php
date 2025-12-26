<?php
/**
 * TOM3 - Orgs Search API (Autocomplete)
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $orgService = new OrgService($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Query und Filter aus Query-Parametern extrahieren
$query = $_GET['q'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

$filters = [];
if (!empty($_GET['industry'])) $filters['industry'] = $_GET['industry'];
if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
if (!empty($_GET['tier'])) $filters['tier'] = $_GET['tier'];
if (isset($_GET['strategic'])) $filters['strategic'] = $_GET['strategic'] === '1';
if (!empty($_GET['org_kind'])) $filters['org_kind'] = $_GET['org_kind'];
if (!empty($_GET['city'])) $filters['city'] = $_GET['city'];
if (!empty($_GET['revenue_min'])) $filters['revenue_min'] = (float)$_GET['revenue_min'];
if (!empty($_GET['employees_min'])) $filters['employees_min'] = (int)$_GET['employees_min'];
// Autocomplete-Suche zeigt standardmäßig auch archivierte Organisationen
$filters['include_archived'] = isset($_GET['include_archived']) ? $_GET['include_archived'] === 'true' : true;

if (empty($query) && empty($filters)) {
    echo json_encode([]);
    exit;
}

$results = $orgService->searchOrgs($query, $filters, $limit);
echo json_encode($results ?: []);

