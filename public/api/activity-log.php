<?php
/**
 * Activity-Log API Endpoint
 * 
 * GET /api/activity-log?user_id=1&action_type=login&limit=50
 * GET /api/activity-log/user/{user_id}?limit=50&offset=0
 * GET /api/activity-log/entity/{entity_type}/{entity_uuid}?limit=50
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

use TOM\Infrastructure\Activity\ActivityLogService;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $activityLogService = new ActivityLogService();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_GET['path'] ?? '';
    $pathParts = explode('/', trim($path, '/'));
    
    if ($method === 'GET') {
        // GET /api/activity-log/user/{user_id}
        if (count($pathParts) === 2 && $pathParts[0] === 'user') {
            $userId = $pathParts[1];
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $activities = $activityLogService->getUserActivities($userId, $limit, $offset);
            $total = $activityLogService->countActivities(['user_id' => $userId]);
            
            jsonResponse([
                'success' => true,
                'data' => $activities,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]);
        }
        // GET /api/activity-log/entity/{entity_type}/{entity_uuid}
        elseif (count($pathParts) === 3 && $pathParts[0] === 'entity') {
            $entityType = $pathParts[1];
            $entityUuid = $pathParts[2];
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            
            $activities = $activityLogService->getEntityActivities($entityType, $entityUuid, $limit);
            
            jsonResponse([
                'success' => true,
                'data' => $activities
            ]);
        }
        // GET /api/activity-log (mit Filtern)
        else {
            $filters = [];
            
            if (isset($_GET['user_id'])) {
                $filters['user_id'] = $_GET['user_id'];
            }
            if (isset($_GET['action_type'])) {
                $filters['action_type'] = $_GET['action_type'];
            }
            if (isset($_GET['entity_type'])) {
                $filters['entity_type'] = $_GET['entity_type'];
            }
            if (isset($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (isset($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $activities = $activityLogService->getActivities($filters, $limit, $offset);
            $total = $activityLogService->countActivities($filters);
            
            jsonResponse([
                'success' => true,
                'data' => $activities,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]);
        }
    } else {
        jsonError('Method not allowed', 405);
    }
} catch (\Exception $e) {
    handleApiException($e, 'Activity Log');
}


