<?php
declare(strict_types=1);

namespace TOM\Service\Org\Management;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Database\TransactionHelper;
use TOM\Infrastructure\Events\EventPublisher;

/**
 * OrgArchiveService
 * 
 * Handles archive management for organizations:
 * - Archive organization
 * - Unarchive organization
 * - Audit trail logging
 */
class OrgArchiveService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    private $orgGetter;
    private $auditEntryCallback;
    
    /**
     * @param PDO|null $db
     * @param callable|null $orgGetter Callback to get organization: function(string $orgUuid): ?array
     * @param callable|null $auditEntryCallback Callback to insert audit entries: function(string $orgUuid, string $userId, string $action, ?string $fieldName, ?string $oldValue, string $changeType, ?array $metadata, ?array $additionalData): void
     */
    public function __construct(
        ?PDO $db = null,
        ?callable $orgGetter = null,
        ?callable $auditEntryCallback = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
        $this->orgGetter = $orgGetter;
        $this->auditEntryCallback = $auditEntryCallback;
    }
    
    /**
     * Archiviert eine Organisation
     */
    public function archiveOrg(string $orgUuid, string $userId): array
    {
        if (!$this->orgGetter) {
            throw new \Exception("Organisation nicht gefunden");
        }
        
        $org = call_user_func($this->orgGetter, $orgUuid);
        if (!$org) {
            throw new \Exception("Organisation nicht gefunden");
        }
        
        if ($org['archived_at']) {
            throw new \Exception("Organisation ist bereits archiviert");
        }
        
        // Führe UPDATE in Transaktion aus
        $org = TransactionHelper::executeInTransaction($this->db, function($db) use ($orgUuid, $userId) {
            $stmt = $db->prepare("
                UPDATE org 
                SET archived_at = NOW(), 
                    archived_by_user_id = :user_id
                WHERE org_uuid = :org_uuid
            ");
            
            $stmt->execute([
                'org_uuid' => $orgUuid,
                'user_id' => $userId
            ]);
            
            // Hole aktualisierte Organisation zurück
            if (!$this->orgGetter) {
                throw new \Exception("Organisation nicht gefunden");
            }
            return call_user_func($this->orgGetter, $orgUuid);
        });
        
        // Protokolliere im Audit-Trail (nach Commit)
        if ($org && $this->auditEntryCallback) {
            call_user_func($this->auditEntryCallback,
                $orgUuid,
                $userId,
                'update',
                'archived_at',
                '(nicht archiviert)',
                'org_archived',
                [
                    'archived_at' => $org['archived_at'],
                    'archived_by_user_id' => $userId
                ],
                ['old' => '(nicht archiviert)', 'new' => 'Archiviert am ' . date('d.m.Y H:i', strtotime($org['archived_at']))]
            );
            $this->eventPublisher->publish('org', $org['org_uuid'], 'OrgArchived', $org);
        }
        
        return $org ?: [];
    }
    
    /**
     * Reaktiviert eine archivierte Organisation
     */
    public function unarchiveOrg(string $orgUuid, string $userId): array
    {
        if (!$this->orgGetter) {
            throw new \Exception("Organisation nicht gefunden");
        }
        
        $org = call_user_func($this->orgGetter, $orgUuid);
        if (!$org) {
            throw new \Exception("Organisation nicht gefunden");
        }
        
        if (!$org['archived_at']) {
            throw new \Exception("Organisation ist nicht archiviert");
        }
        
        $oldArchivedAt = $org['archived_at'];
        $oldArchivedAtFormatted = 'Archiviert am ' . date('d.m.Y H:i', strtotime($oldArchivedAt));
        
        // Führe UPDATE in Transaktion aus
        $org = TransactionHelper::executeInTransaction($this->db, function($db) use ($orgUuid) {
            $stmt = $db->prepare("
                UPDATE org 
                SET archived_at = NULL, 
                    archived_by_user_id = NULL
                WHERE org_uuid = :org_uuid
            ");
            
            $stmt->execute(['org_uuid' => $orgUuid]);
            
            // Hole aktualisierte Organisation zurück
            if (!$this->orgGetter) {
                throw new \Exception("Organisation nicht gefunden");
            }
            return call_user_func($this->orgGetter, $orgUuid);
        });
        
        // Protokolliere im Audit-Trail (nach Commit)
        if ($org && $this->auditEntryCallback) {
            call_user_func($this->auditEntryCallback,
                $orgUuid,
                $userId,
                'update',
                'archived_at',
                $oldArchivedAtFormatted,
                'org_unarchived',
                [
                    'archived_at' => $oldArchivedAt,
                    'archived_by_user_id' => null
                ],
                ['old' => $oldArchivedAtFormatted, 'new' => '(nicht archiviert)']
            );
            $this->eventPublisher->publish('org', $org['org_uuid'], 'OrgUnarchived', $org);
        }
        
        return $org ?: [];
    }
}

