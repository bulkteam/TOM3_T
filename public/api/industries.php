<?php
/**
 * TOM3 - Industries API
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Auth\AuthHelper;
use TOM\Infrastructure\Activity\ActivityLogService;
use TOM\Infrastructure\Utils\UuidHelper;

try {
    $db = DatabaseConnection::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // POST: Neue Branche hinzufügen
    
    $currentUser = AuthHelper::getCurrentUser();
    $userId = $currentUser['user_id'] ?? null;
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'name is required']);
        exit;
    }
    
    try {
        $name = trim($data['name']);
        $nameShort = isset($data['name_short']) ? trim($data['name_short']) : null;
        $code = $data['code'] ?? null;
        $parentUuid = $data['parent_industry_uuid'] ?? null;
        $description = $data['description'] ?? null;
        
        // Wenn kein name_short angegeben, verwende name als name_short
        if (empty($nameShort)) {
            $nameShort = $name;
        }
        
        // Generiere UUID (nutze UuidHelper)
        $industryUuid = UuidHelper::generate($db);
        
        // Prüfe, ob Branche bereits existiert (mit Parent, falls vorhanden)
        if ($parentUuid) {
            $stmt = $db->prepare("
                SELECT industry_uuid 
                FROM industry 
                WHERE name = :name AND parent_industry_uuid = :parent_uuid
            ");
            $stmt->execute(['name' => $name, 'parent_uuid' => $parentUuid]);
        } else {
            $stmt = $db->prepare("SELECT industry_uuid FROM industry WHERE name = :name AND parent_industry_uuid IS NULL");
            $stmt->execute(['name' => $name]);
        }
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Industry already exists',
                'industry_uuid' => $existing['industry_uuid']
            ]);
            exit;
        }
        
        // Füge Branche hinzu
        $stmt = $db->prepare("
            INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description)
            VALUES (:uuid, :name, :name_short, :code, :parent_uuid, :description)
        ");
        
        $stmt->execute([
            'uuid' => $industryUuid,
            'name' => $name,
            'name_short' => $nameShort,
            'code' => $code,
            'parent_uuid' => $parentUuid,
            'description' => $description
        ]);
        
        // Activity-Log
        $activityLogService = new ActivityLogService($db);
        $activityLogService->logActivity(
            (string)$userId,
            'system_action',
            'industry',
            $industryUuid,
            [
                'action' => 'industry_created',
                'name' => $name,
                'code' => $code,
                'parent_uuid' => $parentUuid,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        echo json_encode([
            'success' => true,
            'industry_uuid' => $industryUuid,
            'name' => $name
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Prüfe verschiedene Filter-Optionen
    $parentUuid = $_GET['parent_uuid'] ?? null;
    $mainClassesOnly = isset($_GET['main_classes_only']) && $_GET['main_classes_only'] === 'true';
    $level = isset($_GET['level']) ? (int)$_GET['level'] : null;
    
    // Level-basierte Abfrage (neue 3-stufige Hierarchie)
    if ($level !== null && $level >= 1 && $level <= 3) {
        if ($level === 1) {
            // Level 1: Branchenbereiche (ohne parent)
            $stmt = $db->prepare("
                SELECT industry_uuid, name, name_short, code, parent_industry_uuid, description, created_at,
                       COALESCE(name_short, name) as display_name
                FROM industry 
                WHERE parent_industry_uuid IS NULL 
                ORDER BY name
            ");
            $stmt->execute();
            $allIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Filtere Duplikate: Behalte nur die erste Branche pro Name
            $industries = [];
            $seenNames = [];
            foreach ($allIndustries as $industry) {
                $name = $industry['name'];
                if (!isset($seenNames[$name])) {
                    $seenNames[$name] = true;
                    $industries[] = $industry;
                }
            }
        } elseif ($level === 2) {
            // Level 2: Branchen (mit parent auf Level 1)
            if ($parentUuid) {
                // Nur Branchen eines bestimmten Branchenbereichs
                $stmt = $db->prepare("
                    SELECT i2.industry_uuid, i2.name, i2.code, i2.parent_industry_uuid, i2.description, i2.created_at
                    FROM industry i2
                    WHERE i2.parent_industry_uuid = :parent_uuid
                    ORDER BY i2.name
                ");
                $stmt->execute(['parent_uuid' => $parentUuid]);
                $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Alle Level 2 Branchen (haben einen Level 1 Parent)
                $stmt = $db->prepare("
                    SELECT i2.industry_uuid, i2.name, i2.name_short, i2.code, i2.parent_industry_uuid, i2.description, i2.created_at,
                           COALESCE(i2.name_short, i2.name) as display_name
                    FROM industry i2
                    INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
                    WHERE i1.parent_industry_uuid IS NULL
                    ORDER BY i2.name
                ");
                $stmt->execute();
                $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($level === 3) {
            // Level 3: Unterbranchen (mit parent auf Level 2)
            if ($parentUuid) {
                // Nur Unterbranchen einer bestimmten Branche
                $stmt = $db->prepare("
                    SELECT i3.industry_uuid, i3.name, i3.code, i3.parent_industry_uuid, i3.description, i3.created_at
                    FROM industry i3
                    WHERE i3.parent_industry_uuid = :parent_uuid
                    ORDER BY i3.name
                ");
                $stmt->execute(['parent_uuid' => $parentUuid]);
                $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Alle Level 3 Unterbranchen (haben einen Level 2 Parent)
                $stmt = $db->prepare("
                    SELECT i3.industry_uuid, i3.name, i3.name_short, i3.code, i3.parent_industry_uuid, i3.description, i3.created_at,
                           COALESCE(i3.name_short, i3.name) as display_name
                    FROM industry i3
                    INNER JOIN industry i2 ON i3.parent_industry_uuid = i2.industry_uuid
                    INNER JOIN industry i1 ON i2.parent_industry_uuid = i1.industry_uuid
                    WHERE i1.parent_industry_uuid IS NULL
                    ORDER BY i3.name
                ");
                $stmt->execute();
                $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } elseif ($mainClassesOnly) {
        // Rückwärtskompatibilität: Nur Hauptklassen (ohne parent) - eindeutig nach Name
        $stmt = $db->prepare("
            SELECT industry_uuid, name, code, parent_industry_uuid, description, created_at
            FROM industry 
            WHERE parent_industry_uuid IS NULL 
            ORDER BY name
        ");
        $stmt->execute();
        $allIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtere Duplikate: Behalte nur die erste Branche pro Name
        $industries = [];
        $seenNames = [];
        foreach ($allIndustries as $industry) {
            $name = $industry['name'];
            if (!isset($seenNames[$name])) {
                $seenNames[$name] = true;
                $industries[] = $industry;
            }
        }
    } elseif ($parentUuid) {
        // Rückwärtskompatibilität: Unterklassen einer bestimmten Hauptklasse
        $stmt = $db->prepare("
            SELECT industry_uuid, name, name_short, code, parent_industry_uuid, description, created_at,
                   COALESCE(name_short, name) as display_name
            FROM industry 
            WHERE parent_industry_uuid = :parent_uuid 
            ORDER BY name
        ");
        $stmt->execute(['parent_uuid' => $parentUuid]);
        $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Alle Industries (für Kompatibilität)
        $stmt = $db->query("
            SELECT industry_uuid, name, name_short, code, parent_industry_uuid, description, created_at,
                   COALESCE(name_short, name) as display_name
            FROM industry 
            ORDER BY name
        ");
        $industries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($industries ?: []);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

