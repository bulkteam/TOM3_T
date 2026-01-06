<?php
/**
 * TOM3 - Tasks API
 */

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    http_response_code(404);
    exit;
}

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
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Router-Variablen nutzen (vom Router gesetzt)
// $id = task UUID (z.B. für /api/tasks/{uuid}/complete)
// $action = 'complete' (z.B. für /api/tasks/{uuid}/complete)
$taskUuid = $id ?? null;
$action = $action ?? null;

switch ($method) {
    case 'GET':
        // GET /api/tasks?case_uuid=...
        $caseUuid = $_GET['case_uuid'] ?? null;
        $tasks = $taskService->listTasks($caseUuid);
        echo json_encode($tasks);
        break;
        
    case 'POST':
        if ($taskUuid && $action === 'complete') {
            // POST /api/tasks/{uuid}/complete
            $result = $taskService->completeTask($taskUuid);
            echo json_encode($result);
        } else {
            // POST /api/tasks
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $taskService->createTask($data);
            echo json_encode($result);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}


