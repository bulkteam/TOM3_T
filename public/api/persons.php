<?php
/**
 * TOM3 - Persons API
 */

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    require_once __DIR__ . '/base-api-handler.php';
    initApiErrorHandling();
    jsonError('Direct access not allowed', 403);
}

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

use TOM\Service\PersonService;
use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$personService = new PersonService($db);

$method = $_SERVER['REQUEST_METHOD'];
// Verwende $id vom Router (wird vom index.php übergeben)
// Der Router parst /api/persons/{uuid} und übergibt {uuid} als $id
$personUuid = $id ?? null;

// Prüfe auf by-org Endpoint (wird vom Router als $id='by-org' übergeben)
if (isset($id) && $id === 'by-org') {
    // GET /api/persons/by-org?org_uuid=...&include_inactive=1
    $orgUuid = $_GET['org_uuid'] ?? null;
    $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] == '1';
    
    if (!$orgUuid) {
        jsonError('org_uuid parameter required', 400);
    }
    
    $persons = $personService->listPersonsByOrg($orgUuid, $includeInactive);
    jsonResponse($persons);
    exit;
}

// Prüfe auf search Endpoint (wird vom Router als $id='search' übergeben)
if (isset($id) && $id === 'search') {
    // GET /api/persons/search?q=...
    $query = $_GET['q'] ?? '';
    $persons = $personService->searchPersons($query, true);
    jsonResponse($persons ?: []);
    exit;
}

// Prüfe auf Sub-Ressourcen (wird vom Router als $action übergeben)
// $id ist die person_uuid, $action ist die Sub-Ressource (z.B. 'affiliations', 'relationships')
if ($personUuid && isset($action)) {
    if ($action === 'affiliations') {
        if ($method === 'GET') {
            // GET /api/persons/{uuid}/affiliations
            $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
            $affiliations = $personService->getPersonAffiliations($personUuid, !$includeInactive);
            jsonResponse($affiliations ?: []);
            exit;
        } elseif ($method === 'POST') {
            // POST /api/persons/{uuid}/affiliations
            $data = json_decode(file_get_contents('php://input'), true);
            // Stelle sicher, dass person_uuid gesetzt ist
            $data['person_uuid'] = $personUuid;
            // Setze Standard-Werte
            $data['kind'] = $data['kind'] ?? 'employee';
            $data['is_primary'] = isset($data['is_primary']) ? (int)$data['is_primary'] : 0;
            $data['since_date'] = $data['since_date'] ?? date('Y-m-d');
            try {
                $affiliation = $personService->createAffiliation($data);
                jsonResponse($affiliation);
            } catch (\Exception $e) {
                handleApiException($e, 'Creating affiliation');
            }
            exit;
        } elseif ($method === 'PUT') {
            // PUT /api/persons/{uuid}/affiliations
            // affiliation_uuid muss im Body übergeben werden
            $data = json_decode(file_get_contents('php://input'), true);
            $affiliationUuid = $data['affiliation_uuid'] ?? null;
            if (!$affiliationUuid) {
                jsonError('affiliation_uuid required', 400);
            }
            try {
                $affiliation = $personService->updateAffiliation($affiliationUuid, $data);
                jsonResponse($affiliation);
            } catch (\Exception $e) {
                handleApiException($e, 'Updating affiliation');
            }
            exit;
        } elseif ($method === 'DELETE') {
            // DELETE /api/persons/{uuid}/affiliations?affiliation_uuid=...
            $affiliationUuid = $_GET['affiliation_uuid'] ?? null;
            if (!$affiliationUuid) {
                jsonError('affiliation_uuid parameter required', 400);
            }
            try {
                $success = $personService->deleteAffiliation($affiliationUuid);
                if ($success) {
                    jsonResponse(['success' => true, 'message' => 'Affiliation gelöscht']);
                } else {
                    jsonError('Affiliation nicht gefunden', 404);
                }
            } catch (\Exception $e) {
                handleApiException($e, 'Deleting affiliation');
            }
            exit;
        }
    } elseif ($action === 'relationships') {
        if ($method === 'GET') {
            // GET /api/persons/{uuid}/relationships
            $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
            $relationships = $personService->getPersonRelationships($personUuid, !$includeInactive);
            jsonResponse($relationships ?: []);
            exit;
        } elseif ($method === 'POST') {
            // POST /api/persons/{uuid}/relationships
            $data = json_decode(file_get_contents('php://input'), true);
            // Stelle sicher, dass person_a_uuid gesetzt ist
            $data['person_a_uuid'] = $personUuid;
            // Setze Standard-Werte
            $data['relation_type'] = $data['relation_type'] ?? 'knows';
            $data['direction'] = $data['direction'] ?? 'bidirectional';
            try {
                $relationship = $personService->createRelationship($data);
                jsonResponse($relationship);
            } catch (\Exception $e) {
                handleApiException($e, 'Creating relationship');
            }
            exit;
        } elseif ($method === 'DELETE') {
            // DELETE /api/persons/{uuid}/relationships?relationship_uuid=...
            $relationshipUuid = $_GET['relationship_uuid'] ?? null;
            if (!$relationshipUuid) {
                jsonError('relationship_uuid parameter required', 400);
            }
            try {
                $success = $personService->deleteRelationship($relationshipUuid);
                if ($success) {
                    jsonResponse(['success' => true, 'message' => 'Beziehung gelöscht']);
                } else {
                    jsonError('Beziehung nicht gefunden', 404);
                }
            } catch (\Exception $e) {
                handleApiException($e, 'Deleting relationship');
            }
            exit;
        }
    } elseif ($action === 'audit-trail') {
        // GET /api/persons/{uuid}/audit-trail
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $auditTrail = $personService->getAuditTrail($personUuid, $limit);
        jsonResponse($auditTrail ?: []);
        exit;
    }
}

switch ($method) {
    case 'GET':
        if ($personUuid) {
            // GET /api/persons/{uuid}
            $person = $personService->getPerson($personUuid);
            echo json_encode($person);
        } else {
            // GET /api/persons
            $persons = $personService->listPersons();
            echo json_encode($persons ?: []);
        }
        break;
        
    case 'POST':
        // POST /api/persons
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $result = $personService->createPerson($data);
            echo json_encode($result);
        } catch (\Exception $e) {
            handleApiException($e, 'Creating person');
        }
        break;
        
    case 'PUT':
        // PUT /api/persons/{uuid}
        if (!$personUuid) {
            jsonError('Person UUID required', 400);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $result = $personService->updatePerson($personUuid, $data);
            jsonResponse($result);
        } catch (\Exception $e) {
            handleApiException($e, 'Updating person');
        }
        break;
        
    default:
        jsonError('Method not allowed', 405);
        break;
}


