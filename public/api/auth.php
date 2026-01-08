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

// VERWENDE VARIABLEN AUS INDEX.PHP ROUTER
// $id ist der erste Teil nach 'auth' (z.B. 'current')
// Wenn diese Datei direkt aufgerufen würde, wären sie null.
$action = $id ?? $action ?? null;

// Fallback für Robustheit, falls Router-Logik sich ändert
if (!$action) {
    // Versuche Pfad manuell zu parsen, aber OHNE hardcodierten Base-Pfad
    // Wir nehmen an, der Pfad endet auf /auth/{action}
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $parts = explode('/', trim($path, '/'));
    // Suche nach 'auth' und nimm die Teile danach
    $index = array_search('auth', $parts);
    if ($index !== false && isset($parts[$index + 1])) {
        $action = $parts[$index + 1];
    }
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
                            'document.read', 'document.write', 'document.delete', 'document.archive',
                            // Case-Management
                            'case.read', 'case.write', 'case.close',
                            // Import-Management
                            'import.read', 'import.write',
                            // Admin
                            'admin.users', 'admin.config', 'admin.logs'
                        ];
                        
                        // Filtere Capabilities
                        $capabilities = array_values(array_unique(array_intersect($allCapabilities, $frontendCapabilities)));
                        
                        // Add some basic info for UI
                        $user['capabilities'] = $capabilities;
                        
                        jsonResponse($user);
                    } else {
                        // Kein User eingeloggt -> 401 Unauthorized
                        // WICHTIG: Frontend erwartet 401 um Login-Page zu zeigen
                        jsonError('Not authenticated', 401);
                    }
                } catch (Exception $e) {
                    // Logge den Fehler, aber sende 401 an den Client damit er nicht abstürzt
                    error_log("Auth current user error: " . $e->getMessage());
                    jsonError('Authentication error', 401);
                }
            } elseif ($action === 'csrf-token') {
                // GET /api/auth/csrf-token - Neuen CSRF-Token holen
                // Erfordert Session
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
                
                jsonResponse(['token' => $_SESSION['csrf_token']]);
            } else {
                jsonError('Endpoint not found', 404);
            }
            break;

        case 'POST':
            if ($action === 'login') {
                // POST /api/auth/login
                $data = json_decode(file_get_contents('php://input'), true);
                $username = $data['username'] ?? '';
                $password = $data['password'] ?? '';
                
                if ($auth->isDevMode() && isset($data['user_id'])) {
                    $userId = (int)$data['user_id'];
                    if ($auth->login($userId)) {
                        $user = $auth->getCurrentUser();
                        jsonResponse(['success' => true, 'user' => $user]);
                    } else {
                        jsonError('Invalid user', 401);
                    }
                    break;
                }
                
                if (!$username || !$password) {
                    jsonError('Username and password required', 400);
                }
                
                // Rate Limiting prüfen
                $ip = $_SERVER['REMOTE_ADDR'];
                if (!$rateLimiter->checkLimit($ip, 'login_attempt', 5, 300)) { // 5 Versuche pro 5 Min
                    jsonError('Too many login attempts. Please try again later.', 429);
                }
                
                if ($auth->login($username, $password)) {
                    // Login erfolgreich
                    $user = $auth->getCurrentUser();
                    jsonResponse(['success' => true, 'user' => $user]);
                } else {
                    jsonError('Invalid credentials', 401);
                }
            } elseif ($action === 'logout') {
                // POST /api/auth/logout
                $auth->logout();
                jsonResponse(['success' => true]);
            } else {
                jsonError('Endpoint not found', 404);
            }
            break;

        default:
            jsonError('Method not allowed', 405);
    }
} catch (Exception $e) {
    handleApiException($e, 'Auth API Error');
}
