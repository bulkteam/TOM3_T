<?php
declare(strict_types=1);

namespace TOM\Service\Org\Audit;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * OrgAuditHelperService
 * 
 * Handles audit trail helpers for organizations:
 * - Field value resolution (UUID → display name)
 * - Audit entry insertion
 */
class OrgAuditHelperService
{
    private PDO $db;
    private $industryGetter;
    private $accountOwnerGetter;
    
    /**
     * @param PDO|null $db
     * @param callable|null $industryGetter Callback to get industry: function(string $industryUuid): ?array
     * @param callable|null $accountOwnerGetter Callback to get account owners: function(): array
     */
    public function __construct(
        ?PDO $db = null,
        ?callable $industryGetter = null,
        ?callable $accountOwnerGetter = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->industryGetter = $industryGetter;
        $this->accountOwnerGetter = $accountOwnerGetter;
    }
    
    /**
     * Resolviert einen Feldwert zu seinem Klarnamen (z.B. UUID → Branchenname)
     * Wird für Audit-Trail verwendet
     */
    public function resolveFieldValue(string $field, $value): string
    {
        if (empty($value)) {
            return '(leer)';
        }
        
        // Branche (Hauptklasse)
        if ($field === 'industry_main_uuid' && $value && $this->industryGetter) {
            $industry = call_user_func($this->industryGetter, $value);
            return $industry ? ($industry['name_short'] ?? $industry['name']) : $value;
        }
        
        // Branche (Unterklasse)
        if ($field === 'industry_sub_uuid' && $value && $this->industryGetter) {
            $industry = call_user_func($this->industryGetter, $value);
            return $industry ? ($industry['name_short'] ?? $industry['name']) : $value;
        }
        
        // 3-stufige Hierarchie
        if ($field === 'industry_level1_uuid' && $value && $this->industryGetter) {
            $industry = call_user_func($this->industryGetter, $value);
            return $industry ? ($industry['name_short'] ?? $industry['name']) : $value;
        }
        
        if ($field === 'industry_level2_uuid' && $value && $this->industryGetter) {
            $industry = call_user_func($this->industryGetter, $value);
            return $industry ? ($industry['name_short'] ?? $industry['name']) : $value;
        }
        
        if ($field === 'industry_level3_uuid' && $value && $this->industryGetter) {
            $industry = call_user_func($this->industryGetter, $value);
            return $industry ? ($industry['name_short'] ?? $industry['name']) : $value;
        }
        
        // Account Owner
        if ($field === 'account_owner_user_id' && $value && $this->accountOwnerGetter) {
            $owners = call_user_func($this->accountOwnerGetter);
            return $owners[$value] ?? $value;
        }
        
        // Status (mit Klarnamen)
        if ($field === 'status' && $value) {
            $statusLabels = [
                'lead' => 'Lead',
                'prospect' => 'Interessent',
                'customer' => 'Kunde',
                'inactive' => 'Inaktiv'
            ];
            return $statusLabels[$value] ?? $value;
        }
        
        // Org Kind (mit Klarnamen)
        if ($field === 'org_kind' && $value) {
            $kindLabels = [
                'customer' => 'Kunde',
                'supplier' => 'Lieferant',
                'consultant' => 'Berater',
                'engineering_firm' => 'Ingenieurbüro',
                'other' => 'Sonstiges'
            ];
            return $kindLabels[$value] ?? $value;
        }
        
        // Standard: Wert als String zurückgeben
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        
        return (string)$value;
    }
    
    /**
     * Fügt einen Eintrag ins Audit-Trail ein
     */
    public function insertAuditEntry(
        string $orgUuid,
        string $userId,
        string $action,
        ?string $fieldName,
        ?string $oldValue,
        string $changeType,
        ?array $metadata = null,
        ?array $additionalData = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO org_audit_trail (
                org_uuid, user_id, action, field_name, old_value, new_value, change_type, metadata
            ) VALUES (
                :org_uuid, :user_id, :action, :field_name, :old_value, :new_value, :change_type, :metadata
            )
        ");
        
        $newValue = null;
        if ($additionalData && isset($additionalData['new'])) {
            // Wenn 'new' vorhanden ist, verwende es direkt (sollte bereits formatiert sein)
            $newValue = is_array($additionalData['new']) || is_object($additionalData['new']) 
                ? json_encode($additionalData['new']) 
                : (string)$additionalData['new'];
        } elseif ($additionalData && isset($additionalData['old'])) {
            // Wenn nur 'old' vorhanden ist (z.B. bei Delete)
            $newValue = null;
        } elseif ($additionalData && !isset($additionalData['new']) && !isset($additionalData['old'])) {
            // Wenn additionalData direkt ein Objekt ist (z.B. alter Kanal), nicht als JSON speichern
            // Stattdessen nur in metadata speichern
            $newValue = null;
        }
        
        $metadataJson = null;
        if ($metadata || $additionalData) {
            $metadataJson = json_encode($metadata ?? $additionalData ?? []);
        }
        
        $stmt->execute([
            'org_uuid' => $orgUuid,
            'user_id' => $userId,
            'action' => $action,
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'change_type' => $changeType,
            'metadata' => $metadataJson
        ]);
    }
}


