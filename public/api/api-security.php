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

/**
 * Prüft ob die App-Umgebung gesetzt ist
 * Setzt Default auf 'local' für lokale Entwicklung
 */
function requireAppEnv(): void
{
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
    if (empty($appEnv)) {
        // In lokaler Entwicklung: Default auf 'local' setzen
        // In Production sollte APP_ENV explizit gesetzt sein
        $_ENV['APP_ENV'] = 'local';
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
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
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
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
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
 * @return array|null User-Daten oder null
 */
function requireAuth(): ?array
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
 * Gibt eine sichere Fehlerantwort zurück
 * 
 * @param Exception $e Exception
 * @param bool $includeTrace Ob Stack-Trace enthalten sein soll (nur in dev)
 */
function sendErrorResponse(Exception $e, bool $includeTrace = false): void
{
    $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
    $isDev = in_array($appEnv, ['local', 'dev', 'development']);
    
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
