<?php
/**
 * TOM3 - Track Org Access API
 */

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

// Security Guard: Verhindere direkten Aufruf
if (!defined('TOM3_API_ROUTER')) {
    jsonError('Direct access not allowed', 403);
}

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
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonError('Method not allowed', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$orgUuid = $data['org_uuid'] ?? null;
// Security: Auth erzwingen (kein 'default_user' Fallback)
require_once __DIR__ . '/api-security.php';
$currentUser = requireAuth();
$userId = (string)$currentUser['user_id'];
$accessType = $data['access_type'] ?? 'recent';

if (!$orgUuid) {
    jsonError('org_uuid required', 400);
}

try {
    $orgService->trackAccess($userId, $orgUuid, $accessType);
    jsonResponse(['success' => true]);
} catch (Exception $e) {
    handleApiException($e, 'Track org access');
}





