<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;

class WorkflowService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
    }
    
    public function handover(string $caseUuid, string $targetRole, ?string $justification = null): array
    {
        // Hole aktuellen Case
        $case = (new CaseService($this->db))->getCase($caseUuid);
        if (!$case) {
            throw new \RuntimeException("Case not found: $caseUuid");
        }
        
        // Erstelle Handover-Eintrag
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO case_handover (handover_uuid, case_uuid, from_role, to_role, justification)
            VALUES (:handover_uuid, :case_uuid, :from_role, :to_role, :justification)
        ");
        
        $stmt->execute([
            'handover_uuid' => $uuid,
            'case_uuid' => $caseUuid,
            'from_role' => $case['owner_role'],
            'to_role' => $targetRole,
            'justification' => $justification
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM case_handover WHERE handover_uuid = :uuid");
        $stmt->execute(['uuid' => $uuid]);
        $handover = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        // Update Case
        (new CaseService($this->db))->updateCase($caseUuid, ['owner_role' => $targetRole]);
        
        // Notiz hinzufügen
        (new CaseService($this->db))->addNote($caseUuid, "Übergabe von {$case['owner_role']} an $targetRole");
        
        return $handover;
    }
    
    public function returnCase(string $caseUuid, string $reason): array
    {
        $case = (new CaseService($this->db))->getCase($caseUuid);
        if (!$case) {
            throw new \RuntimeException("Case not found: $caseUuid");
        }
        
        // Erstelle Return-Eintrag
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        // Für Rückläufer: zurück zur vorherigen Rolle (vereinfacht)
        $previousRole = 'customer_inbound'; // TODO: Aus Historie ermitteln
        
        $stmt = $this->db->prepare("
            INSERT INTO case_return (return_uuid, case_uuid, from_role, to_role, reason)
            VALUES (:return_uuid, :case_uuid, :from_role, :to_role, :reason)
        ");
        
        $stmt->execute([
            'return_uuid' => $uuid,
            'case_uuid' => $caseUuid,
            'from_role' => $case['owner_role'],
            'to_role' => $previousRole,
            'reason' => $reason
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM case_return WHERE return_uuid = :uuid");
        $stmt->execute(['uuid' => $uuid]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        // Update Case
        (new CaseService($this->db))->updateCase($caseUuid, ['owner_role' => $previousRole]);
        
        // Notiz hinzufügen
        (new CaseService($this->db))->addNote($caseUuid, "Rückläufer: $reason");
        
        return $return;
    }
}


