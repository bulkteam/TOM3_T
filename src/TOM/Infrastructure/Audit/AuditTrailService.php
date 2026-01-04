<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Audit;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Auth\AuthHelper;
use TOM\Infrastructure\Activity\ActivityLogService;

/**
 * Zentrale Audit-Trail-Service-Klasse für TOM3
 * 
 * Stellt sicher, dass Audit-Trail-Logik konsistent für alle Entitäten verwendet wird.
 * Eliminiert Code-Duplikation zwischen PersonService und OrgService.
 * 
 * Erstellt automatisch Activity-Log-Einträge für Entity-Änderungen.
 */
class AuditTrailService
{
    private PDO $db;
    private ?ActivityLogService $activityLogService = null;
    
    // Mapping von Entity-Typen zu Tabellennamen
    private const AUDIT_TABLE_MAP = [
        'org' => 'org_audit_trail',
        'person' => 'person_audit_trail',
        'project' => 'project_audit_trail', // für zukünftige Nutzung
        'document' => 'document_audit_trail',
    ];
    
    // Mapping von Entity-Typen zu UUID-Feldnamen
    private const UUID_FIELD_MAP = [
        'org' => 'org_uuid',
        'person' => 'person_uuid',
        'project' => 'project_uuid',
        'document' => 'document_uuid',
    ];
    
    public function __construct(?PDO $db = null, ?ActivityLogService $activityLogService = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->activityLogService = $activityLogService;
    }
    
