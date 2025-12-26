<?php
/**
 * TOM3 - Projects API
 */

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
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
// Verwende die vom Router übergebenen Parameter
$projectUuid = $id ?? null;
// $action wird bereits vom Router übergeben

switch ($method) {
    case 'GET':
        if ($projectUuid) {
            // GET /api/projects/{uuid}
            $project = $projectService->getProject($projectUuid);
            echo json_encode($project);
        } else {
            // GET /api/projects
            $projects = $projectService->listProjects();
            // Stelle sicher, dass immer ein Array zurückgegeben wird
            echo json_encode($projects ?: []);
        }
        break;
        
    case 'POST':
        if ($action === 'cases') {
            // POST /api/projects/{uuid}/cases
            $data = json_decode(file_get_contents('php://input'), true);
            $caseUuid = $data['case_uuid'] ?? null;
            if (!$caseUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'case_uuid required']);
                exit;
            }
            $result = $projectService->linkCase($projectUuid, $caseUuid);
            
            // Track Zugriff auf Organisation, wenn Case mit Org verknüpft ist
            try {
                $caseService = new \TOM\Service\CaseService($db);
                $case = $caseService->getCase($caseUuid);
                if ($case && !empty($case['org_uuid'])) {
                    $orgService = new OrgService($db);
                    $userId = $_GET['user_id'] ?? 'default_user';
                    $orgService->trackAccess($userId, $case['org_uuid'], 'recent');
                }
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } else {
            // POST /api/projects
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $projectService->createProject($data);
            
            // Track Zugriff auf Organisation, wenn Project mit Org verknüpft ist
            if (!empty($data['sponsor_org_uuid'])) {
                try {
                    $orgService = new OrgService($db);
                    $userId = $_GET['user_id'] ?? 'default_user';
                    $orgService->trackAccess($userId, $data['sponsor_org_uuid'], 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                }
            }
            
            echo json_encode($result);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}


