<?php
/**
 * TOM3 - PLZ Lookup API
 * 
 * Gibt Stadt und Bundesland für eine PLZ zurück
 */

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

// Security Guard: Verhindere direkten Aufruf
if (!defined('TOM3_API_ROUTER')) {
    jsonError('Direct access not allowed', 403);
}

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
}

$plz = $_GET['plz'] ?? '';

if (empty($plz)) {
    jsonError('PLZ required', 400);
}

// Lade PLZ-Mapping
require_once __DIR__ . '/../../config/plz_mapping.php';

try {
    $result = mapPlzToBundeslandAndCity($plz);
    jsonResponse($result);
} catch (Exception $e) {
    handleApiException($e, 'PLZ Lookup');
}





