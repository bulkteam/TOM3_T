<?php
/**
 * TOM3 - Recent Persons API
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

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
}

// Hole aktuellen User aus Session/Auth
$userId = AuthHelper::getCurrentUserId();
if (!$userId) {
    jsonError('Unauthorized', 401);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    $recent = $personService->getRecentPersons($userId, $limit);
    jsonResponse($recent ?: []);
} catch (Exception $e) {
    handleApiException($e, 'Get recent persons');
}


