<?php
/**
 * TOM3 - Recent Documents API
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

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Hole aktuellen User aus Session/Auth oder Query-Parameter
$userId = $_GET['user_id'] ?? AuthHelper::getCurrentUserId();
if (!$userId) {
    // Fallback auf default_user wenn kein User gefunden (für Entwicklung)
    $userId = 'default_user';
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    $recent = $documentService->getRecentDocuments($userId, $limit);
    // Immer ein Array zurückgeben, auch wenn leer
    if (!is_array($recent)) {
        $recent = [];
    }
    echo json_encode($recent);
} catch (Exception $e) {
    require_once __DIR__ . '/api-security.php';
    sendErrorResponse($e);
}


