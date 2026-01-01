<?php
/**
 * TOM3 - Persons API
 */

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Service\PersonService;
use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    $personService = new PersonService($db);
} catch (Exception $e) {
    jsonError('Database connection failed: ' . $e->getMessage(), 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$personUuid = $id ?? null;
$action = $action ?? null;

// Spezielle Behandlung für /api/persons/search (wird vom Router als $id='search' übergeben)
if ($id === 'search') {
    // GET /api/persons/search?q=...
    $query = $_GET['q'] ?? '';
    $activeOnly = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== 'true';
    $persons = $personService->searchPersons($query, $activeOnly);
    echo json_encode($persons ?: []);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            if ($action === 'affiliations' && $personUuid) {
                // GET /api/persons/{uuid}/affiliations
                $activeOnly = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== 'true';
                $affiliations = $personService->getPersonAffiliations($personUuid, $activeOnly);
                echo json_encode($affiliations ?: []);
            } elseif ($action === 'audit-trail' && $personUuid) {
                // GET /api/persons/{uuid}/audit-trail
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $auditTrail = $personService->getAuditTrail($personUuid, $limit);
                echo json_encode($auditTrail);
            } elseif ($action === 'relationships' && $personUuid) {
                // GET /api/persons/{uuid}/relationships
                $activeOnly = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== 'true';
                $relationships = $personService->getPersonRelationships($personUuid, $activeOnly);
                echo json_encode($relationships ?: []);
            } elseif ($action === 'org-units' && isset($_GET['org_uuid'])) {
                // GET /api/persons/org-units?org_uuid=...
                $orgUuid = $_GET['org_uuid'];
                $orgUnits = $personService->getOrgUnits($orgUuid);
                echo json_encode($orgUnits ?: []);
            } elseif ($personUuid) {
                // GET /api/persons/{uuid}
                $person = $personService->getPerson($personUuid);
                if (!$person) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Person not found']);
                } else {
                    echo json_encode($person);
                }
            } else {
                // GET /api/persons
                $activeOnly = !isset($_GET['include_inactive']) || $_GET['include_inactive'] !== 'true';
                $persons = $personService->listPersons($activeOnly);
                echo json_encode($persons ?: []);
            }
            break;
            
        case 'POST':
            if ($action === 'affiliations' && $personUuid) {
                // POST /api/persons/{uuid}/affiliations
                $data = json_decode(file_get_contents('php://input'), true);
                $data['person_uuid'] = $personUuid;
                $result = $personService->createAffiliation($data);
                http_response_code(201);
                echo json_encode($result);
            } elseif ($action === 'relationships' && $personUuid) {
                // POST /api/persons/{uuid}/relationships
                $data = json_decode(file_get_contents('php://input'), true);
                $data['person_a_uuid'] = $personUuid;
                $result = $personService->createRelationship($data);
                http_response_code(201);
                echo json_encode($result);
            } elseif ($action === 'org-units') {
                // POST /api/persons/org-units
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $personService->createOrgUnit($data);
                http_response_code(201);
                echo json_encode($result);
            } else {
                // POST /api/persons
                $data = json_decode(file_get_contents('php://input'), true);
                if (empty($data)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid request body']);
                    break;
                }
                try {
                    $result = $personService->createPerson($data);
                    http_response_code(201);
                    echo json_encode($result);
                } catch (\InvalidArgumentException $e) {
                    http_response_code(409); // Conflict
                    echo json_encode(['error' => $e->getMessage()]);
                }
            }
            break;
            
        case 'PUT':
        case 'PATCH':
            if (!$personUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'Person UUID required']);
                break;
            }
            // PUT/PATCH /api/persons/{uuid}
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid request body']);
                break;
            }
            try {
                $result = $personService->updatePerson($personUuid, $data);
                echo json_encode($result);
            } catch (\InvalidArgumentException $e) {
                http_response_code(409); // Conflict
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;
            
        case 'DELETE':
            if ($action === 'relationships' && isset($_GET['relationship_uuid'])) {
                // DELETE /api/persons/{uuid}/relationships?relationship_uuid=...
                $relationshipUuid = $_GET['relationship_uuid'];
                $result = $personService->deleteRelationship($relationshipUuid);
                if ($result) {
                    http_response_code(204);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Relationship not found']);
                }
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}


