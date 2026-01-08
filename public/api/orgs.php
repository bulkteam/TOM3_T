<?php
/**
 * TOM3 - Orgs API
 */

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    require_once __DIR__ . '/base-api-handler.php';
    initApiErrorHandling();
    jsonError('Direct access not allowed', 403);
}

require_once __DIR__ . '/base-api-handler.php';
require_once __DIR__ . '/api-security.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\OrgService;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Auth\AuthHelper;

try {
    $db = DatabaseConnection::getInstance();
    $orgService = new OrgService($db);
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];

// Auth prüfen für geschützte Endpoints (außer GET ohne state-changing actions)
// GET-Endpoints sind öffentlich, POST/PUT/DELETE benötigen Auth
$currentUser = null;
$currentUserId = null;
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    $currentUser = requireAuth();
    $currentUserId = (string)$currentUser['user_id'];
    // CSRF prüfen für state-changing Requests
    validateCsrfToken($method);
}
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
    jsonResponse($owners);
    exit;
}

// Spezielle Behandlung für /api/orgs/next-customer-number
if ($id === 'next-customer-number') {
    // GET /api/orgs/next-customer-number - Nächste verfügbare Kundennummer
    try {
        $nextNumber = $orgService->getNextCustomerNumber();
        jsonResponse(['next_customer_number' => $nextNumber]);
    } catch (Exception $e) {
        handleApiException($e, 'Get next customer number');
    }
    exit;
}

$pathParts = parseApiPathParts('orgs');

