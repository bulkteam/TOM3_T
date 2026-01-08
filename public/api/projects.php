<?php
/**
 * TOM3 - Projects API
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

use TOM\Service\ProjectService;
use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $projectService = new ProjectService($db);
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
// Verwende die vom Router übergebenen Parameter
$projectUuid = $id ?? null;
// $action wird bereits vom Router übergeben

try {
    switch ($method) {
        case 'GET':
            if ($projectUuid) {
                // GET /api/projects/{uuid}
                $project = $projectService->getProject($projectUuid);
                jsonResponse($project);
            } else {
                // GET /api/projects
                $projects = $projectService->listProjects();
                // Stelle sicher, dass immer ein Array zurückgegeben wird
                jsonResponse($projects ?: []);
            }
            break;
            
        case 'POST':
            if ($action === 'cases') {
                // POST /api/projects/{uuid}/cases
                $data = json_decode(file_get_contents('php://input'), true);
                $caseUuid = $data['case_uuid'] ?? null;
                if (!$caseUuid) {
                    jsonError('case_uuid required', 400);
                }
                $result = $projectService->linkCase($projectUuid, $caseUuid);
                
                // Track Zugriff auf Organisation, wenn Case mit Org verknüpft ist
                try {
                    $caseService = new \TOM\Service\CaseService($db);
                    $case = $caseService->getCase($caseUuid);
                    if ($case && !empty($case['org_uuid'])) {
                        $orgService = new OrgService($db);
                        // $currentUserId ist bereits durch requireAuth() gesetzt
                        $orgService->trackAccess($currentUserId, $case['org_uuid'], 'recent');
                    }
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                }
                
                jsonResponse($result);
            } else {
                // POST /api/projects
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $projectService->createProject($data);
                
                // Track Zugriff auf Organisation, wenn Project mit Org verknüpft ist
                if (!empty($data['sponsor_org_uuid'])) {
                    try {
                        $orgService = new OrgService($db);
                        // $currentUserId ist bereits durch requireAuth() gesetzt
                        $orgService->trackAccess($currentUserId, $data['sponsor_org_uuid'], 'recent');
                    } catch (Exception $e) {
                        // Tracking-Fehler sollten die Antwort nicht beeinflussen
                    }
                }
                
                jsonResponse($result);
            }
            break;
            
        default:
            jsonError('Method not allowed', 405);
            break;
    }
} catch (\Exception $e) {
    handleApiException($e, 'Projects API');
}


