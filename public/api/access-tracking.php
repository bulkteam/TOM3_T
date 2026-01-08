<?php
/**
 * TOM3 - Access Tracking API (Zentral)
 * Generischer Endpoint für Access-Tracking (recent, favorite, etc.)
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

use TOM\Infrastructure\Access\AccessTrackingService;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $db = DatabaseConnection::getInstance();
    $accessTrackingService = new AccessTrackingService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/tom3/public#i', '', $path);
$path = preg_replace('#^/api/access-tracking/?|^access-tracking/?#', '', $path);
$path = trim($path, '/');
$parts = explode('/', $path);

$entityType = $parts[0] ?? null; // 'org' | 'person'
$action = $parts[1] ?? null; // 'recent' | 'track'

// Validiere Entity-Typ
if (!in_array($entityType, ['org', 'person', 'document'])) {
    jsonError('Invalid entity type. Must be "org", "person" or "document"', 400);
}

if ($action === 'recent') {
    // GET /api/access-tracking/{entityType}/recent
    if ($method !== 'GET') {
        jsonError('Method not allowed', 405);
    }
    
    // Verwende user_id aus Query-Parameter oder Session
    $userId = $_GET['user_id'] ?? AuthHelper::getCurrentUserId();
    if (!$userId) {
        jsonError('Unauthorized', 401);
    }
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    try {
        $recent = $accessTrackingService->getRecentEntities($entityType, $userId, $limit);
        jsonResponse($recent ?: []);
    } catch (Exception $e) {
        handleApiException($e, 'Get recent entities');
    }
} elseif ($action === 'track') {
    // POST /api/access-tracking/{entityType}/track
    if ($method !== 'POST') {
        jsonError('Method not allowed', 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    // Unterstütze verschiedene UUID-Feldnamen
    $uuidFieldMap = [
        'org' => 'org_uuid',
        'person' => 'person_uuid',
        'document' => 'document_uuid'
    ];
    $uuidField = $uuidFieldMap[$entityType] ?? $entityType . '_uuid';
    $entityUuid = $data[$uuidField] ?? null;
    // Security: Auth erzwingen (kein 'default_user' Fallback)
    require_once __DIR__ . '/api-security.php';
    $currentUser = requireAuth();
    $userId = (string)$currentUser['user_id'];
    $accessType = $data['access_type'] ?? 'recent';
    
    if (!$entityUuid) {
        jsonError($uuidField . ' required', 400);
    }
    
    try {
        $accessTrackingService->trackAccess($entityType, $userId, $entityUuid, $accessType);
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        handleApiException($e, 'Track access');
    }
} else {
    jsonError('Action not found', 404);
}