switch ($method) {
    case 'GET':
        if ($orgUuid) {
            if ($action === 'addresses') {
                // GET /api/orgs/{uuid}/addresses
                $addressType = $_GET['type'] ?? null;
                $addresses = $orgService->getAddresses($orgUuid, $addressType);
                jsonResponse($addresses ?: []);
            } elseif ($action === 'relations') {
                // GET /api/orgs/{uuid}/relations
                $direction = $_GET['direction'] ?? null; // 'parent' | 'child' | null
                $relations = $orgService->getRelations($orgUuid, $direction);
                jsonResponse($relations ?: []);
            } elseif ($action === 'channels') {
                // GET /api/orgs/{uuid}/channels
                $channelType = $_GET['type'] ?? null;
                $channels = $orgService->getCommunicationChannels($orgUuid, $channelType);
                jsonResponse($channels ?: []);
            } elseif ($action === 'vat-registrations') {
                // GET /api/orgs/{uuid}/vat-registrations
                $onlyValid = !isset($_GET['all']) || $_GET['all'] !== 'true';
                $vatRegs = $orgService->getVatRegistrations($orgUuid, $onlyValid);
                jsonResponse($vatRegs ?: []);
            } elseif ($action === 'details') {
                // GET /api/orgs/{uuid}/details (mit Adressen und Relationen)
                $org = $orgService->getOrgWithDetails($orgUuid);
                if ($org) {
                    $org['health'] = $orgService->getAccountHealth($orgUuid);
                }
                jsonResponse($org);
            } elseif ($action === 'audit-trail') {
                // GET /api/orgs/{uuid}/audit-trail
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $auditTrail = $orgService->getAuditTrail($orgUuid, $limit);
                jsonResponse($auditTrail);
            } elseif ($action === 'track-access') {
                // POST /api/orgs/{uuid}/track-access
                if ($method !== 'POST') {
                    jsonError('Method not allowed. Use POST.', 405);
                }
                
                // Security: Auth erzwingen (kein 'default_user' Fallback)
                $currentUser = requireAuth();
                $userId = (string)$currentUser['user_id'];
                
                // Unterstütze sowohl Body als auch Query-Parameter
                $data = json_decode(file_get_contents('php://input'), true) ?: [];
                $accessType = $data['access_type'] ?? $_GET['access_type'] ?? 'recent';
                
                try {
                    $orgService->trackAccess($userId, $orgUuid, $accessType);
                    jsonResponse(['success' => true]);
                } catch (Exception $e) {
                    handleApiException($e, 'Track access');
                }
            } elseif ($action === 'health') {
                // GET /api/orgs/{uuid}/health - Account-Gesundheit
                $health = $orgService->getAccountHealth($orgUuid);
                jsonResponse($health);
            } else {
                // GET /api/orgs/{uuid}
                $org = $orgService->getOrg($orgUuid);
                
                // Track Zugriff beim Abrufen (optional, nur wenn explizit gewünscht)
                $trackAccess = isset($_GET['track']) && $_GET['track'] === 'true';
                if ($trackAccess && $org) {
                    try {
                        $user = AuthHelper::getCurrentUser();
                        $userId = $_GET['user_id'] ?? ($user ? (string)$user['user_id'] : null);
                        if ($userId) {
                            $orgService->trackAccess($userId, $orgUuid, 'recent');
                        }
                    } catch (Exception $e) {
                        // Tracking-Fehler sollten die Antwort nicht beeinflussen
                    }
                }
                
                jsonResponse($org);
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
            jsonResponse($orgs ?: []);
        }
        break;
        
    case 'POST':
        if ($orgUuid && $action === 'addresses') {
            // POST /api/orgs/{uuid}/addresses
            $data = json_decode(file_get_contents('php://input'), true);
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->addAddress($orgUuid, $data, $currentUserId);
            
            // Track Zugriff beim Hinzufügen einer Adresse
            try {
                $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            jsonResponse($result);
        } elseif ($orgUuid && $action === 'relations') {
            // POST /api/orgs/{uuid}/relations
            $data = json_decode(file_get_contents('php://input'), true);
            // parent_org_uuid sollte die aktuelle Org sein, wenn nicht explizit angegeben
            if (!isset($data['parent_org_uuid'])) {
                $data['parent_org_uuid'] = $orgUuid;
            }
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->addRelation($data, $currentUserId);
            
            // Track Zugriff beim Hinzufügen einer Relation
            try {
                $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            jsonResponse($result);
        } elseif ($orgUuid && $action === 'channels') {
            // POST /api/orgs/{uuid}/channels
            $data = json_decode(file_get_contents('php://input'), true);
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->addCommunicationChannel($orgUuid, $data, $currentUserId);
            
            // Track Zugriff beim Hinzufügen eines Kanals
            try {
                $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            jsonResponse($result);
        } elseif ($orgUuid && $action === 'vat-registrations') {
            // POST /api/orgs/{uuid}/vat-registrations
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                jsonError('Invalid JSON data: ' . json_last_error_msg(), 400);
            }
            
            if (empty($data['vat_id']) || empty($data['country_code'])) {
                jsonError('vat_id and country_code required', 400);
            }
            
            try {
                // $currentUserId ist bereits durch requireAuth() gesetzt
                $result = $orgService->addVatRegistration($orgUuid, $data, $currentUserId);
                
                // Track Zugriff beim Hinzufügen einer USt-ID
                try {
                    $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                    error_log("Tracking error (non-fatal): " . $e->getMessage());
                }
                
                jsonResponse($result);
            } catch (Exception $e) {
                handleApiException($e, 'Add VAT registration');
            }
        } elseif ($orgUuid && $action === 'archive') {
            // POST /api/orgs/{uuid}/archive
            // $currentUserId ist bereits durch requireAuth() gesetzt
            try {
                $result = $orgService->archiveOrg($orgUuid, $currentUserId);
                jsonResponse($result);
            } catch (Exception $e) {
                handleApiException($e, 'Archive org');
            }
        } elseif ($orgUuid && $action === 'unarchive') {
            // POST /api/orgs/{uuid}/unarchive
            // $currentUserId ist bereits durch requireAuth() gesetzt
            try {
                $result = $orgService->unarchiveOrg($orgUuid, $currentUserId);
                jsonResponse($result);
            } catch (Exception $e) {
                handleApiException($e, 'Unarchive org');
            }
        } else {
            // POST /api/orgs
            $data = getJsonBody();
            
            // $currentUserId ist bereits durch requireAuth() gesetzt
            try {
                // Validierung mit InputValidator (optional, kann schrittweise migriert werden)
                // InputValidator::validateRequired($data, 'name');
                // InputValidator::validateLength($data['name'] ?? '', 1, 255, 'name');
                
                $result = $orgService->createOrg($data, $currentUserId);
                // Warnungen werden im Ergebnis mit _warnings Feld zurückgegeben
                jsonResponse($result, 201);
            } catch (\InvalidArgumentException $e) {
                handleApiException($e, 'Create org');
            } catch (\Exception $e) {
                handleApiException($e, 'Create org');
            }
        }
        break;
        
    case 'PUT':
        if ($orgUuid && !$action) {
            // PUT /api/orgs/{uuid} - Update Organisation
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validierung: Stelle sicher, dass orgUuid nicht leer ist
            if (empty($orgUuid)) {
                jsonError('org_uuid is required', 400);
            }
            
            // Track Zugriff beim Bearbeiten
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->updateOrg($orgUuid, $data, $currentUserId);
            try {
                $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            jsonResponse($result);
        } elseif ($orgUuid && $action === 'addresses') {
            // PUT /api/orgs/{uuid}/addresses/{address_uuid}
            // address_uuid ist in $parts[3] nach dem Parsen
            $addressUuid = $pathParts[2] ?? null;
            if (!$addressUuid) {
                jsonError('address_uuid required', 400);
            }
            $data = json_decode(file_get_contents('php://input'), true);
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->updateAddress($addressUuid, $data, $currentUserId);
            
            // Track Zugriff beim Bearbeiten einer Adresse
            try {
                $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
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
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->updateRelation($relationUuid, $data, $currentUserId);
            
            // Track Zugriff beim Bearbeiten einer Relation
            try {
                $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
            } catch (Exception $e) {
                // Tracking-Fehler sollten die Antwort nicht beeinflussen
            }
            
            echo json_encode($result);
        } elseif ($orgUuid && $action === 'channels') {
            // PUT /api/orgs/{uuid}/channels/{channel_uuid}
            $channelUuid = $pathParts[2] ?? null;
            if (!$channelUuid) {
                jsonError('channel_uuid required', 400);
            }
            $data = json_decode(file_get_contents('php://input'), true);
            // $currentUserId ist bereits durch requireAuth() gesetzt
            
            try {
                $result = $orgService->updateCommunicationChannel($channelUuid, $data, $currentUserId);
                
                // Track Zugriff beim Bearbeiten eines Kanals
                try {
                    $orgService->trackAccess($currentUserId, $orgUuid, 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                }
                
                jsonResponse($result);
            } catch (Exception $e) {
                handleApiException($e, 'Updating communication channel');
            }
        } elseif ($orgUuid && $action === 'vat-registrations') {
            // PUT /api/orgs/{uuid}/vat-registrations/{vat_registration_uuid}
            $vatUuid = $pathParts[2] ?? null;
            if (!$vatUuid) {
                jsonError('vat_registration_uuid required', 400);
            }
            try {
                $rawInput = file_get_contents('php://input');
                $data = json_decode($rawInput, true);
                
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    jsonError('Invalid JSON data: ' . json_last_error_msg(), 400);
                }
                
                // Debug: Log die empfangenen Daten
                error_log("VAT Update - Received data: " . json_encode($data));
                error_log("VAT Update - VAT UUID: " . $vatUuid);
                
                // $currentUserId ist bereits durch requireAuth() gesetzt
                $result = $orgService->updateVatRegistration($vatUuid, $data, $currentUserId);
                
                if (!$result) {
                    jsonError('VAT registration not found', 404);
                }
                
                // Track Zugriff beim Bearbeiten einer USt-ID
                try {
                    $orgService->trackAccess($userId, $orgUuid, 'recent');
                } catch (Exception $e) {
                    // Tracking-Fehler sollten die Antwort nicht beeinflussen
                    error_log("Tracking error (non-fatal): " . $e->getMessage());
                }
                
                jsonResponse($result);
            } catch (\Throwable $e) {
                handleApiException($e, 'Update VAT registration');
            }
        } else {
            jsonError('Invalid endpoint', 400);
        }
        break;
        
    case 'DELETE':
        if ($orgUuid && $action === 'addresses') {
            // DELETE /api/orgs/{uuid}/addresses/{address_uuid}
            $addressUuid = $pathParts[2] ?? null;
            if (!$addressUuid) {
                jsonError('address_uuid required', 400);
            }
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->deleteAddress($addressUuid, $currentUserId);
            jsonResponse(['success' => $result]);
        } elseif ($orgUuid && $action === 'relations') {
            // DELETE /api/orgs/{uuid}/relations/{relation_uuid}
            $relationUuid = $pathParts[2] ?? null;
            if (!$relationUuid) {
                jsonError('relation_uuid required', 400);
            }
            // $currentUserId ist bereits durch requireAuth() gesetzt
            $result = $orgService->deleteRelation($relationUuid, $currentUserId);
            jsonResponse(['success' => $result]);
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
                // Alternativ aus pathParts probieren
                $channelUuid = $pathParts[2] ?? null;
            }
            
            if (!$channelUuid) {
                jsonError('channel_uuid required', 400);
            }
            // $currentUserId ist bereits durch requireAuth() gesetzt
            
            try {
                $result = $orgService->deleteCommunicationChannel($channelUuid, $currentUserId);
                jsonResponse(['success' => $result]);
            } catch (Exception $e) {
                handleApiException($e, 'Deleting communication channel');
            }
        } elseif ($orgUuid && $action === 'vat-registrations') {
            // DELETE /api/orgs/{uuid}/vat-registrations/{vat_registration_uuid}
            $vatUuid = $pathParts[2] ?? null;
            if (!$vatUuid) {
                jsonError('vat_registration_uuid required', 400);
            }
            try {
                // $currentUserId ist bereits durch requireAuth() gesetzt
                $result = $orgService->deleteVatRegistration($vatUuid, $currentUserId);
                
                if (!$result) {
                    jsonError('VAT registration not found', 404);
                }
                
                jsonResponse(['success' => true]);
            } catch (Exception $e) {
                handleApiException($e, 'Delete VAT registration');
            }
        } else {
            jsonError('Invalid endpoint', 400);
        }
        break;
        
    default:
        jsonError('Method not allowed', 405);
        break;
}


