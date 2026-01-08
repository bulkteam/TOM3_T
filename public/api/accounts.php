<?php
/**
 * TOM3 - Accounts API (Account Owner Dashboard)
 */

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    jsonError('Direct access not allowed', 403);
}

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $orgService = new OrgService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonError('Method not allowed', 405);
}

// GET /api/accounts?user_id=...
// Security: Auth erzwingen (kein 'default_user' Fallback)
require_once __DIR__ . '/api-security.php';
$currentUser = requireAuth();
$userId = $_GET['user_id'] ?? (string)$currentUser['user_id'];

try {
    $accounts = $orgService->getAccountsByOwner($userId, true);
    
    // Sortiere nach Gesundheitsstatus (rot zuerst, dann gelb, dann grün)
    usort($accounts, function($a, $b) {
        $statusOrder = ['red' => 0, 'yellow' => 1, 'green' => 2, 'unknown' => 3];
        $aStatus = $statusOrder[$a['health']['status']] ?? 3;
        $bStatus = $statusOrder[$b['health']['status']] ?? 3;
        return $aStatus <=> $bStatus;
    });
    
    jsonResponse($accounts ?: []);
} catch (Exception $e) {
    handleApiException($e, 'Get accounts');
}





