<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Access\AccessTrackingService;
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
        
        // Optional: Prüfe auf Name-Duplikat (als Warnung, nicht blockierend)
        // Diese Prüfung wird nur durchgeführt, wenn beide Namen vorhanden sind
        $nameWarning = null;
        if (!empty($data['first_name']) && !empty($data['last_name'])) {
            $stmt = $this->db->prepare("
                SELECT person_uuid, first_name, last_name, email 
                FROM person 
                WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name)) 
                AND LOWER(TRIM(last_name)) = LOWER(TRIM(:last_name))
                AND is_active = 1
            ");
            $stmt->execute([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name']
            ]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $existingEmail = $existing['email'] ?? 'keine E-Mail';
                $nameWarning = "Eine Person mit dem Namen '{$data['first_name']} {$data['last_name']}' existiert bereits (E-Mail: {$existingEmail}). Bitte prüfen Sie, ob es sich um eine Duplikat handelt.";
            }
        }
        
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO person (
                    person_uuid, first_name, last_name, salutation, title,
                    email, phone, mobile_phone, linkedin_url, notes, is_active
                )
                VALUES (
                    :person_uuid, :first_name, :last_name, :salutation, :title,
                    :email, :phone, :mobile_phone, :linkedin_url, :notes, :is_active
                )
            ");
            
            $stmt->execute([
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
                'is_active' => $data['is_active'] ?? 1
            ]);
        } catch (\PDOException $e) {
            // Falls UNIQUE Constraint verletzt wird (Fallback)
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                throw new \InvalidArgumentException("Eine Person mit der E-Mail-Adresse '{$data['email']}' existiert bereits.");
            }
            throw $e;
        }
        
        $person = $this->getPerson($uuid);
        if ($person) {
            // Audit-Trail: Erstellung protokollieren (zentralisiert)
            $this->logCreateAuditTrail('person', $uuid, null, $person, [$this, 'resolveFieldValue']);
            
            // Event-Publishing (zentralisiert)
            $this->publishEntityEvent('person', $person['person_uuid'], 'PersonCreated', $person);
        }
        
        return $person ?: [];
    }
    
    public function getPerson(string $personUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM person WHERE person_uuid = :uuid");
        $stmt->execute(['uuid' => $personUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function updatePerson(string $personUuid, array $data): array
    {
        // Hole alte Daten für Audit-Trail
        $oldData = $this->getPerson($personUuid);
        if (!$oldData) {
            throw new \InvalidArgumentException("Person nicht gefunden");
        }
        
        // Prüfe auf E-Mail-Duplikat (nur wenn E-Mail geändert wird)
        if (isset($data['email']) && $data['email'] !== ($oldData['email'] ?? null)) {
            if (!empty($data['email'])) {
                $stmt = $this->db->prepare("SELECT person_uuid, first_name, last_name FROM person WHERE LOWER(TRIM(email)) = LOWER(TRIM(:email)) AND person_uuid != :uuid AND is_active = 1");
                $stmt->execute(['email' => $data['email'], 'uuid' => $personUuid]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $existingName = trim(($existing['first_name'] ?? '') . ' ' . ($existing['last_name'] ?? ''));
                    throw new \InvalidArgumentException("Eine Person mit der E-Mail-Adresse '{$data['email']}' existiert bereits" . ($existingName ? " ({$existingName})" : ''));
                }
            }
        }
        
        $allowedFields = [
            'first_name', 'last_name', 'salutation', 'title',
            'email', 'phone', 'mobile_phone', 'linkedin_url', 'notes', 'is_active'
        ];
        $updates = [];
        $params = ['uuid' => $personUuid];
        $changedFields = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $oldValue = $oldData[$field] ?? null;
                $newValue = $data[$field];
                
                // Nur updaten, wenn sich der Wert geändert hat
                if ($oldValue != $newValue) {
                    $updates[] = "$field = :$field";
                    $params[$field] = $newValue;
                    $changedFields[$field] = true;
                }
            }
        }
        
        // Soft-Delete: Wenn is_active = 0, setze archived_at
        if (isset($data['is_active']) && $data['is_active'] == 0) {
            if (($oldData['is_active'] ?? 1) != 0) {
                $updates[] = "archived_at = NOW()";
                $changedFields['is_active'] = true;
            }
        } elseif (isset($data['is_active']) && $data['is_active'] == 1) {
            if (($oldData['is_active'] ?? 0) != 1) {
                $updates[] = "archived_at = NULL";
                $changedFields['is_active'] = true;
            }
        }
        
        if (empty($updates)) {
            return $oldData ?: [];
        }
        
        $sql = "UPDATE person SET " . implode(', ', $updates) . " WHERE person_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $newData = $this->getPerson($personUuid);
        if ($newData) {
            // Audit-Trail: Änderungen protokollieren (zentralisiert)
            $this->logUpdateAuditTrail('person', $personUuid, null, $oldData, $newData, [$this, 'resolveFieldValue']);
            
            // Event-Publishing (zentralisiert)
            $this->publishEntityEvent('person', $newData['person_uuid'], 'PersonUpdated', $newData);
        }
        
        return $newData ?: [];
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
        return $stmt->fetchAll();
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
        return $stmt->fetchAll();
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
    
    // ============================================================================
    // RELATIONSHIP MANAGEMENT (delegiert an PersonRelationshipService)
    // ============================================================================
    
    /**
     * Erstellt eine Person-zu-Person Beziehung
     */
    public function createRelationship(array $data): array
    {
        return $this->relationshipService->createRelationship($data);
    }
    
    /**
     * Holt eine Person-zu-Person Beziehung
     */
    public function getRelationship(string $relationshipUuid): ?array
    {
        return $this->relationshipService->getRelationship($relationshipUuid);
    }
    
    /**
     * Holt alle Beziehungen einer Person
     */
    public function getPersonRelationships(string $personUuid, ?bool $activeOnly = true): array
    {
        return $this->relationshipService->getPersonRelationships($personUuid, $activeOnly);
    }
    
    /**
     * Löscht eine Person-zu-Person Beziehung
     */
    public function deleteRelationship(string $relationshipUuid): bool
    {
        return $this->relationshipService->deleteRelationship($relationshipUuid);
    }
    
    /**
     * Holt alle Org Units einer Organisation
     */
    public function getOrgUnits(string $orgUuid): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM org_unit
            WHERE org_uuid = :org_uuid AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute(['org_uuid' => $orgUuid]);
        return $stmt->fetchAll();
    }
    
    /**
     * Erstellt eine Org Unit
     */
    public function createOrgUnit(array $data): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_unit (
                org_unit_uuid, org_uuid, parent_org_unit_uuid, name, code, is_active
            )
            VALUES (
                :org_unit_uuid, :org_uuid, :parent_org_unit_uuid, :name, :code, :is_active
            )
        ");
        
        $stmt->execute([
            'org_unit_uuid' => $uuid,
            'org_uuid' => $data['org_uuid'],
            'parent_org_unit_uuid' => $data['parent_org_unit_uuid'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'is_active' => $data['is_active'] ?? 1
        ]);
        
        $orgUnit = $this->getOrgUnit($uuid);
        if ($orgUnit) {
            $this->eventPublisher->publish('org_unit', $orgUnit['org_unit_uuid'], 'OrgUnitCreated', $orgUnit);
        }
        
        return $orgUnit ?: [];
    }
    
    /**
     * Holt eine Org Unit
     */
    public function getOrgUnit(string $orgUnitUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                ou.*,
                o.name as org_name
            FROM org_unit ou
            JOIN org o ON o.org_uuid = ou.org_uuid
            WHERE ou.org_unit_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $orgUnitUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Holt das Audit-Trail für eine Person
     */
    public function getAuditTrail(string $personUuid, int $limit = 100): array
    {
        return $this->auditTrailService->getAuditTrail('person', $personUuid, $limit);
    }
    
    /**
     * Formatiert einen Feldwert für die Anzeige (für Audit-Trail)
     */
    public function resolveFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '(leer)';
        }
        
        if ($field === 'is_active') {
            return $value ? 'Aktiv' : 'Inaktiv';
        }
        
        return (string)$value;
    }
    
    /**
     * Protokolliert den Zugriff auf eine Person (für "Zuletzt angesehen")
     */
    public function trackAccess(string $userId, string $personUuid, string $accessType = 'recent'): void
    {
        $this->accessTrackingService->trackAccess('person', $userId, $personUuid, $accessType);
    }
    
    /**
     * Holt die zuletzt angesehenen Personen für einen Benutzer
     */
    public function getRecentPersons(string $userId, int $limit = 10): array
    {
        return $this->accessTrackingService->getRecentEntities('person', $userId, $limit);
    }
}


