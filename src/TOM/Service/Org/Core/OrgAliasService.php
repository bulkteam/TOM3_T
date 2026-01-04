<?php
declare(strict_types=1);

namespace TOM\Service\Org\Core;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

/**
 * OrgAliasService
 * 
 * Handles alias management for organizations:
 * - Add alias (former names, trade names)
 * - Get aliases for an organization
 */
class OrgAliasService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Fügt einen Alias (früherer Name, Handelsname) hinzu
     */
    public function addAlias(string $orgUuid, string $aliasName, string $aliasType = 'other'): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_alias (alias_uuid, org_uuid, alias_name, alias_type)
            VALUES (:alias_uuid, :org_uuid, :alias_name, :alias_type)
        ");
        
        $stmt->execute([
            'alias_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'alias_name' => $aliasName,
            'alias_type' => $aliasType
        ]);
        
        $stmt = $this->db->prepare("SELECT * FROM org_alias WHERE alias_uuid = :uuid");
        $stmt->execute(['uuid' => $uuid]);
        return $stmt->fetch() ?: [];
    }
    
    /**
     * Holt alle Aliases einer Organisation
     */
    public function getAliases(string $orgUuid): array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_alias WHERE org_uuid = :uuid ORDER BY is_primary DESC, alias_name");
        $stmt->execute(['uuid' => $orgUuid]);
        return $stmt->fetchAll();
    }
}

