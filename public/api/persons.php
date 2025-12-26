<?php
/**
 * TOM3 - Persons API
 */

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
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
// Verwende die vom Router übergebenen Parameter
$personUuid = $id ?? null;

switch ($method) {
    case 'GET':
        if ($personUuid) {
            // GET /api/persons/{uuid}
            $person = $personService->getPerson($personUuid);
            echo json_encode($person);
        } else {
            // GET /api/persons
            $persons = $personService->listPersons();
            // Stelle sicher, dass immer ein Array zurückgegeben wird
            echo json_encode($persons ?: []);
        }
        break;
        
    case 'POST':
        // POST /api/persons
        $data = json_decode(file_get_contents('php://input'), true);
        $result = $personService->createPerson($data);
        echo json_encode($result);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}


