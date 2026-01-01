<?php
declare(strict_types=1);

namespace TOM\Service\User;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * UserRoleService
 * Handles role management for users (Permission Roles, Workflow Roles, Account Team Roles)
 */
class UserRoleService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Hole alle Workflow-Rollen eines Users
     */
    public function getUserWorkflowRoles($userId): array
    {
        $userIdInt = (int)$userId;
        
        $stmt = $this->db->prepare("
            SELECT wr.role_code
            FROM user_workflow_role uwr
            JOIN workflow_role wr ON uwr.workflow_role_id = wr.workflow_role_id
            WHERE uwr.user_id = :user_id
            ORDER BY wr.role_code
        ");
        $stmt->execute(['user_id' => $userIdInt]);
        
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'role_code');
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Workflow-Rolle hat
     */
    public function userHasWorkflowRole($userId, string $role): bool
    {
        $roles = $this->getUserWorkflowRoles($userId);
        return in_array($role, $roles, true);
    }
    
    /**
     * Hole alle verfügbaren Workflow-Rollen (aus DB)
     */
    public function getAvailableWorkflowRoles(): array
    {
        $stmt = $this->db->query("
            SELECT workflow_role_id, role_code, role_name, description
            FROM workflow_role
            ORDER BY role_code
        ");
        
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu altem Format für Kompatibilität
        $result = [];
        foreach ($roles as $role) {
            $result[$role['role_code']] = [
                'name' => $role['role_name'],
                'description' => $role['description']
            ];
        }
        
        return $result;
    }
    
    /**
     * Hole alle verfügbaren Account-Team-Rollen (aus DB)
     */
    public function getAvailableAccountTeamRoles(): array
    {
        $stmt = $this->db->query("
            SELECT account_team_role_id, role_code, role_name, description
            FROM account_team_role
            ORDER BY role_code
        ");
        
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu altem Format für Kompatibilität
        $result = [];
        foreach ($roles as $role) {
            $result[$role['role_code']] = [
                'name' => $role['role_name'],
                'description' => $role['description']
            ];
        }
        
        return $result;
    }
    
    /**
     * Hole alle verfügbaren Berechtigungs-Rollen (aus DB)
     */
    public function getAvailablePermissionRoles(): array
    {
        $stmt = $this->db->query("
            SELECT role_code, role_name, description
            FROM role
            ORDER BY role_code
        ");
        
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu altem Format für Kompatibilität
        $result = [];
        foreach ($roles as $role) {
            $result[$role['role_code']] = [
                'name' => $role['role_name'],
                'description' => $role['description']
            ];
        }
        
        return $result;
    }
    
    /**
     * Hole User, die eine bestimmte Workflow-Rolle haben
     */
    public function getUsersByWorkflowRole(string $role): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                u.user_id,
                u.email,
                u.name,
                u.is_active
            FROM users u
            JOIN user_workflow_role uwr ON u.user_id = uwr.user_id
            JOIN workflow_role wr ON uwr.workflow_role_id = wr.workflow_role_id
            WHERE wr.role_code = :role_code AND u.is_active = 1
            ORDER BY u.name
        ");
        $stmt->execute(['role_code' => $role]);
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere user_id zu String für Kompatibilität
        foreach ($users as &$user) {
            $user['user_id'] = (string)$user['user_id'];
        }
        
        return $users;
    }
}
