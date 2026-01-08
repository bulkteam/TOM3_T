<?php
/**
 * TOM3 - Recent Documents API
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

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
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
    jsonResponse($recent);
} catch (Exception $e) {
    handleApiException($e, 'Get recent documents');
}


