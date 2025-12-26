<?php
/**
 * TOM3 - Users API
 * 
 * API für User-Verwaltung und Rollen-Abfragen
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\UserService;

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
$pathParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
// Entferne /TOM3/public/api falls vorhanden
$pathParts = array_filter($pathParts, function($part) {
    return $part !== 'TOM3' && $part !== 'public' && $part !== 'api';
});
$pathParts = array_values($pathParts);

// users ist parts[0], action ist parts[1], id ist parts[2]
$action = $pathParts[1] ?? null;
$userId = $pathParts[2] ?? null;

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
            $user = $userService->getUser($userId);
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
            $users = $userService->getAllUsers();
            echo json_encode($users);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

