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
use TOM\Infrastructure\Security\RateLimiter;

try {
    $db = DatabaseConnection::getInstance();
    $workItemService = new WorkItemService($db);
    $timelineService = new WorkItemTimelineService($db);
    $rateLimiter = new RateLimiter($db);
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
            // Rate-Limit prüfen
            if (!$rateLimiter->checkUserLimit('work-items-patch', $currentUserId, 30, 60)) {
                http_response_code(429);
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Too many requests. Please try again later.'
                ]);
                exit;
            }
            
            // PATCH /api/work-items/{uuid}
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Input Validation
            $errors = [];
            
            // Stage-Validation
            if (isset($data['stage'])) {
                $validStages = ['NEW', 'IN_PROGRESS', 'SNOOZED', 'QUALIFIED', 'DATA_CHECK', 'DISQUALIFIED', 'DUPLICATE', 'CLOSED'];
                if (!in_array($data['stage'], $validStages, true)) {
                    $errors[] = "Invalid stage. Allowed values: " . implode(', ', $validStages);
                }
            }
            
            // Datumsformat-Validation für next_action_at
            if (isset($data['next_action_at'])) {
                if ($data['next_action_at'] !== null) {
                    try {
                        $date = new \DateTime($data['next_action_at']);
                        // Konvertiere zurück zu ISO-Format für Konsistenz
                        $data['next_action_at'] = $date->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        $errors[] = "Invalid date format for next_action_at. Expected ISO 8601 format (e.g. '2026-01-15T10:30:00')";
                    }
                }
            }
            
            // priority_stars Validation
            if (isset($data['priority_stars'])) {
                $stars = (int)$data['priority_stars'];
                if ($stars < 0 || $stars > 5) {
                    $errors[] = "priority_stars must be between 0 and 5";
                }
                $data['priority_stars'] = $stars;
            }
            
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Validation failed',
                    'errors' => $errors
                ]);
                exit;
            }
            
            // Hole aktuelles WorkItem für Audit
            $oldWorkItem = $workItemService->getWorkItem($workItemUuid);
            if (!$oldWorkItem) {
                http_response_code(404);
                echo json_encode(['error' => 'Work item not found']);
                exit;
            }
            
            // Update WorkItem
            $workItem = $workItemService->updateWorkItem($workItemUuid, $data);
            
            // Audit: Stage-Änderung protokollieren
            if (isset($data['stage']) && $oldWorkItem['stage'] !== $data['stage']) {
                $timelineService->addSystemMessage(
                    $workItemUuid,
                    'STAGE_CHANGE',
                    "Stage geändert: {$oldWorkItem['stage']} → {$data['stage']}",
                    ['old_stage' => $oldWorkItem['stage'], 'new_stage' => $data['stage'], 'changed_by' => $currentUserId]
                );
            }
            
            // Audit: Owner-Änderung protokollieren
            if (isset($data['owner_user_id']) && ($oldWorkItem['owner_user_id'] ?? null) !== ($data['owner_user_id'] ?? null)) {
                $oldOwner = $oldWorkItem['owner_user_id'] ?? 'unassigned';
                $newOwner = $data['owner_user_id'] ?? 'unassigned';
                $timelineService->addSystemMessage(
                    $workItemUuid,
                    'OWNER_CHANGE',
                    "Owner geändert: {$oldOwner} → {$newOwner}",
                    ['old_owner' => $oldOwner, 'new_owner' => $newOwner, 'changed_by' => $currentUserId]
                );
            }
            
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
        } elseif ($workItemUuid && ($action === 'handover' || $action === 'handoff')) {
            // POST /api/work-items/{uuid}/handover (oder /handoff für Kompatibilität)
            $data = json_decode(file_get_contents('php://input'), true);
            
            $handoffType = $data['handoff_type'] ?? 'QUOTE_REQUEST';
            
            // Generiere Notes-Text basierend auf Handoff-Type
            $notes = '';
            $metadata = ['handoff_type' => $handoffType];
            
            if ($handoffType === 'QUOTE_REQUEST') {
                // QUOTE_REQUEST: Bedarf, Ansprechpartner, Nächster Schritt
                $needSummary = $data['need_summary'] ?? '';
                $contactHint = $data['contact_hint'] ?? '';
                $nextStep = $data['next_step'] ?? '';
                
                $notes = "Übergabe an Sales Ops (Angebot angefragt)\n\n";
                if ($needSummary) {
                    $notes .= "Bedarf: $needSummary\n";
                }
                if ($contactHint) {
                    $notes .= "Ansprechpartner: $contactHint\n";
                }
                if ($nextStep) {
                    $notes .= "Nächster Schritt: $nextStep\n";
                }
                
                $metadata['need_summary'] = $needSummary;
                $metadata['contact_hint'] = $contactHint;
                $metadata['next_step'] = $nextStep;
            } elseif ($handoffType === 'DATA_CHECK') {
                // DATA_CHECK: Problem, Anfrage, Ansprechpartner, Nächster Schritt, Links
                $issue = $data['issue'] ?? '';
                $request = $data['request'] ?? '';
                $contactHint = $data['contact_hint'] ?? '';
                $nextStep = $data['next_step'] ?? '';
                $links = $data['links'] ?? [];
                
                $notes = "Übergabe an Sales Ops (Datenprüfung)\n\n";
                if ($issue) {
                    $notes .= "Problem: $issue\n";
                }
                if ($request) {
                    $notes .= "Anfrage: $request\n";
                }
                if ($contactHint) {
                    $notes .= "Ansprechpartner: $contactHint\n";
                }
                if ($nextStep) {
                    $notes .= "Nächster Schritt: $nextStep\n";
                }
                if (!empty($links) && is_array($links)) {
                    $notes .= "\nLinks:\n" . implode("\n", $links);
                }
                
                $metadata['issue'] = $issue;
                $metadata['request'] = $request;
                $metadata['contact_hint'] = $contactHint;
                $metadata['next_step'] = $nextStep;
                $metadata['links'] = $links;
            }
            
            // Füge Timeline-Eintrag hinzu (User + System)
            $timelineId = $timelineService->addHandoffActivityWithMetadata(
                $workItemUuid,
                $currentUserId,
                $notes,
                $metadata
            );
            
            // Update WorkItem: Stage und Owner-Role
            $updateData = [
                'stage' => $handoffType === 'QUOTE_REQUEST' ? 'QUALIFIED' : 'DATA_CHECK',
                'owner_role' => 'ops' // VORGANG
            ];
            
            $workItemService->updateWorkItem($workItemUuid, $updateData);
            
            echo json_encode([
                'timeline_id' => $timelineId,
                'stage' => $updateData['stage'],
                'owner_role' => $updateData['owner_role']
            ]);
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

