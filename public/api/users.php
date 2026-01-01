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

// Wenn von index.php aufgerufen, verwende die bereits geparsten Variablen
// Ansonsten parse den Pfad selbst
if (isset($id) || isset($action)) {
    // Von index.php: $id ist der erste Teil nach 'users' (z.B. '1' für /api/users/1)
    $userId = $id ?? null;
    $action = $action ?? null;
} else {
    // Direkter Aufruf: Parse den Pfad selbst
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
    
    // Entferne /TOM3/public oder /tom3/public falls vorhanden (case-insensitive)
    $path = preg_replace('#^/tom3/public#i', '', $path);
    // Entferne /api prefix
    $path = preg_replace('#^/api/?|^api/?#', '', $path);
    $path = trim($path, '/');
    
    $pathParts = explode('/', $path);
    // Filtere 'users' heraus, da wir bereits wissen dass wir in users.php sind
    $pathParts = array_filter($pathParts, function($p) { return $p !== 'users' && $p !== ''; });
    $pathParts = array_values($pathParts);
    
    // users ist parts[0], action ist parts[1], id ist parts[2]
    $action = $pathParts[1] ?? null;
    $userId = $pathParts[0] ?? null; // Erster Teil nach 'users' ist die User-ID
}

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
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } elseif ($action === 'activate' && $userId) {
            // PUT /api/users/{user_id}/activate - User aktivieren
            try {
                $userService->activateUser($userId);
                echo json_encode(['success' => true, 'message' => 'User wurde aktiviert']);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } elseif ($userId) {
            // PUT /api/users/{user_id} - User aktualisieren
            $data = json_decode(file_get_contents('php://input'), true);
            try {
                $user = $userService->updateUser($userId, $data);
                echo json_encode($user);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
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

