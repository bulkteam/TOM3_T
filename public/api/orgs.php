<?php
/**
 * TOM3 - Orgs API
 */

// Fehler als JSON ausgeben, nicht als HTML
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Content-Type auf JSON setzen
header('Content-Type: application/json; charset=utf-8');

// Shutdown Handler für Fatal Errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Fatal error',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
        exit;
    }
});

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
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
// Verwende die vom Router übergebenen Parameter
$orgUuid = $id ?? null;
$action = $action ?? null;

// Spezielle Behandlung für /api/orgs/owners (wird vom Router als $id='owners' übergeben)
if ($id === 'owners') {
    // GET /api/orgs/owners - Liste verfügbarer Account Owners
    $withNames = isset($_GET['with_names']) && $_GET['with_names'] === 'true';
    if ($withNames) {
        $owners = $orgService->getAvailableAccountOwnersWithNames();
    } else {
        $owners = $orgService->getAvailableAccountOwners();
    }
    echo json_encode($owners);
    exit;
}

// Für PUT/DELETE mit address_uuid oder relation_uuid müssen wir den Pfad nochmal parsen
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
// Entferne /TOM3/public falls vorhanden
$path = preg_replace('#^/TOM3/public#', '', $path);
// Entferne /api prefix
$path = preg_replace('#^/api/?|^api/?#', '', $path);
$path = trim($path, '/');
$pathParts = explode('/', $path);
// Filtere 'orgs' heraus
$pathParts = array_filter($pathParts, function($p) { return $p !== 'orgs'; });
$pathParts = array_values($pathParts);

