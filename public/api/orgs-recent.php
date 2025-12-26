<?php
/**
 * TOM3 - Recent Orgs API
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
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// TODO: Echten user_id aus Session/Auth holen
$userId = $_GET['user_id'] ?? 'default_user';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    $recent = $orgService->getRecentOrgs($userId, $limit);
    echo json_encode($recent ?: []);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

