<?php
/**
 * TOM3 - Queues API
 * API für Queue-Operationen (Inside Sales)
 */

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\WorkItemService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $workItemService = new WorkItemService($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Auth prüfen
$currentUser = requireAuth();
$currentUserId = (string)$currentUser['user_id'];

// CSRF prüfen für state-changing Requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    validateCsrfToken($method);
}

// Parse path
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = preg_replace('#^/tom3/public#i', '', $path);
$path = preg_replace('#^/api/?|^api/?#', '', $path);
$path = trim($path, '/');
$pathParts = explode('/', $path);

// queues ist parts[0], engine ist parts[1], action ist parts[2]
$engine = isset($pathParts[1]) && !empty($pathParts[1]) ? $pathParts[1] : null;
$action = isset($pathParts[2]) && !empty($pathParts[2]) ? $pathParts[2] : null;

if ($engine === 'inside-sales' && $action === 'next') {
    // POST /api/queues/inside-sales/next?tab=...
    if ($method === 'POST') {
        $tab = $_GET['tab'] ?? null;
        $lead = $workItemService->getNextLead($currentUserId, $tab);
        if ($lead) {
            // Stelle sicher, dass priority_stars als Integer zurückgegeben wird
            $lead['priority_stars'] = isset($lead['priority_stars']) ? (int)$lead['priority_stars'] : 0;
            // Stelle sicher, dass company_phone als String zurückgegeben wird (auch wenn leer)
            $lead['company_phone'] = isset($lead['company_phone']) ? trim((string)$lead['company_phone']) : null;
        }
        echo json_encode($lead ?: null);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
}

