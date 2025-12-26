<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;

class ProjectService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
    }
    
    public function createProject(array $data): array
    {
        // Generiere UUID (konsistent für MariaDB und Neo4j)
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO project (project_uuid, name, status, priority, target_date, sponsor_org_uuid)
            VALUES (:project_uuid, :name, :status, :priority, :target_date, :sponsor_org_uuid)
        ");
        
        $stmt->execute([
            'project_uuid' => $uuid,
            'name' => $data['name'] ?? '',
            'status' => $data['status'] ?? 'active',
            'priority' => $data['priority'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'sponsor_org_uuid' => $data['sponsor_org_uuid'] ?? null
        ]);
        
        $project = $this->getProject($uuid);
        $this->eventPublisher->publish('project', $project['project_uuid'], 'ProjectCreated', $project);
        
        return $project;
    }
    
    public function getProject(string $projectUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM project WHERE project_uuid = :uuid");
        $stmt->execute(['uuid' => $projectUuid]);
        return $stmt->fetch() ?: null;
    }
    
    public function listProjects(): array
    {
        $stmt = $this->db->query("SELECT * FROM project ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }
    
    public function linkCase(string $projectUuid, string $caseUuid): array
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO project_case (project_uuid, case_uuid)
            VALUES (:project_uuid, :case_uuid)
        ");
        $stmt->execute(['project_uuid' => $projectUuid, 'case_uuid' => $caseUuid]);
        
        $this->eventPublisher->publish('project', $projectUuid, 'ProjectCaseLinked', ['case_uuid' => $caseUuid]);
        
        return ['project_uuid' => $projectUuid, 'case_uuid' => $caseUuid];
    }
}


