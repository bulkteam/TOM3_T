<?php
/**
 * TOM3 - Users API
 * 
 * API für User-Verwaltung und Rollen-Abfragen
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

use TOM\Service\UserService;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $userService = new UserService();
} catch (Exception $e) {
    handleApiException($e, 'User service initialization');
}

$method = $_SERVER['REQUEST_METHOD'];

// Router-Variablen nutzen (vom Router gesetzt)
// $id = user ID (z.B. für /api/users/{user_id}/roles)
// $action = action (z.B. 'roles' für /api/users/{user_id}/roles)
$userId = $id ?? null;
$action = $action ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($action === 'roles' && $userId) {
                // GET /api/users/{user_id}/roles - Workflow-Rollen eines Users
                $roles = $userService->getUserWorkflowRoles($userId);
                jsonResponse($roles);
            } elseif ($action === 'roles') {
                // GET /api/users/roles - Alle verfügbaren Workflow-Rollen
                $roles = $userService->getAvailableWorkflowRoles();
                jsonResponse($roles);
            } elseif ($action === 'workflow-roles') {
                // GET /api/users/workflow-roles - Alle verfügbaren Workflow-Rollen (mit Details)
                $roles = $userService->getAvailableWorkflowRoles();
                jsonResponse($roles);
            } elseif ($action === 'account-team-roles') {
                // GET /api/users/account-team-roles - Alle verfügbaren Account-Team-Rollen
                $roles = $userService->getAvailableAccountTeamRoles();
                jsonResponse($roles);
            } elseif ($action === 'permission-roles') {
                // GET /api/users/permission-roles - Alle verfügbaren Berechtigungs-Rollen
                $roles = $userService->getAvailablePermissionRoles();
                jsonResponse($roles);
            } elseif ($userId) {
                // GET /api/users/{user_id} - Einzelner User
                // Erlaube auch inaktive User für Admin-Bearbeitung
                $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
                $user = $userService->getUser($userId, $includeInactive);
                if ($user) {
                    jsonResponse($user);
                } else {
                    jsonError('User not found', 404);
                }
            } elseif ($action === 'by-role' && isset($_GET['role'])) {
                // GET /api/users/by-role?role=ops - User mit bestimmter Workflow-Rolle
                $role = $_GET['role'];
                $users = $userService->getUsersByWorkflowRole($role);
                jsonResponse($users);
            } else {
                // GET /api/users - Alle User
                $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
                $users = $userService->getAllUsers($includeInactive);
                jsonResponse($users);
            }
            break;
            
        case 'PUT':
            // Prüfe Admin-Berechtigung
            $currentUser = AuthHelper::getCurrentUser();
            if (!$currentUser || !in_array('admin', $currentUser['roles'] ?? [], true)) {
                jsonError('Admin-Berechtigung erforderlich', 403);
            }
            
            if ($action === 'deactivate' && $userId) {
                // PUT /api/users/{user_id}/deactivate - User deaktivieren
                try {
                    $currentUserId = AuthHelper::getCurrentUserId();
                    $userService->deactivateUser($userId, $currentUserId);
                    jsonResponse(['success' => true, 'message' => 'User wurde deaktiviert']);
                } catch (\Exception $e) {
                    handleApiException($e, 'Deactivating user');
                }
            } elseif ($action === 'activate' && $userId) {
                // PUT /api/users/{user_id}/activate - User aktivieren
                try {
                    $userService->activateUser($userId);
                    jsonResponse(['success' => true, 'message' => 'User wurde aktiviert']);
                } catch (\Exception $e) {
                    handleApiException($e, 'Activating user');
                }
            } elseif ($userId) {
                // PUT /api/users/{user_id} - User aktualisieren
                $data = json_decode(file_get_contents('php://input'), true);
                try {
                    $user = $userService->updateUser($userId, $data);
                    jsonResponse($user);
                } catch (\Exception $e) {
                    handleApiException($e, 'Updating user');
                }
            } else {
                jsonError('Invalid request', 400);
            }
            break;
            
        default:
            jsonError('Method not allowed', 405);
            break;
    }
} catch (\Exception $e) {
    handleApiException($e, 'Users API');
}

