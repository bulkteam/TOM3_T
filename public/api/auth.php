<?php
/**
 * TOM3 - Auth API
 * 
 * API-Endpunkte für Authentifizierung
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Auth\AuthService;
use TOM\Infrastructure\Activity\ActivityLogService;
use TOM\Infrastructure\Security\RateLimiter;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\User\UserPermissionService;
require_once __DIR__ . '/api-security.php';
require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

// Headers werden bereits vom Router gesetzt
// Nur setzen, wenn noch nicht gesetzt (für direkten Aufruf)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $db = DatabaseConnection::getInstance();
    $activityLogService = new ActivityLogService();
    $auth = new AuthService(null, $activityLogService);
    $rateLimiter = new RateLimiter($db);
} catch (Exception $e) {
    handleApiException($e, 'Auth service initialization');
}

$method = $_SERVER['REQUEST_METHOD'];

// Wenn von index.php aufgerufen, verwende die bereits geparsten Variablen
// Ansonsten parse den Pfad selbst
if (isset($id) || isset($action)) {
    // Von index.php: $id ist der erste Teil nach 'auth' (z.B. 'current')
    $action = $id ?? $action ?? null;
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
    // Filtere 'auth' heraus, da wir bereits wissen dass wir in auth.php sind
    $pathParts = array_filter($pathParts, function($p) { return $p !== 'auth' && $p !== ''; });
    $pathParts = array_values($pathParts);
    
    $action = $pathParts[0] ?? null; // First part after 'auth' is the action
}

// Debug: Log action for troubleshooting (remove in production)
// error_log("Auth API - Action: " . ($action ?? 'null') . ", Method: $method");

// Wrap entire switch in try-catch for safety
try {
    switch ($method) {
        case 'GET':
            if ($action === 'current') {
                // GET /api/auth/current - Aktueller User (minimal response mit Capabilities)
                try {
                    $user = $auth->getCurrentUser();
                    if ($user) {
                        // Cache-Control: keine Proxy-Caches für User-Daten
                        header('Cache-Control: no-store, no-cache, must-revalidate, private');
                        header('Pragma: no-cache');
                        header('Expires: 0');
                        
                        // Minimal-Response: nur das Nötige für Frontend
                        $userId = (int)$user['user_id'];
                        $userRoles = $user['roles'] ?? [];
                        
                        // Lade Capabilities für den User
                        $permissionService = new UserPermissionService();
                        $allCapabilities = $permissionService->getUserCapabilities($userId, $userRoles);
                        
                        // Filter: Nur Frontend-relevante Capabilities senden
                        // Backend-interne Capabilities (z.B. import.commit, import.delete) werden nicht gesendet
                        $frontendCapabilities = [
                            // Org-Management
                            'org.read', 'org.write', 'org.delete', 'org.archive', 'org.export',
                            // Person-Management
                            'person.read', 'person.write', 'person.delete',
                            // Document-Management
                            'document.read', 'document.upload', 'document.delete',
                            // Import (nur Upload/Review, nicht Backend-Operationen)
                            'import.upload', 'import.review',
                            // Case-Management
                            'case.read', 'case.write', 'case.delete',
                            // Project-Management
                            'project.read', 'project.write', 'project.delete',
                            // Admin
                            'admin.manage_users', 'admin.manage_roles', 'admin.view_monitoring', 'admin.export_data'
                        ];
                        
                        // Filtere nur die Capabilities, die der User hat UND Frontend-relevant sind
                        $capabilities = array_intersect($allCapabilities, $frontendCapabilities);
                        
                        // Minimal-Response (keine PII wie Email, keine Role-Details)
                        // WICHTIG: user_id muss vorhanden sein für Frontend-Kompatibilität
                        $response = [
                            'authenticated' => true,
                            'user_id' => (string)$userId, // Als String für Konsistenz
                            'user' => [
                                'id' => $userId,
                                'user_id' => (string)$userId, // Für Frontend-Kompatibilität
                                'name' => $user['name'] ?? 'Unknown',
                                'email' => $user['email'] ?? null,
                                'displayName' => $user['name'] ?? 'Unknown',
                                'roles' => $userRoles
                            ],
                            'capabilities' => array_values($capabilities) // array_values für konsistente Indizierung
                        ];
                        
                        // Optional: Roles für Backward Compatibility (kann später entfernt werden)
                        if (!empty($userRoles)) {
                            $response['roles'] = $userRoles;
                        }
                        
                        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if ($json === false) {
                            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
                        }
                        echo $json;
                    } else {
                        http_response_code(401);
                        header('Cache-Control: no-store, no-cache, must-revalidate, private');
                        echo json_encode([
                            'authenticated' => false,
                            'error' => 'Not authenticated'
                        ]);
                    }
                } catch (\Exception $e) {
                    handleApiException($e, 'Get current user');
                }
            } elseif ($action === 'users' && $auth->isDevMode()) {
                // GET /api/auth/users - Liste aller User (nur Dev-Modus)
                try {
                    $users = $auth->getActiveUsers();
                    echo json_encode($users);
                } catch (\Exception $e) {
                    handleApiException($e, 'Get users');
                }
            } elseif ($action === 'csrf-token') {
                // GET /api/auth/csrf-token - CSRF-Token für Frontend
                try {
                    $token = generateCsrfToken();
                    echo json_encode(['token' => $token]);
                } catch (\Exception $e) {
                    handleApiException($e, 'Generate CSRF token');
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Not found', 'action' => $action]);
            }
        break;
        
    case 'POST':
        if ($action === 'login' && $auth->isDevMode()) {
            // POST /api/auth/login - Login (nur Dev-Modus)
            // Rate-Limit: 5 Versuche pro IP pro Minute
            if (!$rateLimiter->checkIpLimit('auth-login', 5, 60)) {
                http_response_code(429);
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Too many login attempts. Please try again later.'
                ]);
                exit;
            }
            
            try {
                $data = json_decode(file_get_contents('php://input'), true);
                $userId = (int)($data['user_id'] ?? 0);
                
                if ($auth->login($userId)) {
                    $user = $auth->getCurrentUser();
                    echo json_encode([
                        'success' => true,
                        'user' => $user
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid user'
                    ]);
                }
            } catch (\Exception $e) {
                handleApiException($e, 'Login failed');
            }
        } elseif ($action === 'logout') {
            // POST /api/auth/logout - Logout
            try {
                $auth->logout();
                echo json_encode(['success' => true]);
            } catch (\Exception $e) {
                handleApiException($e, 'Logout failed');
            }
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
        }
        break;
        
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (\Throwable $e) {
    handleApiException($e, 'Unhandled auth error');
}


