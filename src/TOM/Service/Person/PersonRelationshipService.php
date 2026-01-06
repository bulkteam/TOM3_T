<?php
declare(strict_types=1);

namespace TOM\Service\Person;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;
use TOM\Infrastructure\Auth\AuthHelper;

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
            // Audit-Trail: Protokolliere Erstellung der Relationship für beide Personen
            try {
                $userId = AuthHelper::getCurrentUserId(true); // Erlaube Fallback für Dev-Mode
                
                // Erstelle menschenlesbare Beschreibung
                $otherPersonName = $relationship['person_b_name'] ?? 'Unbekannt';
                $relationTypeLabels = [
                    'knows' => 'Kennt',
                    'friendly' => 'Freundlich',
                    'adversarial' => 'Gegnerisch',
                    'advisor_of' => 'Berät',
                    'mentor_of' => 'Mentor von',
                    'former_colleague' => 'Ehemaliger Kollege',
                    'influences' => 'Beeinflusst',
                    'gatekeeper_for' => 'Türöffner für'
                ];
                $relationTypeLabel = $relationTypeLabels[$relationship['relation_type']] ?? $relationship['relation_type'];
                $description = "Beziehung zu {$otherPersonName} ({$relationTypeLabel})";
                
                // Protokolliere für Person A - direktes Einfügen in Audit-Trail
                $this->logRelationshipAuditEntry(
                    $data['person_a_uuid'],
                    $userId,
                    'relation_added',
                    $description,
                    [
                        'relationship_uuid' => $uuid,
                        'other_person_uuid' => $data['person_b_uuid'],
                        'other_person_name' => $otherPersonName,
                        'relation_type' => $relationship['relation_type'],
                        'direction' => $relationship['direction']
                    ]
                );
                
                // Protokolliere für Person B (falls unterschiedlich)
                if ($data['person_b_uuid'] !== $data['person_a_uuid']) {
                    $personAName = $relationship['person_a_name'] ?? 'Unbekannt';
                    $descriptionB = "Beziehung zu {$personAName} ({$relationTypeLabel})";
                    $this->logRelationshipAuditEntry(
                        $data['person_b_uuid'],
                        $userId,
                        'relation_added',
                        $descriptionB,
                        [
                            'relationship_uuid' => $uuid,
                            'other_person_uuid' => $data['person_a_uuid'],
                            'other_person_name' => $personAName,
                            'relation_type' => $relationship['relation_type'],
                            'direction' => $relationship['direction']
                        ]
                    );
                }
            } catch (\Exception $e) {
                error_log("Person relationship audit trail error: " . $e->getMessage());
            }
            
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        
        // Audit-Trail: Protokolliere Löschung der Relationship für beide Personen
        try {
            $userId = AuthHelper::getCurrentUserId(true); // Erlaube Fallback für Dev-Mode
            
            // Erstelle menschenlesbare Beschreibung
            $otherPersonName = $relationship['person_b_name'] ?? 'Unbekannt';
            $relationTypeLabels = [
                'knows' => 'Kennt',
                'friendly' => 'Freundlich',
                'adversarial' => 'Gegnerisch',
                'advisor_of' => 'Berät',
                'mentor_of' => 'Mentor von',
                'former_colleague' => 'Ehemaliger Kollege',
                'influences' => 'Beeinflusst',
                'gatekeeper_for' => 'Türöffner für'
            ];
            $relationTypeLabel = $relationTypeLabels[$relationship['relation_type']] ?? $relationship['relation_type'];
            $description = "Beziehung zu {$otherPersonName} ({$relationTypeLabel})";
            
            // Protokolliere für Person A - direktes Einfügen in Audit-Trail
            $this->logRelationshipAuditEntry(
                $relationship['person_a_uuid'],
                $userId,
                'relation_removed',
                $description,
                [
                    'relationship_uuid' => $relationshipUuid,
                    'other_person_uuid' => $relationship['person_b_uuid'],
                    'other_person_name' => $otherPersonName,
                    'relation_type' => $relationship['relation_type'],
                    'direction' => $relationship['direction']
                ],
                true // old_value statt new_value
            );
            
            // Protokolliere für Person B (falls unterschiedlich)
            if ($relationship['person_b_uuid'] !== $relationship['person_a_uuid']) {
                $personAName = $relationship['person_a_name'] ?? 'Unbekannt';
                $descriptionB = "Beziehung zu {$personAName} ({$relationTypeLabel})";
                $this->logRelationshipAuditEntry(
                    $relationship['person_b_uuid'],
                    $userId,
                    'relation_removed',
                    $descriptionB,
                    [
                        'relationship_uuid' => $relationshipUuid,
                        'other_person_uuid' => $relationship['person_a_uuid'],
                        'other_person_name' => $personAName,
                        'relation_type' => $relationship['relation_type'],
                        'direction' => $relationship['direction']
                    ],
                    true // old_value statt new_value
                );
            }
        } catch (\Exception $e) {
            error_log("Person relationship audit trail error: " . $e->getMessage());
        }
        
        $this->eventPublisher->publish('person_relationship', $relationshipUuid, 'PersonRelationshipDeleted', $relationship);
        
        return true;
    }
    
    /**
     * Protokolliert eine Relationship-Änderung im Audit-Trail
     */
    private function logRelationshipAuditEntry(
        string $personUuid,
        string $userId,
        string $changeType,
        string $description,
        array $metadata,
        bool $useOldValue = false
    ): void {
        try {
            $tableName = 'person_audit_trail';
            
            // Prüfe ob activity_log_id Spalte existiert
            $hasActivityLogId = false;
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = :table_name
                    AND COLUMN_NAME = 'activity_log_id'
                ");
                $stmt->execute(['table_name' => $tableName]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $hasActivityLogId = ($result['count'] ?? 0) > 0;
            } catch (\Exception $e) {
                // Ignorieren
            }
            
            $metadataJson = json_encode($metadata);
            
            if ($hasActivityLogId) {
                $stmt = $this->db->prepare("
                    INSERT INTO {$tableName} (
                        activity_log_id, person_uuid, user_id, action, field_name, old_value, new_value, change_type, metadata
                    ) VALUES (
                        NULL, :person_uuid, :user_id, 'update', 'relationship', :old_value, :new_value, :change_type, :metadata
                    )
                ");
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO {$tableName} (
                        person_uuid, user_id, action, field_name, old_value, new_value, change_type, metadata
                    ) VALUES (
                        :person_uuid, :user_id, 'update', 'relationship', :old_value, :new_value, :change_type, :metadata
                    )
                ");
            }
            
            $params = [
                'person_uuid' => $personUuid,
                'user_id' => $userId,
                'change_type' => $changeType,
                'metadata' => $metadataJson
            ];
            
            if ($useOldValue) {
                $params['old_value'] = $description;
                $params['new_value'] = null;
            } else {
                $params['old_value'] = null;
                $params['new_value'] = $description;
            }
            
            $stmt->execute($params);
        } catch (\Exception $e) {
            error_log("Error logging relationship audit entry: " . $e->getMessage());
        }
    }
}


