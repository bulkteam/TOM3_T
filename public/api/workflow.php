<?php
/**
 * TOM3 - Workflow API
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\WorkflowService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $workflowService = new WorkflowService($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'POST':
        $pathParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
        $action = $pathParts[1] ?? '';
        
        if ($action === 'handover') {
            // POST /api/workflow/handover
            $caseUuid = $data['case_uuid'] ?? null;
            $targetRole = $data['target_role'] ?? null;
            $justification = $data['justification'] ?? null;
            
            if (!$caseUuid || !$targetRole) {
                http_response_code(400);
                echo json_encode(['error' => 'case_uuid and target_role required']);
                exit;
            }
            
            $result = $workflowService->handover($caseUuid, $targetRole, $justification);
            echo json_encode($result);
        } elseif ($action === 'return') {
            // POST /api/workflow/return
            $caseUuid = $data['case_uuid'] ?? null;
            $reason = $data['reason'] ?? null;
            
            if (!$caseUuid || !$reason) {
                http_response_code(400);
                echo json_encode(['error' => 'case_uuid and reason required']);
                exit;
            }
            
            $result = $workflowService->returnCase($caseUuid, $reason);
            echo json_encode($result);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

