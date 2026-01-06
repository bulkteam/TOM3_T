<?php
/**
 * TOM3 - Work Items API
 * API für WorkItem-Operationen (Inside Sales)
 */

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\WorkItemService;
use TOM\Service\WorkItem\Timeline\WorkItemTimelineService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $workItemService = new WorkItemService($db);
    $timelineService = new WorkItemTimelineService($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Auth prüfen
$currentUser = requireAuth();
$currentUserId = (string)$currentUser['user_id'];

// CSRF prüfen für state-changing Requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    validateCsrfToken($method);
}

// Router-Variablen nutzen (vom Router gesetzt)
// $id = work item UUID (z.B. für /api/work-items/{uuid}/timeline)
// $action = action (z.B. 'timeline' für /api/work-items/{uuid}/timeline)
$workItemUuid = $id ?? null;
$action = $action ?? null;

switch ($method) {
    case 'GET':
        if ($workItemUuid) {
            if ($action === 'timeline') {
                // GET /api/work-items/{uuid}/timeline
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                $timeline = $workItemService->getTimeline($workItemUuid, $limit);
                echo json_encode($timeline);
            } else {
                // GET /api/work-items/{uuid}
                $workItem = $workItemService->getWorkItem($workItemUuid);
                if ($workItem) {
                    // Stelle sicher, dass priority_stars als Integer zurückgegeben wird
                    $workItem['priority_stars'] = isset($workItem['priority_stars']) ? (int)$workItem['priority_stars'] : 0;
                    // Stelle sicher, dass company_phone als String zurückgegeben wird (auch wenn leer)
                    $workItem['company_phone'] = isset($workItem['company_phone']) ? trim((string)$workItem['company_phone']) : null;
                    echo json_encode($workItem);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Work item not found']);
                }
            }
        } else {
            // GET /api/work-items?type=LEAD&tab=...&sort=...&order=...
            $type = $_GET['type'] ?? 'LEAD';
            $tab = $_GET['tab'] ?? null;
            $sortField = $_GET['sort'] ?? null;
            $sortOrder = $_GET['order'] ?? 'asc';
            
            $items = $workItemService->listWorkItems($type, $tab, $currentUserId, $sortField, $sortOrder);
            // Stelle sicher, dass priority_stars als Integer zurückgegeben wird
            // Stelle sicher, dass company_phone als String zurückgegeben wird (auch wenn leer)
            foreach ($items as &$item) {
                $item['priority_stars'] = isset($item['priority_stars']) ? (int)$item['priority_stars'] : 0;
                $item['company_phone'] = isset($item['company_phone']) ? trim((string)$item['company_phone']) : null;
            }
            unset($item);
            $stats = $workItemService->getQueueStats($type, $currentUserId);
            
            echo json_encode([
                'items' => $items,
                'counts' => $stats
            ]);
        }
        break;
        
    case 'PATCH':
        if ($workItemUuid) {
            // PATCH /api/work-items/{uuid}
            $data = json_decode(file_get_contents('php://input'), true);
            $workItem = $workItemService->updateWorkItem($workItemUuid, $data);
            // Stelle sicher, dass priority_stars als Integer zurückgegeben wird
            if ($workItem) {
                $workItem['priority_stars'] = isset($workItem['priority_stars']) ? (int)$workItem['priority_stars'] : 0;
            }
            echo json_encode($workItem);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Work item UUID required']);
        }
        break;
        
    case 'POST':
        if ($workItemUuid && $action === 'activities') {
            // POST /api/work-items/{uuid}/activities
            $data = json_decode(file_get_contents('php://input'), true);
            
            $activityType = $data['activity_type'] ?? 'USER_NOTE';
            $notes = $data['notes'] ?? null;
            $outcome = $data['outcome'] ?? null;
            $nextActionAt = isset($data['next_action_at']) ? new \DateTime($data['next_action_at']) : null;
            $nextActionType = $data['next_action_type'] ?? null;
            
            $timelineId = $timelineService->addUserNote(
                $workItemUuid,
                $currentUserId,
                $activityType,
                $notes,
                $outcome,
                $nextActionAt,
                $nextActionType
            );
            
            echo json_encode(['timeline_id' => $timelineId]);
        } elseif ($workItemUuid && $action === 'handoff') {
            // POST /api/work-items/{uuid}/handoff
            $data = json_decode(file_get_contents('php://input'), true);
            
            // TODO: Implementiere Handoff-Logik
            // Für jetzt: nur Timeline-Eintrag
            $handoffType = $data['handoff_type'] ?? 'QUOTE_REQUEST';
            $notes = $data['notes'] ?? '';
            $metadata = [
                'handoff_type' => $handoffType,
                'need_summary' => $data['need_summary'] ?? '',
                'contact_hint' => $data['contact_hint'] ?? '',
                'next_step' => $data['next_step'] ?? ''
            ];
            
            $timelineId = $timelineService->addHandoffActivityWithMetadata(
                $workItemUuid,
                $currentUserId,
                $notes,
                $metadata
            );
            
            // Update WorkItem stage
            $workItemService->updateWorkItem($workItemUuid, [
                'stage' => $handoffType === 'QUOTE_REQUEST' ? 'QUALIFIED' : 'DATA_CHECK'
            ]);
            
            echo json_encode(['timeline_id' => $timelineId]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

