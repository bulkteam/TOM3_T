<?php
declare(strict_types=1);

namespace TOM\Service\User;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Permission\CapabilityRegistry;

/**
 * UserPermissionService
 * Handles permission checks for users
 */
class UserPermissionService
{
    private PDO $db;
    private UserRoleService $roleService;
    
    public function __construct(?PDO $db = null, ?UserRoleService $roleService = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->roleService = $roleService ?? new UserRoleService($this->db);
    }
    
    /**
     * Hole Berechtigungs-Rolle eines Users (aus DB)
     * 
     * @param int|string $userId User-ID
     * @param array|null $userRoles Optional: Bereits geladene User-Rollen (für Performance)
     * @return string|null Höchste Permission-Rolle (admin, manager, user, readonly)
     */
    public function getUserPermissionRole($userId, ?array $userRoles = null): ?string
    {
        // Wenn Rollen nicht übergeben wurden, lade sie
        if ($userRoles === null) {
            $stmt = $this->db->prepare("
                SELECT r.role_code
                FROM user_role ur
                JOIN role r ON ur.role_id = r.role_id
                WHERE ur.user_id = :user_id
                ORDER BY r.role_code
            ");
            $stmt->execute(['user_id' => (int)$userId]);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $userRoles = array_column($roles, 'role_code');
        }
        
        if (empty($userRoles)) {
            return null;
        }
        
        // Priorität: admin > manager > user > readonly
        $priority = ['admin' => 4, 'manager' => 3, 'user' => 2, 'readonly' => 1];
        
        $highestRole = null;
        $highestPriority = 0;
        
        foreach ($userRoles as $role) {
            $rolePriority = $priority[$role] ?? 0;
            if ($rolePriority > $highestPriority) {
                $highestPriority = $rolePriority;
                $highestRole = $role;
            }
        }
        
        return $highestRole;
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Berechtigung hat
     * 
     * @deprecated Verwende userHasCapability() für Capability-basierte Prüfung
     * @param int|string $userId User-ID
     * @param string $permission Berechtigung (admin, manager, user, readonly)
     * @param array|null $userRoles Optional: Bereits geladene User-Rollen (für Performance)
     * @return bool True wenn User die Berechtigung hat
     */
    public function userHasPermission($userId, string $permission, ?array $userRoles = null): bool
    {
        $userRole = $this->getUserPermissionRole($userId, $userRoles);
        
        // Admin hat alle Berechtigungen
        if ($userRole === 'admin') {
            return true;
        }
        
        // Spezifische Berechtigungen (für Backward Compatibility)
        return $userRole === $permission;
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Capability hat
     * 
     * @param int|string $userId User-ID
     * @param string $capability Capability (z.B. 'org.write', 'person.read')
     * @param array|null $userRoles Optional: Bereits geladene User-Rollen (für Performance)
     * @return bool True wenn User die Capability hat
     */
    public function userHasCapability($userId, string $capability, ?array $userRoles = null): bool
    {
        $userRole = $this->getUserPermissionRole($userId, $userRoles);
        
        if ($userRole === null) {
            return false;
        }
        
        return CapabilityRegistry::roleHasCapability($userRole, $capability);
    }
    
    /**
     * Gibt alle Capabilities eines Users zurück
     * 
     * @param int|string $userId User-ID
     * @param array|null $userRoles Optional: Bereits geladene User-Rollen (für Performance)
     * @return array Liste von Capabilities
     */
    public function getUserCapabilities($userId, ?array $userRoles = null): array
    {
        $userRole = $this->getUserPermissionRole($userId, $userRoles);
        
        if ($userRole === null) {
            return [];
        }
        
        return CapabilityRegistry::getCapabilitiesForRole($userRole);
    }
    
    /**
     * Prüft ob ein User mindestens eine der angegebenen Capabilities hat
     * 
     * @param int|string $userId User-ID
     * @param array $capabilities Liste von Capabilities
     * @param array|null $userRoles Optional: Bereits geladene User-Rollen (für Performance)
     * @return bool True wenn User mindestens eine Capability hat
     */
    public function userHasAnyCapability($userId, array $capabilities, ?array $userRoles = null): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->userHasCapability($userId, $capability, $userRoles)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Prüft ob ein User alle angegebenen Capabilities hat
     * 
     * @param int|string $userId User-ID
     * @param array $capabilities Liste von Capabilities
     * @param array|null $userRoles Optional: Bereits geladene User-Rollen (für Performance)
     * @return bool True wenn User alle Capabilities hat
     */
    public function userHasAllCapabilities($userId, array $capabilities, ?array $userRoles = null): bool
    {
        foreach ($capabilities as $capability) {
            if (!$this->userHasCapability($userId, $capability, $userRoles)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Prüfe, ob ein User als Account Owner fungieren kann
     * 
     * Standard: Alle aktiven User können Account Owner sein
     * 
     * @param int|string $userId User-ID
     * @param callable|null $getUserCallback Optional: Callback zum Laden des Users (für Performance)
     * @return bool True wenn User Account Owner sein kann
     */
    public function canUserBeAccountOwner($userId, ?callable $getUserCallback = null): bool
    {
        if ($getUserCallback) {
            $user = $getUserCallback($userId);
        } else {
            $stmt = $this->db->prepare("SELECT user_id FROM users WHERE user_id = :user_id AND is_active = 1");
            $stmt->execute(['user_id' => (int)$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        return $user !== null; // Alle aktiven User können Account Owner sein
    }
    
    /**
     * Prüft, ob mindestens ein Admin-User aktiv ist
     * 
     * @param int|null $excludeUserId User-ID, die von der Prüfung ausgeschlossen werden soll (z.B. der zu deaktivierende User)
     * @return bool True wenn mindestens ein Admin aktiv ist
     */
    public function hasActiveAdmin(?int $excludeUserId = null): bool
    {
        $sql = "
            SELECT COUNT(*) as admin_count
            FROM users u
            JOIN user_role ur ON u.user_id = ur.user_id
            JOIN role r ON ur.role_id = r.role_id
            WHERE r.role_code = 'admin' 
              AND u.is_active = 1
        ";
        
        $params = [];
        if ($excludeUserId !== null) {
            $sql .= " AND u.user_id != :exclude_user_id";
            $params['exclude_user_id'] = $excludeUserId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (int)($result['admin_count'] ?? 0) > 0;
    }
}


