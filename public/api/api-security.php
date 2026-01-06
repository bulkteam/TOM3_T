<?php
/**
 * TOM3 - API Security Layer
 * 
 * Zentrale Sicherheits-Funktionen für alle API-Endpunkte
 */

declare(strict_types=1);

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Auth\AuthHelper;
use TOM\Infrastructure\Security\SecurityHelper;
use TOM\Infrastructure\Security\CsrfTokenService;
use TOM\Service\User\UserPermissionService;

/**
 * Prüft ob die App-Umgebung gesetzt ist
 * In Production: Fail wenn nicht gesetzt (fail-closed)
 * In Dev: Default auf 'local'
 */
function requireAppEnv(): void
{
    try {
        SecurityHelper::requireAppEnv();
    } catch (\RuntimeException $e) {
        // In Production: Fail sofort
        http_response_code(500);
        echo json_encode([
            'error' => 'Configuration error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/**
 * Setzt CORS-Header (nur in dev/local)
 */
function setCorsHeaders(): void
{
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
    $isDev = in_array($appEnv, ['local', 'dev', 'development']);
    
    if ($isDev) {
        // Dev: CORS offen (für lokale Entwicklung)
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    } else {
        // Prod: CORS nur für erlaubte Domains
        $allowedOrigins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS') ?: '';
        if ($allowedOrigins) {
            $origins = explode(',', $allowedOrigins);
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if (in_array($origin, $origins)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Credentials: true');
            }
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    }
    
    // OPTIONS Preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Prüft ob der aktuelle User authentifiziert ist
 * 
 * @return array User-Daten
 * @throws \RuntimeException Wenn kein User eingeloggt
 */
function requireAuth(): array
{
    $user = AuthHelper::getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Authentication required']);
        exit;
    }
    return $user;
}

/**
 * Prüft ob der User eine bestimmte Rolle hat
 * 
 * @param string|array $requiredRole Rolle(n) die erforderlich sind
 * @return array User-Daten
 */
function requireRole($requiredRole): array
{
    $user = requireAuth();
    
    $roles = is_array($requiredRole) ? $requiredRole : [$requiredRole];
    $userRoles = $user['roles'] ?? [];
    
    if (empty(array_intersect($roles, $userRoles))) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions',
            'required' => $roles,
            'user_roles' => $userRoles
        ]);
        exit;
    }
    
    return $user;
}

/**
 * Prüft ob der User Admin-Rechte hat
 */
function requireAdmin(): array
{
    return requireRole('admin');
}

/**
 * Prüft ob der User eine bestimmte Capability hat
 * 
 * @param string $capability Capability (z.B. 'org.write', 'person.read')
 * @return array User-Daten
 */
function requireCapability(string $capability): array
{
    $user = requireAuth();
    $userId = (string)$user['user_id'];
    
    $permissionService = new UserPermissionService();
    $userRoles = $user['roles'] ?? null;
    
    if (!$permissionService->userHasCapability($userId, $capability, $userRoles)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions',
            'required_capability' => $capability,
            'user_roles' => $userRoles ?? []
        ]);
        exit;
    }
    
    return $user;
}

/**
 * Prüft ob der User mindestens eine der angegebenen Capabilities hat
 * 
 * @param array $capabilities Liste von Capabilities
 * @return array User-Daten
 */
function requireAnyCapability(array $capabilities): array
{
    $user = requireAuth();
    $userId = (string)$user['user_id'];
    
    $permissionService = new UserPermissionService();
    $userRoles = $user['roles'] ?? null;
    
    if (!$permissionService->userHasAnyCapability($userId, $capabilities, $userRoles)) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions',
            'required_capabilities' => $capabilities,
            'user_roles' => $userRoles ?? []
        ]);
        exit;
    }
    
    return $user;
}

/**
 * Liste der öffentlichen Endpunkte (keine Auth erforderlich)
 */
function isPublicEndpoint(string $resource, ?string $id = null, ?string $action = null): bool
{
    // Auth-Endpunkte sind öffentlich
    if ($resource === 'auth') {
        return true;
    }
    
    // Spezielle öffentliche Endpunkte können hier hinzugefügt werden
    // z.B. 'health', 'status', etc.
    
    return false;
}

/**
 * CSRF-Token generieren und in Session speichern
 * 
 * @return string CSRF-Token
 */
function generateCsrfToken(): string
{
    return CsrfTokenService::generateToken();
}

/**
 * CSRF-Token aus Session holen
 * 
 * @return string|null CSRF-Token oder null
 */
function getCsrfToken(): ?string
{
    return CsrfTokenService::getToken();
}

/**
 * Prüft CSRF-Token für state-changing Requests
 * 
 * @param string $method HTTP-Methode
 * @throws \RuntimeException Wenn CSRF-Token ungültig
 */
function validateCsrfToken(string $method): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
    
    try {
        CsrfTokenService::requireValidToken($method, $token);
    } catch (\RuntimeException $e) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Invalid CSRF token',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/**
 * Gibt eine sichere Fehlerantwort zurück
 * 
 * @param Exception $e Exception
 * @param bool $includeTrace Ob Stack-Trace enthalten sein soll (nur in dev)
 */
function sendErrorResponse(Exception $e, bool $includeTrace = false): void
{
    $isDev = SecurityHelper::isDevMode();
    
    $error = [
        'error' => 'Internal server error',
        'message' => $isDev ? $e->getMessage() : 'An error occurred'
    ];
    
    if ($isDev && $includeTrace) {
        $error['file'] = basename($e->getFile());
        $error['line'] = $e->getLine();
        $error['trace'] = explode("\n", $e->getTraceAsString());
    }
    
    // In Production: Korrelations-ID für Logs
    if (!$isDev) {
        $error['correlation_id'] = bin2hex(random_bytes(8));
        // Logge Details intern (nicht an Client)
        error_log("API Error [{$error['correlation_id']}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }
    
    http_response_code(500);
    echo json_encode($error);
    exit;
}


