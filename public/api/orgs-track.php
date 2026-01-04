<?php
/**
 * TOM3 - Track Org Access API
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

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orgUuid = $data['org_uuid'] ?? null;
$userId = $data['user_id'] ?? 'default_user';
$accessType = $data['access_type'] ?? 'recent';

if (!$orgUuid) {
    http_response_code(400);
    echo json_encode(['error' => 'org_uuid required']);
    exit;
}

try {
    $orgService->trackAccess($userId, $orgUuid, $accessType);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}