switch ($method) {
    case 'GET':
        if ($orgUuid) {
            if ($action === 'addresses') {
                // GET /api/orgs/{uuid}/addresses
                $addressType = $_GET['type'] ?? null;
                $addresses = $orgService->getAddresses($orgUuid, $addressType);
                echo json_encode($addresses ?: []);
            } elseif ($action === 'relations') {
                // GET /api/orgs/{uuid}/relations
                $direction = $_GET['direction'] ?? null; // 'parent' | 'child' | null
                $relations = $orgService->getRelations($orgUuid, $direction);
                echo json_encode($relations ?: []);
            } elseif ($action === 'channels') {
                // GET /api/orgs/{uuid}/channels
                $channelType = $_GET['type'] ?? null;
                $channels = $orgService->getCommunicationChannels($orgUuid, $channelType);
                echo json_encode($channels ?: []);
            } elseif ($action === 'vat-registrations') {
                // GET /api/orgs/{uuid}/vat-registrations
                $onlyValid = !isset($_GET['all']) || $_GET['all'] !== 'true';
                $vatRegs = $orgService->getVatRegistrations($orgUuid, $onlyValid);
                echo json_encode($vatRegs ?: []);
            } elseif ($action === 'details') {
                // GET /api/orgs/{uuid}/details (mit Adressen und Relationen)
                $org = $orgService->getOrgWithDetails($orgUuid);
                if ($org) {
                    $org['health'] = $orgService->getAccountHealth($orgUuid);
                }
                echo json_encode($org);
            } elseif ($action === 'audit-trail') {
                // GET /api/orgs/{uuid}/audit-trail
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $auditTrail = $orgService->getAuditTrail($orgUuid, $limit);
                echo json_encode($auditTrail);
            } elseif ($action === 'health') {
                // GET /api/orgs/{uuid}/health - Account-Gesundheit
                $health = $orgService->getAccountHealth($orgUuid);
                echo json_encode($health);
            } else {
                // GET /api/orgs/{uuid}
                $org = $orgService->getOrg($orgUuid);
                
                // Track Zugriff beim Abrufen (optional, nur wenn explizit gewünscht)
                $trackAccess = isset($_GET['track']) && $_GET['track'] === 'true';
                if ($trackAccess && $org) {
                    $userId = $_GET['user_id'] ?? 'default_user';
                    try {
                        $orgService->trackAccess($userId, $orgUuid, 'recent');
                    } catch (Exception $e) {
                        // Tracking-Fehler sollten die Antwort nicht beeinflussen
                    }
                }
                
                echo json_encode($org);
            }
        } else {
            // GET /api/orgs
            // Unterstützt Query-Parameter: ?search=...&org_kind=...&limit=...
            $filters = [];
            if (!empty($_GET['search'])) {
                $filters['search'] = $_GET['search'];
            }
            if (!empty($_GET['org_kind'])) {
                $filters['org_kind'] = $_GET['org_kind'];
            }
            if (!empty($_GET['limit'])) {
                $filters['limit'] = (int)$_GET['limit'];
            }
            
            $orgs = $orgService->listOrgs($filters);
            // Stelle sicher, dass immer ein Array zurückgegeben wird
            echo json_encode($orgs ?: []);
        }
        break;
        
    case 'POST':
        if ($orgUuid && $action === 'addresses') {
            // POST /api/orgs/{uuid}/addresses
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $orgService->addAddress($orgUuid, $data);
            
            // Track Zugriff beim Hinzufügen einer Adresse
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'relations') {
            // POST /api/orgs/{uuid}/relations
            $data = json_decode(file_get_contents('php://input'), true);
            // parent_org_uuid sollte die aktuelle Org sein, wenn nicht explizit angegeben
            if (!isset($data['parent_org_uuid'])) {
                $data['parent_org_uuid'] = $orgUuid;
            }
            $result = $orgService->addRelation($data);
            
            // Track Zugriff beim Hinzufügen einer Relation
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'channels') {
            // POST /api/orgs/{uuid}/channels
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $orgService->addCommunicationChannel($orgUuid, $data);
            
            // Track Zugriff beim Hinzufügen eines Kanals
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'vat-registrations') {
            // POST /api/orgs/{uuid}/vat-registrations
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['vat_id']) || empty($data['country_code'])) {
                http_response_code(400);
                echo json_encode(['error' => 'vat_id and country_code required']);
                exit;
            }
            $result = $orgService->addVatRegistration($orgUuid, $data);
            
            // Track Zugriff beim Hinzufügen einer USt-ID
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'archive') {
            // POST /api/orgs/{uuid}/archive
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $result = $orgService->archiveOrg($orgUuid, $userId);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } elseif ($orgUuid && $action === 'unarchive') {
            // POST /api/orgs/{uuid}/unarchive
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $result = $orgService->unarchiveOrg($orgUuid, $userId);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        } else {
            // POST /api/orgs
            $data = json_decode(file_get_contents('php://input'), true);
            if ($data === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON data']);
                exit;
            }
            $userId = $_GET['user_id'] ?? 'default_user';
            $result = $orgService->createOrg($data, $userId);
            echo json_encode($result);
        }
        break;
        
    case 'PUT':
        if ($orgUuid && !$action) {
            // PUT /api/orgs/{uuid} - Update Organisation
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validierung: Stelle sicher, dass orgUuid nicht leer ist
            if (empty($orgUuid)) {
                http_response_code(400);
                echo json_encode(['error' => 'org_uuid is required']);
                exit;
            }
            
            // Track Zugriff beim Bearbeiten
            $userId = $_GET['user_id'] ?? 'default_user';
            $result = $orgService->updateOrg($orgUuid, $data, $userId);
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'addresses') {
            // PUT /api/orgs/{uuid}/addresses/{address_uuid}
            // address_uuid ist in $parts[3] nach dem Parsen
            $addressUuid = $pathParts[2] ?? null;
            if (!$addressUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'address_uuid required']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $orgService->updateAddress($addressUuid, $data);
            
            // Track Zugriff beim Bearbeiten einer Adresse
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'relations') {
            // PUT /api/orgs/{uuid}/relations/{relation_uuid}
            $relationUuid = $pathParts[2] ?? null;
            if (!$relationUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'relation_uuid required']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $orgService->updateRelation($relationUuid, $data);
            
            // Track Zugriff beim Bearbeiten einer Relation
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'channels') {
            // PUT /api/orgs/{uuid}/channels/{channel_uuid}
            $channelUuid = $pathParts[2] ?? null;
            if (!$channelUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'channel_uuid required']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $result = $orgService->updateCommunicationChannel($channelUuid, $data);
            
            // Track Zugriff beim Bearbeiten eines Kanals
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'vat-registrations') {
            // PUT /api/orgs/{uuid}/vat-registrations/{vat_registration_uuid}
            $vatUuid = $pathParts[2] ?? null;
            if (!$vatUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'vat_registration_uuid required']);
                exit;
            }
            try {
                $rawInput = file_get_contents('php://input');
                $data = json_decode($rawInput, true);
                
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid JSON data', 'json_error' => json_last_error_msg()]);
                    exit;
                }
                
                // Debug: Log die empfangenen Daten
                error_log("VAT Update - Received data: " . json_encode($data));
                error_log("VAT Update - VAT UUID: " . $vatUuid);
                
                $result = $orgService->updateVatRegistration($vatUuid, $data);
                
                if (!$result) {
                    http_response_code(404);
                    echo json_encode(['error' => 'VAT registration not found']);
                    exit;
                }
                
                // Track Zugriff beim Bearbeiten einer USt-ID
                $userId = $_GET['user_id'] ?? 'default_user';
                try {
                    $orgService->trackAccess($userId, $orgUuid, 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                    error_log("Tracking error (non-fatal): " . $e->getMessage());
                }
                
                $jsonResult = json_encode($result);
                if ($jsonResult === false) {
                    error_log("JSON encode error: " . json_last_error_msg());
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Failed to encode response',
                        'json_error' => json_last_error_msg()
                    ]);
                    exit;
                }
                echo $jsonResult;
                exit;
            } catch (PDOException $e) {
                http_response_code(500);
                $errorMsg = $e->getMessage();
                error_log("VAT Registration Update PDO Error: " . $errorMsg);
                error_log("PDO Error Info: " . json_encode($e->errorInfo ?? []));
                echo json_encode([
                    'error' => 'Database error',
                    'message' => $errorMsg,
                    'code' => $e->getCode()
                ]);
                exit;
            } catch (Exception $e) {
                http_response_code(500);
                $errorMsg = $e->getMessage();
                error_log("VAT Registration Update Error: " . $errorMsg);
                error_log("Error in file: " . $e->getFile() . " line " . $e->getLine());
                echo json_encode([
                    'error' => 'Failed to update VAT registration',
                    'message' => $errorMsg,
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]);
                exit;
            } catch (Throwable $e) {
                http_response_code(500);
                $errorMsg = $e->getMessage();
                error_log("VAT Registration Update Fatal Error: " . $errorMsg);
                echo json_encode([
                    'error' => 'Fatal error',
                    'message' => $errorMsg,
                    'type' => get_class($e)
                ]);
                exit;
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid endpoint']);
        }
        break;
        
    case 'DELETE':
        if ($orgUuid && $action === 'addresses') {
            // DELETE /api/orgs/{uuid}/addresses/{address_uuid}
            $addressUuid = $pathParts[2] ?? null;
            if (!$addressUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'address_uuid required']);
                exit;
            }
            $result = $orgService->deleteAddress($addressUuid);
            echo json_encode(['success' => $result]);
        } elseif ($orgUuid && $action === 'relations') {
            // DELETE /api/orgs/{uuid}/relations/{relation_uuid}
            $relationUuid = $pathParts[2] ?? null;
            if (!$relationUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'relation_uuid required']);
                exit;
            }
            $result = $orgService->deleteRelation($relationUuid);
            echo json_encode(['success' => $result]);
        } elseif ($orgUuid && $action === 'channels') {
            // DELETE /api/orgs/{uuid}/channels/{channel_uuid}
            // pathParts nach Filterung: [0] = org_uuid, [1] = channels, [2] = channel_uuid
            // Aber: Der Router gibt $id = org_uuid und $action = 'channels'
            // Also müssen wir die channel_uuid aus dem REST-Pfad extrahieren
            
            // Parse URL nochmal, um channel_uuid zu bekommen
            $requestUri = $_SERVER['REQUEST_URI'];
            $path = parse_url($requestUri, PHP_URL_PATH);
            $path = preg_replace('#^/TOM3/public#', '', $path);
            $path = preg_replace('#^/api/?|^api/?#', '', $path);
            $path = trim($path, '/');
            $urlParts = explode('/', $path);
            
            // urlParts: [0] = orgs, [1] = org_uuid, [2] = channels, [3] = channel_uuid
            $channelUuid = $urlParts[3] ?? null;
            
            if (!$channelUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'channel_uuid required']);
                exit;
            }
            
            $result = $orgService->deleteCommunicationChannel($channelUuid);
            
            if (!$result) {
                http_response_code(404);
                echo json_encode(['error' => 'Channel not found or could not be deleted']);
                exit;
            }
            
            // Track Zugriff beim Löschen eines Kanals
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode(['success' => true]);
        } elseif ($orgUuid && $action === 'vat-registrations') {
            // DELETE /api/orgs/{uuid}/vat-registrations/{vat_registration_uuid}
            $requestUri = $_SERVER['REQUEST_URI'];
            $path = parse_url($requestUri, PHP_URL_PATH);
            $path = preg_replace('#^/TOM3/public#', '', $path);
            $path = preg_replace('#^/api/?|^api/?#', '', $path);
            $path = trim($path, '/');
            $urlParts = explode('/', $path);
            
            // urlParts: [0] = orgs, [1] = org_uuid, [2] = vat-registrations, [3] = vat_uuid
            $vatUuid = $urlParts[3] ?? null;
            
            if (!$vatUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'vat_registration_uuid required']);
                exit;
            }
            
            $result = $orgService->deleteVatRegistration($vatUuid);
            
            if (!$result) {
                http_response_code(404);
                echo json_encode(['error' => 'VAT registration not found or could not be deleted']);
                exit;
            }
            
            // Track Zugriff beim Löschen einer USt-ID
            $userId = $_GET['user_id'] ?? 'default_user';
            try {
                $orgService->trackAccess($userId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid endpoint']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}


