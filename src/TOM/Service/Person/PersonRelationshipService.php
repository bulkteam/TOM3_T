<?php
declare(strict_types=1);

namespace TOM\Service\Person;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;

/**
 * PersonRelationshipService
 * Handles person-to-person relationship management
 */
class PersonRelationshipService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    private AuditTrailService $auditTrailService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
        $this->auditTrailService = new AuditTrailService($this->db);
    }
    
    /**
     * Erstellt eine Person-zu-Person Beziehung
     */
    public function createRelationship(array $data): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO person_relationship (
                relationship_uuid, person_a_uuid, person_b_uuid,
                relation_type, direction, strength, confidence,
                context_org_uuid, context_project_uuid,
                start_date, end_date, notes
            )
            VALUES (
                :relationship_uuid, :person_a_uuid, :person_b_uuid,
                :relation_type, :direction, :strength, :confidence,
                :context_org_uuid, :context_project_uuid,
                :start_date, :end_date, :notes
            )
        ");
        
        $stmt->execute([
            'relationship_uuid' => $uuid,
            'person_a_uuid' => $data['person_a_uuid'],
            'person_b_uuid' => $data['person_b_uuid'],
            'relation_type' => $data['relation_type'] ?? 'knows',
            'direction' => $data['direction'] ?? 'bidirectional',
            'strength' => $data['strength'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'context_org_uuid' => $data['context_org_uuid'] ?? null,
            'context_project_uuid' => $data['context_project_uuid'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'notes' => $data['notes'] ?? null
        ]);
        
        $relationship = $this->getRelationship($uuid);
        if ($relationship) {
            $this->eventPublisher->publish('person_relationship', $relationship['relationship_uuid'], 'PersonRelationshipAdded', $relationship);
        }
        
        return $relationship ?: [];
    }
    
    /**
     * Holt eine Person-zu-Person Beziehung
     */
    public function getRelationship(string $relationshipUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                pr.*,
                pa.display_name as person_a_name,
                pb.display_name as person_b_name,
                o.name as context_org_name
            FROM person_relationship pr
            JOIN person pa ON pa.person_uuid = pr.person_a_uuid
            JOIN person pb ON pb.person_uuid = pr.person_b_uuid
            LEFT JOIN org o ON o.org_uuid = pr.context_org_uuid
            WHERE pr.relationship_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $relationshipUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Holt alle Beziehungen einer Person
     */
    public function getPersonRelationships(string $personUuid, ?bool $activeOnly = true): array
    {
        $sql = "
            SELECT 
                pr.*,
                CASE 
                    WHEN pr.person_a_uuid = :person_uuid THEN pb.display_name
                    ELSE pa.display_name
                END as other_person_name,
                CASE 
                    WHEN pr.person_a_uuid = :person_uuid THEN pb.person_uuid
                    ELSE pa.person_uuid
                END as other_person_uuid,
                o.name as context_org_name
            FROM person_relationship pr
            JOIN person pa ON pa.person_uuid = pr.person_a_uuid
            JOIN person pb ON pb.person_uuid = pr.person_b_uuid
            LEFT JOIN org o ON o.org_uuid = pr.context_org_uuid
            WHERE (pr.person_a_uuid = :person_uuid OR pr.person_b_uuid = :person_uuid)
        ";
        
        if ($activeOnly) {
            $sql .= " AND (pr.end_date IS NULL OR pr.end_date >= CURDATE())";
        }
        
        $sql .= " ORDER BY pr.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['person_uuid' => $personUuid]);
        return $stmt->fetchAll();
    }
    
    /**
     * Löscht eine Person-zu-Person Beziehung
     */
    public function deleteRelationship(string $relationshipUuid): bool
    {
        $relationship = $this->getRelationship($relationshipUuid);
        if (!$relationship) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM person_relationship WHERE relationship_uuid = :uuid");
        $stmt->execute(['uuid' => $relationshipUuid]);
        
        $this->eventPublisher->publish('person_relationship', $relationshipUuid, 'PersonRelationshipDeleted', $relationship);
        
        return true;
    }
}
