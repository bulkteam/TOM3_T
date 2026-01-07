<?php
declare(strict_types=1);

namespace TOM\Service\Org\Search;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\SearchQueryHelper;

/**
 * OrgSearchService
 * 
 * Handles search functionality for organizations:
 * - Full-text search with filters
 * - Similar organizations finder
 * - List organizations with filters
 */
class OrgSearchService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Lists organizations with optional filters
     */
    public function listOrgs(array $filters = []): array
    {
        // Verwende searchOrgs für bessere Funktionalität
        $query = $filters['search'] ?? '';
        $limit = $filters['limit'] ?? 100;
        return $this->searchOrgs($query, $filters, $limit);
    }
    
    /**
     * Volltextsuche Organisationen (Search-first)
     * Sucht in: Name, Aliases, external_ref, Adressen (Stadt), Branche, Marktsegment
     * Gruppiert nach Relevanz
     */
    public function searchOrgs(string $query, array $filters = [], int $limit = 20): array
    {
        if (SearchQueryHelper::isEmpty($query) && empty($filters)) {
            return [];
        }
        
        $searchTerm = '%' . trim($query) . '%';
        $exactTerm = trim($query);
        $startsTerm = trim($query) . '%';
        
        // Erweiterte Suche mit Aliases, Industries (direkte Felder), Locations
        // Standard: Nur aktive Organisationen (archived_at IS NULL)
        $includeArchived = $filters['include_archived'] ?? false;
        
        $sql = "
            SELECT DISTINCT o.*,
                GROUP_CONCAT(DISTINCT oa.city) as cities,
                GROUP_CONCAT(DISTINCT oa.country) as countries,
                -- Branchen über direkte Felder (Hauptklasse + Unterklasse)
                CONCAT_WS(' / ', 
                    COALESCE(imain.name, ''), 
                    COALESCE(isub.name, '')
                ) as industries,
                GROUP_CONCAT(DISTINCT oalias.alias_name) as aliases,
                -- Aktueller Tier
                (SELECT tier_code FROM org_customer_tier 
                 WHERE org_uuid = o.org_uuid 
                 AND (valid_to IS NULL OR valid_to >= CURDATE())
                 ORDER BY valid_from DESC LIMIT 1) as current_tier,
                -- Strategic Flag
                (SELECT is_strategic FROM org_strategic_flag 
                 WHERE org_uuid = o.org_uuid LIMIT 1) as is_strategic,
                -- Letzte Metriken
                (SELECT revenue_amount FROM org_metrics 
                 WHERE org_uuid = o.org_uuid 
                 ORDER BY year DESC LIMIT 1) as last_revenue,
                (SELECT employees FROM org_metrics 
                 WHERE org_uuid = o.org_uuid 
                 ORDER BY year DESC LIMIT 1) as last_employees
            FROM org o
            LEFT JOIN org_address oa ON o.org_uuid = oa.org_uuid
            LEFT JOIN org_alias oalias ON o.org_uuid = oalias.org_uuid
            LEFT JOIN industry imain ON o.industry_main_uuid = imain.industry_uuid
            LEFT JOIN industry isub ON o.industry_sub_uuid = isub.industry_uuid
            WHERE 1=1
        ";
        
        // Filter: Nur aktive Organisationen (außer wenn explizit archivierte eingeschlossen werden)
        if (!$includeArchived) {
            $sql .= " AND o.archived_at IS NULL";
        }
        
        $params = [];
        
        // Volltextsuche (wenn Query vorhanden)
        if (!empty($query)) {
            $sql .= " AND (
                o.name LIKE :search 
                OR o.external_ref LIKE :search
                OR oalias.alias_name LIKE :search
                OR oa.city LIKE :search
                OR oa.country LIKE :search
                OR imain.name LIKE :search
                OR isub.name LIKE :search
            )";
            $params['search'] = $searchTerm;
            $params['exact'] = $exactTerm;
            $params['starts'] = $startsTerm;
        }
        
        // Filter nach org_kind
        if (!empty($filters['org_kind'])) {
            $sql .= " AND o.org_kind = :org_kind";
            $params['org_kind'] = $filters['org_kind'];
        }
        
        // Filter nach Status
        if (!empty($filters['status'])) {
            $sql .= " AND o.status = :status";
            $params['status'] = $filters['status'];
        }
        
        // Filter nach Stadt
        if (!empty($filters['city'])) {
            $sql .= " AND oa.city LIKE :city";
            $params['city'] = '%' . $filters['city'] . '%';
        }
        
        // Filter nach Land
        if (!empty($filters['country'])) {
            $sql .= " AND oa.country = :country";
            $params['country'] = $filters['country'];
        }
        
        // Filter nach Branche (über alle Level für Rückwärtskompatibilität)
        if (!empty($filters['industry'])) {
            $sql .= " AND (o.industry_main_uuid = :industry OR o.industry_sub_uuid = :industry OR o.industry_level1_uuid = :industry OR o.industry_level2_uuid = :industry OR o.industry_level3_uuid = :industry)";
            $params['industry'] = $filters['industry'];
        }
        
        // Filter nach Tier
        if (!empty($filters['tier'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM org_customer_tier 
                WHERE org_uuid = o.org_uuid 
                AND tier_code = :tier
                AND (valid_to IS NULL OR valid_to >= CURDATE())
            )";
            $params['tier'] = $filters['tier'];
        }
        
        // Filter nach Strategic
        if (isset($filters['strategic']) && $filters['strategic'] !== '') {
            $sql .= " AND EXISTS (
                SELECT 1 FROM org_strategic_flag 
                WHERE org_uuid = o.org_uuid 
                AND is_strategic = :strategic
            )";
            $params['strategic'] = $filters['strategic'] ? 1 : 0;
        }
        
        // Filter nach Umsatz (letztes verfügbares Jahr)
        if (!empty($filters['revenue_min'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM org_metrics 
                WHERE org_uuid = o.org_uuid 
                AND revenue_amount >= :revenue_min
                AND year = (SELECT MAX(year) FROM org_metrics WHERE org_uuid = o.org_uuid)
            )";
            $params['revenue_min'] = $filters['revenue_min'];
        }
        
        // Filter nach Mitarbeiter
        if (!empty($filters['employees_min'])) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM org_metrics 
                WHERE org_uuid = o.org_uuid 
                AND employees >= :employees_min
                AND year = (SELECT MAX(year) FROM org_metrics WHERE org_uuid = o.org_uuid)
            )";
            $params['employees_min'] = $filters['employees_min'];
        }
        
        $sql .= " GROUP BY o.org_uuid";
        
        // Relevanz-Sortierung (wenn Query vorhanden)
        if (!empty($query)) {
            $sql .= " ORDER BY 
                CASE 
                    WHEN o.name = :exact THEN 1
                    WHEN o.name LIKE :starts THEN 2
                    WHEN oalias.alias_name LIKE :starts THEN 3
                    WHEN o.name LIKE :search THEN 4
                    WHEN oalias.alias_name LIKE :search THEN 5
                    WHEN o.external_ref LIKE :search THEN 6
                    ELSE 7
                END,
                o.name
            ";
        } else {
            $sql .= " ORDER BY o.name";
        }
        
        $sql .= " LIMIT :limit";
        $params['limit'] = $limit;
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'limit') {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Ähnliche Organisationen finden (für "Meintest du...?")
     */
    public function findSimilarOrgs(string $query, int $limit = 5): array
    {
        if (empty(trim($query))) {
            return [];
        }
        
        $searchTerm = '%' . trim($query) . '%';
        
        // Suche nach ähnlichen Namen (Levenshtein-ähnlich über LIKE)
        $sql = "
            SELECT * FROM org 
            WHERE name LIKE :search 
               OR name LIKE :search2
               OR external_ref LIKE :search
            ORDER BY 
                CASE 
                    WHEN name LIKE :starts THEN 1
                    ELSE 2
                END,
                name
            LIMIT :limit
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->bindValue(':search2', '%' . str_replace(' ', '%', trim($query)) . '%'); // Fuzzy für Leerzeichen
        $stmt->bindValue(':starts', trim($query) . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}




