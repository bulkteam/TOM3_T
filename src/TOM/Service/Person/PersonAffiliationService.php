<?php
declare(strict_types=1);

namespace TOM\Service\Person;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;
use TOM\Infrastructure\Auth\AuthHelper;

/**
 * PersonAffiliationService
 * Handles affiliation management for persons (Historie/Besch├ñftigungsverlauf)
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
            // Audit-Trail: Protokolliere Erstellung der Affiliation
            try {
                $userId = AuthHelper::getCurrentUserId(true); // Erlaube Fallback für Dev-Mode
                $this->auditTrailService->logAuditTrail(
                    'person',
                    $data['person_uuid'],
                    $userId,
                    'update',
                    null,
                    ['affiliation' => $affiliation],
                    null,
                    null,
                    null
                );
            } catch (\Exception $e) {
                error_log("Person affiliation audit trail error: " . $e->getMessage());
            }
            
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
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Aktualisiert eine Person-Affiliation
     */
    public function updateAffiliation(string $affiliationUuid, array $data): array
    {
        // Hole bestehende Affiliation für Audit-Trail
        $oldAffiliation = $this->getAffiliation($affiliationUuid);
        if (!$oldAffiliation) {
            throw new \RuntimeException("Affiliation nicht gefunden: {$affiliationUuid}");
        }
        
        $personUuid = $oldAffiliation['person_uuid'];
        
        // Baue UPDATE-Query dynamisch auf
        $updateFields = [];
        $params = ['affiliation_uuid' => $affiliationUuid];
        
        $allowedFields = ['org_uuid', 'org_unit_uuid', 'kind', 'title', 'job_function', 'seniority', 'is_primary', 'since_date', 'until_date'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($updateFields)) {
            // Keine Änderungen
            return $oldAffiliation;
        }
        
        // Hinweis: person_affiliation hat keine updated_at Spalte
        
        $stmt = $this->db->prepare("
            UPDATE person_affiliation 
            SET " . implode(', ', $updateFields) . "
            WHERE affiliation_uuid = :affiliation_uuid
        ");
        
        $stmt->execute($params);
        
        // Hole aktualisierte Affiliation
        $newAffiliation = $this->getAffiliation($affiliationUuid);
        
        if ($newAffiliation) {
            // Audit-Trail: Protokolliere Update der Affiliation
            // Konvertiere Affiliation-Objekte in flache Arrays für Feld-Vergleich
            try {
                $userId = AuthHelper::getCurrentUserId(true);
                
                // Erstelle flache Arrays für Vergleich (nur relevante Felder)
                $oldDataFlat = [
                    'org_uuid' => $oldAffiliation['org_uuid'] ?? null,
                    'org_name' => $oldAffiliation['org_name'] ?? null,
                    'kind' => $oldAffiliation['kind'] ?? null,
                    'title' => $oldAffiliation['title'] ?? null,
                    'job_function' => $oldAffiliation['job_function'] ?? null,
                    'seniority' => $oldAffiliation['seniority'] ?? null,
                    'is_primary' => $oldAffiliation['is_primary'] ?? null,
                    'since_date' => $oldAffiliation['since_date'] ?? null,
                    'until_date' => $oldAffiliation['until_date'] ?? null
                ];
                
                $newDataFlat = [
                    'org_uuid' => $newAffiliation['org_uuid'] ?? null,
                    'org_name' => $newAffiliation['org_name'] ?? null,
                    'kind' => $newAffiliation['kind'] ?? null,
                    'title' => $newAffiliation['title'] ?? null,
                    'job_function' => $newAffiliation['job_function'] ?? null,
                    'seniority' => $newAffiliation['seniority'] ?? null,
                    'is_primary' => $newAffiliation['is_primary'] ?? null,
                    'since_date' => $newAffiliation['since_date'] ?? null,
                    'until_date' => $newAffiliation['until_date'] ?? null
                ];
                
                // Verwende allowedFields, um nur relevante Felder zu protokollieren
                $allowedFields = ['org_uuid', 'org_name', 'kind', 'title', 'job_function', 'seniority', 'is_primary', 'since_date', 'until_date'];
                
                $this->auditTrailService->logAuditTrail(
                    'person',
                    $personUuid,
                    $userId,
                    'update',
                    $oldDataFlat,
                    $newDataFlat,
                    $allowedFields,
                    null,
                    function($field, $value) use ($oldAffiliation, $newAffiliation) {
                        // Field-Resolver für bessere Anzeige
                        if ($field === 'org_uuid' || $field === 'org_name') {
                            return $value ? ($newAffiliation['org_name'] ?? $value) : '(keine)';
                        }
                        if ($field === 'kind') {
                            $kindLabels = [
                                'employee' => 'Mitarbeiter',
                                'contractor' => 'Freelancer/Berater',
                                'advisor' => 'Berater',
                                'other' => 'Sonstiges'
                            ];
                            return $kindLabels[$value] ?? $value;
                        }
                        if ($field === 'is_primary') {
                            return $value == 1 ? 'Ja' : 'Nein';
                        }
                        if ($field === 'seniority') {
                            $seniorityLabels = [
                                'intern' => 'Praktikant',
                                'junior' => 'Junior',
                                'mid' => 'Mittel',
                                'senior' => 'Senior',
                                'lead' => 'Lead',
                                'head' => 'Head',
                                'vp' => 'VP',
                                'cxo' => 'C-Level'
                            ];
                            return $seniorityLabels[$value] ?? $value;
                        }
                        return $value ?? '(leer)';
                    }
                );
            } catch (\Exception $e) {
                error_log("Person affiliation update audit trail error: " . $e->getMessage());
            }
            
            $this->eventPublisher->publish('person_affiliation', $affiliationUuid, 'PersonAffiliationUpdated', $newAffiliation);
        }
        
        return $newAffiliation ?: [];
    }
    
    /**
     * Löscht eine Person-Affiliation
     */
    public function deleteAffiliation(string $affiliationUuid): bool
    {
        // Hole bestehende Affiliation für Audit-Trail
        $affiliation = $this->getAffiliation($affiliationUuid);
        if (!$affiliation) {
            return false;
        }
        
        $personUuid = $affiliation['person_uuid'];
        
        $stmt = $this->db->prepare("DELETE FROM person_affiliation WHERE affiliation_uuid = :uuid");
        $stmt->execute(['uuid' => $affiliationUuid]);
        
        if ($stmt->rowCount() > 0) {
            // Audit-Trail: Protokolliere Löschung der Affiliation
            try {
                $userId = AuthHelper::getCurrentUserId(true);
                $this->auditTrailService->logAuditTrail(
                    'person',
                    $personUuid,
                    $userId,
                    'update',
                    ['affiliation' => $affiliation],
                    null,
                    null,
                    null,
                    null
                );
            } catch (\Exception $e) {
                error_log("Person affiliation delete audit trail error: " . $e->getMessage());
            }
            
            $this->eventPublisher->publish('person_affiliation', $affiliationUuid, 'PersonAffiliationDeleted', $affiliation);
            return true;
        }
        
        return false;
    }
}


