<?php
/**
 * TOM3 - Telephony API
 * 
 * Handles SIPgate integration for making calls
 */

declare(strict_types=1);

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

// Security Guard: Verhindere direkten Aufruf
if (!defined('TOM3_API_ROUTER')) {
    jsonError('Direct access not allowed', 403);
}

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\WorkItem\Timeline\WorkItemTimelineService;
use TOM\Infrastructure\Security\RateLimiter;

try {
    $db = DatabaseConnection::getInstance();
    $timelineService = new WorkItemTimelineService($db);
    $rateLimiter = new RateLimiter($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];
// $id und $action werden vom Router übergeben
// $id ist z.B. 'calls' für /api/telephony/calls
// $action ist z.B. null oder 'finalize' für /api/telephony/activities/{id}/finalize

// Parse zusätzliche Pfad-Teile
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$path = preg_replace('#^/tom3/public#i', '', $path);
$path = preg_replace('#^/api/?|^api/?#', '', $path);
$path = trim($path, '/');
$parts = explode('/', $path);

// telephony/calls -> $parts[0] = 'telephony', $parts[1] = 'calls'
// telephony/calls/{call_ref} -> $parts[0] = 'telephony', $parts[1] = 'calls', $parts[2] = '{call_ref}'
// telephony/activities/{activity_id}/finalize -> $parts[0] = 'telephony', $parts[1] = 'activities', $parts[2] = '{activity_id}', $parts[3] = 'finalize'
$subResource = $parts[1] ?? null; // 'calls' oder 'activities'
$subId = $parts[2] ?? null; // call_ref oder activity_id
$subAction = $parts[3] ?? null; // 'finalize' etc.

// Auth prüfen
$currentUser = requireAuth();
$currentUserId = (string)$currentUser['user_id'];

// CSRF prüfen für state-changing Requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    validateCsrfToken($method);
}

try {
    switch ($subResource) {
        case 'calls':
            if ($method === 'POST' && !$subId) {
                // POST /api/telephony/calls - Starte Anruf
                // Rate-Limit: 20 Calls pro User pro Minute
                if (!$rateLimiter->checkUserLimit('telephony-calls', $currentUserId, 20, 60)) {
                    jsonError('Rate limit exceeded: Too many calls. Please try again later.', 429);
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                $workItemUuid = $data['work_item_uuid'] ?? null;
                $phoneNumber = $data['phone_number'] ?? null;
                
                if (!$workItemUuid || !$phoneNumber) {
                    jsonError('work_item_uuid and phone_number required', 400);
                }
                
                // TODO: SIPgate-Integration hier implementieren
                // Für jetzt: Erstelle Timeline-Eintrag und gebe Mock-Daten zurück
                $callRef = 'call_' . uniqid();
                $activityId = $timelineService->addCallActivity(
                    $workItemUuid,
                    $currentUserId,
                    $phoneNumber,
                    'initiated',
                    null,
                    null,
                    null
                );
                
                jsonResponse([
                    'call_ref' => $callRef,
                    'activity_id' => $activityId,
                    'phone_number' => $phoneNumber,
                    'state' => 'initiated',
                    'message' => 'Call initiated (SIPgate integration pending)'
                ]);
                
            } elseif ($method === 'GET' && $subId) {
                // GET /api/telephony/calls/{call_ref} - Hole Call-Status
                // TODO: SIPgate-Status abfragen
                // Für jetzt: Mock-Daten zurückgeben
                jsonResponse([
                    'call_ref' => $subId,
                    'state' => 'connected',
                    'initiated_at' => date('Y-m-d H:i:s'),
                    'connected_at' => date('Y-m-d H:i:s'),
                    'duration' => 0
                ]);
                
            } else {
                jsonError('Method not allowed', 405);
            }
            break;
            
        case 'activities':
            if ($method === 'POST' && $subId && $subAction === 'finalize') {
                // POST /api/telephony/activities/{activity_id}/finalize - Finalisiere Call-Activity
                $data = json_decode(file_get_contents('php://input'), true);
                
                $callDuration = isset($data['call_duration']) ? (int)$data['call_duration'] : null;
                $outcome = $data['outcome'] ?? null;
                $notes = $data['notes'] ?? null;
                $nextActionAt = isset($data['next_action_at']) && !empty($data['next_action_at']) 
                    ? new \DateTime($data['next_action_at']) 
                    : null;
                $nextActionType = $data['next_action_type'] ?? null;
                
                // Update Timeline-Eintrag mit finalen Daten
                $timelineService->updateActivity(
                    (int)$subId,
                    $callDuration,
                    $outcome,
                    $notes,
                    $nextActionAt,
                    $nextActionType
                );
                
                jsonResponse([
                    'success' => true,
                    'activity_id' => (int)$subId,
                    'message' => 'Call activity finalized'
                ]);
                
            } else {
                jsonError('Method not allowed', 405);
            }
            break;
            
        default:
            jsonError('Not found: ' . $subResource, 404);
            break;
    }
} catch (Exception $e) {
    handleApiException($e, 'Telephony Error');
}

