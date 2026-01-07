<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Access\AccessTrackingService;
use TOM\Infrastructure\Database\TransactionHelper;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Service\BaseEntityService;
use TOM\Service\Person\PersonAffiliationService;
use TOM\Service\Person\PersonRelationshipService;

class PersonService extends BaseEntityService
{
    private AccessTrackingService $accessTrackingService;
    private PersonAffiliationService $affiliationService;
    private PersonRelationshipService $relationshipService;
    
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
        $this->accessTrackingService = new AccessTrackingService($this->db);
        $this->affiliationService = new PersonAffiliationService($this->db);
        $this->relationshipService = new PersonRelationshipService($this->db);
    }
    
    public function createPerson(array $data): array
    {
        // Prüfe auf E-Mail-Duplikat
        if (!empty($data['email'])) {
            $stmt = $this->db->prepare("SELECT person_uuid, first_name, last_name FROM person WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) AND is_active = 1");
            $stmt->execute(['email' => $data['email']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existingName = trim(($existing['first_name'] ?? '') . ' ' . ($existing['last_name'] ?? ''));
                throw new \InvalidArgumentException("Eine Person mit der E-Mail-Adresse '{$data['email']}' existiert bereits" . ($existingName ? " ({$existingName})" : ''));
            }
        }
        
        $uuid = UuidHelper::generate($this->db);
        $now = date('Y-m-d H:i:s');
        
        // Basis-Daten für Person
        // display_name ist eine GENERATED COLUMN und wird automatisch aus salutation, title, first_name, last_name generiert
        $personData = [
            'person_uuid' => $uuid,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'salutation' => $data['salutation'] ?? null,
            'title' => $data['title'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile_phone' => $data['mobile_phone'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'created_at' => $now,
            'updated_at' => $now
        ];
        
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO person (
                    person_uuid, first_name, last_name, salutation, title,
                    email, phone, mobile_phone, linkedin_url, notes, is_active, created_at, updated_at
                ) VALUES (
                    :person_uuid, :first_name, :last_name, :salutation, :title,
                    :email, :phone, :mobile_phone, :linkedin_url, :notes, :is_active, :created_at, :updated_at
                )
            ");
            $stmt->execute($personData);
            
            // Event-Publishing (nach Commit)
            $this->db->commit();
            
            // Protokolliere Erstellung im Audit-Trail (nach Commit)
            try {
                $userId = $this->getCurrentUserId(true); // Erlaube Fallback für Dev-Mode
                $this->logCreateAuditTrail('person', $uuid, $userId, $personData);
            } catch (\Exception $e) {
                // Audit-Fehler sollten nicht den Haupt-Flow blockieren
                error_log("Person audit trail error: " . $e->getMessage());
            }
            
            $this->publishEntityEvent('person', $uuid, 'PersonCreated', $personData);
            
            return $personData;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    public function getPerson(string $personUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM person WHERE person_uuid = :uuid");
        $stmt->execute(['uuid' => $personUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public function updatePerson(string $personUuid, array $data): array
    {
        // Prüfe ob Person existiert
        $existing = $this->getPerson($personUuid);
        if (!$existing) {
            throw new \RuntimeException("Person nicht gefunden: {$personUuid}");
        }
        
        // Prüfe auf E-Mail-Duplikat (nur wenn E-Mail geändert wird und nicht leer ist)
        if (!empty($data['email']) && $data['email'] !== $existing['email']) {
            $stmt = $this->db->prepare("SELECT person_uuid FROM person WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) AND is_active = 1 AND person_uuid != :uuid");
            $stmt->execute(['email' => $data['email'], 'uuid' => $personUuid]);
            $duplicate = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($duplicate) {
                throw new \InvalidArgumentException("Eine Person mit der E-Mail-Adresse '{$data['email']}' existiert bereits");
            }
        }
        
        $now = date('Y-m-d H:i:s');
        
        // Aktualisiere nur gesetzte Felder
        $updateFields = [];
        $updateValues = ['uuid' => $personUuid];
        
        // display_name ist eine GENERATED COLUMN und kann nicht direkt aktualisiert werden
        // Sie wird automatisch aus salutation, title, first_name, last_name generiert
        $allowedFields = ['first_name', 'last_name', 'salutation', 'title', 'email', 'phone', 'mobile_phone', 'linkedin_url', 'notes', 'is_active'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[] = "{$field} = :{$field}";
                $updateValues[$field] = $field === 'is_active' ? (int)$data[$field] : ($data[$field] ?? null);
            }
        }
        
        if (empty($updateFields)) {
            return $existing; // Keine Änderungen
        }
        
        $updateFields[] = "updated_at = :updated_at";
        $updateValues['updated_at'] = $now;
        
        $this->db->beginTransaction();
        try {
            $sql = "UPDATE person SET " . implode(', ', $updateFields) . " WHERE person_uuid = :uuid";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($updateValues);
            
            // Lade aktualisierte Daten
            $newData = $this->getPerson($personUuid);
            
            // Event-Publishing (nach Commit)
            $this->db->commit();
            
            // Protokolliere Änderungen im Audit-Trail (nach Commit)
            try {
                $userId = $this->getCurrentUserId(true); // Erlaube Fallback für Dev-Mode
                $this->logUpdateAuditTrail('person', $personUuid, $userId, $existing, $newData);
            } catch (\Exception $e) {
                // Audit-Fehler sollten nicht den Haupt-Flow blockieren
                error_log("Person audit trail error: " . $e->getMessage());
            }
            
            // Event-Publishing (nach Commit)
            $this->publishEntityEvent('person', $personUuid, 'PersonUpdated', $newData);
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        
        return $newData;
    }
    
    public function listPersons(?bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM person";
        $params = [];
        
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        
        $sql .= " ORDER BY last_name, first_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    public function searchPersons(string $query, ?bool $activeOnly = true): array
    {
        if (SearchQueryHelper::isEmpty($query)) {
            return [];
        }
        
        $terms = SearchQueryHelper::prepareSearchTerms($query);
        $fields = ['first_name', 'last_name', 'email', 'display_name'];
        
        $sql = "
            SELECT * FROM person
            WHERE " . SearchQueryHelper::buildLikeCondition($fields, 'search') . "
        ";
        
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        
        $sql .= " ORDER BY last_name, first_name LIMIT 50";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['search' => $terms['search']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Holt alle Personen einer Organisation über person_affiliation
     */
    public function listPersonsByOrg(string $orgUuid, ?bool $includeInactive = false): array
    {
        $sql = "
            SELECT DISTINCT
                p.*,
                pa.is_primary,
                pa.since_date as affiliation_since,
                pa.until_date as affiliation_until
            FROM person p
            INNER JOIN person_affiliation pa ON pa.person_uuid = p.person_uuid
            WHERE pa.org_uuid = :org_uuid
        ";
        
        if (!$includeInactive) {
            // Nur aktive Personen und aktive Affiliations
            $sql .= " AND p.is_active = 1";
            $sql .= " AND (pa.until_date IS NULL OR pa.until_date >= CURDATE())";
        }
        
        $sql .= " ORDER BY pa.is_primary DESC, p.last_name, p.first_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['org_uuid' => $orgUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    // ============================================================================
    // AFFILIATION MANAGEMENT (delegiert an PersonAffiliationService)
    // ============================================================================
    
    /**
     * Erstellt eine Person-Affiliation (Person arbeitet bei Organisation)
     */
    public function createAffiliation(array $data): array
    {
        return $this->affiliationService->createAffiliation($data);
    }
    
    public function getAffiliation(string $affiliationUuid): ?array
    {
        return $this->affiliationService->getAffiliation($affiliationUuid);
    }
    
    public function getPersonAffiliations(string $personUuid, ?bool $activeOnly = true): array
    {
        return $this->affiliationService->getPersonAffiliations($personUuid, $activeOnly);
    }
    
    public function updateAffiliation(string $affiliationUuid, array $data): array
    {
        return $this->affiliationService->updateAffiliation($affiliationUuid, $data);
    }
    
    public function deleteAffiliation(string $affiliationUuid): bool
    {
        return $this->affiliationService->deleteAffiliation($affiliationUuid);
    }
    
    // ============================================================================
    // RELATIONSHIP MANAGEMENT (delegiert an PersonRelationshipService)
    // ============================================================================
    
    public function createRelationship(array $data): array
    {
        return $this->relationshipService->createRelationship($data);
    }
    
    public function getRelationship(string $relationshipUuid): ?array
    {
        return $this->relationshipService->getRelationship($relationshipUuid);
    }
    
    public function getPersonRelationships(string $personUuid, ?bool $activeOnly = true): array
    {
        return $this->relationshipService->getPersonRelationships($personUuid, $activeOnly);
    }
    
    public function deleteRelationship(string $relationshipUuid): bool
    {
        return $this->relationshipService->deleteRelationship($relationshipUuid);
    }
    
    // ============================================================================
    // ORG UNIT MANAGEMENT
    // ============================================================================
    
    public function getOrgUnits(string $orgUuid): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM org_unit
            WHERE org_uuid = :org_uuid
            ORDER BY name
        ");
        $stmt->execute(['org_uuid' => $orgUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    public function createOrgUnit(array $data): array
    {
        $uuid = UuidHelper::generate($this->db);
        $now = date('Y-m-d H:i:s');
        
        $orgUnitData = [
            'org_unit_uuid' => $uuid,
            'org_uuid' => $data['org_uuid'],
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'parent_org_unit_uuid' => $data['parent_org_unit_uuid'] ?? null,
            'created_at' => $now,
            'updated_at' => $now
        ];
        
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO org_unit (
                    org_unit_uuid, org_uuid, name, description, parent_org_unit_uuid, created_at, updated_at
                ) VALUES (
                    :org_unit_uuid, :org_uuid, :name, :description, :parent_org_unit_uuid, :created_at, :updated_at
                )
            ");
            $stmt->execute($orgUnitData);
            
            $this->db->commit();
            return $orgUnitData;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    public function getOrgUnit(string $orgUnitUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_unit WHERE org_unit_uuid = :uuid");
        $stmt->execute(['uuid' => $orgUnitUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    // ============================================================================
    // AUDIT TRAIL
    // ============================================================================
    
    public function getAuditTrail(string $personUuid, int $limit = 100): array
    {
        return $this->auditTrailService->getAuditTrail('person', $personUuid, $limit);
    }
    
    // ============================================================================
    // FIELD RESOLUTION (für Event-Publishing)
    // ============================================================================
    
    public function resolveFieldValue(string $field, $value): string
    {
        // Spezielle Behandlung für Person-Felder
        switch ($field) {
            case 'person_uuid':
                return $value ?? '';
            case 'display_name':
                return $value ?? 'Unbekannt';
            case 'email':
                return $value ?? '';
            default:
                return parent::resolveFieldValue($field, $value);
        }
    }
    
    // ============================================================================
    // ACCESS TRACKING
    // ============================================================================
    
    public function trackAccess(string $userId, string $personUuid, string $accessType = 'recent'): void
    {
        $this->accessTrackingService->trackAccess('person', $userId, $personUuid, $accessType);
    }
    
    public function getRecentPersons(string $userId, int $limit = 10): array
    {
        return $this->accessTrackingService->getRecentEntities('person', $userId, $limit);
    }
}
