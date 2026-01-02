<?php
/**
 * TOM3 - Track Document Access API
 */

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
$documentUuid = $data['document_uuid'] ?? null;
$userId = AuthHelper::getCurrentUserId() ?? $data['user_id'] ?? 'default_user';
$accessType = $data['access_type'] ?? 'recent';

if (!$documentUuid) {
    http_response_code(400);
    echo json_encode(['error' => 'document_uuid required']);
    exit;
}

try {
    $documentService->trackAccess($userId, $documentUuid, $accessType);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
