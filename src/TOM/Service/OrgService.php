<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Utils\UrlHelper;

class OrgService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
    }
    
    public function createOrg(array $data, ?string $userId = null): array
    {
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
                industry, revenue_range, employee_count, website, notes, status,
                account_owner_user_id, account_owner_since
            )
            VALUES (
                :org_uuid, :name, :org_kind, :external_ref,
                :industry, :revenue_range, :employee_count, :website, :notes, :status,
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
            'revenue_range' => $data['revenue_range'] ?? null,
            'employee_count' => $data['employee_count'] ?? null,
            'website' => $data['website'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $status,
            'account_owner_user_id' => $data['account_owner_user_id'] ?? null,
            'account_owner_since' => $accountOwnerSince
        ]);
        
        $org = $this->getOrg($uuid);
        
        // Protokolliere Erstellung im Audit-Trail
        if ($org && $userId) {
            $this->logAuditTrail($uuid, $userId, 'create', null, $org, $data);
        }
        
        $this->eventPublisher->publish('org', $org['org_uuid'], 'OrgCreated', $org);
        
        return $org;
    }
    
    public function getOrg(string $orgUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                o.*,
                i_main.name as industry_main_name,
                i_sub.name as industry_sub_name
            FROM org o
            LEFT JOIN industry i_main ON o.industry_main_uuid = i_main.industry_uuid
            LEFT JOIN industry i_sub ON o.industry_sub_uuid = i_sub.industry_uuid
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
        
        $allowedFields = ['name', 'org_kind', 'external_ref', 'industry', 'industry_main_uuid', 'industry_sub_uuid', 'revenue_range', 'employee_count', 'website', 'notes', 'status', 'account_owner_user_id', 'account_owner_since'];
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
        
        // Protokolliere Änderungen im Audit-Trail
        if ($org && $oldOrg && $userId) {
            $this->logAuditTrail($orgUuid, $userId, 'update', $oldOrg, $org, $data);
        }
        
        if ($org) {
            $this->eventPublisher->publish('org', $org['org_uuid'], 'OrgUpdated', $org);
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
        // Lösche alte Einträge (nur für 'recent', behalte max. 10)
        if ($accessType === 'recent') {
            $stmt = $this->db->prepare("
                DELETE FROM user_org_access 
                WHERE user_id = :user_id AND access_type = 'recent'
                AND org_uuid NOT IN (
                    SELECT org_uuid FROM (
                        SELECT org_uuid FROM user_org_access
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
            SELECT access_uuid FROM user_org_access 
            WHERE user_id = :user_id AND org_uuid = :org_uuid AND access_type = :access_type
        ");
        $stmt->execute([
            'user_id' => $userId,
            'org_uuid' => $orgUuid,
            'access_type' => $accessType
        ]);
        
        if ($stmt->fetch()) {
            // Update timestamp
            $stmt = $this->db->prepare("
                UPDATE user_org_access 
                SET accessed_at = NOW() 
                WHERE user_id = :user_id AND org_uuid = :org_uuid AND access_type = :access_type
            ");
            $stmt->execute([
                'user_id' => $userId,
                'org_uuid' => $orgUuid,
                'access_type' => $accessType
            ]);
        } else {
            // Neuer Eintrag
            $uuid = UuidHelper::generate($this->db);
            $stmt = $this->db->prepare("
                INSERT INTO user_org_access (access_uuid, user_id, org_uuid, access_type)
                VALUES (:access_uuid, :user_id, :org_uuid, :access_type)
            ");
            $stmt->execute([
                'access_uuid' => $uuid,
                'user_id' => $userId,
                'org_uuid' => $orgUuid,
                'access_type' => $accessType
            ]);
        }
    }
    
    public function getRecentOrgs(string $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT o.*, uoa.accessed_at
            FROM org o
            INNER JOIN user_org_access uoa ON o.org_uuid = uoa.org_uuid
            WHERE uoa.user_id = :user_id AND uoa.access_type = 'recent'
            ORDER BY uoa.accessed_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
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
        if (empty(trim($query)) && empty($filters)) {
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
        
        // Filter nach Branche (über industry_main_uuid oder industry_sub_uuid)
        if (!empty($filters['industry'])) {
            $sql .= " AND (o.industry_main_uuid = :industry OR o.industry_sub_uuid = :industry)";
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
    // ADDRESS MANAGEMENT
    // ============================================================================
    
    public function addAddress(string $orgUuid, array $data): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_address (
                address_uuid, org_uuid, address_type, street, address_additional, city, postal_code, 
                country, state, latitude, longitude, is_default, notes
            )
            VALUES (
                :address_uuid, :org_uuid, :address_type, :street, :address_additional, :city, :postal_code,
                :country, :state, :latitude, :longitude, :is_default, :notes
            )
        ");
        
        // Wenn diese Adresse als default markiert wird, entferne Default von anderen Adressen
        if (!empty($data['is_default'])) {
            $this->db->prepare("UPDATE org_address SET is_default = 0 WHERE org_uuid = :org_uuid")
                ->execute(['org_uuid' => $orgUuid]);
        }
        
        $stmt->execute([
            'address_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'address_type' => $data['address_type'] ?? 'other',
            'street' => $data['street'] ?? null,
            'address_additional' => $data['address_additional'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'state' => $data['state'] ?? null,
            'latitude' => isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null,
            'longitude' => isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null,
            'is_default' => $data['is_default'] ?? 0,
            'notes' => $data['notes'] ?? null
        ]);
        
        $address = $this->getAddress($uuid);
        $this->eventPublisher->publish('org', $orgUuid, 'OrgAddressAdded', $address);
        
        return $address;
    }
    
    public function getAddress(string $addressUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_address WHERE address_uuid = :uuid");
        $stmt->execute(['uuid' => $addressUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function getAddresses(string $orgUuid, ?string $addressType = null): array
    {
        $sql = "SELECT * FROM org_address WHERE org_uuid = :org_uuid";
        $params = ['org_uuid' => $orgUuid];
        
        if ($addressType) {
            $sql .= " AND address_type = :address_type";
            $params['address_type'] = $addressType;
        }
        
        $sql .= " ORDER BY is_default DESC, address_type, city";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateAddress(string $addressUuid, array $data): array
    {
        $allowed = ['address_type', 'street', 'address_additional', 'city', 'postal_code', 'country', 'state', 'latitude', 'longitude', 'is_default', 'notes'];
        $updates = [];
        $params = ['uuid' => $addressUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                // Spezielle Behandlung für Koordinaten (können null sein)
                if (in_array($field, ['latitude', 'longitude'])) {
                    $params[$field] = ($data[$field] !== null && $data[$field] !== '') ? (float)$data[$field] : null;
                } else {
                    $params[$field] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return $this->getAddress($addressUuid);
        }
        
        // Wenn diese Adresse als default markiert wird, entferne Default von anderen
        if (isset($data['is_default']) && $data['is_default']) {
            $address = $this->getAddress($addressUuid);
            if ($address) {
                $this->db->prepare("UPDATE org_address SET is_default = 0 WHERE org_uuid = :org_uuid AND address_uuid != :address_uuid")
                    ->execute(['org_uuid' => $address['org_uuid'], 'address_uuid' => $addressUuid]);
            }
        }
        
        $sql = "UPDATE org_address SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE address_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $address = $this->getAddress($addressUuid);
        if ($address) {
            $this->eventPublisher->publish('org', $address['org_uuid'], 'OrgAddressUpdated', $address);
        }
        
        return $address;
    }
    
    public function deleteAddress(string $addressUuid): bool
    {
        $address = $this->getAddress($addressUuid);
        if (!$address) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM org_address WHERE address_uuid = :uuid");
        $stmt->execute(['uuid' => $addressUuid]);
        
        $this->eventPublisher->publish('org', $address['org_uuid'], 'OrgAddressDeleted', ['address_uuid' => $addressUuid]);
        
        return true;
    }
    
    // ============================================================================
    // VAT REGISTRATION (USt-ID) MANAGEMENT
    // ============================================================================
    
    /**
     * Fügt eine USt-ID-Registrierung für eine Organisation hinzu
     * address_uuid ist optional (nur für Kontext)
     */
    public function addVatRegistration(string $orgUuid, array $data): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        // Wenn diese USt-ID als primary_for_country markiert wird, entferne Primary von anderen
        if (!empty($data['is_primary_for_country'])) {
            $stmt = $this->db->prepare("
                UPDATE org_vat_registration 
                SET is_primary_for_country = 0 
                WHERE org_uuid = :org_uuid 
                  AND country_code = :country_code
                  AND (valid_to IS NULL OR valid_to >= CURDATE())
            ");
            $stmt->execute([
                'org_uuid' => $orgUuid,
                'country_code' => $data['country_code']
            ]);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO org_vat_registration (
                vat_registration_uuid, org_uuid, address_uuid, vat_id, country_code,
                valid_from, valid_to, is_primary_for_country, location_type, notes
            )
            VALUES (
                :vat_registration_uuid, :org_uuid, :address_uuid, :vat_id, :country_code,
                :valid_from, :valid_to, :is_primary_for_country, :location_type, :notes
            )
        ");
        
        $stmt->execute([
            'vat_registration_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'address_uuid' => $data['address_uuid'] ?? null,
            'vat_id' => $data['vat_id'],
            'country_code' => $data['country_code'],
            'valid_from' => $data['valid_from'] ?? date('Y-m-d'),
            'valid_to' => $data['valid_to'] ?? null,
            'is_primary_for_country' => $data['is_primary_for_country'] ?? 0,
            'location_type' => $data['location_type'] ?? null,
            'notes' => $data['notes'] ?? null
        ]);
        
        $vatReg = $this->getVatRegistration($uuid);
        $this->eventPublisher->publish('org', $orgUuid, 'OrgVatRegistrationAdded', $vatReg);
        
        return $vatReg;
    }
    
    /**
     * Holt eine USt-ID-Registrierung
     */
    public function getVatRegistration(string $vatRegistrationUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT vr.*, 
                   oa.street, oa.city, oa.country, oa.location_type
            FROM org_vat_registration vr
            LEFT JOIN org_address oa ON vr.address_uuid = oa.address_uuid
            WHERE vr.vat_registration_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $vatRegistrationUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Holt alle USt-ID-Registrierungen einer Organisation
     */
    public function getVatRegistrations(string $orgUuid, bool $onlyValid = true): array
    {
        $sql = "
            SELECT vr.*, 
                   oa.street, oa.city, oa.country, oa.location_type
            FROM org_vat_registration vr
            LEFT JOIN org_address oa ON vr.address_uuid = oa.address_uuid
            WHERE vr.org_uuid = :org_uuid
        ";
        
        if ($onlyValid) {
            $sql .= " AND (vr.valid_to IS NULL OR vr.valid_to >= CURDATE())";
        }
        
        $sql .= " ORDER BY vr.country_code, vr.is_primary_for_country DESC, vr.valid_from DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['org_uuid' => $orgUuid]);
        return $stmt->fetchAll();
    }
    
    /**
     * Holt die USt-ID für eine bestimmte Adresse (optional, für Kontext)
     */
    public function getVatIdForAddress(string $addressUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT vr.*
            FROM org_vat_registration vr
            WHERE vr.address_uuid = :address_uuid
              AND (vr.valid_to IS NULL OR vr.valid_to >= CURDATE())
            ORDER BY vr.is_primary_for_country DESC, vr.valid_from DESC
            LIMIT 1
        ");
        $stmt->execute(['address_uuid' => $addressUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Aktualisiert eine USt-ID-Registrierung
     */
    public function updateVatRegistration(string $vatRegistrationUuid, array $data): array
    {
        $allowed = ['vat_id', 'country_code', 'valid_from', 'valid_to', 'is_primary_for_country', 'location_type', 'notes'];
        $updates = [];
        $params = ['uuid' => $vatRegistrationUuid];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                // Spezielle Behandlung für NULL-Werte (z.B. valid_to = null für "aktuell gültig")
                if ($data[$field] === null || $data[$field] === '') {
                    if (in_array($field, ['valid_to', 'location_type', 'notes'])) {
                        // Verwende COALESCE oder direkt NULL in SQL
                        $updates[] = "$field = NULL";
                    }
                    // Für andere Felder wird der leere Wert ignoriert
                } else {
                    $updates[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return $this->getVatRegistration($vatRegistrationUuid);
        }
        
        // Wenn is_primary_for_country gesetzt wird, entferne Primary von anderen
        if (isset($data['is_primary_for_country']) && $data['is_primary_for_country']) {
            $vatReg = $this->getVatRegistration($vatRegistrationUuid);
            if ($vatReg) {
                $stmt = $this->db->prepare("
                    UPDATE org_vat_registration 
                    SET is_primary_for_country = 0 
                    WHERE org_uuid = :org_uuid 
                      AND country_code = :country_code
                      AND vat_registration_uuid != :vat_uuid
                      AND (valid_to IS NULL OR valid_to >= CURDATE())
                ");
                $stmt->execute([
                    'org_uuid' => $vatReg['org_uuid'],
                    'country_code' => $data['country_code'] ?? $vatReg['country_code'],
                    'vat_uuid' => $vatRegistrationUuid
                ]);
            }
        }
        
        // Prüfe nochmal, ob nach der Verarbeitung noch Updates vorhanden sind
        if (empty($updates)) {
            return $this->getVatRegistration($vatRegistrationUuid);
        }
        
        $sql = "UPDATE org_vat_registration SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE vat_registration_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        
        if (!$stmt) {
            throw new \Exception("Failed to prepare SQL statement: " . implode(', ', $this->db->errorInfo()));
        }
        
        $result = $stmt->execute($params);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new \Exception("Failed to execute SQL: " . ($errorInfo[2] ?? 'Unknown error'));
        }
        
        // Hinweis: rowCount() kann bei MySQL/MariaDB 0 zurückgeben, auch wenn die Query erfolgreich war
        // (z.B. wenn die Daten unverändert sind). Das ist kein Fehler.
        $affectedRows = $stmt->rowCount();
        
        // Hole die aktualisierten Daten (unabhängig von rowCount)
        $vatReg = $this->getVatRegistration($vatRegistrationUuid);
        
        if (!$vatReg) {
            // Fallback: Direkt aus der DB lesen (ohne JOIN)
            $fallbackStmt = $this->db->prepare("SELECT * FROM org_vat_registration WHERE vat_registration_uuid = :uuid");
            $fallbackStmt->execute(['uuid' => $vatRegistrationUuid]);
            $vatReg = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vatReg) {
                throw new \Exception("VAT registration not found after update (vat_registration_uuid: $vatRegistrationUuid, affected rows: $affectedRows)");
            }
            
            // Füge leere Adress-Felder hinzu für Konsistenz
            $vatReg['street'] = null;
            $vatReg['city'] = null;
            $vatReg['country'] = null;
        }
        
        $this->eventPublisher->publish('org', $vatReg['org_uuid'], 'OrgVatRegistrationUpdated', $vatReg);
        
        return $vatReg;
    }
    
    /**
     * Löscht eine USt-ID-Registrierung
     */
    public function deleteVatRegistration(string $vatRegistrationUuid): bool
    {
        $vatReg = $this->getVatRegistration($vatRegistrationUuid);
        if (!$vatReg) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM org_vat_registration WHERE vat_registration_uuid = :uuid");
        $stmt->execute(['uuid' => $vatRegistrationUuid]);
        
        $this->eventPublisher->publish('org', $vatReg['org_uuid'], 'OrgVatRegistrationDeleted', ['vat_registration_uuid' => $vatRegistrationUuid]);
        
        return true;
    }
    
    // ============================================================================
    // RELATION MANAGEMENT
    // ============================================================================
    
    public function addRelation(array $data, ?string $userId = null): array
    {
        $uuid = UuidHelper::generate($this->db);
        $userId = $userId ?? 'default_user';
        
        $stmt = $this->db->prepare("
            INSERT INTO org_relation (
                relation_uuid, parent_org_uuid, child_org_uuid, relation_type,
                ownership_percent, since_date, until_date, notes,
                has_voting_rights, is_direct, source, confidence, tags, is_current
            )
            VALUES (
                :relation_uuid, :parent_org_uuid, :child_org_uuid, :relation_type,
                :ownership_percent, :since_date, :until_date, :notes,
                :has_voting_rights, :is_direct, :source, :confidence, :tags, :is_current
            )
        ");
        
        $stmt->execute([
            'relation_uuid' => $uuid,
            'parent_org_uuid' => $data['parent_org_uuid'],
            'child_org_uuid' => $data['child_org_uuid'],
            'relation_type' => $data['relation_type'] ?? 'subsidiary_of',
            'ownership_percent' => $data['ownership_percent'] ?? null,
            'since_date' => $data['since_date'] ?? null,
            'until_date' => $data['until_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'has_voting_rights' => isset($data['has_voting_rights']) ? (int)$data['has_voting_rights'] : 0,
            'is_direct' => isset($data['is_direct']) ? (int)$data['is_direct'] : 1,
            'source' => $data['source'] ?? null,
            'confidence' => $data['confidence'] ?? 'high',
            'tags' => $data['tags'] ?? null,
            'is_current' => isset($data['is_current']) ? (int)$data['is_current'] : 1
        ]);
        
        $relation = $this->getRelation($uuid);
        
        // Protokolliere im Audit-Trail
        if ($relation) {
            $this->insertAuditEntry(
                $data['parent_org_uuid'],
                $userId,
                'create',
                null,
                null,
                'relation_added',
                [
                    'relation_uuid' => $uuid,
                    'child_org_uuid' => $data['child_org_uuid'],
                    'relation_type' => $relation['relation_type'],
                    'child_org_name' => $relation['child_org_name'] ?? null
                ],
                $relation
            );
        }
        
        $this->eventPublisher->publish('org', $data['parent_org_uuid'], 'OrgRelationAdded', $relation);
        
        return $relation;
    }
    
    public function getRelation(string $relationUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                r.*,
                parent.name as parent_org_name,
                child.name as child_org_name
            FROM org_relation r
            LEFT JOIN org parent ON r.parent_org_uuid = parent.org_uuid
            LEFT JOIN org child ON r.child_org_uuid = child.org_uuid
            WHERE r.relation_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $relationUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function getRelations(string $orgUuid, ?string $direction = null): array
    {
        // direction: 'parent' = wo ist orgUuid Kind, 'child' = wo ist orgUuid Parent, null = beide
        $sql = "
            SELECT 
                r.*,
                parent.name as parent_org_name,
                child.name as child_org_name
            FROM org_relation r
            LEFT JOIN org parent ON r.parent_org_uuid = parent.org_uuid
            LEFT JOIN org child ON r.child_org_uuid = child.org_uuid
            WHERE 1=1
        ";
        $params = [];
        
        if ($direction === 'parent') {
            $sql .= " AND r.child_org_uuid = :org_uuid";
            $params['org_uuid'] = $orgUuid;
        } elseif ($direction === 'child') {
            $sql .= " AND r.parent_org_uuid = :org_uuid";
            $params['org_uuid'] = $orgUuid;
        } else {
            $sql .= " AND (r.parent_org_uuid = :org_uuid OR r.child_org_uuid = :org_uuid)";
            $params['org_uuid'] = $orgUuid;
        }
        
        // Nur aktuelle Relationen (is_current = 1 und until_date ist NULL oder in der Zukunft)
        $sql .= " AND r.is_current = 1 AND (r.until_date IS NULL OR r.until_date >= CURDATE())";
        $sql .= " ORDER BY r.relation_type, r.since_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateRelation(string $relationUuid, array $data, ?string $userId = null): array
    {
        $userId = $userId ?? 'default_user';
        $oldRelation = $this->getRelation($relationUuid);
        
        if (!$oldRelation) {
            throw new \Exception("Relation nicht gefunden");
        }
        
        $allowed = [
            'relation_type', 'ownership_percent', 'since_date', 'until_date', 'notes',
            'has_voting_rights', 'is_direct', 'source', 'confidence', 'tags', 'is_current'
        ];
        $updates = [];
        $params = ['uuid' => $relationUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['has_voting_rights', 'is_direct', 'is_current'])) {
                    $params[$field] = (int)$data[$field];
                } else {
                    $params[$field] = $data[$field];
                }
                $updates[] = "$field = :$field";
            }
        }
        
        if (empty($updates)) {
            return $oldRelation;
        }
        
        $sql = "UPDATE org_relation SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE relation_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $relation = $this->getRelation($relationUuid);
        if ($relation) {
            // Protokolliere Änderungen im Audit-Trail
            $changedFields = [];
            foreach ($allowed as $field) {
                $oldValue = $oldRelation[$field] ?? null;
                $newValue = $relation[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $changedFields[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
            
            if (!empty($changedFields)) {
                $this->insertAuditEntry(
                    $relation['parent_org_uuid'],
                    $userId,
                    'update',
                    null,
                    null,
                    'relation_updated',
                    [
                        'relation_uuid' => $relationUuid,
                        'child_org_uuid' => $relation['child_org_uuid'],
                        'child_org_name' => $relation['child_org_name'] ?? null,
                        'changed_fields' => $changedFields
                    ],
                    $changedFields
                );
            }
            
            $this->eventPublisher->publish('org', $relation['parent_org_uuid'], 'OrgRelationUpdated', $relation);
        }
        
        return $relation;
    }
    
    public function deleteRelation(string $relationUuid, ?string $userId = null): bool
    {
        $userId = $userId ?? 'default_user';
        $relation = $this->getRelation($relationUuid);
        if (!$relation) {
            return false;
        }
        
        $parentOrgUuid = $relation['parent_org_uuid'];
        $childOrgUuid = $relation['child_org_uuid'];
        $childOrgName = $relation['child_org_name'] ?? null;
        $relationType = $relation['relation_type'];
        
        $stmt = $this->db->prepare("DELETE FROM org_relation WHERE relation_uuid = :uuid");
        $stmt->execute(['uuid' => $relationUuid]);
        
        // Protokolliere im Audit-Trail
        $this->insertAuditEntry(
            $parentOrgUuid,
            $userId,
            'delete',
            null,
            null,
            'relation_removed',
            [
                'relation_uuid' => $relationUuid,
                'child_org_uuid' => $childOrgUuid,
                'child_org_name' => $childOrgName,
                'relation_type' => $relationType
            ],
            $relation
        );
        
        $this->eventPublisher->publish('org', $parentOrgUuid, 'OrgRelationDeleted', ['relation_uuid' => $relationUuid]);
        
        return true;
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
    
    public function addCommunicationChannel(string $orgUuid, array $data): array
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
    
    public function updateCommunicationChannel(string $channelUuid, array $data): array
    {
        $allowed = ['channel_type', 'country_code', 'area_code', 'number', 'extension', 
                   'email_address', 'label', 'is_primary', 'is_public', 'notes'];
        $updates = [];
        $params = ['uuid' => $channelUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[$field] = $data[$field];
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
        if ($channel) {
            $this->eventPublisher->publish('org', $channel['org_uuid'], 'OrgCommunicationChannelUpdated', $channel);
        }
        
        return $channel;
    }
    
    public function deleteCommunicationChannel(string $channelUuid): bool
    {
        $channel = $this->getCommunicationChannel($channelUuid);
        if (!$channel) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM org_communication_channel WHERE channel_uuid = :uuid");
        $stmt->execute(['uuid' => $channelUuid]);
        
        $this->eventPublisher->publish('org', $channel['org_uuid'], 'OrgCommunicationChannelDeleted', ['channel_uuid' => $channelUuid]);
        
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
     * Protokolliert Änderungen im Audit-Trail
     */
    private function logAuditTrail(string $orgUuid, string $userId, string $action, ?array $oldData, ?array $newData, ?array $changedFields = null): void
    {
        if (!$oldData && $action !== 'create') {
            return; // Keine alte Daten vorhanden (außer bei Erstellung)
        }
        
        if ($action === 'create') {
            // Bei Erstellung: Protokolliere alle initialen Werte
            $this->insertAuditEntry($orgUuid, $userId, 'create', null, null, 'org_created', null, $newData);
        } elseif ($action === 'update' && $oldData && $newData) {
            // Bei Update: Protokolliere nur geänderte Felder
            $allowedFields = ['name', 'org_kind', 'external_ref', 'industry', 'industry_main_uuid', 'industry_sub_uuid', 'revenue_range', 'employee_count', 'website', 'notes', 'status', 'account_owner_user_id', 'account_owner_since'];
            
            foreach ($allowedFields as $field) {
                $oldValue = $oldData[$field] ?? null;
                $newValue = $newData[$field] ?? null;
                
                // Nur protokollieren, wenn sich der Wert geändert hat
                if ($oldValue !== $newValue && (isset($changedFields[$field]) || $changedFields === null)) {
                    // Resolviere Klarnamen für UUID-Felder
                    $oldValueStr = $this->resolveFieldValue($field, $oldValue);
                    $newValueStr = $this->resolveFieldValue($field, $newValue);
                    
                    $this->insertAuditEntry($orgUuid, $userId, 'update', $field, $oldValueStr, 'field_change', null, ['old' => $oldValueStr, 'new' => $newValueStr]);
                }
            }
        }
    }
    
    /**
     * Resolviert einen Feldwert zu seinem Klarnamen (z.B. UUID → Branchenname)
     */
    private function resolveFieldValue(string $field, $value): string
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
            $newValue = is_array($additionalData['new']) || is_object($additionalData['new']) 
                ? json_encode($additionalData['new']) 
                : (string)$additionalData['new'];
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
        $stmt = $this->db->prepare("
            SELECT 
                audit_id,
                org_uuid,
                user_id,
                action,
                field_name,
                old_value,
                new_value,
                change_type,
                metadata,
                created_at
            FROM org_audit_trail
            WHERE org_uuid = :org_uuid
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':org_uuid', $orgUuid, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $this->logAuditTrail($orgUuid, $userId, 'update', ['archived_at' => null], ['archived_at' => $org['archived_at']], ['archived_at' => $org['archived_at']]);
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
            $this->logAuditTrail($orgUuid, $userId, 'update', ['archived_at' => $oldArchivedAt], ['archived_at' => null], ['archived_at' => null]);
            $this->eventPublisher->publish('org', $org['org_uuid'], 'OrgUnarchived', $org);
        }
        
        return $org ?: [];
    }
}


