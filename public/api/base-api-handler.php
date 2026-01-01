<?php
/**
 * TOM3 - Base API Handler (Zentral)
 * Gemeinsame Patterns für API-Endpoints
 * Eliminiert Code-Duplikation zwischen orgs.php und persons.php
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

/**
 * Setzt Standard-Header für JSON-Responses
 */
function setJsonHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
}

/**
 * Gibt eine JSON-Response aus
 */
function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Gibt eine Fehler-Response aus
 */
function jsonError(string $message, int $statusCode = 500, ?string $file = null, ?int $line = null): void
{
    $error = ['error' => $message];
    if ($file) {
        $error['file'] = basename($file);
    }
    if ($line) {
        $error['line'] = $line;
    }
    jsonResponse($error, $statusCode);
}

/**
 * Registriert einen Shutdown-Handler für Fatal Errors
 */
function registerFatalErrorHandler(): void
{
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            setJsonHeaders();
            $message = $error['message'] ?? 'Fatal error';
            $file = $error['file'] ?? null;
            $line = $error['line'] ?? null;
            jsonError($message, 500, $file, $line);
        }
    });
}

/**
 * Initialisiert Standard-Error-Handling
 */
function initApiErrorHandling(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    setJsonHeaders();
    registerFatalErrorHandler();
}

/**
 * Validiert HTTP-Methode
 */
function validateMethod(string $allowedMethod): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $allowedMethod) {
        jsonError('Method not allowed', 405);
    }
}

/**
 * Liest JSON-Body aus Request
 */
function getJsonBody(): array
{
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON: ' . json_last_error_msg(), 400);
    }
    
    return $data ?? [];
}

/**
 * Holt Query-Parameter
 */
function getQueryParam(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/**
 * Holt Boolean-Query-Parameter
 */
function getBoolQueryParam(string $key, bool $default = false): bool
{
    $value = getQueryParam($key);
    if ($value === null) {
        return $default;
    }
    return $value === 'true' || $value === '1' || $value === true;
}
