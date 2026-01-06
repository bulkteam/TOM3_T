<?php
declare(strict_types=1);

namespace TOM\Service\Org\Account;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * OrgAccountHealthService
 * 
 * Handles account health monitoring for organizations:
 * - Contact freshness
 * - Stale offers
 * - Waiting projects
 * - Open escalations
 */
class OrgAccountHealthService
{
    private PDO $db;
    private $orgGetter;
    
    /**
     * @param PDO|null $db
     * @param callable|null $orgGetter Callback to get organization: function(string $orgUuid): ?array
     */
    public function __construct(?PDO $db = null, ?callable $orgGetter = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->orgGetter = $orgGetter;
    }
    
    /**
     * Berechnet Account-Gesundheit für eine Organisation
     * Gibt Status (green/yellow/red) und Gründe zurück
     */
    public function getAccountHealth(string $orgUuid): array
    {
        if (!$this->orgGetter) {
            return ['status' => 'unknown', 'reasons' => []];
        }
        
        $org = call_user_func($this->orgGetter, $orgUuid);
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
}



