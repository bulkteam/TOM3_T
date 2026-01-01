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

// Headers werden bereits vom Router gesetzt
// Nur setzen, wenn noch nicht gesetzt (für direkten Aufruf)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $activityLogService = new ActivityLogService();
    $auth = new AuthService(null, $activityLogService);
} catch (Exception $e) {
    http_response_code(500);
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
    $isDev = in_array($appEnv, ['local', 'dev', 'development']);
    
    $error = [
        'error' => 'Auth service initialization failed',
        'message' => $isDev ? $e->getMessage() : 'Authentication service unavailable'
    ];
    
    if ($isDev) {
        $error['file'] = basename($e->getFile());
        $error['line'] = $e->getLine();
        $error['trace'] = explode("\n", $e->getTraceAsString());
    }
    
    echo json_encode($error);
    exit;
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
                // GET /api/auth/current - Aktueller User
                try {
                    $user = $auth->getCurrentUser();
                    if ($user) {
                        // Entferne sensitive Daten
                        unset($user['last_login_at']);
                        $json = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if ($json === false) {
                            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
                        }
                        echo $json;
                    } else {
                        http_response_code(401);
                        echo json_encode(['error' => 'Not authenticated']);
                    }
                } catch (\Exception $e) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Failed to get current user',
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }
            } elseif ($action === 'users' && $auth->isDevMode()) {
                // GET /api/auth/users - Liste aller User (nur Dev-Modus)
                try {
                    $users = $auth->getActiveUsers();
                    echo json_encode($users);
                } catch (\Exception $e) {
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Failed to get users',
                        'message' => $e->getMessage()
                    ]);
                }
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Not found', 'action' => $action]);
            }
        break;
        
    case 'POST':
        if ($action === 'login' && $auth->isDevMode()) {
            // POST /api/auth/login - Login (nur Dev-Modus)
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
                http_response_code(500);
                echo json_encode([
                    'error' => 'Login failed',
                    'message' => $e->getMessage()
                ]);
            }
        } elseif ($action === 'logout') {
            // POST /api/auth/logout - Logout
            try {
                $auth->logout();
                echo json_encode(['success' => true]);
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Logout failed',
                    'message' => $e->getMessage()
                ]);
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
    // Catch any unhandled errors (including fatal errors)
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => get_class($e)
    ]);
}


