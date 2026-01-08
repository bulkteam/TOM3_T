<?php
/**
 * TOM3 - Track Person Access API
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

use TOM\Service\PersonService;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $db = DatabaseConnection::getInstance();
    $personService = new PersonService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Security: Auth erzwingen (kein 'default_user' Fallback)
require_once __DIR__ . '/api-security.php';
$currentUser = requireAuth();
$userId = (string)$currentUser['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
$personUuid = $data['person_uuid'] ?? null;
$accessType = $data['access_type'] ?? 'recent';

if (!$personUuid) {
    jsonError('person_uuid required', 400);
}

try {
    $personService->trackAccess($userId, $personUuid, $accessType);
    jsonResponse(['success' => true]);
} catch (Exception $e) {
    handleApiException($e, 'Track person access');
}


