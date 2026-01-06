<?php
/**
 * TOM3 - Users API
 * 
 * API für User-Verwaltung und Rollen-Abfragen
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

use TOM\Service\UserService;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $userService = new UserService();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'User service initialization failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Router-Variablen nutzen (vom Router gesetzt)
// $id = user ID (z.B. für /api/users/{user_id}/roles)
// $action = action (z.B. 'roles' für /api/users/{user_id}/roles)
$userId = $id ?? null;
$action = $action ?? null;

switch ($method) {
    case 'GET':
        if ($action === 'roles' && $userId) {
            // GET /api/users/{user_id}/roles - Workflow-Rollen eines Users
            $roles = $userService->getUserWorkflowRoles($userId);
            echo json_encode($roles);
        } elseif ($action === 'roles') {
            // GET /api/users/roles - Alle verfügbaren Workflow-Rollen
            $roles = $userService->getAvailableWorkflowRoles();
            echo json_encode($roles);
        } elseif ($action === 'workflow-roles') {
            // GET /api/users/workflow-roles - Alle verfügbaren Workflow-Rollen (mit Details)
            $roles = $userService->getAvailableWorkflowRoles();
            echo json_encode($roles);
        } elseif ($action === 'account-team-roles') {
            // GET /api/users/account-team-roles - Alle verfügbaren Account-Team-Rollen
            $roles = $userService->getAvailableAccountTeamRoles();
            echo json_encode($roles);
        } elseif ($action === 'permission-roles') {
            // GET /api/users/permission-roles - Alle verfügbaren Berechtigungs-Rollen
            $roles = $userService->getAvailablePermissionRoles();
            echo json_encode($roles);
        } elseif ($userId) {
            // GET /api/users/{user_id} - Einzelner User
            // Erlaube auch inaktive User für Admin-Bearbeitung
            $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
            $user = $userService->getUser($userId, $includeInactive);
            if ($user) {
                echo json_encode($user);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
            }
        } elseif ($action === 'by-role' && isset($_GET['role'])) {
            // GET /api/users/by-role?role=ops - User mit bestimmter Workflow-Rolle
            $role = $_GET['role'];
            $users = $userService->getUsersByWorkflowRole($role);
            echo json_encode($users);
        } else {
            // GET /api/users - Alle User
            $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
            $users = $userService->getAllUsers($includeInactive);
            echo json_encode($users);
        }
        break;
        
    case 'PUT':
        // Prüfe Admin-Berechtigung
        $currentUser = AuthHelper::getCurrentUser();
        if (!$currentUser || !in_array('admin', $currentUser['roles'] ?? [], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin-Berechtigung erforderlich']);
            exit;
        }
        
        if ($action === 'deactivate' && $userId) {
            // PUT /api/users/{user_id}/deactivate - User deaktivieren
            try {
                $currentUserId = AuthHelper::getCurrentUserId();
                $userService->deactivateUser($userId, $currentUserId);
                echo json_encode(['success' => true, 'message' => 'User wurde deaktiviert']);
            } catch (\Exception $e) {
                require_once __DIR__ . '/api-security.php';
                sendErrorResponse($e);
            }
        } elseif ($action === 'activate' && $userId) {
            // PUT /api/users/{user_id}/activate - User aktivieren
            try {
                $userService->activateUser($userId);
                echo json_encode(['success' => true, 'message' => 'User wurde aktiviert']);
            } catch (\Exception $e) {
                require_once __DIR__ . '/api-security.php';
                sendErrorResponse($e);
            }
        } elseif ($userId) {
            // PUT /api/users/{user_id} - User aktualisieren
            $data = json_decode(file_get_contents('php://input'), true);
            try {
                $user = $userService->updateUser($userId, $data);
                echo json_encode($user);
            } catch (\Exception $e) {
                require_once __DIR__ . '/api-security.php';
                sendErrorResponse($e);
            }
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

