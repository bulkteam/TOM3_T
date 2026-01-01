<?php
declare(strict_types=1);

namespace TOM\Service\Org;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;

/**
 * OrgRelationService
 * Handles organization relation management
 */
class OrgRelationService
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
     * Fügt eine Relation zwischen zwei Organisationen hinzu
     */
    public function addRelation(array $data, ?string $userId = null): array
    {
        $uuid = UuidHelper::generate($this->db);
        $userId = $userId ?? 'default_user';
        
        $stmt = $this->db->prepare("
            INSERT INTO org_relation (
                relation_uuid, parent_org_uuid, child_org_uuid, relation_type,
                ownership_percent, since_date, until_date, notes,
                has_voting_rights, is_direct, source, confidence, tags, is_current
            )
            VALUES (
                :relation_uuid, :parent_org_uuid, :child_org_uuid, :relation_type,
                :ownership_percent, :since_date, :until_date, :notes,
                :has_voting_rights, :is_direct, :source, :confidence, :tags, :is_current
            )
        ");
        
        $stmt->execute([
            'relation_uuid' => $uuid,
            'parent_org_uuid' => $data['parent_org_uuid'],
            'child_org_uuid' => $data['child_org_uuid'],
            'relation_type' => $data['relation_type'] ?? 'subsidiary_of',
            'ownership_percent' => $data['ownership_percent'] ?? null,
            'since_date' => $data['since_date'] ?? null,
            'until_date' => $data['until_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'has_voting_rights' => isset($data['has_voting_rights']) ? (int)$data['has_voting_rights'] : 0,
            'is_direct' => isset($data['is_direct']) ? (int)$data['is_direct'] : 1,
            'source' => $data['source'] ?? null,
            'confidence' => $data['confidence'] ?? 'high',
            'tags' => $data['tags'] ?? null,
            'is_current' => isset($data['is_current']) ? (int)$data['is_current'] : 1
        ]);
        
        $relation = $this->getRelation($uuid);
        
        // Protokolliere im Audit-Trail
        if ($relation) {
            $this->insertAuditEntry(
                $data['parent_org_uuid'],
                $userId,
                'create',
                null,
                null,
                'relation_added',
                [
                    'relation_uuid' => $uuid,
                    'child_org_uuid' => $data['child_org_uuid'],
                    'relation_type' => $relation['relation_type'],
                    'child_org_name' => $relation['child_org_name'] ?? null
                ],
                $relation
            );
        }
        
        $this->eventPublisher->publish('org', $data['parent_org_uuid'], 'OrgRelationAdded', $relation);
        
        return $relation;
    }
    
    /**
     * Holt eine einzelne Relation
     */
    public function getRelation(string $relationUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                r.*,
                parent.name as parent_org_name,
                child.name as child_org_name
            FROM org_relation r
            LEFT JOIN org parent ON r.parent_org_uuid = parent.org_uuid
            LEFT JOIN org child ON r.child_org_uuid = child.org_uuid
            WHERE r.relation_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $relationUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Holt alle Relationen einer Organisation
     * @param string $orgUuid Die UUID der Organisation
     * @param string|null $direction 'parent' = wo ist orgUuid Kind, 'child' = wo ist orgUuid Parent, null = beide
     */
    public function getRelations(string $orgUuid, ?string $direction = null): array
    {
        // direction: 'parent' = wo ist orgUuid Kind, 'child' = wo ist orgUuid Parent, null = beide
        $sql = "
            SELECT 
                r.*,
                parent.name as parent_org_name,
                child.name as child_org_name
            FROM org_relation r
            LEFT JOIN org parent ON r.parent_org_uuid = parent.org_uuid
            LEFT JOIN org child ON r.child_org_uuid = child.org_uuid
            WHERE 1=1
        ";
        $params = [];
        
        if ($direction === 'parent') {
            $sql .= " AND r.child_org_uuid = :org_uuid";
            $params['org_uuid'] = $orgUuid;
        } elseif ($direction === 'child') {
            $sql .= " AND r.parent_org_uuid = :org_uuid";
            $params['org_uuid'] = $orgUuid;
        } else {
            $sql .= " AND (r.parent_org_uuid = :org_uuid OR r.child_org_uuid = :org_uuid)";
            $params['org_uuid'] = $orgUuid;
        }
        
        // Nur aktuelle Relationen (is_current = 1 und until_date ist NULL oder in der Zukunft)
        $sql .= " AND r.is_current = 1 AND (r.until_date IS NULL OR r.until_date >= CURDATE())";
        $sql .= " ORDER BY r.relation_type, r.since_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Aktualisiert eine Relation
     */
    public function updateRelation(string $relationUuid, array $data, ?string $userId = null): array
    {
        $userId = $userId ?? 'default_user';
        $oldRelation = $this->getRelation($relationUuid);
        
        if (!$oldRelation) {
            throw new \Exception("Relation nicht gefunden");
        }
        
        $allowed = [
            'relation_type', 'ownership_percent', 'since_date', 'until_date', 'notes',
            'has_voting_rights', 'is_direct', 'source', 'confidence', 'tags', 'is_current'
        ];
        $updates = [];
        $params = ['uuid' => $relationUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['has_voting_rights', 'is_direct', 'is_current'])) {
                    $params[$field] = (int)$data[$field];
                } else {
                    $params[$field] = $data[$field];
                }
                $updates[] = "$field = :$field";
            }
        }
        
        if (empty($updates)) {
            return $oldRelation;
        }
        
        $sql = "UPDATE org_relation SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE relation_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $relation = $this->getRelation($relationUuid);
        if ($relation) {
            // Protokolliere Änderungen im Audit-Trail
            $changedFields = [];
            foreach ($allowed as $field) {
                $oldValue = $oldRelation[$field] ?? null;
                $newValue = $relation[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $changedFields[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
            
            if (!empty($changedFields)) {
                $this->insertAuditEntry(
                    $relation['parent_org_uuid'],
                    $userId,
                    'update',
                    null,
                    null,
                    'relation_updated',
                    [
                        'relation_uuid' => $relationUuid,
                        'child_org_uuid' => $relation['child_org_uuid'],
                        'child_org_name' => $relation['child_org_name'] ?? null,
                        'changed_fields' => $changedFields
                    ],
                    $changedFields
                );
            }
            
            $this->eventPublisher->publish('org', $relation['parent_org_uuid'], 'OrgRelationUpdated', $relation);
        }
        
        return $relation;
    }
    
    /**
     * Löscht eine Relation
     */
    public function deleteRelation(string $relationUuid, ?string $userId = null): bool
    {
        $userId = $userId ?? 'default_user';
        $relation = $this->getRelation($relationUuid);
        if (!$relation) {
            return false;
        }
        
        $parentOrgUuid = $relation['parent_org_uuid'];
        $childOrgUuid = $relation['child_org_uuid'];
        $childOrgName = $relation['child_org_name'] ?? null;
        $relationType = $relation['relation_type'];
        
        $stmt = $this->db->prepare("DELETE FROM org_relation WHERE relation_uuid = :uuid");
        $stmt->execute(['uuid' => $relationUuid]);
        
        // Protokolliere im Audit-Trail
        $this->insertAuditEntry(
            $parentOrgUuid,
            $userId,
            'delete',
            null,
            null,
            'relation_removed',
            [
                'relation_uuid' => $relationUuid,
                'child_org_uuid' => $childOrgUuid,
                'child_org_name' => $childOrgName,
                'relation_type' => $relationType
            ],
            $relation
        );
        
        $this->eventPublisher->publish('org', $parentOrgUuid, 'OrgRelationDeleted', [
            'relation_uuid' => $relationUuid,
            'child_org_uuid' => $childOrgUuid
        ]);
        
        return true;
    }
    
    /**
     * Fügt einen Eintrag ins Audit-Trail ein
     */
    private function insertAuditEntry(string $orgUuid, string $userId, string $action, ?string $fieldName, ?string $oldValue, string $changeType, ?array $metadata = null, ?array $additionalData = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO org_audit_trail (
                org_uuid, user_id, action, field_name, old_value, new_value, change_type, metadata
            ) VALUES (
                :org_uuid, :user_id, :action, :field_name, :old_value, :new_value, :change_type, :metadata
            )
        ");
        
        $newValue = null;
        if ($additionalData && isset($additionalData['new'])) {
            $newValue = is_array($additionalData['new']) || is_object($additionalData['new']) 
                ? json_encode($additionalData['new']) 
                : (string)$additionalData['new'];
        } elseif ($additionalData && !isset($additionalData['new'])) {
            // Wenn additionalData direkt die neue Relation ist
            $newValue = json_encode($additionalData);
        }
        
        $metadataJson = null;
        if ($metadata || $additionalData) {
            $metadataJson = json_encode($metadata ?? $additionalData ?? []);
        }
        
        $stmt->execute([
            'org_uuid' => $orgUuid,
            'user_id' => $userId,
            'action' => $action,
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_type' => $changeType,
            'metadata' => $metadataJson
        ]);
    }
}
