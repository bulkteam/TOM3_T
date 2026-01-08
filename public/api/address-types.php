<?php
/**
 * TOM3 - Address Types API
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
}

$configFile = __DIR__ . '/../../config/address_types.php';
if (!file_exists($configFile)) {
    jsonError('Address types config not found', 500);
}

$addressTypes = require $configFile;
jsonResponse($addressTypes);





