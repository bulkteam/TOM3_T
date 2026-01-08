<?php
/**
 * TOM3 - Tasks API
 */

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    jsonError('Direct access not allowed', 403);
}

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\TaskService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $taskService = new TaskService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];

// Router-Variablen nutzen (vom Router gesetzt)
// $id = task UUID (z.B. für /api/tasks/{uuid}/complete)
// $action = 'complete' (z.B. für /api/tasks/{uuid}/complete)
$taskUuid = $id ?? null;
$action = $action ?? null;

try {
    switch ($method) {
        case 'GET':
            // GET /api/tasks?case_uuid=...
            $caseUuid = $_GET['case_uuid'] ?? null;
            $tasks = $taskService->listTasks($caseUuid);
            jsonResponse($tasks);
            break;
            
        case 'POST':
            if ($taskUuid && $action === 'complete') {
                // POST /api/tasks/{uuid}/complete
                $result = $taskService->completeTask($taskUuid);
                jsonResponse($result);
            } else {
                // POST /api/tasks
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $taskService->createTask($data);
                jsonResponse($result);
            }
            break;
            
        default:
            jsonError('Method not allowed', 405);
            break;
    }
} catch (\Exception $e) {
    handleApiException($e, 'Tasks API');
}


