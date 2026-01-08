<?php
/**
 * TOM3 - API Router
 * 
 * Zentrale API-Routing mit Security-Layer
 */

declare(strict_types=1);

// Definiere Router-Flag (wird von allen API-Handlern geprüft)
// MUSS vor den requires stehen, da api-security.php -> base-api-handler.php das Flag prüft
define('TOM3_API_ROUTER', true);

// Unterdrücke Deprecation-Warnungen von laudis/neo4j-php-client (PHP 8.1+ Kompatibilität)
// Dies muss VOR dem Autoloading geschehen, da die Klasse beim Laden bereits den Fehler wirft
$oldErrorReporting = error_reporting();
error_reporting($oldErrorReporting & ~E_DEPRECATED);

// Verhindere HTML-Fehlerausgaben
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/api-security.php';

// Prüfe APP_ENV (setzt Default auf 'local' wenn nicht gesetzt)
requireAppEnv();

// CORS Headers (dev vs prod)
setCorsHeaders();

// Content-Type
header('Content-Type: application/json; charset=utf-8');

// Error Handling (aber ignoriere E_DEPRECATED für Neo4j-Kompatibilität)
set_error_handler(function($severity, $message, $file, $line) {
    // Ignoriere Deprecation-Warnungen von laudis/neo4j-php-client
    if ($severity === E_DEPRECATED) {
        // Ignoriere alle Deprecation-Warnungen (nicht nur von Neo4j)
        // Dies verhindert, dass sie in Exceptions konvertiert werden
        return true; // Unterdrücke diese Warnung
    }
    // Nur non-deprecation Errors in Exceptions konvertieren
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Shutdown Handler für Fatal Errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        // Ignoriere Neo4j Deprecation-Fehler und alle ArrayAccess Return-Type Fehler
        if ($error['type'] === E_RECOVERABLE_ERROR && 
            (strpos($error['file'], 'laudis/neo4j-php-client') !== false ||
             strpos($error['message'], 'Return type of') !== false ||
             strpos($error['message'], 'ArrayAccess') !== false)) {
            return; // Ignoriere diesen Fehler
        }
        
        // Sende JSON-Fehlerantwort
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Internal server error',
            'message' => $error['message'] ?? 'Fatal error occurred',
            'file' => basename($error['file'] ?? 'unknown'),
            'line' => $error['line'] ?? 0
        ]);
        exit;
    }
});

try {
    // Robust path parsing independent of deploy base (/TOM3/public or /)
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
    
    // Script dir is the directory of this router, e.g. "/TOM3/public/api" or "/api"
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    
    // Strip scriptDir prefix from URL path if present
    if ($scriptDir !== '' && stripos($path, $scriptDir) === 0) {
        $path = substr($path, strlen($scriptDir));
    }
    
    // If called as /api/index.php/... strip index.php
    $path = preg_replace('#^/index\.php#i', '', $path);
    
    $path = trim($path, '/');               // e.g. "auth/current"
    $parts = ($path === '') ? [] : explode('/', $path);
    
    $method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $resource = $parts[0] ?? '';
    $id       = $parts[1] ?? null;
    $action   = $parts[2] ?? null;
    
    // Optional: helpful output if no resource given
    if ($resource === '') {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["ok" => true, "message" => "API router is running"], JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Auth-Check (außer für öffentliche Endpunkte)
    if (!isPublicEndpoint($resource, $id, $action)) {
        requireAuth();
    }
    
    // Spezielle Rollen-Checks für sensible Endpunkte
    if ($resource === 'monitoring' || $resource === 'users') {
        requireAdmin();
    }
    
    // Route to appropriate handler
    switch ($resource) {
        case 'health':
            // GET /api/health - Health check endpoint
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(["ok" => true, "message" => "API router is running"], JSON_UNESCAPED_SLASHES);
            exit;
        case 'import':
            require __DIR__ . '/import.php';
            break;
        case 'cases':
            require __DIR__ . '/cases.php';
            break;
        case 'workflow':
            require __DIR__ . '/workflow.php';
            break;
        case 'projects':
            require __DIR__ . '/projects.php';
            break;
        case 'orgs':
            if ($id === 'search') {
                // GET /api/orgs/search?q=... - Autocomplete-Suche
                require __DIR__ . '/orgs-search.php';
            } elseif ($id === 'recent') {
                // GET /api/orgs/recent - Zuletzt verwendet (Legacy, wird zu access-tracking umgeleitet)
                require __DIR__ . '/orgs-recent.php';
            } elseif ($id === 'track') {
                // POST /api/orgs/track - Track Zugriff (Legacy, wird zu access-tracking umgeleitet)
                require __DIR__ . '/orgs-track.php';
            } elseif ($id === 'owners') {
                // GET /api/orgs/owners - Liste verfügbarer Account Owners
                require __DIR__ . '/orgs.php';
            } else {
                require __DIR__ . '/orgs.php';
            }
            break;
        case 'access-tracking':
            require __DIR__ . '/access-tracking.php';
            break;
        case 'industries':
            require __DIR__ . '/industries.php';
            break;
        case 'plz-lookup':
            require __DIR__ . '/plz-lookup.php';
            break;
        case 'address-types':
            require __DIR__ . '/address-types.php';
            break;
        case 'accounts':
            require __DIR__ . '/accounts.php';
            break;
        case 'users':
            require __DIR__ . '/users.php';
            break;
        case 'persons':
            if ($id === 'recent') {
                // GET /api/persons/recent - Zuletzt angesehen
                require __DIR__ . '/persons-recent.php';
            } elseif ($id === 'track') {
                // POST /api/persons/track - Track Zugriff
                require __DIR__ . '/persons-track.php';
            } elseif ($id === 'by-org' || $id === 'search') {
                // GET /api/persons/by-org?org_uuid=...&include_inactive=1
                // GET /api/persons/search?q=...
                require __DIR__ . '/persons.php';
            } else {
                require __DIR__ . '/persons.php';
            }
            break;
        case 'tasks':
            require __DIR__ . '/tasks.php';
            break;
        case 'monitoring':
            require __DIR__ . '/monitoring.php';
            break;
        case 'auth':
            // $id enthält den ersten Teil nach 'auth' (z.B. 'current' für /api/auth/current)
            // auth.php erwartet $action als ersten Teil nach 'auth'
            // Setze $action auf $id, damit auth.php es korrekt verarbeitet
            $action = $id ?? $action ?? null;
            $id = null; // Reset $id, da auth.php es nicht verwendet
            require __DIR__ . '/auth.php';
            break;
        case 'documents':
            if ($id === 'recent') {
                // GET /api/documents/recent - Zuletzt angesehen
                require __DIR__ . '/documents-recent.php';
            } elseif ($id === 'track') {
                // POST /api/documents/track - Track Zugriff
                require __DIR__ . '/documents-track.php';
            } else {
                require __DIR__ . '/documents.php';
            }
            break;
        case 'queues':
            require __DIR__ . '/queues.php';
            break;
        case 'work-items':
            require __DIR__ . '/work-items.php';
            break;
        case 'telephony':
            require __DIR__ . '/telephony.php';
            break;
        default:
            http_response_code(404);
            echo json_encode([
                'error' => 'Not found',
                'message' => 'API endpoint not found',
                'resource' => $resource,
                'path' => $path
            ]);
            break;
    }
} catch (Exception $e) {
    sendErrorResponse($e, true);
}
