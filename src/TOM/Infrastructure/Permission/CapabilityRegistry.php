<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Permission;

/**
 * CapabilityRegistry
 * 
 * Definiert Capabilities und ihre Zuordnung zu Rollen
 * 
 * Capabilities sind granularer als Rollen:
 * - org.read, org.write, org.delete
 * - person.read, person.write, person.delete
 * - export.run, admin.manage_users, etc.
 * 
 * Rollen-Hierarchie: admin > manager > user > readonly
 */
class CapabilityRegistry
{
    /**
     * Rollen-Hierarchie (höhere Zahl = mehr Rechte)
     */
    private const ROLE_HIERARCHY = [
        'admin' => 4,
        'manager' => 3,
        'user' => 2,
        'readonly' => 1,
    ];
    
    /**
     * Capability-Mapping: Welche Rollen haben welche Capabilities?
     * 
     * Format: 'capability' => ['role1', 'role2', ...]
     * Rollen werden hierarchisch geprüft (admin hat automatisch alle)
     */
    private const CAPABILITIES = [
        // Organisationen
        'org.read' => ['admin', 'manager', 'user', 'readonly'],
        'org.write' => ['admin', 'manager', 'user'],
        'org.delete' => ['admin', 'manager'],
        'org.archive' => ['admin', 'manager'],
        'org.export' => ['admin', 'manager', 'user'],
        
        // Personen
        'person.read' => ['admin', 'manager', 'user', 'readonly'],
        'person.write' => ['admin', 'manager', 'user'],
        'person.delete' => ['admin', 'manager'],
        
        // Import
        'import.upload' => ['admin', 'manager', 'user'],
        'import.review' => ['admin', 'manager', 'user'],
        'import.commit' => ['admin', 'manager'],
        'import.delete' => ['admin', 'manager'],
        
        // Dokumente
        'document.read' => ['admin', 'manager', 'user', 'readonly'],
        'document.upload' => ['admin', 'manager', 'user'],
        'document.delete' => ['admin', 'manager'],
        
        // Cases/Vorgänge
        'case.read' => ['admin', 'manager', 'user', 'readonly'],
        'case.write' => ['admin', 'manager', 'user'],
        'case.delete' => ['admin', 'manager'],
        
        // Projekte
        'project.read' => ['admin', 'manager', 'user', 'readonly'],
        'project.write' => ['admin', 'manager', 'user'],
        'project.delete' => ['admin', 'manager'],
        
        // Admin-Funktionen
        'admin.manage_users' => ['admin'],
        'admin.manage_roles' => ['admin'],
        'admin.view_monitoring' => ['admin', 'manager'],
        'admin.export_data' => ['admin', 'manager'],
    ];
    
    /**
     * Gibt die Rollen-Hierarchie zurück
     * 
     * @return array Role => Priority
     */
    public static function getRoleHierarchy(): array
    {
        return self::ROLE_HIERARCHY;
    }
    
    /**
     * Gibt alle Capabilities zurück
     * 
     * @return array Capability => Roles[]
     */
    public static function getCapabilities(): array
    {
        return self::CAPABILITIES;
    }
    
    /**
     * Prüft ob eine Rolle eine Capability hat (hierarchisch)
     * 
     * @param string $role Rolle (admin, manager, user, readonly)
     * @param string $capability Capability (z.B. 'org.write')
     * @return bool True wenn Rolle die Capability hat
     */
    public static function roleHasCapability(string $role, string $capability): bool
    {
        // Admin hat alle Capabilities
        if ($role === 'admin') {
            return true;
        }
        
        // Prüfe ob Capability existiert
        if (!isset(self::CAPABILITIES[$capability])) {
            return false;
        }
        
        // Prüfe ob Rolle in der Liste ist
        $allowedRoles = self::CAPABILITIES[$capability];
        return in_array($role, $allowedRoles, true);
    }
    
    /**
     * Gibt alle Capabilities einer Rolle zurück (hierarchisch)
     * 
     * @param string $role Rolle
     * @return array Liste von Capabilities
     */
    public static function getCapabilitiesForRole(string $role): array
    {
        // Admin hat alle Capabilities
        if ($role === 'admin') {
            return array_keys(self::CAPABILITIES);
        }
        
        $capabilities = [];
        foreach (self::CAPABILITIES as $capability => $allowedRoles) {
            if (in_array($role, $allowedRoles, true)) {
                $capabilities[] = $capability;
            }
        }
        
        return $capabilities;
    }
    
    /**
     * Prüft ob eine Rolle höher oder gleich einer anderen ist
     * 
     * @param string $role1 Erste Rolle
     * @param string $role2 Zweite Rolle
     * @return bool True wenn role1 >= role2
     */
    public static function isRoleHigherOrEqual(string $role1, string $role2): bool
    {
        $priority1 = self::ROLE_HIERARCHY[$role1] ?? 0;
        $priority2 = self::ROLE_HIERARCHY[$role2] ?? 0;
        
        return $priority1 >= $priority2;
    }
    
    /**
     * Gibt die höchste Rolle aus einer Liste zurück
     * 
     * @param array $roles Liste von Rollen
     * @return string|null Höchste Rolle oder null
     */
    public static function getHighestRole(array $roles): ?string
    {
        if (empty($roles)) {
            return null;
        }
        
        $highestRole = null;
        $highestPriority = 0;
        
        foreach ($roles as $role) {
            $priority = self::ROLE_HIERARCHY[$role] ?? 0;
            if ($priority > $highestPriority) {
                $highestPriority = $priority;
                $highestRole = $role;
            }
        }
        
        return $highestRole;
    }
}




