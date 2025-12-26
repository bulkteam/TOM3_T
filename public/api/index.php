<?php
/**
 * TOM3 - API Router
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// CORS Headers (für Entwicklung)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Content-Type
header('Content-Type: application/json');

// Error Handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Parse URL path
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    
    // Entferne /TOM3/public falls vorhanden
    $path = preg_replace('#^/TOM3/public#', '', $path);
    
    // Entferne /api prefix
    $path = preg_replace('#^/api/?|^api/?#', '', $path);
    $path = trim($path, '/');
    
    $parts = explode('/', $path);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $parts[0] ?? '';
    $id = $parts[1] ?? null; // Pass ID to sub-handlers
    $action = $parts[2] ?? null; // Pass action to sub-handlers
    
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
                // GET /api/orgs/recent - Zuletzt verwendet
                require __DIR__ . '/orgs-recent.php';
            } elseif ($id === 'track') {
                // POST /api/orgs/track - Track Zugriff
                require __DIR__ . '/orgs-track.php';
            } elseif ($id === 'owners') {
                // GET /api/orgs/owners - Liste verfügbarer Account Owners
                require __DIR__ . '/orgs.php';
            } else {
                require __DIR__ . '/orgs.php';
            }
            break;
        case 'industries':
            require __DIR__ . '/industries.php';
            break;
        case 'accounts':
            require __DIR__ . '/accounts.php';
            break;
        case 'users':
            require __DIR__ . '/users.php';
            break;
        case 'persons':
            require __DIR__ . '/persons.php';
            break;
        case 'tasks':
            require __DIR__ . '/tasks.php';
            break;
        case 'monitoring':
            require __DIR__ . '/monitoring.php';
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found', 'path' => $path, 'resource' => $resource]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
