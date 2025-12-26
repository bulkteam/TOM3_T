<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;

class PersonService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
    }
    
    public function createPerson(array $data): array
    {
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO person (person_uuid, display_name, email, phone)
            VALUES (:person_uuid, :display_name, :email, :phone)
        ");
        
        $stmt->execute([
            'person_uuid' => $uuid,
            'display_name' => $data['display_name'] ?? '',
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null
        ]);
        
        $person = $this->getPerson($uuid);
        $this->eventPublisher->publish('person', $person['person_uuid'], 'PersonCreated', $person);
        
        return $person;
    }
    
    public function getPerson(string $personUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM person WHERE person_uuid = :uuid");
        $stmt->execute(['uuid' => $personUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function listPersons(): array
    {
        $stmt = $this->db->query("SELECT * FROM person ORDER BY display_name");
        return $stmt->fetchAll();
    }
}