    /**
     * Protokolliert Änderungen im Audit-Trail
     * 
     * @param string $entityType 'org' | 'person' | 'project' | ...
     * @param string $entityUuid UUID der Entität
     * @param string|null $userId User-ID (wenn null, wird AuthHelper verwendet)
     * @param string $action 'create' | 'update' | 'delete'
     * @param array|null $oldData Alte Daten
     * @param array|null $newData Neue Daten
     * @param array|null $allowedFields Felder, die protokolliert werden sollen
     * @param array|null $changedFields Geänderte Felder (optional, für Performance)
     * @param callable|null $fieldResolver Callback für Feldwert-Formatierung (optional)
     */
    public function logAuditTrail(
        string $entityType,
        string $entityUuid,
        ?string $userId = null,
        string $action = 'update',
        ?array $oldData = null,
        ?array $newData = null,
        ?array $allowedFields = null,
        ?array $changedFields = null,
        ?callable $fieldResolver = null
    ): void {
        if (!$oldData && $action !== 'create') {
            return; // Keine alte Daten vorhanden (außer bei Erstellung)
        }
        
        $userId = $userId ?? AuthHelper::getCurrentUserId();
        
        $tableName = self::AUDIT_TABLE_MAP[$entityType] ?? null;
        $changedFieldsList = [];
        
        if ($action === 'create') {
            // Bei Erstellung: Protokolliere alle initialen Werte
            // Für Dokumente: change_type = 'upload', sonst '{entity}_created'
            $changeType = ($entityType === 'document') ? 'upload' : ($entityType . '_created');
            
            // Für Dokumente: Spezielle Felder in metadata packen
            $auditMetadata = null;
            $referenceEntityType = null;
            $referenceEntityUuid = null;
            if ($entityType === 'document') {
                $auditMetadata = [];
                if (isset($newData['current_blob_uuid'])) {
                    $auditMetadata['blob_uuid'] = $newData['current_blob_uuid'];
                }
                if (isset($newData['entity_type'])) {
                    $auditMetadata['entity_type'] = $newData['entity_type'];
                    $referenceEntityType = $newData['entity_type'];
                }
                if (isset($newData['entity_uuid'])) {
                    $auditMetadata['entity_uuid'] = $newData['entity_uuid'];
                    $referenceEntityUuid = $newData['entity_uuid'];
                }
            }
            
            // Erstelle zuerst Activity-Log-Eintrag (für Rückverknüpfung)
            $activityLogId = null;
            if ($this->activityLogService) {
                // Für Dokumente: Verwende 'upload' als action_type, sonst 'entity_change'
                $actionType = ($entityType === 'document') ? 'upload' : 'entity_change';
                
                $documentTitle = $newData['title'] ?? $newData['name'] ?? $newData['display_name'] ?? null;
                
                $summary = [
                    'action' => 'create',
                    'change_type' => $changeType,
                    'entity_name' => $documentTitle
                ];
                
                // Für Dokumente: Füge Referenz-Informationen hinzu
                if ($entityType === 'document') {
                    $summary['document_title'] = $documentTitle;
                    if ($referenceEntityType && $referenceEntityUuid) {
                        $summary['reference_entity_type'] = $referenceEntityType;
                        $summary['reference_entity_uuid'] = $referenceEntityUuid;
                    }
                }
                
                // Temporärer Eintrag ohne audit_trail_id
                $activityLogId = $this->activityLogService->logActivity(
                    $userId,
                    $actionType,
                    $entityType,
                    $entityUuid,
                    $summary,
                    null, // audit_trail_id wird später gesetzt
                    $tableName
                );
            }
            
            $auditTrailId = $this->insertAuditEntry($entityType, $entityUuid, $userId, 'create', null, null, $changeType, $auditMetadata, $newData, $activityLogId);
            
            // Update Activity-Log mit audit_trail_id
            if ($this->activityLogService && $activityLogId !== null) {
                $this->db->prepare("
                    UPDATE activity_log 
                    SET audit_trail_id = :audit_trail_id 
                    WHERE activity_id = :activity_id
                ")->execute([
                    'audit_trail_id' => $auditTrailId,
                    'activity_id' => $activityLogId
                ]);
            }
        } elseif ($action === 'update' && $oldData && $newData) {
            // Bei Update: Protokolliere nur geänderte Felder
            if ($allowedFields === null) {
                // Wenn keine Felder angegeben, protokolliere alle geänderten Felder
                $allowedFields = array_keys(array_merge($oldData, $newData));
            }
            
            $firstAuditTrailId = null;
            foreach ($allowedFields as $field) {
                $oldValue = $oldData[$field] ?? null;
                $newValue = $newData[$field] ?? null;
                
                // Nur protokollieren, wenn sich der Wert geändert hat
                if ($oldValue !== $newValue && (isset($changedFields[$field]) || $changedFields === null)) {
                    // Verwende Field-Resolver, falls vorhanden
                    if ($fieldResolver) {
                        $oldValueStr = call_user_func($fieldResolver, $field, $oldValue);
                        $newValueStr = call_user_func($fieldResolver, $field, $newValue);
                    } else {
                        $oldValueStr = $this->defaultResolveFieldValue($field, $oldValue);
                        $newValueStr = $this->defaultResolveFieldValue($field, $newValue);
                    }
                    
                    $auditTrailId = $this->insertAuditEntry(
                        $entityType,
                        $entityUuid,
                        $userId,
                        'update',
                        $field,
                        $oldValueStr,
                        'field_change',
                        null,
                        ['old' => $oldValueStr, 'new' => $newValueStr],
                        null // activity_log_id wird später gesetzt
                    );
                    
                    if ($firstAuditTrailId === null) {
                        $firstAuditTrailId = $auditTrailId;
                    }
                    $changedFieldsList[] = $field;
                }
            }
            
            // Erstelle einen Activity-Log-Eintrag für das gesamte Update (nur wenn Felder geändert wurden)
            $activityLogId = null;
            if ($this->activityLogService && !empty($changedFieldsList) && $firstAuditTrailId !== null) {
                $summary = [
                    'action' => 'update',
                    'change_type' => 'field_change',
                    'changed_fields' => $changedFieldsList,
                    'changed_fields_count' => count($changedFieldsList)
                ];
                // Temporärer Eintrag ohne audit_trail_id
                $activityLogId = $this->activityLogService->logActivity(
                    $userId,
                    'entity_change',
                    $entityType,
                    $entityUuid,
                    $summary,
                    null, // audit_trail_id wird später gesetzt
                    $tableName
                );
                
                // Update alle Audit-Trail-Einträge mit activity_log_id
                if ($activityLogId !== null) {
                    $uuidFieldName = self::UUID_FIELD_MAP[$entityType] ?? null;
                    if ($uuidFieldName) {
                        $this->db->prepare("
                            UPDATE {$tableName} 
                            SET activity_log_id = :activity_log_id 
                            WHERE {$uuidFieldName} = :entity_uuid 
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
                            AND action = 'update'
                        ")->execute([
                            'activity_log_id' => $activityLogId,
                            'entity_uuid' => $entityUuid
                        ]);
                    }
                    
                    // Update Activity-Log mit audit_trail_id (erster Eintrag)
                    $this->db->prepare("
                        UPDATE activity_log 
                        SET audit_trail_id = :audit_trail_id 
                        WHERE activity_id = :activity_id
                    ")->execute([
                        'audit_trail_id' => $firstAuditTrailId,
                        'activity_id' => $activityLogId
                    ]);
                }
            }
        }
    }
    
    /**
     * Fügt einen Audit-Eintrag ein
     * 
     * @param int|null $activityLogId Activity-Log-ID für Rückverknüpfung
     * @return int Audit-ID des erstellten Eintrags
     */
    private function insertAuditEntry(
        string $entityType,
        string $entityUuid,
        string $userId,
        string $action,
        ?string $fieldName,
        ?string $oldValue,
        string $changeType,
        ?array $metadata = null,
        ?array $additionalData = null,
        ?int $activityLogId = null
    ): int {
        $tableName = self::AUDIT_TABLE_MAP[$entityType] ?? null;
        $uuidFieldName = self::UUID_FIELD_MAP[$entityType] ?? null;
        
        if (!$tableName || !$uuidFieldName) {
            throw new \InvalidArgumentException("Unbekannter Entity-Typ: $entityType");
        }
        
        // Standard-Behandlung für alle Entity-Typen (vereinheitlichte Struktur)
        // Prüfe ob activity_log_id Spalte existiert (für Rückwärtskompatibilität)
        $hasActivityLogId = $this->hasActivityLogIdColumn($tableName);
        
        if ($hasActivityLogId) {
            $stmt = $this->db->prepare("
                INSERT INTO {$tableName} (
                    activity_log_id, {$uuidFieldName}, user_id, action, field_name, old_value, new_value, change_type, metadata
                ) VALUES (
                    :activity_log_id, :entity_uuid, :user_id, :action, :field_name, :old_value, :new_value, :change_type, :metadata
                )
            ");
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO {$tableName} (
                    {$uuidFieldName}, user_id, action, field_name, old_value, new_value, change_type, metadata
                ) VALUES (
                    :entity_uuid, :user_id, :action, :field_name, :old_value, :new_value, :change_type, :metadata
                )
            ");
        }
        
        $newValue = null;
        if ($additionalData && isset($additionalData['new'])) {
            $newValue = is_array($additionalData['new']) || is_object($additionalData['new']) 
                ? json_encode($additionalData['new']) 
                : (string)$additionalData['new'];
        }
        
        $metadataJson = null;
        if ($metadata || $additionalData) {
            $metadataJson = json_encode($metadata ?? $additionalData ?? []);
        }
        
        $params = [
            'entity_uuid' => $entityUuid,
            'user_id' => $userId,
            'action' => $action,
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_type' => $changeType,
            'metadata' => $metadataJson
        ];
        
        // Wenn die Spalte existiert, muss der Parameter immer vorhanden sein (auch wenn null)
        if ($hasActivityLogId) {
            $params['activity_log_id'] = $activityLogId;
        }
        
        $stmt->execute($params);
        
        $auditTrailId = (int)$this->db->lastInsertId();
        
        // Activity-Log-Einträge werden in logAuditTrail() erstellt, nicht hier
        // (um zu vermeiden, dass bei Updates mehrere Activity-Log-Einträge erstellt werden)
        
        return $auditTrailId;
    }
    
    /**
     * Prüft, ob die activity_log_id Spalte in der angegebenen Tabelle existiert
     */
    private function hasActivityLogIdColumn(string $tableName): bool
    {
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
            return ($result['count'] ?? 0) > 0;
        } catch (\Exception $e) {
            // Bei Fehler annehmen, dass Spalte nicht existiert (Rückwärtskompatibilität)
            return false;
        }
    }
    
    
    /**
     * Holt das Audit-Trail für eine Entität
     */
    public function getAuditTrail(string $entityType, string $entityUuid, int $limit = 100): array
    {
        $tableName = self::AUDIT_TABLE_MAP[$entityType] ?? null;
        $uuidFieldName = self::UUID_FIELD_MAP[$entityType] ?? null;
        
        if (!$tableName || !$uuidFieldName) {
            throw new \InvalidArgumentException("Unbekannter Entity-Typ: $entityType");
        }
        
        $stmt = $this->db->prepare("
            SELECT 
                a.audit_id,
                a.{$uuidFieldName} as entity_uuid,
                a.user_id,
                a.action,
                a.field_name,
                a.old_value,
                a.new_value,
                a.change_type,
                a.metadata,
                a.created_at,
                u.name as user_name
            FROM {$tableName} a
            LEFT JOIN users u ON a.user_id = u.user_id
            WHERE a.{$uuidFieldName} = :entity_uuid
            ORDER BY a.created_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':entity_uuid', $entityUuid, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Standard-Feldwert-Formatierung (Fallback, wenn kein Resolver angegeben)
     */
    private function defaultResolveFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '(leer)';
        }
        
        // Gemeinsame Felder
        if ($field === 'is_active') {
            return $value ? 'Aktiv' : 'Inaktiv';
        }
        
        return (string)$value;
    }
}


