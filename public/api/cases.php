<?php
/**
 * TOM3 - Cases API
 */

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

use TOM\Service\CaseService;
use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $caseService = new CaseService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
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

try {
    switch ($method) {
        case 'GET':
            if ($caseUuid) {
                if ($action === 'blockers') {
                    // GET /api/cases/{uuid}/blockers
                    $blockers = $caseService->getBlockers($caseUuid);
                    jsonResponse($blockers);
                } else {
                    // GET /api/cases/{uuid}
                    $case = $caseService->getCase($caseUuid);
                    jsonResponse($case);
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
                jsonResponse($cases ?: []);
            }
            break;
            
        case 'POST':
            if ($action === 'notes') {
                // POST /api/cases/{uuid}/notes
                $data = json_decode(file_get_contents('php://input'), true);
                $note = $data['note'] ?? '';
                $result = $caseService->addNote($caseUuid, $note);
                jsonResponse($result);
            } elseif ($action === 'requirements' && isset($pathParts[3], $pathParts[4]) && $pathParts[4] === 'fulfill') {
                // POST /api/cases/{uuid}/requirements/{req_uuid}/fulfill
                $requirementUuid = $pathParts[3];
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $caseService->fulfillRequirement($caseUuid, $requirementUuid, $data);
                jsonResponse($result);
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
                
                jsonResponse($result);
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
            
            jsonResponse($result);
            break;
            
        default:
            jsonError('Method not allowed', 405);
            break;
    }
} catch (\Exception $e) {
    handleApiException($e, 'Cases API');
}


