<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Utils\UrlHelper;
use TOM\Infrastructure\Audit\AuditTrailService;
use TOM\Infrastructure\Access\AccessTrackingService;
use TOM\Service\BaseEntityService;
use TOM\Service\Org\OrgAddressService;
use TOM\Service\Org\OrgVatService;
use TOM\Service\Org\OrgRelationService;

class OrgService extends BaseEntityService
{
    private AccessTrackingService $accessTrackingService;
    private OrgAddressService $addressService;
    private OrgVatService $vatService;
    private OrgRelationService $relationService;
    
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        $this->accessTrackingService = new AccessTrackingService($this->db);
        $this->addressService = new OrgAddressService($this->db);
        $this->vatService = new OrgVatService($this->db);
        $this->relationService = new OrgRelationService($this->db);
    }
    
    public function createOrg(array $data, ?string $userId = null): array
    {
        // Prüfe auf potenzielle Duplikate (als Warnung, nicht blockierend)
        $duplicateWarnings = [];
        
        // Prüfe auf gleichen Namen + gleichen org_kind
        if (!empty($data['name']) && !empty($data['org_kind'])) {
            $stmt = $this->db->prepare("
                SELECT org_uuid, name, org_kind, external_ref 
                FROM org 
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) 
                AND org_kind = :org_kind
                AND archived_at IS NULL
            ");
            $stmt->execute([
                'name' => $data['name'],
                'org_kind' => $data['org_kind']
            ]);
            $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($existing)) {
                foreach ($existing as $org) {
                    $ref = $org['external_ref'] ?? 'keine Referenz';
                    $duplicateWarnings[] = "Eine Organisation mit dem Namen '{$data['name']}' und Typ '{$data['org_kind']}' existiert bereits (Referenz: {$ref}).";
                }
            }
        }
        
        // Prüfe auf gleiche Website
        if (!empty($data['website'])) {
            $normalizedWebsite = UrlHelper::normalize($data['website']);
            $stmt = $this->db->prepare("
                SELECT org_uuid, name, website 
                FROM org 
                WHERE LOWER(TRIM(website)) = LOWER(TRIM(:website))
                AND archived_at IS NULL
            ");
            $stmt->execute(['website' => $normalizedWebsite]);
            $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($existing)) {
                foreach ($existing as $org) {
                    $duplicateWarnings[] = "Eine Organisation mit der Website '{$normalizedWebsite}' existiert bereits: '{$org['name']}'.";
                }
            }
        }
        
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        // Automatische Kundennummer-Generierung, wenn keine angegeben wurde
        if (empty($data['external_ref'])) {
            $data['external_ref'] = $this->generateCustomerNumber();
        }
        
        // Normalisiere Website-URL
        if (!empty($data['website'])) {
            $data['website'] = UrlHelper::normalize($data['website']);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO org (
                org_uuid, name, org_kind, external_ref, 
                industry, industry_main_uuid, industry_sub_uuid,
                industry_level1_uuid, industry_level2_uuid, industry_level3_uuid,
                revenue_range, employee_count, website, notes, status,
                account_owner_user_id, account_owner_since
            )
            VALUES (
                :org_uuid, :name, :org_kind, :external_ref,
                :industry, :industry_main_uuid, :industry_sub_uuid,
                :industry_level1_uuid, :industry_level2_uuid, :industry_level3_uuid,
                :revenue_range, :employee_count, :website, :notes, :status,
                :account_owner_user_id, :account_owner_since
            )
        ");
        
        $status = $data['status'] ?? 'lead';
        $accountOwnerSince = null;
        if (isset($data['account_owner_user_id']) && $data['account_owner_user_id']) {
            $accountOwnerSince = $data['account_owner_since'] ?? date('Y-m-d');
        }
        
        $stmt->execute([
            'org_uuid' => $uuid,
            'name' => $data['name'] ?? '',
            'org_kind' => $data['org_kind'] ?? 'other',
            'external_ref' => $data['external_ref'] ?? null,
            'industry' => $data['industry'] ?? null,
            'industry_main_uuid' => $data['industry_main_uuid'] ?? $data['industry_level1_uuid'] ?? null, // Rückwärtskompatibilität
            'industry_sub_uuid' => $data['industry_sub_uuid'] ?? $data['industry_level2_uuid'] ?? null, // Rückwärtskompatibilität
            'industry_level1_uuid' => $data['industry_level1_uuid'] ?? $data['industry_main_uuid'] ?? null, // Neue 3-stufige Hierarchie
            'industry_level2_uuid' => $data['industry_level2_uuid'] ?? $data['industry_sub_uuid'] ?? null,
            'industry_level3_uuid' => $data['industry_level3_uuid'] ?? null,
            'revenue_range' => $data['revenue_range'] ?? null,
            'employee_count' => $data['employee_count'] ?? null,
            'website' => $data['website'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $status,
            'account_owner_user_id' => $data['account_owner_user_id'] ?? null,
            'account_owner_since' => $accountOwnerSince
        ]);
        
        $org = $this->getOrg($uuid);
        
        // Protokolliere Erstellung im Audit-Trail (zentralisiert)
        if ($org) {
            $this->logCreateAuditTrail('org', $uuid, $userId ?? null, $org, [$this, 'resolveFieldValue']);
        }
        
        // Event-Publishing (zentralisiert)
        if ($org) {
            $this->publishEntityEvent('org', $org['org_uuid'], 'OrgCreated', $org);
        }
        
        return $org;
    }
    
    public function getOrg(string $orgUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                o.*,
                i_main.name as industry_main_name,
                i_sub.name as industry_sub_name,
                i_level1.name as industry_level1_name,
                i_level2.name as industry_level2_name,
                i_level3.name as industry_level3_name,
                u.name as account_owner_name
            FROM org o
            LEFT JOIN industry i_main ON o.industry_main_uuid = i_main.industry_uuid
            LEFT JOIN industry i_sub ON o.industry_sub_uuid = i_sub.industry_uuid
            LEFT JOIN industry i_level1 ON o.industry_level1_uuid = i_level1.industry_uuid
            LEFT JOIN industry i_level2 ON o.industry_level2_uuid = i_level2.industry_uuid
            LEFT JOIN industry i_level3 ON o.industry_level3_uuid = i_level3.industry_uuid
            LEFT JOIN users u ON o.account_owner_user_id = u.user_id
            WHERE o.org_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $orgUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function updateOrg(string $orgUuid, array $data, ?string $userId = null): array
    {
        // Hole alte Werte für Audit-Trail
        $oldOrg = $this->getOrg($orgUuid);
        
        // Normalisiere Website-URL falls vorhanden
        if (isset($data['website'])) {
            $data['website'] = UrlHelper::normalize($data['website']);
        }
        
        $allowedFields = ['name', 'org_kind', 'external_ref', 'industry', 'industry_main_uuid', 'industry_sub_uuid', 'industry_level1_uuid', 'industry_level2_uuid', 'industry_level3_uuid', 'revenue_range', 'employee_count', 'website', 'notes', 'status', 'account_owner_user_id', 'account_owner_since'];
        $updates = [];
        $params = ['uuid' => $orgUuid];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        
        // Wenn account_owner_user_id gesetzt wird, setze auch account_owner_since falls nicht vorhanden
        if (isset($data['account_owner_user_id']) && $data['account_owner_user_id'] && !isset($data['account_owner_since'])) {
            $updates[] = "account_owner_since = :account_owner_since";
            $params['account_owner_since'] = date('Y-m-d');
        }
        
        // Wenn account_owner_user_id entfernt wird, entferne auch account_owner_since
        if (isset($data['account_owner_user_id']) && !$data['account_owner_user_id']) {
            $updates[] = "account_owner_since = NULL";
        }
        
        if (empty($updates)) {
            return $this->getOrg($orgUuid) ?: [];
        }
        
        $sql = "UPDATE org SET " . implode(', ', $updates) . " WHERE org_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        // Verifiziere, dass genau eine Zeile aktualisiert wurde
        $affectedRows = $stmt->rowCount();
        if ($affectedRows > 1) {
            throw new \RuntimeException("Update affected multiple organizations! This should not happen.");
        }
        
        $org = $this->getOrg($orgUuid);
        
        // Protokolliere Änderungen im Audit-Trail (zentralisiert)
        if ($org && $oldOrg) {
            $this->logUpdateAuditTrail('org', $orgUuid, $userId ?? null, $oldOrg, $org, [$this, 'resolveFieldValue']);
        }
        
        // Event-Publishing (zentralisiert)
        if ($org) {
            $this->publishEntityEvent('org', $org['org_uuid'], 'OrgUpdated', $org);
        }
        
        return $org ?: [];
    }
    
    /**
     * Berechnet Account-Gesundheit für eine Organisation
     * Gibt Status (green/yellow/red) und Gründe zurück
     */
    public function getAccountHealth(string $orgUuid): array
    {
        $org = $this->getOrg($orgUuid);
        if (!$org) {
            return ['status' => 'unknown', 'reasons' => []];
        }
        
        $reasons = [];
        $status = 'green';
        
        // 1. Kontaktfrische prüfen
        $lastContact = $this->getLastContactDate($orgUuid);
        if ($lastContact) {
            $daysSinceContact = (time() - strtotime($lastContact)) / 86400;
            if ($daysSinceContact > 60) {
                $reasons[] = ['type' => 'no_contact', 'days' => (int)$daysSinceContact, 'severity' => 'red'];
                $status = 'red';
            } elseif ($daysSinceContact > 30) {
                $reasons[] = ['type' => 'no_contact', 'days' => (int)$daysSinceContact, 'severity' => 'yellow'];
                if ($status === 'green') $status = 'yellow';
            }
        } else {
            // Kein Kontakt erfasst
            $reasons[] = ['type' => 'no_contact_recorded', 'severity' => 'yellow'];
            if ($status === 'green') $status = 'yellow';
        }
        
        // 2. Stagnierende Angebote prüfen
        $staleOffers = $this->getStaleOffers($orgUuid, 21);
        if (count($staleOffers) > 0) {
            $oldestDays = $staleOffers[0]['days_stale'];
            if ($oldestDays > 30) {
                $reasons[] = ['type' => 'offer_stale', 'count' => count($staleOffers), 'days' => $oldestDays, 'severity' => 'red'];
                $status = 'red';
            } else {
                $reasons[] = ['type' => 'offer_stale', 'count' => count($staleOffers), 'days' => $oldestDays, 'severity' => 'yellow'];
                if ($status === 'green') $status = 'yellow';
            }
        }
        
        // 3. Projekte "draußen" prüfen
        $waitingProjects = $this->getWaitingProjects($orgUuid, 14);
        if (count($waitingProjects) > 0) {
            $oldestDays = $waitingProjects[0]['days_waiting'];
            if ($oldestDays > 30) {
                $reasons[] = ['type' => 'project_waiting', 'count' => count($waitingProjects), 'days' => $oldestDays, 'severity' => 'red'];
                $status = 'red';
            } else {
                $reasons[] = ['type' => 'project_waiting', 'count' => count($waitingProjects), 'days' => $oldestDays, 'severity' => 'yellow'];
                if ($status === 'green') $status = 'yellow';
            }
        }
        
        // 4. Offene Eskalationen prüfen
        $escalations = $this->getOpenEscalations($orgUuid);
        if (count($escalations) > 0) {
            $reasons[] = ['type' => 'escalation', 'count' => count($escalations), 'severity' => 'red'];
            $status = 'red';
        }
        
        return [
            'status' => $status,
            'reasons' => $reasons,
            'last_contact' => $lastContact,
            'org_status' => $org['status'] ?? 'lead'
        ];
    }
    
    private function getLastContactDate(string $orgUuid): ?string
    {
        // Prüfe user_org_access für letzten Zugriff
        $stmt = $this->db->prepare("
            SELECT MAX(accessed_at) as last_contact
            FROM user_org_access
            WHERE org_uuid = :org_uuid
        ");
        $stmt->execute(['org_uuid' => $orgUuid]);
        $result = $stmt->fetch();
        return $result['last_contact'] ?? null;
    }
    
    private function getStaleOffers(string $orgUuid, int $daysThreshold): array
    {
        // TODO: Implementiere wenn Angebote-Tabelle existiert
        // Für jetzt: Prüfe Cases ohne Bewegung
        $stmt = $this->db->prepare("
            SELECT 
                case_uuid,
                title,
                DATEDIFF(CURDATE(), updated_at) as days_stale
            FROM case_item
            WHERE org_uuid = :org_uuid
            AND status NOT IN ('abgeschlossen', 'eskaliert')
            AND DATEDIFF(CURDATE(), updated_at) > :threshold
            ORDER BY updated_at ASC
        ");
        $stmt->execute(['org_uuid' => $orgUuid, 'threshold' => $daysThreshold]);
        return $stmt->fetchAll();
    }
    
    private function getWaitingProjects(string $orgUuid, int $daysThreshold): array
    {
        // Projekte mit Status "on_hold" oder ohne Bewegung
        $stmt = $this->db->prepare("
            SELECT 
                project_uuid,
                name,
                status,
                DATEDIFF(CURDATE(), updated_at) as days_waiting
            FROM project
            WHERE sponsor_org_uuid = :org_uuid
            AND (status = 'on_hold' OR DATEDIFF(CURDATE(), updated_at) > :threshold)
            ORDER BY updated_at ASC
        ");
        $stmt->execute(['org_uuid' => $orgUuid, 'threshold' => $daysThreshold]);
        return $stmt->fetchAll();
    }
    
    private function getOpenEscalations(string $orgUuid): array
    {
        // Cases mit Status "eskaliert"
        $stmt = $this->db->prepare("
            SELECT case_uuid, title
            FROM case_item
            WHERE org_uuid = :org_uuid
            AND status = 'eskaliert'
        ");
        $stmt->execute(['org_uuid' => $orgUuid]);
        return $stmt->fetchAll();
    }
    
    /**
     * Hole alle Organisationen eines Account Owners mit Gesundheitsstatus
     */
    public function getAccountsByOwner(string $userId, bool $includeHealth = true): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM org
            WHERE account_owner_user_id = :user_id
            ORDER BY name
        ");
        $stmt->execute(['user_id' => $userId]);
        $orgs = $stmt->fetchAll();
        
        if ($includeHealth) {
            foreach ($orgs as &$org) {
                $org['health'] = $this->getAccountHealth($org['org_uuid']);
            }
        }
        
        return $orgs;
    }
    
    /**
     * Hole Liste aller verfügbaren Account Owners (User-IDs)
     * Kombiniert:
     * 1. User aus Config-Datei (falls vorhanden) - nur wenn can_be_account_owner = true
     * 2. User, die bereits als Account Owner verwendet werden
     */
    public function getAvailableAccountOwners(): array
    {
        // Hole alle aktiven User aus der DB
        $stmt = $this->db->query("
            SELECT 
                u.user_id,
                u.name,
                u.email
            FROM users u
            WHERE u.is_active = 1
            ORDER BY u.name
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu Array von user_ids (als String für Kompatibilität)
        $userIds = [];
        foreach ($users as $user) {
            $userIds[] = (string)$user['user_id'];
        }
        
        return $userIds;
    }
    
    /**
     * Hole Liste aller verfügbaren Account Owners mit Display-Namen
     * Gibt Array zurück: ['user_id' => 'display_name', ...]
     */
    public function getAvailableAccountOwnersWithNames(): array
    {
        // Hole alle aktiven User aus der DB
        $stmt = $this->db->query("
            SELECT 
                u.user_id,
                u.name,
                u.email
            FROM users u
            WHERE u.is_active = 1
            ORDER BY u.name
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu Array: user_id => name (email)
        $owners = [];
        foreach ($users as $user) {
            $userId = (string)$user['user_id'];
            $displayName = $user['name'];
            if ($user['email']) {
                $displayName .= ' (' . $user['email'] . ')';
            }
            $owners[$userId] = $displayName;
        }
        
        return $owners;
    }
    
    public function listOrgs(array $filters = []): array
    {
        // Verwende searchOrgs für bessere Funktionalität
        $query = $filters['search'] ?? '';
        $limit = $filters['limit'] ?? 100;
        return $this->searchOrgs($query, $filters, $limit);
    }
    
    // ============================================================================
    // ALIAS MANAGEMENT (frühere Namen, Handelsnamen)
    // ============================================================================
    
    public function addAlias(string $orgUuid, string $aliasName, string $aliasType = 'other'): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_alias (alias_uuid, org_uuid, alias_name, alias_type)
            VALUES (:alias_uuid, :org_uuid, :alias_name, :alias_type)
        ");
        
        $stmt->execute([
            'alias_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'alias_name' => $aliasName,
            'alias_type' => $aliasType
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM org_alias WHERE alias_uuid = :uuid");
        $stmt->execute(['uuid' => $uuid]);
        return $stmt->fetch() ?: [];
    }
    
    public function getAliases(string $orgUuid): array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_alias WHERE org_uuid = :uuid ORDER BY is_primary DESC, alias_name");
        $stmt->execute(['uuid' => $orgUuid]);
        return $stmt->fetchAll();
    }
    
    // ============================================================================
    // USER ACCESS TRACKING (Zuletzt verwendet, Favoriten)
    // ============================================================================
    
    public function trackAccess(string $userId, string $orgUuid, string $accessType = 'recent'): void
    {
        $this->accessTrackingService->trackAccess('org', $userId, $orgUuid, $accessType);
    }
    
    public function getRecentOrgs(string $userId, int $limit = 10): array
    {
        return $this->accessTrackingService->getRecentEntities('org', $userId, $limit);
    }
    
    public function getFavoriteOrgs(string $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.*
            FROM org o
            INNER JOIN user_org_access uoa ON o.org_uuid = uoa.org_uuid
            WHERE uoa.user_id = :user_id AND uoa.access_type = 'favorite'
            ORDER BY uoa.accessed_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
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
        
        return $stmt->fetchAll();
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
        
        return $stmt->fetchAll();
    }
    
    // ============================================================================
    // ADDRESS MANAGEMENT (delegiert an OrgAddressService)
    // ============================================================================
    
    public function addAddress(string $orgUuid, array $data, ?string $userId = null): array
    {
        return $this->addressService->addAddress($orgUuid, $data, $userId);
    }
    
    public function getAddress(string $addressUuid): ?array
    {
        return $this->addressService->getAddress($addressUuid);
    }
    
    public function getAddresses(string $orgUuid, ?string $addressType = null): array
    {
        return $this->addressService->getAddresses($orgUuid, $addressType);
    }
    
    public function updateAddress(string $addressUuid, array $data, ?string $userId = null): array
    {
        return $this->addressService->updateAddress($addressUuid, $data, $userId);
    }
    
    public function deleteAddress(string $addressUuid, ?string $userId = null): bool
    {
        return $this->addressService->deleteAddress($addressUuid, $userId);
    }
    
    // ============================================================================
    // VAT REGISTRATION (USt-ID) MANAGEMENT (delegiert an OrgVatService)
    // ============================================================================
    
    /**
     * Fügt eine USt-ID-Registrierung für eine Organisation hinzu
     * address_uuid ist optional (nur für Kontext)
     */
    public function addVatRegistration(string $orgUuid, array $data, ?string $userId = null): array
    {
        return $this->vatService->addVatRegistration($orgUuid, $data, $userId);
    }
    
    /**
     * Holt eine USt-ID-Registrierung
     */
    public function getVatRegistration(string $vatRegistrationUuid): ?array
    {
        return $this->vatService->getVatRegistration($vatRegistrationUuid);
    }
    
    /**
     * Holt alle USt-ID-Registrierungen einer Organisation
     */
    public function getVatRegistrations(string $orgUuid, bool $onlyValid = true): array
    {
        return $this->vatService->getVatRegistrations($orgUuid, $onlyValid);
    }
    
    /**
     * Holt die USt-ID für eine bestimmte Adresse (optional, für Kontext)
     */
    public function getVatIdForAddress(string $addressUuid): ?array
    {
        return $this->vatService->getVatIdForAddress($addressUuid);
    }
    
    /**
     * Aktualisiert eine USt-ID-Registrierung
     */
    public function updateVatRegistration(string $vatRegistrationUuid, array $data, ?string $userId = null): array
    {
        return $this->vatService->updateVatRegistration($vatRegistrationUuid, $data, $userId);
    }
    
    /**
     * Löscht eine USt-ID-Registrierung
     */
    public function deleteVatRegistration(string $vatRegistrationUuid, ?string $userId = null): bool
    {
        return $this->vatService->deleteVatRegistration($vatRegistrationUuid, $userId);
    }
    
    // ============================================================================
    // RELATION MANAGEMENT
    // ============================================================================
    
    public function addRelation(array $data, ?string $userId = null): array
    {
        return $this->relationService->addRelation($data, $userId);
    }
    
    public function getRelation(string $relationUuid): ?array
    {
        return $this->relationService->getRelation($relationUuid);
    }
    
    public function getRelations(string $orgUuid, ?string $direction = null): array
    {
        return $this->relationService->getRelations($orgUuid, $direction);
    }
    public function updateRelation(string $relationUuid, array $data, ?string $userId = null): array
    {
        return $this->relationService->updateRelation($relationUuid, $data, $userId);
    }
    public function deleteRelation(string $relationUuid, ?string $userId = null): bool
    {
        return $this->relationService->deleteRelation($relationUuid, $userId);
    }
    // ============================================================================
    // ENRICHED ORG DATA
    // ============================================================================
    
    public function getOrgWithDetails(string $orgUuid): ?array
    {
        $org = $this->getOrg($orgUuid);
        if (!$org) {
            return null;
        }
        
        $org['addresses'] = $this->getAddresses($orgUuid);
        $org['relations'] = $this->getRelations($orgUuid);
        $org['communication_channels'] = $this->getCommunicationChannels($orgUuid);
        $org['vat_registrations'] = $this->getVatRegistrations($orgUuid, true);

        // Optional: Lade USt-IDs für Adressen (nur wenn vorhanden)
        foreach ($org['addresses'] as &$address) {
            $address['vat_id'] = $this->getVatIdForAddress($address['address_uuid']);
        }
        unset($address);

        // Industry-Namen werden bereits von getOrg() über JOIN geladen
        // Falls sie fehlen, lade sie nach (Fallback)
        if ($org['industry_main_uuid'] && empty($org['industry_main_name'])) {
            $mainIndustry = $this->getIndustryByUuid($org['industry_main_uuid']);
            $org['industry_main_name'] = $mainIndustry['name'] ?? null;
        }
        if ($org['industry_sub_uuid'] && empty($org['industry_sub_name'])) {
            $subIndustry = $this->getIndustryByUuid($org['industry_sub_uuid']);
            $org['industry_sub_name'] = $subIndustry['name'] ?? null;
        }
        // 3-stufige Hierarchie
        if ($org['industry_level1_uuid'] && empty($org['industry_level1_name'])) {
            $level1Industry = $this->getIndustryByUuid($org['industry_level1_uuid']);
            $org['industry_level1_name'] = $level1Industry['name'] ?? null;
        }
        if ($org['industry_level2_uuid'] && empty($org['industry_level2_name'])) {
            $level2Industry = $this->getIndustryByUuid($org['industry_level2_uuid']);
            $org['industry_level2_name'] = $level2Industry['name'] ?? null;
        }
        if ($org['industry_level3_uuid'] && empty($org['industry_level3_name'])) {
            $level3Industry = $this->getIndustryByUuid($org['industry_level3_uuid']);
            $org['industry_level3_name'] = $level3Industry['name'] ?? null;
        }
        
        return $org;
    }
    
    /**
     * Holt eine Branche anhand der UUID
     */
    private function getIndustryByUuid(string $industryUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM industry WHERE industry_uuid = :uuid");
        $stmt->execute(['uuid' => $industryUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Gibt die nächste verfügbare Kundennummer zurück (ohne sie zu vergeben)
     * 
     * @return string Numerische Kundennummer
     */
    public function getNextCustomerNumber(): string
    {
        return $this->generateCustomerNumber();
    }

    /**
     * Generiert eine neue Kundennummer basierend auf der höchsten vorhandenen Nummer
     * 
     * @return string Numerische Kundennummer
     */
    private function generateCustomerNumber(): string
    {
        // Lade Konfiguration
        $configFile = dirname(__DIR__, 2) . '/config/customer_number.php';
        if (!file_exists($configFile)) {
            // Fallback: Standard-Startnummer
            $startNumber = 100;
        } else {
            $config = require $configFile;
            $startNumber = $config['start_number'] ?? 100;
        }
        
        // Finde die höchste numerische Kundennummer
        // Nur Werte, die rein numerisch sind (ohne Präfix, Suffix, etc.)
        $stmt = $this->db->query("
            SELECT external_ref 
            FROM org 
            WHERE external_ref IS NOT NULL 
              AND external_ref REGEXP '^[0-9]+$'
            ORDER BY CAST(external_ref AS UNSIGNED) DESC 
            LIMIT 1
        ");
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['external_ref'])) {
            // Erhöhe die höchste Nummer um 1
            $nextNumber = (int)$result['external_ref'] + 1;
        } else {
            // Keine vorhandene Nummer, verwende Startnummer
            $nextNumber = $startNumber;
        }
        
        // Stelle sicher, dass die Nummer nicht kleiner als die Startnummer ist
        if ($nextNumber < $startNumber) {
            $nextNumber = $startNumber;
        }
        
        // Rückgabe als String (rein numerisch)
        return (string)$nextNumber;
    }
    
    // ============================================================================
    // COMMUNICATION CHANNEL MANAGEMENT
    // ============================================================================
    
    public function addCommunicationChannel(string $orgUuid, array $data, ?string $userId = null): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_communication_channel (
                channel_uuid, org_uuid, channel_type, country_code, area_code, number, extension,
                email_address, label, is_primary, is_public, notes
            )
            VALUES (
                :channel_uuid, :org_uuid, :channel_type, :country_code, :area_code, :number, :extension,
                :email_address, :label, :is_primary, :is_public, :notes
            )
        ");
        
        // Wenn dieser Kanal als primary markiert wird, entferne primary von anderen Kanälen desselben Typs
        if (!empty($data['is_primary'])) {
            $this->db->prepare("
                UPDATE org_communication_channel 
                SET is_primary = 0 
                WHERE org_uuid = :org_uuid AND channel_type = :channel_type
            ")->execute([
                'org_uuid' => $orgUuid,
                'channel_type' => $data['channel_type']
            ]);
        }
        
        $stmt->execute([
            'channel_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'channel_type' => $data['channel_type'] ?? 'other',
            'country_code' => $data['country_code'] ?? null,
            'area_code' => $data['area_code'] ?? null,
            'number' => $data['number'] ?? null,
            'extension' => $data['extension'] ?? null,
            'email_address' => $data['email_address'] ?? null,
            'label' => $data['label'] ?? null,
            'is_primary' => $data['is_primary'] ?? 0,
            'is_public' => $data['is_public'] ?? 1,
            'notes' => $data['notes'] ?? null
        ]);
        
        $channel = $this->getCommunicationChannel($uuid);
        
        // Protokolliere im Audit-Trail
        if ($channel) {
            $userId = $userId ?? 'default_user';
            
            // Erstelle menschenlesbare Beschreibung
            $channelTypeLabels = [
                'email' => 'E-Mail',
                'phone' => 'Telefon',
                'mobile' => 'Mobil',
                'fax' => 'Fax',
                'website' => 'Website',
                'other' => 'Sonstiges'
            ];
            $channelType = $channelTypeLabels[$channel['channel_type']] ?? $channel['channel_type'];
            $channelValue = '';
            if ($channel['channel_type'] === 'email' && $channel['email_address']) {
                $channelValue = $channel['email_address'];
            } elseif (in_array($channel['channel_type'], ['phone', 'mobile', 'fax']) && $channel['number']) {
                $parts = [];
                if ($channel['country_code']) $parts[] = $channel['country_code'];
                if ($channel['area_code']) $parts[] = $channel['area_code'];
                if ($channel['number']) $parts[] = $channel['number'];
                $channelValue = implode(' ', $parts);
            }
            $channelDescription = $channelType;
            if ($channel['label']) $channelDescription .= ' (' . $channel['label'] . ')';
            if ($channelValue) $channelDescription .= ': ' . $channelValue;
            
            // Speichere sowohl menschenlesbare Werte als auch vollständige JSON-Daten
            $this->insertAuditEntry(
                $orgUuid,
                $userId,
                'create',
                null,
                null,
                'channel_added',
                [
                    'channel_uuid' => $uuid,
                    'channel_type' => $channel['channel_type'],
                    'label' => $channel['label'],
                    'full_data' => $channel // Vollständige Daten für Analyse
                ],
                ['new' => $channelDescription]
            );
        }
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgCommunicationChannelAdded', $channel);
        
        return $channel;
    }
    
    public function getCommunicationChannel(string $channelUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_communication_channel WHERE channel_uuid = :uuid");
        $stmt->execute(['uuid' => $channelUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function getCommunicationChannels(string $orgUuid, ?string $channelType = null): array
    {
        $sql = "SELECT * FROM org_communication_channel WHERE org_uuid = :org_uuid";
        $params = ['org_uuid' => $orgUuid];
        
        if ($channelType) {
            $sql .= " AND channel_type = :channel_type";
            $params['channel_type'] = $channelType;
        }
        
        $sql .= " ORDER BY is_primary DESC, channel_type, label";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateCommunicationChannel(string $channelUuid, array $data, ?string $userId = null): array
    {
        $userId = $userId ?? 'default_user';
        $oldChannel = $this->getCommunicationChannel($channelUuid);
        
        if (!$oldChannel) {
            throw new \Exception("Kommunikationskanal nicht gefunden");
        }
        
        $allowed = ['channel_type', 'country_code', 'area_code', 'number', 'extension', 
                   'email_address', 'label', 'is_primary', 'is_public', 'notes'];
        $updates = [];
        $params = ['uuid' => $channelUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                // Konvertiere leere Strings zu null für optionale Felder
                $value = $data[$field];
                if ($value === '' && in_array($field, ['country_code', 'area_code', 'extension', 'label', 'notes'])) {
                    $params[$field] = null;
                } else {
                    $params[$field] = $value;
                }
            }
        }
        
        if (empty($updates)) {
            return $this->getCommunicationChannel($channelUuid);
        }
        
        // Wenn dieser Kanal als primary markiert wird, entferne primary von anderen
        if (isset($data['is_primary']) && $data['is_primary']) {
            $channel = $this->getCommunicationChannel($channelUuid);
            if ($channel) {
                $this->db->prepare("
                    UPDATE org_communication_channel 
                    SET is_primary = 0 
                    WHERE org_uuid = :org_uuid 
                    AND channel_type = :channel_type 
                    AND channel_uuid != :channel_uuid
                ")->execute([
                    'org_uuid' => $channel['org_uuid'],
                    'channel_type' => $channel['channel_type'],
                    'channel_uuid' => $channelUuid
                ]);
            }
        }
        
        $sql = "UPDATE org_communication_channel SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE channel_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $channel = $this->getCommunicationChannel($channelUuid);
        
        // Protokolliere Änderungen im Audit-Trail (ein Eintrag pro geändertem Feld, wie bei Stammdaten)
        if ($channel && $oldChannel) {
            foreach ($allowed as $field) {
                $oldValue = $oldChannel[$field] ?? null;
                $newValue = $channel[$field] ?? null;
                
                // Nur protokollieren, wenn sich der Wert geändert hat
                if ($oldValue !== $newValue) {
                    // Formatiere Werte für Anzeige
                    $oldValueStr = $this->formatChannelFieldValue($field, $oldValue);
                    $newValueStr = $this->formatChannelFieldValue($field, $newValue);
                    
                    // Erstelle einen Eintrag pro geändertem Feld (wie bei Stammdaten)
                    $this->insertAuditEntry(
                        $channel['org_uuid'],
                        $userId,
                        'update',
                        'channel_' . $field, // Feldname mit Präfix
                        $oldValueStr,
                        'field_change',
                        [
                            'channel_uuid' => $channelUuid,
                            'channel_type' => $channel['channel_type'] ?? '',
                            'label' => $channel['label'] ?? '',
                            'full_data' => [
                                'old' => $oldChannel,
                                'new' => $channel
                            ] // Vollständige Daten für Analyse
                        ],
                        ['old' => $oldValueStr, 'new' => $newValueStr]
                    );
                }
            }
            
            $this->eventPublisher->publish('org', $channel['org_uuid'], 'OrgCommunicationChannelUpdated', $channel);
        }
        
        return $channel;
    }
    
    public function deleteCommunicationChannel(string $channelUuid, ?string $userId = null): bool
    {
        $userId = $userId ?? 'default_user';
        $channel = $this->getCommunicationChannel($channelUuid);
        if (!$channel) {
            return false;
        }
        
        $orgUuid = $channel['org_uuid'];
        
        $stmt = $this->db->prepare("DELETE FROM org_communication_channel WHERE channel_uuid = :uuid");
        $stmt->execute(['uuid' => $channelUuid]);
        
        // Protokolliere im Audit-Trail
        $channelTypeLabels = [
            'email' => 'E-Mail',
            'phone' => 'Telefon',
            'mobile' => 'Mobil',
            'fax' => 'Fax',
            'website' => 'Website',
            'other' => 'Sonstiges'
        ];
        $channelType = $channelTypeLabels[$channel['channel_type']] ?? $channel['channel_type'];
        $channelValue = '';
        if ($channel['channel_type'] === 'email' && $channel['email_address']) {
            $channelValue = $channel['email_address'];
        } elseif (in_array($channel['channel_type'], ['phone', 'mobile', 'fax']) && $channel['number']) {
            $parts = [];
            if ($channel['country_code']) $parts[] = $channel['country_code'];
            if ($channel['area_code']) $parts[] = $channel['area_code'];
            if ($channel['number']) $parts[] = $channel['number'];
            $channelValue = implode(' ', $parts);
        }
        $channelDescription = $channelType;
        if ($channel['label']) $channelDescription .= ' (' . $channel['label'] . ')';
        if ($channelValue) $channelDescription .= ': ' . $channelValue;
        
        // Speichere sowohl menschenlesbare Werte als auch vollständige JSON-Daten
        $this->insertAuditEntry(
            $orgUuid,
            $userId,
            'delete',
            null,
            $channelDescription,
            'channel_removed',
            [
                'channel_uuid' => $channelUuid,
                'channel_type' => $channel['channel_type'],
                'label' => $channel['label'],
                'full_data' => $channel // Vollständige Daten für Analyse
            ],
            ['old' => $channelDescription]
        );
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgCommunicationChannelDeleted', ['channel_uuid' => $channelUuid]);
        
        return true;
    }
    
    /**
     * Formatiert eine Telefonnummer für die Anzeige
     */
    public function formatPhoneNumber(?string $countryCode, ?string $areaCode, ?string $number, ?string $extension = null): string
    {
        $parts = [];
        if ($countryCode) $parts[] = $countryCode;
        if ($areaCode) $parts[] = $areaCode;
        if ($number) $parts[] = $number;
        
        $formatted = implode(' ', $parts);
        if ($extension) {
            $formatted .= ' Durchwahl ' . $extension;
        }
        
        return $formatted ?: '';
    }
    
    // ============================================================================
    // AUDIT TRAIL
    // ============================================================================
    
    /**
     * Resolviert einen Feldwert zu seinem Klarnamen (z.B. UUID → Branchenname)
     * Wird für Audit-Trail verwendet
     */
    public function resolveFieldValue(string $field, $value): string
    {
        if (empty($value)) {
            return '(leer)';
        }
        
        // Branche (Hauptklasse)
        if ($field === 'industry_main_uuid' && $value) {
            $industry = $this->getIndustryByUuid($value);
            return $industry ? $industry['name'] : $value;
        }
        
        // Branche (Unterklasse)
        if ($field === 'industry_sub_uuid' && $value) {
            $industry = $this->getIndustryByUuid($value);
            return $industry ? $industry['name'] : $value;
        }
        
        // 3-stufige Hierarchie
        if ($field === 'industry_level1_uuid' && $value) {
            $industry = $this->getIndustryByUuid($value);
            return $industry ? $industry['name'] : $value;
        }
        
        if ($field === 'industry_level2_uuid' && $value) {
            $industry = $this->getIndustryByUuid($value);
            return $industry ? $industry['name'] : $value;
        }
        
        if ($field === 'industry_level3_uuid' && $value) {
            $industry = $this->getIndustryByUuid($value);
            return $industry ? $industry['name'] : $value;
        }
        
        // Account Owner
        if ($field === 'account_owner_user_id' && $value) {
            $owners = $this->getAvailableAccountOwnersWithNames();
            return $owners[$value] ?? $value;
        }
        
        // Status (mit Klarnamen)
        if ($field === 'status' && $value) {
            $statusLabels = [
                'lead' => 'Lead',
                'prospect' => 'Interessent',
                'customer' => 'Kunde',
                'inactive' => 'Inaktiv'
            ];
            return $statusLabels[$value] ?? $value;
        }
        
        // Org Kind (mit Klarnamen)
        if ($field === 'org_kind' && $value) {
            $kindLabels = [
                'customer' => 'Kunde',
                'supplier' => 'Lieferant',
                'consultant' => 'Berater',
                'engineering_firm' => 'Ingenieurbüro',
                'other' => 'Sonstiges'
            ];
            return $kindLabels[$value] ?? $value;
        }
        
        // Standard: Wert als String zurückgeben
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        
        return (string)$value;
    }
    
    /**
     * Formatiert einen Kommunikationskanal-Feldwert für die Anzeige
     */
    private function formatChannelFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '(leer)';
        }
        
        // Spezielle Formatierung für bestimmte Felder
        if ($field === 'is_primary') {
            return $value ? 'Ja' : 'Nein';
        }
        
        if ($field === 'is_public') {
            return $value ? 'Ja' : 'Nein';
        }
        
        if ($field === 'channel_type') {
            $types = [
                'email' => 'E-Mail',
                'phone' => 'Telefon',
                'mobile' => 'Mobil',
                'fax' => 'Fax',
                'website' => 'Website',
                'other' => 'Sonstiges'
            ];
            return $types[$value] ?? $value;
        }
        
        if ($field === 'country_code') {
            $countries = [
                'DE' => 'Deutschland',
                'AT' => 'Österreich',
                'CH' => 'Schweiz',
                'FR' => 'Frankreich',
                'IT' => 'Italien',
                'NL' => 'Niederlande',
                'BE' => 'Belgien',
                'PL' => 'Polen',
                'CZ' => 'Tschechien',
                'UK' => 'Vereinigtes Königreich'
            ];
            return $countries[$value] ?? $value;
        }
        
        return (string)$value;
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
            // Wenn 'new' vorhanden ist, verwende es direkt (sollte bereits formatiert sein)
            $newValue = is_array($additionalData['new']) || is_object($additionalData['new']) 
                ? json_encode($additionalData['new']) 
                : (string)$additionalData['new'];
        } elseif ($additionalData && isset($additionalData['old'])) {
            // Wenn nur 'old' vorhanden ist (z.B. bei Delete)
            $newValue = null;
        } elseif ($additionalData && !isset($additionalData['new']) && !isset($additionalData['old'])) {
            // Wenn additionalData direkt ein Objekt ist (z.B. alter Kanal), nicht als JSON speichern
            // Stattdessen nur in metadata speichern
            $newValue = null;
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
    
    /**
     * Holt das Audit-Trail für eine Organisation
     */
    public function getAuditTrail(string $orgUuid, int $limit = 100): array
    {
        return $this->auditTrailService->getAuditTrail('org', $orgUuid, $limit);
    }
    
    /**
     * Archiviert eine Organisation
     */
    public function archiveOrg(string $orgUuid, string $userId): array
    {
        $org = $this->getOrg($orgUuid);
        if (!$org) {
            throw new \Exception("Organisation nicht gefunden");
        }
        
        if ($org['archived_at']) {
            throw new \Exception("Organisation ist bereits archiviert");
        }
        
        $stmt = $this->db->prepare("
            UPDATE org 
            SET archived_at = NOW(), 
                archived_by_user_id = :user_id
            WHERE org_uuid = :org_uuid
        ");
        
        $stmt->execute([
            'org_uuid' => $orgUuid,
            'user_id' => $userId
        ]);
        
        $org = $this->getOrg($orgUuid);
        
        // Protokolliere im Audit-Trail
        if ($org) {
            $this->insertAuditEntry(
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
        $org = $this->getOrg($orgUuid);
        if (!$org) {
            throw new \Exception("Organisation nicht gefunden");
        }
        
        if (!$org['archived_at']) {
            throw new \Exception("Organisation ist nicht archiviert");
        }
        
        $oldArchivedAt = $org['archived_at'];
        $oldArchivedAtFormatted = 'Archiviert am ' . date('d.m.Y H:i', strtotime($oldArchivedAt));
        
        $stmt = $this->db->prepare("
            UPDATE org 
            SET archived_at = NULL, 
                archived_by_user_id = NULL
            WHERE org_uuid = :org_uuid
        ");
        
        $stmt->execute(['org_uuid' => $orgUuid]);
        
        $org = $this->getOrg($orgUuid);
        
        // Protokolliere im Audit-Trail
        if ($org) {
            $this->insertAuditEntry(
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


