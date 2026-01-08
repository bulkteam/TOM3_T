<?php
/**
 * TOM3 - Track Document Access API
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

use TOM\Service\DocumentService;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $db = DatabaseConnection::getInstance();
    $documentService = new DocumentService($db);
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
$documentUuid = $data['document_uuid'] ?? null;
$accessType = $data['access_type'] ?? 'recent';

if (!$documentUuid) {
    jsonError('document_uuid required', 400);
}

try {
    $documentService->trackAccess($userId, $documentUuid, $accessType);
    jsonResponse(['success' => true]);
} catch (Exception $e) {
    handleApiException($e, 'Track document access');
}


