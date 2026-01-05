<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;

class CaseService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
    }
    
    public function createCase(array $data): array
    {
        // Generiere UUID für MySQL
        $uuidStmt = $this->db->query("SELECT UUID() as uuid");
        $uuid = $uuidStmt->fetch()['uuid'];
        
        $stmt = $this->db->prepare("
            INSERT INTO case_item (case_uuid, case_type, engine, phase, status, owner_role, title, description, org_uuid, project_uuid, priority)
            VALUES (:case_uuid, :case_type, :engine, :phase, 'neu', :owner_role, :title, :description, :org_uuid, :project_uuid, :priority)
        ");
        
        $stmt->execute([
            'case_uuid' => $uuid,
            'case_type' => $data['case_type'] ?? 'general',
            'engine' => $data['engine'] ?? 'ops',
            'phase' => $data['phase'] ?? 'OPS-A',
            'owner_role' => $data['owner_role'] ?? 'ops',
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? null,
            'org_uuid' => $data['org_uuid'] ?? null,
            'project_uuid' => $data['project_uuid'] ?? null,
            'priority' => $data['priority'] ?? null
        ]);
        
        $case = $this->getCase($uuid);
        
        $this->eventPublisher->publish('case_item', $case['case_uuid'], 'CaseCreated', $case);
        
        return $case;
    }
    
    public function getCase(string $caseUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM case_item WHERE case_uuid = :uuid");
        $stmt->execute(['uuid' => $caseUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function listCases(array $filters = []): array
    {
        $sql = "SELECT * FROM case_item WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $filters['status'];
        }
        
        if (!empty($filters['engine'])) {
            $sql .= " AND engine = :engine";
            $params['engine'] = $filters['engine'];
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function updateCase(string $caseUuid, array $data): array
    {
        $allowed = ['title', 'description', 'priority', 'status', 'phase', 'owner_role'];
        $updates = [];
        $params = ['uuid' => $caseUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return $this->getCase($caseUuid);
        }
        
        $sql = "UPDATE case_item SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE case_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $case = $this->getCase($caseUuid);
        $this->eventPublisher->publish('case_item', $caseUuid, 'CaseUpdated', $case);
        
        return $case;
    }
    
    public function addNote(string $caseUuid, string $note): array
    {
        // Generiere UUID für MySQL
        $uuidStmt = $this->db->query("SELECT UUID() as uuid");
        $uuid = $uuidStmt->fetch()['uuid'];
        
        $stmt = $this->db->prepare("
            INSERT INTO case_note (note_uuid, case_uuid, note_type, body)
            VALUES (:note_uuid, :case_uuid, 'comment', :body)
        ");
        $stmt->execute(['note_uuid' => $uuid, 'case_uuid' => $caseUuid, 'body' => $note]);
        
        $stmt = $this->db->prepare("SELECT * FROM case_note WHERE note_uuid = :uuid");
        $stmt->execute(['uuid' => $uuid]);
        return $stmt->fetch();
    }
    
    public function getBlockers(string $caseUuid): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM case_requirement
            WHERE case_uuid = :case_uuid AND is_fulfilled = false
        ");
        $stmt->execute(['case_uuid' => $caseUuid]);
        return $stmt->fetchAll();
    }
    
    public function fulfillRequirement(string $caseUuid, string $requirementUuid, array $data): array
    {
        $stmt = $this->db->prepare("
            UPDATE case_requirement
            SET is_fulfilled = true, fulfilled_at = NOW(), fulfilled_by_user_id = :user_id
            WHERE requirement_uuid = :requirement_uuid AND case_uuid = :case_uuid
        ");
        $stmt->execute([
            'requirement_uuid' => $requirementUuid,
            'case_uuid' => $caseUuid,
            'user_id' => $data['user_id'] ?? null
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM case_requirement WHERE requirement_uuid = :uuid");
        $stmt->execute(['uuid' => $requirementUuid]);
        return $stmt->fetch();
    }
}


