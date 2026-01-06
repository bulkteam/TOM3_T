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
        
        // Wenn next_action_at gesetzt wurde, aktualisiere case_item
        if ($nextActionAt) {
            $this->updateWorkItemNextAction($workItemUuid, $nextActionAt, $nextActionType);
        }
        
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
     * Aktualisiert eine Timeline-Activity (z.B. beim Finalisieren eines Calls)
     */
    public function updateActivity(
        int $timelineId,
        ?int $callDuration = null,
        ?string $outcome = null,
        ?string $notes = null,
        ?\DateTime $nextActionAt = null,
        ?string $nextActionType = null
    ): void {
        $updates = [];
        $params = ['timeline_id' => $timelineId];
        
        if ($callDuration !== null) {
            $updates[] = "call_duration = :call_duration";
            $params['call_duration'] = $callDuration;
        }
        
        if ($outcome !== null) {
            $updates[] = "outcome = :outcome";
            $params['outcome'] = $outcome;
        }
        
        if ($notes !== null) {
            $updates[] = "notes = :notes";
            $params['notes'] = $notes;
        }
        
        if ($nextActionAt !== null) {
            $updates[] = "next_action_at = :next_action_at";
            $params['next_action_at'] = $nextActionAt->format('Y-m-d H:i:s');
        }
        
        if ($nextActionType !== null) {
            $updates[] = "next_action_type = :next_action_type";
            $params['next_action_type'] = $nextActionType;
        }
        
        if (empty($updates)) {
            return;
        }
        
        $stmt = $this->db->prepare("
            UPDATE work_item_timeline
            SET " . implode(', ', $updates) . "
            WHERE timeline_id = :timeline_id
        ");
        $stmt->execute($params);
        
        // Wenn next_action_at gesetzt wurde, aktualisiere auch case_item
        if ($nextActionAt !== null) {
            // Hole work_item_uuid aus Timeline
            $stmt = $this->db->prepare("SELECT work_item_uuid FROM work_item_timeline WHERE timeline_id = :timeline_id");
            $stmt->execute(['timeline_id' => $timelineId]);
            $activity = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($activity) {
                $this->updateWorkItemNextAction($activity['work_item_uuid'], $nextActionAt, $nextActionType);
            }
        }
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
    
    /**
     * Aktualisiert next_action_at und stage im case_item
     * - Wenn next_action_at in der Zukunft: stage = 'SNOOZED'
     * - Wenn next_action_at heute oder in der Vergangenheit: stage = 'IN_PROGRESS' (wenn noch 'NEW')
     */
    private function updateWorkItemNextAction(string $workItemUuid, \DateTime $nextActionAt, ?string $nextActionType = null): void
    {
        $now = new \DateTime();
        $isFuture = $nextActionAt > $now;
        
        // Bestimme neuen Stage
        $newStage = null;
        if ($isFuture) {
            // Wiedervorlage in der Zukunft → SNOOZED (unabhängig vom aktuellen Stage)
            $newStage = 'SNOOZED';
        } else {
            // Wiedervorlage heute oder in der Vergangenheit → IN_PROGRESS (nur wenn noch NEW)
            // Hole aktuellen Stage
            $stmt = $this->db->prepare("SELECT stage FROM case_item WHERE case_uuid = :uuid");
            $stmt->execute(['uuid' => $workItemUuid]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($current && $current['stage'] === 'NEW') {
                $newStage = 'IN_PROGRESS';
            }
            // Wenn bereits IN_PROGRESS oder anderer Stage, bleibt unverändert
        }
        
        // Update case_item
        $updates = [
            "next_action_at = :next_action_at",
            "next_action_type = :next_action_type",
            "updated_at = NOW()"
        ];
        $params = [
            'uuid' => $workItemUuid,
            'next_action_at' => $nextActionAt->format('Y-m-d H:i:s'),
            'next_action_type' => $nextActionType
        ];
        
        if ($newStage) {
            $updates[] = "stage = :stage";
            $params['stage'] = $newStage;
        }
        
        $stmt = $this->db->prepare("
            UPDATE case_item
            SET " . implode(', ', $updates) . "
            WHERE case_uuid = :uuid
        ");
        $stmt->execute($params);
    }
}

