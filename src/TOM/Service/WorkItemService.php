<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\WorkItem\Core\WorkItemCrudService;
use TOM\Service\WorkItem\Queue\WorkItemQueueService;
use TOM\Service\WorkItem\Timeline\WorkItemTimelineService;

/**
 * WorkItemService
 * 
 * Facade für WorkItem-Operationen
 */
class WorkItemService
{
    private PDO $db;
    private WorkItemCrudService $crudService;
    private WorkItemQueueService $queueService;
    private WorkItemTimelineService $timelineService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->crudService = new WorkItemCrudService($this->db);
        $this->queueService = new WorkItemQueueService($this->db, $this->crudService);
        $this->timelineService = new WorkItemTimelineService($this->db);
    }
    
    /**
     * Holt WorkItem nach UUID
     */
    public function getWorkItem(string $uuid): ?array
    {
        return $this->crudService->getWorkItem($uuid);
    }
    
    /**
     * Aktualisiert WorkItem
     */
    public function updateWorkItem(string $uuid, array $data): array
    {
        return $this->crudService->updateWorkItem($uuid, $data);
    }
    
    /**
     * Holt nächsten Lead
     */
    public function getNextLead(string $userId, ?string $tab = null): ?array
    {
        return $this->queueService->getNextLead($userId, $tab);
    }
    
    /**
     * Listet WorkItems
     */
    public function listWorkItems(
        string $type,
        ?string $tab = null,
        ?string $userId = null,
        ?string $sortField = null,
        ?string $sortOrder = 'asc'
    ): array {
        return $this->queueService->listWorkItems($type, $tab, $userId, $sortField, $sortOrder);
    }
    
    /**
     * Holt Queue-Statistiken
     */
    public function getQueueStats(string $type, ?string $userId = null): array
    {
        return $this->queueService->getQueueStats($type, $userId);
    }
    
    /**
     * Holt Timeline
     */
    public function getTimeline(string $workItemUuid, ?int $limit = 50): array
    {
        return $this->timelineService->getTimeline($workItemUuid, $limit);
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
        return $this->timelineService->addUserNote(
            $workItemUuid,
            $userId,
            $activityType,
            $notes,
            $outcome,
            $nextActionAt,
            $nextActionType
        );
    }
}

