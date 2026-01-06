<?php
/**
 * TOM3 - Persons API
 */

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    http_response_code(404);
    exit;
}

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
        http_response_code(400);
        echo json_encode(['error' => 'org_uuid parameter required']);
        exit;
    }
    
    $persons = $personService->listPersonsByOrg($orgUuid, $includeInactive);
    echo json_encode($persons);
    exit;
}

// Prüfe auf search Endpoint (wird vom Router als $id='search' übergeben)
if (isset($id) && $id === 'search') {
    // GET /api/persons/search?q=...
    $query = $_GET['q'] ?? '';
    $persons = $personService->searchPersons($query, true);
    echo json_encode($persons ?: []);
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
            echo json_encode($affiliations ?: []);
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
                echo json_encode($affiliation);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Fehler beim Erstellen der Affiliation',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
        } elseif ($method === 'PUT') {
            // PUT /api/persons/{uuid}/affiliations
            // affiliation_uuid muss im Body übergeben werden
            $data = json_decode(file_get_contents('php://input'), true);
            $affiliationUuid = $data['affiliation_uuid'] ?? null;
            if (!$affiliationUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'affiliation_uuid required']);
                exit;
            }
            try {
                $affiliation = $personService->updateAffiliation($affiliationUuid, $data);
                echo json_encode($affiliation);
            } catch (\RuntimeException $e) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Affiliation nicht gefunden',
                    'message' => $e->getMessage()
                ]);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Fehler beim Aktualisieren der Affiliation',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
        } elseif ($method === 'DELETE') {
            // DELETE /api/persons/{uuid}/affiliations?affiliation_uuid=...
            $affiliationUuid = $_GET['affiliation_uuid'] ?? null;
            if (!$affiliationUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'affiliation_uuid parameter required']);
                exit;
            }
            try {
                $success = $personService->deleteAffiliation($affiliationUuid);
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'Affiliation gelöscht']);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Affiliation nicht gefunden']);
                }
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Fehler beim Löschen der Affiliation',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
        }
    } elseif ($action === 'relationships') {
        if ($method === 'GET') {
            // GET /api/persons/{uuid}/relationships
            $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === 'true';
            $relationships = $personService->getPersonRelationships($personUuid, !$includeInactive);
            echo json_encode($relationships ?: []);
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
                echo json_encode($relationship);
            } catch (\Exception $e) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'Fehler beim Erstellen der Beziehung',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
        } elseif ($method === 'DELETE') {
            // DELETE /api/persons/{uuid}/relationships?relationship_uuid=...
            $relationshipUuid = $_GET['relationship_uuid'] ?? null;
            if (!$relationshipUuid) {
                http_response_code(400);
                echo json_encode(['error' => 'relationship_uuid parameter required']);
                exit;
            }
            try {
                $success = $personService->deleteRelationship($relationshipUuid);
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'Beziehung gelöscht']);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Beziehung nicht gefunden']);
                }
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'error' => 'Fehler beim Löschen der Beziehung',
                    'message' => $e->getMessage()
                ]);
            }
            exit;
        }
    } elseif ($action === 'audit-trail') {
        // GET /api/persons/{uuid}/audit-trail
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $auditTrail = $personService->getAuditTrail($personUuid, $limit);
        echo json_encode($auditTrail ?: []);
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
            http_response_code(400);
            $message = $e->getMessage();
            // Prüfe auf Duplikat-Fehler
            if (strpos($message, 'existiert bereits') !== false || strpos($message, 'already exists') !== false || strpos($message, 'Duplicate entry') !== false) {
                echo json_encode([
                    'error' => 'Person bereits vorhanden',
                    'message' => $message
                ]);
            } else {
                echo json_encode([
                    'error' => 'Fehler beim Anlegen der Person',
                    'message' => $message
                ]);
            }
        }
        break;
        
    case 'PUT':
        // PUT /api/persons/{uuid}
        if (!$personUuid) {
            http_response_code(400);
            echo json_encode(['error' => 'Person UUID required']);
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $result = $personService->updatePerson($personUuid, $data);
            echo json_encode($result);
        } catch (\RuntimeException $e) {
            handleApiException($e, 'Update person');
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}


