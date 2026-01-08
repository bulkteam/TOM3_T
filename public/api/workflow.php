<?php
/**
 * TOM3 - Workflow API
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

use TOM\Service\WorkflowService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $workflowService = new WorkflowService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);

try {
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
                    jsonError('case_uuid and target_role required', 400);
                }
                
                $result = $workflowService->handover($caseUuid, $targetRole, $justification);
                jsonResponse($result);
            } elseif ($action === 'return') {
                // POST /api/workflow/return
                $caseUuid = $data['case_uuid'] ?? null;
                $reason = $data['reason'] ?? null;
                
                if (!$caseUuid || !$reason) {
                    jsonError('case_uuid and reason required', 400);
                }
                
                $result = $workflowService->returnCase($caseUuid, $reason);
                jsonResponse($result);
            } else {
                jsonError('Invalid action', 400);
            }
            break;
            
        default:
            jsonError('Method not allowed', 405);
            break;
    }
} catch (\Exception $e) {
    handleApiException($e, 'Workflow API');
}

