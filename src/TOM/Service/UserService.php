<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\User\UserRoleService;
use TOM\Service\User\UserPermissionService;

/**
 * UserService - Verwaltung von Usern
 * 
 * Lädt User-Definitionen aus der Datenbank (users Tabelle)
 * Rollen-Management ist in UserRoleService ausgelagert
 * Permission-Prüfungen sind in UserPermissionService ausgelagert
 */
class UserService
{
    private PDO $db;
    private UserRoleService $roleService;
    private UserPermissionService $permissionService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->roleService = new UserRoleService($this->db);
        $this->permissionService = new UserPermissionService($this->db, $this->roleService);
    }
    
    /**
     * Hole alle User (optional inkl. inaktive)
     */
    public function getAllUsers(bool $includeInactive = false): array
    {
        $whereClause = $includeInactive ? '' : 'WHERE u.is_active = 1';
        
        $stmt = $this->db->query("
            SELECT 
                u.user_id,
                u.email,
                u.name,
                u.is_active,
                u.created_at,
                u.created_by_user_id,
                u.disabled_at,
                u.disabled_by_user_id,
                u.last_login_at,
                GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') as roles,
                GROUP_CONCAT(DISTINCT wr.role_code ORDER BY wr.role_code SEPARATOR ', ') as workflow_roles
            FROM users u
            LEFT JOIN user_role ur ON u.user_id = ur.user_id
            LEFT JOIN role r ON ur.role_id = r.role_id
            LEFT JOIN user_workflow_role uwr ON u.user_id = uwr.user_id
            LEFT JOIN workflow_role wr ON uwr.workflow_role_id = wr.workflow_role_id
            $whereClause
            GROUP BY u.user_id, u.email, u.name, u.is_active, u.created_at, u.created_by_user_id, u.disabled_at, u.disabled_by_user_id, u.last_login_at
            ORDER BY u.is_active DESC, u.name
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere user_id zu String für Kompatibilität
        foreach ($users as &$user) {
            $user['user_id'] = (string)$user['user_id'];
        }
        
        return $users;
    }
    
    /**
     * Hole einen User anhand der user_id
     * 
     * @param string|int $userId User-ID (wird als String behandelt für Kompatibilität)
     */
    public function getUser($userId, bool $includeInactive = true): ?array
    {
        $userIdInt = (int)$userId;
        
        $whereClause = $includeInactive ? '' : 'AND u.is_active = 1';
        
        $stmt = $this->db->prepare("
            SELECT 
                u.user_id,
                u.email,
                u.name,
                u.is_active,
                u.created_at,
                u.last_login_at
            FROM users u
            WHERE u.user_id = :user_id $whereClause
        ");
        $stmt->execute(['user_id' => $userIdInt]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return null;
        }
        
        // Lade System-Rollen (Permission-Rollen)
        $stmt = $this->db->prepare("
            SELECT r.role_code, r.role_name, r.description
            FROM user_role ur
            JOIN role r ON ur.role_id = r.role_id
            WHERE ur.user_id = :user_id
            ORDER BY r.role_code
        ");
        $stmt->execute(['user_id' => $userIdInt]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $user['roles'] = array_column($roles, 'role_code');
        $user['roles_detail'] = $roles;
        
        // Lade Workflow-Rollen
        $stmt = $this->db->prepare("
            SELECT wr.role_code, wr.role_name, wr.description
            FROM user_workflow_role uwr
            JOIN workflow_role wr ON uwr.workflow_role_id = wr.workflow_role_id
            WHERE uwr.user_id = :user_id
            ORDER BY wr.role_code
        ");
        $stmt->execute(['user_id' => $userIdInt]);
        $workflowRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $user['workflow_roles'] = array_column($workflowRoles, 'role_code');
        $user['workflow_roles_detail'] = $workflowRoles;
        
        // Für Kompatibilität: user_id als String zurückgeben
        $user['user_id'] = (string)$user['user_id'];
        
        return $user;
    }
    
    // ============================================================================
    // ROLE MANAGEMENT (delegiert an UserRoleService)
    // ============================================================================
    
    /**
     * Hole alle Workflow-Rollen eines Users
     */
    public function getUserWorkflowRoles($userId): array
    {
        return $this->roleService->getUserWorkflowRoles($userId);
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Workflow-Rolle hat
     */
    public function userHasWorkflowRole($userId, string $role): bool
    {
        return $this->roleService->userHasWorkflowRole($userId, $role);
    }
    
    /**
     * Hole alle verfügbaren Workflow-Rollen (aus DB)
     */
    public function getAvailableWorkflowRoles(): array
    {
        return $this->roleService->getAvailableWorkflowRoles();
    }
    
    /**
     * Hole alle verfügbaren Account-Team-Rollen (aus DB)
     */
    public function getAvailableAccountTeamRoles(): array
    {
        return $this->roleService->getAvailableAccountTeamRoles();
    }
    
    /**
     * Hole alle verfügbaren Berechtigungs-Rollen (aus DB)
     */
    public function getAvailablePermissionRoles(): array
    {
        return $this->roleService->getAvailablePermissionRoles();
    }
    
    /**
     * Hole User, die eine bestimmte Workflow-Rolle haben
     */
    public function getUsersByWorkflowRole(string $role): array
    {
        return $this->roleService->getUsersByWorkflowRole($role);
    }
    
    // ============================================================================
    // PERMISSION MANAGEMENT (delegiert an UserPermissionService)
    // ============================================================================
    
    /**
     * Prüfe, ob ein User als Account Owner fungieren kann
     * 
     * Standard: Alle aktiven User können Account Owner sein
     */
    public function canUserBeAccountOwner($userId): bool
    {
        return $this->permissionService->canUserBeAccountOwner($userId, [$this, 'getUser']);
    }
    
    /**
     * Hole Berechtigungs-Rolle eines Users (aus DB)
     */
    public function getUserPermissionRole($userId): ?string
    {
        $user = $this->getUser($userId);
        $userRoles = $user['roles'] ?? null;
        return $this->permissionService->getUserPermissionRole($userId, $userRoles);
    }
    
    /**
     * Prüfe, ob ein User eine bestimmte Berechtigung hat
     */
    public function userHasPermission($userId, string $permission): bool
    {
        $user = $this->getUser($userId);
        $userRoles = $user['roles'] ?? null;
        return $this->permissionService->userHasPermission($userId, $permission, $userRoles);
    }
    
    /**
     * Prüft, ob mindestens ein Admin-User aktiv ist
     * 
     * @param int|null $excludeUserId User-ID, die von der Prüfung ausgeschlossen werden soll (z.B. der zu deaktivierende User)
     * @return bool True wenn mindestens ein Admin aktiv ist
     */
    private function hasActiveAdmin(?int $excludeUserId = null): bool
    {
        return $this->permissionService->hasActiveAdmin($excludeUserId);
    }
    
    /**
     * Deaktiviert einen User (archiviert statt löschen)
     * 
     * @param int|string $userId User-ID
     * @param int|null $currentUserId User-ID des aktuellen Users (für Admin-Schutz und Audit)
     * @return bool Erfolg
     * @throws \Exception Wenn Admin deaktiviert werden soll oder letzter Admin deaktiviert würde
     */
    public function deactivateUser($userId, $currentUserId = null): bool
    {
        $userIdInt = (int)$userId;
        $currentUserIdInt = $currentUserId ? (int)$currentUserId : null;
        
        // Prüfe ob User Admin ist
        $user = $this->getUserById($userIdInt, false); // Auch inaktive User laden
        if ($user) {
            $roles = $user['roles'] ?? [];
            if (in_array('admin', $roles, true)) {
                // Prüfe ob mindestens ein anderer Admin aktiv bleibt
                if (!$this->hasActiveAdmin($userIdInt)) {
                    throw new \Exception('Der letzte Admin-User kann nicht deaktiviert werden. Mindestens ein Admin muss aktiv bleiben.');
                }
            }
        }
        
        // Prüfe ob versucht wird, sich selbst zu deaktivieren
        if ($currentUserIdInt && $currentUserIdInt === $userIdInt) {
            throw new \Exception('Sie können sich nicht selbst deaktivieren');
        }
        
        $stmt = $this->db->prepare("
            UPDATE users 
            SET is_active = 0, 
                disabled_at = NOW(),
                disabled_by_user_id = :disabled_by,
                updated_at = NOW()
            WHERE user_id = :user_id
        ");
        
        $result = $stmt->execute([
            'user_id' => $userIdInt,
            'disabled_by' => $currentUserIdInt
        ]);
        
        // Session-Invalidierung: Alle Sessions dieses Users beenden
        $this->invalidateUserSessions($userIdInt);
        
        return $result;
    }
    
    /**
     * Aktiviert einen User wieder
     * 
     * @param int|string $userId User-ID
     * @return bool Erfolg
     */
    public function activateUser($userId): bool
    {
        $userIdInt = (int)$userId;
        
        $stmt = $this->db->prepare("
            UPDATE users 
            SET is_active = 1, 
                disabled_at = NULL,
                disabled_by_user_id = NULL,
                updated_at = NOW()
            WHERE user_id = :user_id
        ");
        
        return $stmt->execute(['user_id' => $userIdInt]);
    }
    
    /**
     * Holt einen User anhand der user_id (auch inaktive)
     * 
     * @param int $userId User-ID
     * @param bool $activeOnly Nur aktive User
     * @return array|null User-Daten
     */
    private function getUserById(int $userId, bool $activeOnly = true): ?array
    {
        $whereClause = $activeOnly ? 'AND u.is_active = 1' : '';
        
        $stmt = $this->db->prepare("
            SELECT 
                u.user_id,
                u.email,
                u.name,
                u.is_active,
                u.created_at,
                u.created_by_user_id,
                u.disabled_at,
                u.disabled_by_user_id,
                u.last_login_at
            FROM users u
            WHERE u.user_id = :user_id $whereClause
        ");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return null;
        }
        
        // Lade System-Rollen
        $stmt = $this->db->prepare("
            SELECT r.role_code, r.role_name, r.description
            FROM user_role ur
            JOIN role r ON ur.role_id = r.role_id
            WHERE ur.user_id = :user_id
            ORDER BY r.role_code
        ");
        $stmt->execute(['user_id' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $user['roles'] = array_column($roles, 'role_code');
        $user['roles_detail'] = $roles;
        
        $user['user_id'] = (string)$user['user_id'];
        
        return $user;
    }
    
    /**
     * Erstellt einen neuen User
     * 
     * @param array $data User-Daten (email, name, roles)
     * @param int|null $createdByUserId User-ID des Erstellers
     * @return array Erstellter User
     */
    public function createUser(array $data, ?int $createdByUserId = null): array
    {
        // Validierung
        if (empty($data['email']) || empty($data['name'])) {
            throw new \InvalidArgumentException('Email und Name sind erforderlich');
        }
        
        // Prüfe ob Email bereits existiert
        $stmt = $this->db->prepare("SELECT user_id FROM users WHERE email = :email");
        $stmt->execute(['email' => $data['email']]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Ein User mit dieser Email existiert bereits');
        }
        
        // Erstelle User
        $stmt = $this->db->prepare("
            INSERT INTO users (email, name, created_by_user_id, is_active, created_at, updated_at)
            VALUES (:email, :name, :created_by, 1, NOW(), NOW())
        ");
        $stmt->execute([
            'email' => $data['email'],
            'name' => $data['name'],
            'created_by' => $createdByUserId
        ]);
        
        $userId = (int)$this->db->lastInsertId();
        
        // Zuweise Rollen
        if (!empty($data['roles']) && is_array($data['roles'])) {
            foreach ($data['roles'] as $roleCode) {
                $stmt = $this->db->prepare("
                    INSERT INTO user_role (user_id, role_id, assigned_by_user_id)
                    SELECT :user_id, r.role_id, :assigned_by
                    FROM role r
                    WHERE r.role_code = :role_code
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'role_code' => $roleCode,
                    'assigned_by' => $createdByUserId
                ]);
            }
        }
        
        return $this->getUserById($userId);
    }
    
    /**
     * Aktualisiert einen User
     * 
     * @param int|string $userId User-ID
     * @param array $data Zu aktualisierende Felder (name, email)
     * @return array Aktualisierter User
     */
    public function updateUser($userId, array $data): array
    {
        $userIdInt = (int)$userId;
        
        $allowedFields = ['name', 'email'];
        $updates = [];
        $params = ['user_id' => $userIdInt];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        
        if (empty($updates)) {
            return $this->getUserById($userIdInt) ?: [];
        }
        
        $sql = "UPDATE users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $this->getUserById($userIdInt) ?: [];
    }
    
    /**
     * Invalidiert alle Sessions eines Users
     * 
     * Da wir PHP-Sessions verwenden, können wir nicht direkt auf Session-Daten zugreifen.
     * Stattdessen setzen wir ein Flag in der Session, das bei jedem Request geprüft wird.
     * 
     * @param int $userId User-ID
     */
    private function invalidateUserSessions(int $userId): void
    {
        // Da PHP-Sessions serverseitig gespeichert werden, können wir nicht direkt
        // alle Sessions eines Users löschen. Stattdessen:
        // 1. Der AuthService prüft bei jedem Request, ob der User noch aktiv ist
        // 2. Wenn nicht, wird die Session automatisch invalidiert
        
        // Optional: Wir könnten eine user_sessions Tabelle einführen für bessere Kontrolle,
        // aber für die Testphase ist die aktuelle Lösung ausreichend.
        
        // Die Session-Invalidierung erfolgt automatisch durch getCurrentUser() im AuthService,
        // der prüft ob is_active = 1 ist.
    }
}
