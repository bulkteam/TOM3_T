<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Events;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

class EventPublisher
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    public function publish(string $aggregateType, string $aggregateUuid, string $eventType, array $payload): void
    {
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO outbox_event (event_uuid, aggregate_type, aggregate_uuid, event_type, payload)
            VALUES (:event_uuid, :aggregate_type, :aggregate_uuid, :event_type, :payload)
        ");
        
        $stmt->execute([
            'event_uuid' => $uuid,
            'aggregate_type' => $aggregateType,
            'aggregate_uuid' => $aggregateUuid,
            'event_type' => $eventType,
            'payload' => json_encode($payload)
        ]);
    }
}


