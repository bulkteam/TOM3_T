<?php
/**
 * TOM3 - Logout
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Auth\AuthService;

try {
    $auth = new AuthService();
    $auth->logout();
} catch (Exception $e) {
    // Ignoriere Fehler beim Logout
}

// Relativer Pfad zum Login
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
header('Location: ' . $basePath . '/login.php');
exit;

