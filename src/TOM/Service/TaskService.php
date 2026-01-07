<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

class TaskService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    public function createTask(array $data): array
    {
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO task (task_uuid, case_uuid, title, assignee_role, due_at)
            VALUES (:task_uuid, :case_uuid, :title, :assignee_role, :due_at)
        ");
        
        $stmt->execute([
            'task_uuid' => $uuid,
            'case_uuid' => $data['case_uuid'] ?? null,
            'title' => $data['title'] ?? '',
            'assignee_role' => $data['assignee_role'] ?? null,
            'due_at' => $data['due_at'] ?? null
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM task WHERE task_uuid = :uuid");
        $stmt->execute(['uuid' => $uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    public function listTasks(?string $caseUuid = null): array
    {
        if ($caseUuid) {
            $stmt = $this->db->prepare("SELECT * FROM task WHERE case_uuid = :case_uuid ORDER BY created_at DESC");
            $stmt->execute(['case_uuid' => $caseUuid]);
        } else {
            $stmt = $this->db->query("SELECT * FROM task ORDER BY created_at DESC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    public function completeTask(string $taskUuid): array
    {
        $stmt = $this->db->prepare("
            UPDATE task SET status = 'done', done_at = NOW(), updated_at = NOW()
            WHERE task_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $taskUuid]);
        
        $stmt = $this->db->prepare("SELECT * FROM task WHERE task_uuid = :uuid");
        $stmt->execute(['uuid' => $taskUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}


