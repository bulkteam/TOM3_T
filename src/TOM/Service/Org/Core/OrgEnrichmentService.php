<?php
declare(strict_types=1);

namespace TOM\Service\Org\Core;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * OrgEnrichmentService
 * 
 * Handles enriched organization data:
 * - Get organization with all related data (addresses, relations, communication, VAT)
 * - Get industry by UUID
 */
class OrgEnrichmentService
{
    private PDO $db;
    private $orgGetter;
    private $addressGetter;
    private $relationGetter;
    private $communicationGetter;
    private $vatGetter;
    private $vatIdForAddressGetter;
    
    /**
     * @param PDO|null $db
     * @param callable|null $orgGetter Callback to get organization: function(string $orgUuid): ?array
     * @param callable|null $addressGetter Callback to get addresses: function(string $orgUuid): array
     * @param callable|null $relationGetter Callback to get relations: function(string $orgUuid): array
     * @param callable|null $communicationGetter Callback to get communication channels: function(string $orgUuid): array
     * @param callable|null $vatGetter Callback to get VAT registrations: function(string $orgUuid, bool $onlyValid): array
     * @param callable|null $vatIdForAddressGetter Callback to get VAT ID for address: function(string $addressUuid): ?array
     */
    public function __construct(
        ?PDO $db = null,
        ?callable $orgGetter = null,
        ?callable $addressGetter = null,
        ?callable $relationGetter = null,
        ?callable $communicationGetter = null,
        ?callable $vatGetter = null,
        ?callable $vatIdForAddressGetter = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->orgGetter = $orgGetter;
        $this->addressGetter = $addressGetter;
        $this->relationGetter = $relationGetter;
        $this->communicationGetter = $communicationGetter;
        $this->vatGetter = $vatGetter;
        $this->vatIdForAddressGetter = $vatIdForAddressGetter;
    }
    
    /**
     * Holt eine Organisation mit allen Details (Adressen, Relationen, Kommunikation, USt-IDs)
     */
    public function getOrgWithDetails(string $orgUuid): ?array
    {
        if (!$this->orgGetter) {
            return null;
        }
        
        $org = call_user_func($this->orgGetter, $orgUuid);
        if (!$org) {
            return null;
        }
        
        if ($this->addressGetter) {
            $org['addresses'] = call_user_func($this->addressGetter, $orgUuid);
        }
        
        if ($this->relationGetter) {
            $org['relations'] = call_user_func($this->relationGetter, $orgUuid);
        }
        
        if ($this->communicationGetter) {
            $org['communication_channels'] = call_user_func($this->communicationGetter, $orgUuid);
        }
        
        if ($this->vatGetter) {
            $org['vat_registrations'] = call_user_func($this->vatGetter, $orgUuid, true);
        }

        // Optional: Lade USt-IDs für Adressen (nur wenn vorhanden)
        if ($this->addressGetter && $this->vatIdForAddressGetter) {
            $addresses = call_user_func($this->addressGetter, $orgUuid);
            foreach ($addresses as &$address) {
                $address['vat_id'] = call_user_func($this->vatIdForAddressGetter, $address['address_uuid']);
            }
            unset($address);
            $org['addresses'] = $addresses;
        }

        // Industry-Namen werden bereits von getOrg() über JOIN geladen
        // Falls sie fehlen, lade sie nach (Fallback)
        // Verwende name_short wenn verfügbar, sonst name
        if ($org['industry_main_uuid'] && empty($org['industry_main_name'])) {
            $mainIndustry = $this->getIndustryByUuid($org['industry_main_uuid']);
            $org['industry_main_name'] = $mainIndustry ? ($mainIndustry['name_short'] ?? $mainIndustry['name']) : null;
        }
        if ($org['industry_sub_uuid'] && empty($org['industry_sub_name'])) {
            $subIndustry = $this->getIndustryByUuid($org['industry_sub_uuid']);
            $org['industry_sub_name'] = $subIndustry ? ($subIndustry['name_short'] ?? $subIndustry['name']) : null;
        }
        // 3-stufige Hierarchie
        if ($org['industry_level1_uuid'] && empty($org['industry_level1_name'])) {
            $level1Industry = $this->getIndustryByUuid($org['industry_level1_uuid']);
            $org['industry_level1_name'] = $level1Industry ? ($level1Industry['name_short'] ?? $level1Industry['name']) : null;
        }
        if ($org['industry_level2_uuid'] && empty($org['industry_level2_name'])) {
            $level2Industry = $this->getIndustryByUuid($org['industry_level2_uuid']);
            $org['industry_level2_name'] = $level2Industry ? ($level2Industry['name_short'] ?? $level2Industry['name']) : null;
        }
        if ($org['industry_level3_uuid'] && empty($org['industry_level3_name'])) {
            $level3Industry = $this->getIndustryByUuid($org['industry_level3_uuid']);
            $org['industry_level3_name'] = $level3Industry ? ($level3Industry['name_short'] ?? $level3Industry['name']) : null;
        }
        
        return $org;
    }
    
    /**
     * Holt eine Branche anhand der UUID
     */
    public function getIndustryByUuid(string $industryUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM industry WHERE industry_uuid = :uuid");
        $stmt->execute(['uuid' => $industryUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}



