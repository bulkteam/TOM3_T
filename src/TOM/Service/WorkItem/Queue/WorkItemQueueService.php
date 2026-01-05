<?php
declare(strict_types=1);

namespace TOM\Service\WorkItem\Queue;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\WorkItem\Core\WorkItemCrudService;

/**
 * WorkItemQueueService
 * 
 * Verwaltet Queue-Operationen für WorkItems (case_item)
 */
class WorkItemQueueService
{
    private PDO $db;
    private WorkItemCrudService $crudService;
    
    public function __construct(?PDO $db = null, ?WorkItemCrudService $crudService = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->crudService = $crudService ?? new WorkItemCrudService($this->db);
    }
    
    /**
     * Holt nächsten Lead für User
     * @param string $userId
     * @param string|null $tab - Tab aus dem geladen werden soll (new, due, in_progress, snoozed, qualified)
     * @return array|null
     */
    public function getNextLead(string $userId, ?string $tab = null): ?array
    {
        $this->db->beginTransaction();
        try {
            $sql = "
                SELECT
                    c.*,
                    IFNULL(c.priority_stars, 0) as priority_stars,
                    o.name as company_name,
                    oa.city as company_city,
                    oa.postal_code as company_postal_code,
                    oa.country as company_country,
                    (
                        SELECT CONCAT_WS('',
                            IFNULL(occ1.country_code, ''),
                            IFNULL(occ1.area_code, ''),
                            IFNULL(occ1.number, ''),
                            IF(occ1.extension IS NOT NULL AND occ1.extension != '', CONCAT('-', occ1.extension), '')
                        )
                        FROM org_communication_channel occ1
                        WHERE occ1.org_uuid = o.org_uuid
                          AND (occ1.channel_type = 'phone_main' OR occ1.channel_type = 'phone')
                        ORDER BY 
                            occ1.is_primary DESC,
                            CASE WHEN EXISTS (
                                SELECT 1 FROM org_address oa2 
                                WHERE oa2.org_uuid = occ1.org_uuid 
                                AND oa2.address_type = 'headquarters'
                            ) THEN 1 ELSE 0 END DESC,
                            occ1.created_at DESC
                        LIMIT 1
                    ) as company_phone
                FROM case_item c
                LEFT JOIN org o ON c.org_uuid = o.org_uuid
                LEFT JOIN org_address oa ON o.org_uuid = oa.org_uuid 
                    AND (oa.address_type = 'headquarters' OR oa.is_default = 1)
                WHERE c.case_type = 'LEAD'
                  AND c.engine = 'inside_sales'
                  AND c.stage NOT IN ('DISQUALIFIED', 'DUPLICATE', 'CLOSED', 'DATA_CHECK')
                  AND (c.owner_user_id IS NULL OR c.owner_user_id = :user_id)
            ";

            // Tab-spezifische Filter hinzufügen
            if ($tab === 'new') {
                $sql .= " AND c.stage = 'NEW'";
            } elseif ($tab === 'due') {
                $sql .= " AND c.next_action_at IS NOT NULL";
                $sql .= " AND c.next_action_at <= CURDATE()";
                $sql .= " AND c.stage NOT IN ('DISQUALIFIED', 'DUPLICATE', 'CLOSED', 'DATA_CHECK')";
            } elseif ($tab === 'in_progress') {
                $sql .= " AND c.stage = 'IN_PROGRESS'";
            } elseif ($tab === 'snoozed') {
                $sql .= " AND c.next_action_at IS NOT NULL";
                $sql .= " AND c.next_action_at > CURDATE()";
                $sql .= " AND c.stage = 'SNOOZED'";
            } elseif ($tab === 'qualified') {
                $sql .= " AND c.stage = 'QUALIFIED'";
            } else {
                // Fallback: Wenn kein Tab angegeben, verwende alte Logik (fällige oder neue Leads)
                $sql .= " AND (
                    (c.next_action_at IS NOT NULL AND c.next_action_at <= CURDATE())
                    OR
                    (c.stage = 'NEW' AND c.next_action_at IS NULL)
                )";
            }

            $sql .= "
                ORDER BY
                    CASE
                        WHEN c.next_action_at IS NOT NULL AND c.next_action_at <= CURDATE() THEN 0
                        WHEN c.stage = 'NEW' AND c.next_action_at IS NULL THEN 1
                        ELSE 2
                    END,
                    c.priority_stars DESC,
                    CASE WHEN c.next_action_at IS NULL THEN 1 ELSE 0 END,
                    c.next_action_at ASC,
                    CASE WHEN c.last_touch_at IS NULL THEN 1 ELSE 0 END,
                    c.last_touch_at ASC,
                    c.created_at ASC
                LIMIT 1
                FOR UPDATE
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $userId]);
            $workItem = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$workItem) {
                $this->db->commit();
                return null;
            }

            // Soft-Assign: Setze owner_user_id wenn noch nicht gesetzt
            $updates = [];
            $params = ['uuid' => $workItem['case_uuid']];

            if ($workItem['owner_user_id'] !== $userId) {
                $updates[] = "owner_user_id = :user_id";
                $params['user_id'] = $userId;
            }

            if (!empty($updates)) {
                $updateSql = "UPDATE case_item SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE case_uuid = :uuid";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute($params);

                // Lade aktualisiertes WorkItem
                $workItem = $this->crudService->getWorkItem($workItem['case_uuid']);
            }

            $this->db->commit();
            return $workItem;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Listet WorkItems für Queue
     * @param string $type - case_type (z.B. 'LEAD')
     * @param string|null $tab - Tab (new, due, in_progress, snoozed, qualified)
     * @param string|null $userId - User-ID für Filterung
     * @param string|null $sortField - Sortierfeld
     * @param string|null $sortOrder - Sortierrichtung (asc, desc)
     * @return array
     */
    public function listWorkItems(
        string $type,
        ?string $tab = null,
        ?string $userId = null,
        ?string $sortField = null,
        ?string $sortOrder = 'asc'
    ): array {
        $sql = "
            SELECT
                c.*,
                IFNULL(c.priority_stars, 0) as priority_stars,
                o.name as company_name,
                o.website as company_website,
                oa.city as company_city,
                oa.postal_code as company_postal_code,
                oa.country as company_country,
                (
                    SELECT CONCAT_WS('',
                        IFNULL(occ1.country_code, ''),
                        IFNULL(occ1.area_code, ''),
                        IFNULL(occ1.number, ''),
                        IF(occ1.extension IS NOT NULL AND occ1.extension != '', CONCAT('-', occ1.extension), '')
                    )
                    FROM org_communication_channel occ1
                    WHERE occ1.org_uuid = o.org_uuid
                      AND (occ1.channel_type = 'phone_main' OR occ1.channel_type = 'phone')
                    ORDER BY 
                        occ1.is_primary DESC,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM org_address oa2 
                            WHERE oa2.org_uuid = occ1.org_uuid 
                            AND oa2.address_type = 'headquarters'
                        ) THEN 1 ELSE 0 END DESC,
                        occ1.created_at DESC
                    LIMIT 1
                ) as company_phone
                FROM case_item c
                LEFT JOIN org o ON c.org_uuid = o.org_uuid
                LEFT JOIN org_address oa ON o.org_uuid = oa.org_uuid 
                    AND (oa.address_type = 'headquarters' OR oa.is_default = 1)
            WHERE c.case_type = :type
              AND c.engine = 'inside_sales'
              AND c.stage NOT IN ('DISQUALIFIED', 'DUPLICATE', 'CLOSED', 'DATA_CHECK')
        ";
        
        $params = ['type' => $type];
        
        // User-Filter
        if ($userId) {
            $sql .= " AND (c.owner_user_id IS NULL OR c.owner_user_id = :user_id)";
            $params['user_id'] = $userId;
        }
        
        // Tab-Filter
        if ($tab === 'new') {
            $sql .= " AND c.stage = 'NEW'";
        } elseif ($tab === 'due') {
            $sql .= " AND c.next_action_at IS NOT NULL";
            $sql .= " AND c.next_action_at <= CURDATE()";
            $sql .= " AND c.stage NOT IN ('DISQUALIFIED', 'DUPLICATE', 'CLOSED', 'DATA_CHECK')";
        } elseif ($tab === 'in_progress') {
            $sql .= " AND c.stage = 'IN_PROGRESS'";
        } elseif ($tab === 'snoozed') {
            $sql .= " AND c.next_action_at IS NOT NULL";
            $sql .= " AND c.next_action_at > CURDATE()";
            $sql .= " AND c.stage = 'SNOOZED'";
        } elseif ($tab === 'qualified') {
            $sql .= " AND c.stage = 'QUALIFIED'";
        }
        
        // Sortierung
        $allowedSortFields = ['name', 'city', 'stars', 'next_action', 'last_touch'];
        $sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'stars';
        $sortOrder = strtolower($sortOrder) === 'desc' ? 'DESC' : 'ASC';
        
        switch ($sortField) {
            case 'name':
                $sql .= " ORDER BY o.name $sortOrder";
                break;
            case 'city':
                $sql .= " ORDER BY oa.city $sortOrder, o.name ASC";
                break;
            case 'stars':
                $sql .= " ORDER BY c.priority_stars $sortOrder, c.created_at ASC";
                break;
            case 'next_action':
                $sql .= " ORDER BY 
                    CASE WHEN c.next_action_at IS NULL THEN 1 ELSE 0 END,
                    c.next_action_at $sortOrder";
                break;
            case 'last_touch':
                $sql .= " ORDER BY 
                    CASE WHEN c.last_touch_at IS NULL THEN 1 ELSE 0 END,
                    c.last_touch_at $sortOrder";
                break;
            default:
                $sql .= " ORDER BY c.priority_stars DESC, c.created_at ASC";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Holt Queue-Statistiken
     * @param string $type
     * @param string|null $userId
     * @return array
     */
    public function getQueueStats(string $type, ?string $userId = null): array
    {
        $sql = "
            SELECT
                COUNT(CASE WHEN stage = 'NEW' THEN 1 END) as new_count,
                COUNT(CASE WHEN next_action_at IS NOT NULL AND next_action_at <= CURDATE() 
                    AND stage NOT IN ('DISQUALIFIED', 'DUPLICATE', 'CLOSED', 'DATA_CHECK') THEN 1 END) as due_count,
                COUNT(CASE WHEN stage = 'IN_PROGRESS' THEN 1 END) as in_progress_count,
                COUNT(CASE WHEN next_action_at IS NOT NULL AND next_action_at > CURDATE() 
                    AND stage = 'SNOOZED' THEN 1 END) as snoozed_count,
                COUNT(CASE WHEN stage = 'QUALIFIED' THEN 1 END) as qualified_count
            FROM case_item
            WHERE case_type = :type
              AND engine = 'inside_sales'
              AND stage NOT IN ('DISQUALIFIED', 'DUPLICATE', 'CLOSED', 'DATA_CHECK')
        ";
        
        $params = ['type' => $type];
        
        if ($userId) {
            $sql .= " AND (owner_user_id IS NULL OR owner_user_id = :user_id)";
            $params['user_id'] = $userId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'new' => (int)($result['new_count'] ?? 0),
            'due' => (int)($result['due_count'] ?? 0),
            'in_progress' => (int)($result['in_progress_count'] ?? 0),
            'snoozed' => (int)($result['snoozed_count'] ?? 0),
            'qualified' => (int)($result['qualified_count'] ?? 0)
        ];
    }
}

