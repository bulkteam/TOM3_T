<?php
declare(strict_types=1);

namespace TOM\Service\WorkItem\Core;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * WorkItemCrudService
 * 
 * CRUD-Operationen für WorkItems (case_item)
 */
class WorkItemCrudService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Holt WorkItem nach UUID
     * @param string $uuid
     * @return array|null
     */
    public function getWorkItem(string $uuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                c.*,
                IFNULL(c.priority_stars, 0) as priority_stars,
                o.name as company_name,
                o.website as company_website,
                oa.city as company_city,
                oa.postal_code as company_postal_code,
                oa.country as company_country,
                oa.street as company_street,
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
            WHERE c.case_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $uuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Aktualisiert WorkItem
     * @param string $uuid
     * @param array $data
     * @return array
     */
    public function updateWorkItem(string $uuid, array $data): array
    {
        $allowed = [
            'stage', 'next_action_at', 'next_action_type', 'priority_stars',
            'last_touch_at', 'touch_count', 'owner_user_id', 'title', 'description'
        ];
        
        $updates = [];
        $params = ['uuid' => $uuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return $this->getWorkItem($uuid);
        }
        
        $sql = "UPDATE case_item SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE case_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $this->getWorkItem($uuid);
    }
}

