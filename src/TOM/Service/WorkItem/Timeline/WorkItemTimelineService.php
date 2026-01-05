<?php
declare(strict_types=1);

namespace TOM\Service\WorkItem\Timeline;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * WorkItemTimelineService
 * 
 * Verwaltet Sales Timeline (Human Log + System-Hinweise)
 */
class WorkItemTimelineService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Fügt User-Notiz hinzu
     */
    public function addUserNote(
        string $workItemUuid,
        string $userId,
        string $activityType,
        ?string $notes = null,
        ?string $outcome = null,
        ?\DateTime $nextActionAt = null,
        ?string $nextActionType = null
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO work_item_timeline (
                work_item_uuid, activity_type, created_by, created_by_user_id,
                notes, outcome, next_action_at, next_action_type,
                occurred_at, created_at
            )
            VALUES (
                :work_item_uuid, :activity_type, 'USER', :user_id,
                :notes, :outcome, :next_action_at, :next_action_type,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            'work_item_uuid' => $workItemUuid,
            'activity_type' => $activityType,
            'user_id' => $userId,
            'notes' => $notes,
            'outcome' => $outcome,
            'next_action_at' => $nextActionAt ? $nextActionAt->format('Y-m-d H:i:s') : null,
            'next_action_type' => $nextActionType
        ]);
        
        $timelineId = (int)$this->db->lastInsertId();
        
        // Update WorkItem: last_touch_at, touch_count
        $this->updateWorkItemTouch($workItemUuid);
        
        return $timelineId;
    }
    
    /**
     * Fügt Call-Activity hinzu
     */
    public function addCallActivity(
        string $workItemUuid,
        string $userId,
        string $phoneNumber,
        string $callStatus,
        ?int $callDuration = null,
        ?string $outcome = null,
        ?string $notes = null
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO work_item_timeline (
                work_item_uuid, activity_type, created_by, created_by_user_id,
                phone_number, call_status, call_duration, outcome, notes,
                occurred_at, created_at
            )
            VALUES (
                :work_item_uuid, 'TOUCH_CALL', 'USER', :user_id,
                :phone_number, :call_status, :call_duration, :outcome, :notes,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            'work_item_uuid' => $workItemUuid,
            'user_id' => $userId,
            'phone_number' => $phoneNumber,
            'call_status' => $callStatus,
            'call_duration' => $callDuration,
            'outcome' => $outcome,
            'notes' => $notes
        ]);
        
        $timelineId = (int)$this->db->lastInsertId();
        
        // Update WorkItem: last_touch_at, touch_count
        $this->updateWorkItemTouch($workItemUuid);
        
        return $timelineId;
    }
    
    /**
     * Fügt System-Hinweis hinzu (automatisch von TOM)
     */
    public function addSystemMessage(
        string $workItemUuid,
        string $activityType,
        string $systemMessage,
        ?array $metadata = null
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO work_item_timeline (
                work_item_uuid, activity_type, created_by,
                system_message, metadata,
                occurred_at, created_at
            )
            VALUES (
                :work_item_uuid, :activity_type, 'SYSTEM',
                :system_message, :metadata,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            'work_item_uuid' => $workItemUuid,
            'activity_type' => $activityType,
            'system_message' => $systemMessage,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Holt Timeline für WorkItem
     */
    public function getTimeline(string $workItemUuid, ?int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                t.*,
                u.name as user_name,
                u.email as user_email
            FROM work_item_timeline t
            LEFT JOIN users u ON t.created_by_user_id = u.user_id
            WHERE t.work_item_uuid = :work_item_uuid
            ORDER BY t.occurred_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':work_item_uuid', $workItemUuid);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON metadata
        foreach ($items as &$item) {
            if ($item['metadata']) {
                $item['metadata'] = json_decode($item['metadata'], true);
            }
        }
        
        return $items;
    }
    
    /**
     * Pinnt Activity (z.B. HANDOFF)
     */
    public function pinActivity(int $timelineId): void
    {
        $stmt = $this->db->prepare("
            UPDATE work_item_timeline
            SET is_pinned = 1
            WHERE timeline_id = :timeline_id
        ");
        $stmt->execute(['timeline_id' => $timelineId]);
    }
    
    /**
     * Fügt HANDOFF Activity hinzu (mit pinned Flag)
     */
    public function addHandoffActivity(
        string $workItemUuid,
        string $userId,
        string $needSummary,
        string $contactHint,
        string $nextStep
    ): int {
        $notes = "Übergabe an Sales Ops\n\nBedarf: $needSummary\nAnsprechpartner: $contactHint\nNächster Schritt: $nextStep";
        
        $metadata = [
            'handoff_type' => 'QUOTE_REQUEST',
            'need_summary' => $needSummary,
            'contact_hint' => $contactHint,
            'next_step' => $nextStep
        ];
        
        return $this->addHandoffActivityWithMetadata($workItemUuid, $userId, $notes, $metadata);
    }
    
    /**
     * Fügt HANDOFF Activity mit vollständigen Metadaten hinzu
     */
    public function addHandoffActivityWithMetadata(
        string $workItemUuid,
        string $userId,
        string $notes,
        array $metadata
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO work_item_timeline (
                work_item_uuid, activity_type, created_by, created_by_user_id,
                notes, metadata, is_pinned,
                occurred_at, created_at
            )
            VALUES (
                :work_item_uuid, 'HANDOFF', 'USER', :user_id,
                :notes, :metadata, 1,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            'work_item_uuid' => $workItemUuid,
            'user_id' => $userId,
            'notes' => $notes,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Update WorkItem Touch-Felder
     */
    private function updateWorkItemTouch(string $workItemUuid): void
    {
        $stmt = $this->db->prepare("
            UPDATE case_item
            SET last_touch_at = NOW(),
                touch_count = touch_count + 1,
                updated_at = NOW()
            WHERE case_uuid = :work_item_uuid
        ");
        $stmt->execute(['work_item_uuid' => $workItemUuid]);
    }
}

