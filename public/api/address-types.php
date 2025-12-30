<?php
/**
 * TOM3 - Address Types API
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

$configFile = __DIR__ . '/../../config/address_types.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Address types config not found']);
    exit;
}

$addressTypes = require $configFile;
echo json_encode($addressTypes);



