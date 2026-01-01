<?php
/**
 * TOM3 - API Router
 * 
 * Zentrale API-Routing mit Security-Layer
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/api-security.php';

// Prüfe APP_ENV (setzt Default auf 'local' wenn nicht gesetzt)
requireAppEnv();

// CORS Headers (dev vs prod)
setCorsHeaders();

// Content-Type
header('Content-Type: application/json; charset=utf-8');

// Error Handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Parse URL path
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    
    // Entferne /TOM3/public oder /tom3/public falls vorhanden (case-insensitive)
    $path = preg_replace('#^/tom3/public#i', '', $path);
    
    // Entferne /api prefix
    $path = preg_replace('#^/api/?|^api/?#', '', $path);
    $path = trim($path, '/');
    
    $parts = explode('/', $path);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $parts[0] ?? '';
    $id = $parts[1] ?? null; // Pass ID to sub-handlers
    $action = $parts[2] ?? null; // Pass action to sub-handlers
    
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
            require __DIR__ . '/auth.php';
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found', 'path' => $path, 'resource' => $resource]);
            break;
    }
} catch (Exception $e) {
    sendErrorResponse($e, true);
}
