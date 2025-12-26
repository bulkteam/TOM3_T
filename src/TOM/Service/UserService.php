<?php
declare(strict_types=1);

namespace TOM\Service;

/**
 * UserService - Verwaltung von Usern und Rollen
 * 
 * Lädt User-Definitionen aus config/users.php und stellt Methoden
 * zur Rollen-Verwaltung bereit.
 */
class UserService
{
    private ?array $config = null;
    
    private function loadConfig(): array
    {
        if ($this->config === null) {
            $configFile = __DIR__ . '/../../config/users.php';
            if (file_exists($configFile)) {
                $this->config = require $configFile;
            } else {
                $this->config = [
                    'users' => [],
                    'workflow_roles' => [],
                    'account_team_roles' => [],
                    'permission_roles' => []
                ];
            }
        }
        return $this->config;
    }
    
    /**
     * Hole alle User
     */
    public function getAllUsers(): array
    {
        $config = $this->loadConfig();
        return $config['users'] ?? [];
    }
    
    /**
     * Hole einen User anhand der user_id
     */
    public function getUser(string $userId): ?array
    {
        $users = $this->getAllUsers();
        foreach ($users as $user) {
            if (($user['user_id'] ?? '') === $userId) {
                return $user;
            }
        }
        return null;
    }
    
    /**
     * Hole alle Workflow-Rollen eines Users
     */
    public function getUserWorkflowRoles(string $userId): array
    {
        $user = $this->getUser($userId);
        return $user['workflow_roles'] ?? [];
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Workflow-Rolle hat
     */
    public function userHasWorkflowRole(string $userId, string $role): bool
    {
        $roles = $this->getUserWorkflowRoles($userId);
        return in_array($role, $roles, true);
    }
    
    /**
     * Hole alle verfügbaren Workflow-Rollen
     */
    public function getAvailableWorkflowRoles(): array
    {
        $config = $this->loadConfig();
        return $config['workflow_roles'] ?? [];
    }
    
    /**
     * Hole alle verfügbaren Account-Team-Rollen
     */
    public function getAvailableAccountTeamRoles(): array
    {
        $config = $this->loadConfig();
        return $config['account_team_roles'] ?? [];
    }
    
    /**
     * Hole alle verfügbaren Berechtigungs-Rollen
     */
    public function getAvailablePermissionRoles(): array
    {
        $config = $this->loadConfig();
        return $config['permission_roles'] ?? [];
    }
    
    /**
     * Hole User, die eine bestimmte Workflow-Rolle haben
     */
    public function getUsersByWorkflowRole(string $role): array
    {
        $users = $this->getAllUsers();
        $result = [];
        
        foreach ($users as $user) {
            $userRoles = $user['workflow_roles'] ?? [];
            if (in_array($role, $userRoles, true)) {
                $result[] = $user;
            }
        }
        
        return $result;
    }
    
    /**
     * Prüfe, ob ein User als Account Owner fungieren kann
     */
    public function canUserBeAccountOwner(string $userId): bool
    {
        $user = $this->getUser($userId);
        if (!$user) {
            return false;
        }
        return $user['can_be_account_owner'] ?? true; // Default: true
    }
    
    /**
     * Hole Berechtigungs-Rolle eines Users
     */
    public function getUserPermissionRole(string $userId): ?string
    {
        $user = $this->getUser($userId);
        return $user['permission_role'] ?? null;
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Berechtigung hat
     */
    public function userHasPermission(string $userId, string $permission): bool
    {
        $userRole = $this->getUserPermissionRole($userId);
        
        // Admin hat alle Berechtigungen
        if ($userRole === 'admin') {
            return true;
        }
        
        // Spezifische Berechtigungen
        return $userRole === $permission;
    }
}

