<?php
declare(strict_types=1);

namespace TOM\Service\Org\Core;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Utils\UrlHelper;
use TOM\Service\BaseEntityService;

/**
 * OrgCrudService
 * 
 * Handles core CRUD operations for organizations:
 * - Create organization with duplicate checking
 * - Get organization
 * - Update organization
 */
class OrgCrudService extends BaseEntityService
{
    private $customerNumberGenerator;
    private $fieldResolver;
    
    /**
     * @param PDO|null $db
     * @param callable|null $customerNumberGenerator Callback to generate customer number
     * @param callable|null $fieldResolver Callback to resolve field values for audit trail
     */
    public function __construct(
        ?PDO $db = null,
        ?callable $customerNumberGenerator = null,
        ?callable $fieldResolver = null
    ) {
        parent::__construct($db);
        $this->customerNumberGenerator = $customerNumberGenerator;
        $this->fieldResolver = $fieldResolver;
    }
    
    /**
     * Creates a new organization
     */
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
        if (empty($data['external_ref']) && $this->customerNumberGenerator) {
            $data['external_ref'] = call_user_func($this->customerNumberGenerator);
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
            $fieldResolver = $this->fieldResolver ?? null;
            $this->logCreateAuditTrail('org', $uuid, $userId ?? null, $org, $fieldResolver);
        }
        
        // Event-Publishing (zentralisiert)
        if ($org) {
            $this->publishEntityEvent('org', $org['org_uuid'], 'OrgCreated', $org);
        }
        
        return $org;
    }
    
    /**
     * Gets a single organization by UUID
     */
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
    
    /**
     * Updates an organization
     */
    public function updateOrg(string $orgUuid, array $data, ?string $userId = null, ?callable $fieldResolver = null): array
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
            $resolver = $fieldResolver ?? $this->fieldResolver ?? null;
            $this->logUpdateAuditTrail('org', $orgUuid, $userId ?? null, $oldOrg, $org, $resolver);
        }
        
        // Event-Publishing (zentralisiert)
        if ($org) {
            $this->publishEntityEvent('org', $org['org_uuid'], 'OrgUpdated', $org);
        }
        
        return $org ?: [];
    }
}

