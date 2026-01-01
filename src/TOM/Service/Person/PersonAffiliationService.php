<?php
declare(strict_types=1);

namespace TOM\Service\Person;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;

/**
 * PersonAffiliationService
 * Handles affiliation management for persons (Historie/Beschäftigungsverlauf)
 */
class PersonAffiliationService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    private AuditTrailService $auditTrailService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
        $this->auditTrailService = new AuditTrailService($this->db);
    }
    
    /**
     * Erstellt eine Person-Affiliation (Person arbeitet bei Organisation)
     */
    public function createAffiliation(array $data): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO person_affiliation (
                affiliation_uuid, person_uuid, org_uuid, org_unit_uuid,
                kind, title, job_function, seniority, is_primary,
                since_date, until_date
            )
            VALUES (
                :affiliation_uuid, :person_uuid, :org_uuid, :org_unit_uuid,
                :kind, :title, :job_function, :seniority, :is_primary,
                :since_date, :until_date
            )
        ");
        
        $stmt->execute([
            'affiliation_uuid' => $uuid,
            'person_uuid' => $data['person_uuid'],
            'org_uuid' => $data['org_uuid'],
            'org_unit_uuid' => $data['org_unit_uuid'] ?? null,
            'kind' => $data['kind'] ?? 'employee',
            'title' => $data['title'] ?? null,
            'job_function' => $data['job_function'] ?? null,
            'seniority' => $data['seniority'] ?? null,
            'is_primary' => $data['is_primary'] ?? 0,
            'since_date' => $data['since_date'] ?? date('Y-m-d'),
            'until_date' => $data['until_date'] ?? null
        ]);
        
        $affiliation = $this->getAffiliation($uuid);
        if ($affiliation) {
            $this->eventPublisher->publish('person_affiliation', $affiliation['affiliation_uuid'], 'PersonAffiliationAdded', $affiliation);
        }
        
        return $affiliation ?: [];
    }
    
    /**
     * Holt eine einzelne Affiliation
     */
    public function getAffiliation(string $affiliationUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                pa.*,
                p.display_name as person_name,
                o.name as org_name,
                ou.name as org_unit_name
            FROM person_affiliation pa
            JOIN person p ON p.person_uuid = pa.person_uuid
            JOIN org o ON o.org_uuid = pa.org_uuid
            LEFT JOIN org_unit ou ON ou.org_unit_uuid = pa.org_unit_uuid
            WHERE pa.affiliation_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $affiliationUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Holt alle Affiliations einer Person
     */
    public function getPersonAffiliations(string $personUuid, ?bool $activeOnly = true): array
    {
        $sql = "
            SELECT 
                pa.*,
                o.name as org_name,
                ou.name as org_unit_name
            FROM person_affiliation pa
            JOIN org o ON o.org_uuid = pa.org_uuid
            LEFT JOIN org_unit ou ON ou.org_unit_uuid = pa.org_unit_uuid
            WHERE pa.person_uuid = :person_uuid
        ";
        
        if ($activeOnly) {
            $sql .= " AND (pa.until_date IS NULL OR pa.until_date >= CURDATE())";
        }
        
        $sql .= " ORDER BY pa.is_primary DESC, pa.since_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['person_uuid' => $personUuid]);
        return $stmt->fetchAll();
    }
}
