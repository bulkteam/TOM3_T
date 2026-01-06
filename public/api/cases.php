<?php
/**
 * TOM3 - Cases API
 */

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\CaseService;
use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $caseService = new CaseService($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Auth prüfen für geschützte Endpoints
// GET-Endpoints sind öffentlich, POST/PUT/DELETE benötigen Auth
$currentUser = null;
$currentUserId = null;
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    $currentUser = requireAuth();
    $currentUserId = (string)$currentUser['user_id'];
    // CSRF prüfen für state-changing Requests
    validateCsrfToken($method);
}

// Router-Variablen nutzen (vom Router gesetzt)
// $id = case UUID (z.B. für /api/cases/{uuid})
// $action = action (z.B. 'notes', 'blockers', 'requirements')
$caseUuid = $id ?? null;
$action = $action ?? null;

// Für komplexere Pfade wie /api/cases/{uuid}/requirements/{req_uuid}/fulfill
// müssen wir noch zusätzliche Pfad-Teile parsen (Router unterstützt nur 2 Ebenen)
$pathParts = null;
if ($action === 'requirements') {
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = preg_replace('#^/tom3/public#i', '', $path);
    $path = preg_replace('#^/api/?|^api/?#', '', $path);
    $path = trim($path, '/');
    $pathParts = explode('/', $path);
}

switch ($method) {
    case 'GET':
        if ($caseUuid) {
            if ($action === 'blockers') {
                // GET /api/cases/{uuid}/blockers
                $blockers = $caseService->getBlockers($caseUuid);
                echo json_encode($blockers);
            } else {
                // GET /api/cases/{uuid}
                $case = $caseService->getCase($caseUuid);
                echo json_encode($case);
            }
        } else {
            // GET /api/cases
            $filters = [
                'status' => $_GET['status'] ?? null,
                'engine' => $_GET['engine'] ?? null,
                'search' => $_GET['search'] ?? null
            ];
            $cases = $caseService->listCases($filters);
            // Stelle sicher, dass immer ein Array zurückgegeben wird
            echo json_encode($cases ?: []);
        }
        break;
        
    case 'POST':
        if ($action === 'notes') {
            // POST /api/cases/{uuid}/notes
            $data = json_decode(file_get_contents('php://input'), true);
            $note = $data['note'] ?? '';
            $result = $caseService->addNote($caseUuid, $note);
            echo json_encode($result);
        } elseif ($action === 'requirements' && isset($pathParts[3], $pathParts[4]) && $pathParts[4] === 'fulfill') {
            // POST /api/cases/{uuid}/requirements/{req_uuid}/fulfill
            $requirementUuid = $pathParts[3];
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $caseService->fulfillRequirement($caseUuid, $requirementUuid, $data);
            echo json_encode($result);
        } else {
            // POST /api/cases
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $caseService->createCase($data);
            
            // Track Zugriff auf Organisation, wenn Case mit Org verknüpft ist
            if (!empty($data['org_uuid'])) {
                try {
                    $orgService = new \TOM\Service\OrgService($db);
                    // $currentUserId ist bereits durch requireAuth() gesetzt
                    $orgService->trackAccess($currentUserId, $data['org_uuid'], 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                }
            }
            
            echo json_encode($result);
        }
        break;
        
    case 'PUT':
        // PUT /api/cases/{uuid}
        $data = json_decode(file_get_contents('php://input'), true);
        $result = $caseService->updateCase($caseUuid, $data);
        
        // Track Zugriff auf Organisation, wenn Case mit Org verknüpft ist
        if (!empty($data['org_uuid'])) {
            try {
                $orgService = new \TOM\Service\OrgService($db);
                $userId = $_GET['user_id'] ?? 'default_user';
                $orgService->trackAccess($userId, $data['org_uuid'], 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
        } elseif ($caseUuid) {
            // Hole Case um org_uuid zu prüfen
            $case = $caseService->getCase($caseUuid);
            if ($case && !empty($case['org_uuid'])) {
                try {
                    $orgService = new \TOM\Service\OrgService($db);
                    $userId = $_GET['user_id'] ?? 'default_user';
                    $orgService->trackAccess($userId, $case['org_uuid'], 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                }
            }
        }
        
        echo json_encode($result);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}


