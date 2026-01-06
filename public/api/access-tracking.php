<?php
/**
 * TOM3 - Access Tracking API (Zentral)
 * Generischer Endpoint für Access-Tracking (recent, favorite, etc.)
 */

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
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
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
    http_response_code(400);
    echo json_encode(['error' => 'Invalid entity type. Must be "org", "person" or "document"']);
    exit;
}

if ($action === 'recent') {
    // GET /api/access-tracking/{entityType}/recent
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    $userId = AuthHelper::getCurrentUserId();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    try {
        $recent = $accessTrackingService->getRecentEntities($entityType, $userId, $limit);
        echo json_encode($recent ?: []);
    } catch (Exception $e) {
        require_once __DIR__ . '/api-security.php';
        sendErrorResponse($e);
    }
} elseif ($action === 'track') {
    // POST /api/access-tracking/{entityType}/track
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
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
        http_response_code(400);
        echo json_encode(['error' => $uuidField . ' required']);
        exit;
    }
    
    try {
        $accessTrackingService->trackAccess($entityType, $userId, $entityUuid, $accessType);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        require_once __DIR__ . '/api-security.php';
        sendErrorResponse($e);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Action not found']);
}


