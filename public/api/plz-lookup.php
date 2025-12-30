<?php
/**
 * TOM3 - PLZ Lookup API
 * 
 * Gibt Stadt und Bundesland für eine PLZ zurück
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$plz = $_GET['plz'] ?? '';

if (empty($plz)) {
    http_response_code(400);
    echo json_encode(['error' => 'PLZ required']);
    exit;
}

// Lade PLZ-Mapping
require_once __DIR__ . '/../../config/plz_mapping.php';

try {
    $result = mapPlzToBundeslandAndCity($plz);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}



