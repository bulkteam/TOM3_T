<?php
/**
 * TOM3 - Industries API
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Prüfe ob nach Hauptklassen oder Unterklassen gefiltert werden soll
    $parentUuid = $_GET['parent_uuid'] ?? null;
    $mainClassesOnly = isset($_GET['main_classes_only']) && $_GET['main_classes_only'] === 'true';
    
    if ($mainClassesOnly) {
        // Nur Hauptklassen (ohne parent)
        $stmt = $db->prepare("SELECT * FROM industry WHERE parent_industry_uuid IS NULL ORDER BY name");
        $stmt->execute();
        $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($parentUuid) {
        // Unterklassen einer bestimmten Hauptklasse
        $stmt = $db->prepare("SELECT * FROM industry WHERE parent_industry_uuid = :parent_uuid ORDER BY name");
        $stmt->execute(['parent_uuid' => $parentUuid]);
        $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Alle Industries (für Kompatibilität)
        $stmt = $db->query("SELECT * FROM industry ORDER BY name");
        $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($industries ?: []);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

