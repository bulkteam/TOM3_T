<?php
declare(strict_types=1);

namespace TOM\Service\Org\Account;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * OrgAccountOwnerService
 * 
 * Handles account owner management for organizations:
 * - Get organizations by owner
 * - Get available account owners
 * - Get account owners with display names
 */
class OrgAccountOwnerService
{
    private PDO $db;
    private $orgGetter;
    private $healthGetter;
    
    /**
     * @param PDO|null $db
     * @param callable|null $orgGetter Callback to get organization: function(string $orgUuid): ?array
     * @param callable|null $healthGetter Callback to get account health: function(string $orgUuid): array
     */
    public function __construct(
        ?PDO $db = null,
        ?callable $orgGetter = null,
        ?callable $healthGetter = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->orgGetter = $orgGetter;
        $this->healthGetter = $healthGetter;
    }
    
    /**
     * Hole alle Organisationen eines Account Owners mit Gesundheitsstatus
     */
    public function getAccountsByOwner(string $userId, bool $includeHealth = true): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM org
            WHERE account_owner_user_id = :user_id
            ORDER BY name
        ");
        $stmt->execute(['user_id' => $userId]);
        $orgs = $stmt->fetchAll();
        
        if ($includeHealth && $this->healthGetter) {
            foreach ($orgs as &$org) {
                $org['health'] = call_user_func($this->healthGetter, $org['org_uuid']);
            }
        }
        
        return $orgs;
    }
    
    /**
     * Hole Liste aller verfügbaren Account Owners (User-IDs)
     * Kombiniert:
     * 1. User aus Config-Datei (falls vorhanden) - nur wenn can_be_account_owner = true
     * 2. User, die bereits als Account Owner verwendet werden
     */
    public function getAvailableAccountOwners(): array
    {
        // Hole alle aktiven User aus der DB
        $stmt = $this->db->query("
            SELECT 
                u.user_id,
                u.name,
                u.email
            FROM users u
            WHERE u.is_active = 1
            ORDER BY u.name
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu Array von user_ids (als String für Kompatibilität)
        $userIds = [];
        foreach ($users as $user) {
            $userIds[] = (string)$user['user_id'];
        }
        
        return $userIds;
    }
    
    /**
     * Hole Liste aller verfügbaren Account Owners mit Display-Namen
     * Gibt Array zurück: ['user_id' => 'display_name', ...]
     */
    public function getAvailableAccountOwnersWithNames(): array
    {
        // Hole alle aktiven User aus der DB
        $stmt = $this->db->query("
            SELECT 
                u.user_id,
                u.name,
                u.email
            FROM users u
            WHERE u.is_active = 1
            ORDER BY u.name
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Konvertiere zu Array: user_id => name (email)
        $owners = [];
        foreach ($users as $user) {
            $userId = (string)$user['user_id'];
            $displayName = $user['name'];
            if ($user['email']) {
                $displayName .= ' (' . $user['email'] . ')';
            }
            $owners[$userId] = $displayName;
        }
        
        return $owners;
    }
}

