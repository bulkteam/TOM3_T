<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Access;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

/**
 * Zentrale Service-Klasse für Access-Tracking
 * 
 * Eliminiert Code-Duplikation zwischen OrgService und PersonService
 * für "Zuletzt angesehen" Funktionalität.
 */
class AccessTrackingService
{
    private PDO $db;
    
    // Mapping von Entity-Typen zu Tabellennamen
    private const ACCESS_TABLE_MAP = [
        'org' => 'user_org_access',
        'person' => 'user_person_access',
        'document' => 'user_document_access',
    ];
    
    // Mapping von Entity-Typen zu UUID-Feldnamen in den Access-Tabellen
    private const UUID_FIELD_MAP = [
        'org' => 'org_uuid',
        'person' => 'person_uuid',
        'document' => 'document_uuid',
    ];
    
    // Mapping von Entity-Typen zu Haupttabellen
    private const ENTITY_TABLE_MAP = [
        'org' => 'org',
        'person' => 'person',
        'document' => 'documents',
    ];
    
    // Mapping von Entity-Typen zu Alias-Namen für JOINs
    private const ENTITY_ALIAS_MAP = [
        'org' => 'o',
        'person' => 'p',
        'document' => 'd',
    ];
    
    // Mapping von Entity-Typen zu Access-Alias-Namen für JOINs
    private const ACCESS_ALIAS_MAP = [
        'org' => 'uoa',
        'person' => 'upa',
        'document' => 'uda',
    ];
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Protokolliert den Zugriff auf eine Entität
     * 
     * @param string $entityType 'org' | 'person' | 'document'
     * @param string $userId User-ID
     * @param string $entityUuid UUID der Entität
     * @param string $accessType 'recent' | 'favorite' | 'tag'
     */
    public function trackAccess(string $entityType, string $userId, string $entityUuid, string $accessType = 'recent'): void
    {
        $tableName = self::ACCESS_TABLE_MAP[$entityType] ?? null;
        $uuidFieldName = self::UUID_FIELD_MAP[$entityType] ?? null;
        
        if (!$tableName || !$uuidFieldName) {
            throw new \InvalidArgumentException("Unbekannter Entity-Typ: $entityType");
        }
        
        // Lösche alte Einträge (nur für 'recent', behalte max. 10)
        if ($accessType === 'recent') {
            $stmt = $this->db->prepare("
                DELETE FROM {$tableName} 
                WHERE user_id = :user_id AND access_type = 'recent'
                AND {$uuidFieldName} NOT IN (
                    SELECT {$uuidFieldName} FROM (
                        SELECT {$uuidFieldName} FROM {$tableName}
                        WHERE user_id = :user_id AND access_type = 'recent'
                        ORDER BY accessed_at DESC
                        LIMIT 9
                    ) AS keep
                )
            ");
            $stmt->execute(['user_id' => $userId]);
        }
        
        // Prüfe ob bereits vorhanden
        $stmt = $this->db->prepare("
            SELECT access_uuid FROM {$tableName} 
            WHERE user_id = :user_id AND {$uuidFieldName} = :entity_uuid AND access_type = :access_type
        ");
        $stmt->execute([
            'user_id' => $userId,
            'entity_uuid' => $entityUuid,
            'access_type' => $accessType
        ]);
        
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            // Update timestamp
            $stmt = $this->db->prepare("
                UPDATE {$tableName} 
                SET accessed_at = NOW() 
                WHERE user_id = :user_id AND {$uuidFieldName} = :entity_uuid AND access_type = :access_type
            ");
            $stmt->execute([
                'user_id' => $userId,
                'entity_uuid' => $entityUuid,
                'access_type' => $accessType
            ]);
        } else {
            // Neuer Eintrag
            $uuid = UuidHelper::generate($this->db);
            $stmt = $this->db->prepare("
                INSERT INTO {$tableName} (access_uuid, user_id, {$uuidFieldName}, access_type)
                VALUES (:access_uuid, :user_id, :entity_uuid, :access_type)
            ");
            $stmt->execute([
                'access_uuid' => $uuid,
                'user_id' => $userId,
                'entity_uuid' => $entityUuid,
                'access_type' => $accessType
            ]);
        }
    }
    
    /**
     * Holt die zuletzt angesehenen Entitäten für einen Benutzer
     * 
     * @param string $entityType 'org' | 'person' | 'document'
     * @param string $userId User-ID
     * @param int $limit Anzahl der Einträge
     * @return array
     */
    public function getRecentEntities(string $entityType, string $userId, int $limit = 10): array
    {
        $entityTable = self::ENTITY_TABLE_MAP[$entityType] ?? null;
        $accessTable = self::ACCESS_TABLE_MAP[$entityType] ?? null;
        $uuidFieldName = self::UUID_FIELD_MAP[$entityType] ?? null;
        $entityAlias = self::ENTITY_ALIAS_MAP[$entityType] ?? null;
        $accessAlias = self::ACCESS_ALIAS_MAP[$entityType] ?? null;
        
        if (!$entityTable || !$accessTable || !$uuidFieldName || !$entityAlias || !$accessAlias) {
            throw new \InvalidArgumentException("Unbekannter Entity-Typ: $entityType");
        }
        
        $stmt = $this->db->prepare("
            SELECT {$entityAlias}.*, {$accessAlias}.accessed_at
            FROM {$entityTable} {$entityAlias}
            INNER JOIN {$accessTable} {$accessAlias} ON {$entityAlias}.{$uuidFieldName} = {$accessAlias}.{$uuidFieldName}
            WHERE {$accessAlias}.user_id = :user_id AND {$accessAlias}.access_type = 'recent'
            ORDER BY {$accessAlias}.accessed_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result ?: [];
    }
}


