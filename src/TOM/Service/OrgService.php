<?php
declare(strict_types=1);

namespace TOM\Service;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Utils\UrlHelper;
use TOM\Infrastructure\Audit\AuditTrailService;
use TOM\Infrastructure\Access\AccessTrackingService;
use TOM\Service\BaseEntityService;
use TOM\Service\Org\OrgAddressService;
use TOM\Service\Org\OrgVatService;
use TOM\Service\Org\OrgRelationService;
use TOM\Service\Org\Core\OrgCrudService;
use TOM\Service\Org\Core\OrgAliasService;
use TOM\Service\Org\Core\OrgCustomerNumberService;
use TOM\Service\Org\Core\OrgEnrichmentService;
use TOM\Service\Org\Communication\OrgCommunicationService;
use TOM\Service\Org\Search\OrgSearchService;
use TOM\Service\Org\Account\OrgAccountHealthService;
use TOM\Service\Org\Account\OrgAccountOwnerService;
use TOM\Service\Org\Management\OrgArchiveService;
use TOM\Service\Org\Audit\OrgAuditHelperService;

class OrgService extends BaseEntityService
{
    private AccessTrackingService $accessTrackingService;
    private OrgAddressService $addressService;
    private OrgVatService $vatService;
    private OrgRelationService $relationService;
    private OrgCrudService $crudService;
    private OrgCommunicationService $communicationService;
    private OrgSearchService $searchService;
    private OrgAccountHealthService $accountHealthService;
    private OrgAccountOwnerService $accountOwnerService;
    private OrgArchiveService $archiveService;
    private OrgAliasService $aliasService;
    private OrgCustomerNumberService $customerNumberService;
    private OrgEnrichmentService $enrichmentService;
    private OrgAuditHelperService $auditHelperService;
    
    public function __construct(
        ?PDO $db = null,
        ?AccessTrackingService $accessTrackingService = null,
        ?OrgAddressService $addressService = null,
        ?OrgVatService $vatService = null,
        ?OrgRelationService $relationService = null
    ) {
        parent::__construct($db);
        $this->accessTrackingService = $accessTrackingService ?? new AccessTrackingService($this->db);
        $this->addressService = $addressService ?? new OrgAddressService($this->db);
        $this->vatService = $vatService ?? new OrgVatService($this->db);
        $this->relationService = $relationService ?? new OrgRelationService($this->db);
        
        // Phase 3: Customer Number Service (keine Abhängigkeiten)
        $this->customerNumberService = new OrgCustomerNumberService($this->db);
        
        // Phase 3: Alias Service (keine Abhängigkeiten)
        $this->aliasService = new OrgAliasService($this->db);
        
        // Phase 3: Enrichment Service (braucht nur DB für getIndustryByUuid)
        $this->enrichmentService = new OrgEnrichmentService($this->db);
        
        // Phase 2: Account Owner Service (keine Abhängigkeiten für getAvailableAccountOwnersWithNames)
        $this->accountOwnerService = new OrgAccountOwnerService($this->db);
        
        // Phase 3: Audit Helper Service (braucht EnrichmentService und AccountOwnerService)
        $this->auditHelperService = new OrgAuditHelperService(
            $this->db,
            [$this->enrichmentService, 'getIndustryByUuid'], // Industry Getter
            [$this->accountOwnerService, 'getAvailableAccountOwnersWithNames'] // Account Owner Getter
        );
        
        // Neue modulare Services mit Callbacks
        $this->crudService = new OrgCrudService(
            $this->db,
            [$this->customerNumberService, 'generateCustomerNumber'], // Customer Number Generator
            [$this->auditHelperService, 'resolveFieldValue'] // Field Resolver
        );
        
        $this->communicationService = new OrgCommunicationService(
            $this->db,
            [$this->auditHelperService, 'insertAuditEntry'] // Audit Entry Callback
        );
        
        $this->searchService = new OrgSearchService($this->db);
        
        // Phase 2: Account Health Service (braucht CrudService)
        $this->accountHealthService = new OrgAccountHealthService(
            $this->db,
            [$this->crudService, 'getOrg'] // Org Getter
        );
        
        // Phase 2: Account Owner Service - Update mit Health Getter
        $this->accountOwnerService = new OrgAccountOwnerService(
            $this->db,
            [$this->crudService, 'getOrg'], // Org Getter
            [$this->accountHealthService, 'getAccountHealth'] // Health Getter
        );
        
        // Phase 2: Archive Service (braucht CrudService und AuditHelperService)
        $this->archiveService = new OrgArchiveService(
            $this->db,
            [$this->crudService, 'getOrg'], // Org Getter
            [$this->auditHelperService, 'insertAuditEntry'] // Audit Entry Callback
        );
        
        // Phase 3: Enrichment Service - Update mit allen Gettern
        $this->enrichmentService = new OrgEnrichmentService(
            $this->db,
            [$this->crudService, 'getOrg'], // Org Getter
            [$this->addressService, 'getAddresses'], // Address Getter
            [$this->relationService, 'getRelations'], // Relation Getter
            [$this->communicationService, 'getCommunicationChannels'], // Communication Getter
            [$this->vatService, 'getVatRegistrations'], // VAT Getter
            [$this->vatService, 'getVatIdForAddress'] // VAT ID for Address Getter
        );
        
        // Phase 3: Audit Helper Service - Update mit aktualisiertem AccountOwnerService
        $this->auditHelperService = new OrgAuditHelperService(
            $this->db,
            [$this->enrichmentService, 'getIndustryByUuid'], // Industry Getter
            [$this->accountOwnerService, 'getAvailableAccountOwnersWithNames'] // Account Owner Getter
        );
    }
    
    public function createOrg(array $data, ?string $userId = null): array
    {
        return $this->crudService->createOrg($data, $userId);
    }
    
    public function getOrg(string $orgUuid): ?array
    {
        return $this->crudService->getOrg($orgUuid);
    }
    
    public function updateOrg(string $orgUuid, array $data, ?string $userId = null): array
    {
        return $this->crudService->updateOrg($orgUuid, $data, $userId, [$this, 'resolveFieldValue']);
    }
    
    /**
     * Berechnet Account-Gesundheit für eine Organisation
     * Gibt Status (green/yellow/red) und Gründe zurück
     */
    public function getAccountHealth(string $orgUuid): array
    {
        return $this->accountHealthService->getAccountHealth($orgUuid);
    }
    
    /**
     * Hole alle Organisationen eines Account Owners mit Gesundheitsstatus
     */
    public function getAccountsByOwner(string $userId, bool $includeHealth = true): array
    {
        return $this->accountOwnerService->getAccountsByOwner($userId, $includeHealth);
    }
    
    /**
     * Hole Liste aller verfügbaren Account Owners (User-IDs)
     * Kombiniert:
     * 1. User aus Config-Datei (falls vorhanden) - nur wenn can_be_account_owner = true
     * 2. User, die bereits als Account Owner verwendet werden
     */
    public function getAvailableAccountOwners(): array
    {
        return $this->accountOwnerService->getAvailableAccountOwners();
    }
    
    /**
     * Hole Liste aller verfügbaren Account Owners mit Display-Namen
     * Gibt Array zurück: ['user_id' => 'display_name', ...]
     */
    public function getAvailableAccountOwnersWithNames(): array
    {
        return $this->accountOwnerService->getAvailableAccountOwnersWithNames();
    }
    
    public function listOrgs(array $filters = []): array
    {
        return $this->searchService->listOrgs($filters);
    }
    
    // ============================================================================
    // ALIAS MANAGEMENT (frühere Namen, Handelsnamen)
    // ============================================================================
    
    public function addAlias(string $orgUuid, string $aliasName, string $aliasType = 'other'): array
    {
        return $this->aliasService->addAlias($orgUuid, $aliasName, $aliasType);
    }
    
    public function getAliases(string $orgUuid): array
    {
        return $this->aliasService->getAliases($orgUuid);
    }
    
    // ============================================================================
    // USER ACCESS TRACKING (Zuletzt verwendet, Favoriten)
    // ============================================================================
    
    public function trackAccess(string $userId, string $orgUuid, string $accessType = 'recent'): void
    {
        $this->accessTrackingService->trackAccess('org', $userId, $orgUuid, $accessType);
    }
    
    public function getRecentOrgs(string $userId, int $limit = 10): array
    {
        return $this->accessTrackingService->getRecentEntities('org', $userId, $limit);
    }
    
    public function getFavoriteOrgs(string $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT o.*
            FROM org o
            INNER JOIN user_org_access uoa ON o.org_uuid = uoa.org_uuid
            WHERE uoa.user_id = :user_id AND uoa.access_type = 'favorite'
            ORDER BY uoa.accessed_at DESC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Volltextsuche Organisationen (Search-first)
     * Sucht in: Name, Aliases, external_ref, Adressen (Stadt), Branche, Marktsegment
     * Gruppiert nach Relevanz
     */
    public function searchOrgs(string $query, array $filters = [], int $limit = 20): array
    {
        return $this->searchService->searchOrgs($query, $filters, $limit);
    }
    
    /**
     * Ähnliche Organisationen finden (für "Meintest du...?")
     */
    public function findSimilarOrgs(string $query, int $limit = 5): array
    {
        return $this->searchService->findSimilarOrgs($query, $limit);
    }
    
    // ============================================================================
    // ADDRESS MANAGEMENT (delegiert an OrgAddressService)
    // ============================================================================
    
    public function addAddress(string $orgUuid, array $data, ?string $userId = null): array
    {
        return $this->addressService->addAddress($orgUuid, $data, $userId);
    }
    
    public function getAddress(string $addressUuid): ?array
    {
        return $this->addressService->getAddress($addressUuid);
    }
    
    public function getAddresses(string $orgUuid, ?string $addressType = null): array
    {
        return $this->addressService->getAddresses($orgUuid, $addressType);
    }
    
    public function updateAddress(string $addressUuid, array $data, ?string $userId = null): array
    {
        return $this->addressService->updateAddress($addressUuid, $data, $userId);
    }
    
    public function deleteAddress(string $addressUuid, ?string $userId = null): bool
    {
        return $this->addressService->deleteAddress($addressUuid, $userId);
    }
    
    // ============================================================================
    // VAT REGISTRATION (USt-ID) MANAGEMENT (delegiert an OrgVatService)
    // ============================================================================
    
    /**
     * Fügt eine USt-ID-Registrierung für eine Organisation hinzu
     * address_uuid ist optional (nur für Kontext)
     */
    public function addVatRegistration(string $orgUuid, array $data, ?string $userId = null): array
    {
        return $this->vatService->addVatRegistration($orgUuid, $data, $userId);
    }
    
    /**
     * Holt eine USt-ID-Registrierung
     */
    public function getVatRegistration(string $vatRegistrationUuid): ?array
    {
        return $this->vatService->getVatRegistration($vatRegistrationUuid);
    }
    
    /**
     * Holt alle USt-ID-Registrierungen einer Organisation
     */
    public function getVatRegistrations(string $orgUuid, bool $onlyValid = true): array
    {
        return $this->vatService->getVatRegistrations($orgUuid, $onlyValid);
    }
    
    /**
     * Holt die USt-ID für eine bestimmte Adresse (optional, für Kontext)
     */
    public function getVatIdForAddress(string $addressUuid): ?array
    {
        return $this->vatService->getVatIdForAddress($addressUuid);
    }
    
    /**
     * Aktualisiert eine USt-ID-Registrierung
     */
    public function updateVatRegistration(string $vatRegistrationUuid, array $data, ?string $userId = null): array
    {
        return $this->vatService->updateVatRegistration($vatRegistrationUuid, $data, $userId);
    }
    
    /**
     * Löscht eine USt-ID-Registrierung
     */
    public function deleteVatRegistration(string $vatRegistrationUuid, ?string $userId = null): bool
    {
        return $this->vatService->deleteVatRegistration($vatRegistrationUuid, $userId);
    }
    
    // ============================================================================
    // RELATION MANAGEMENT
    // ============================================================================
    
    public function addRelation(array $data, ?string $userId = null): array
    {
        return $this->relationService->addRelation($data, $userId);
    }
    
    public function getRelation(string $relationUuid): ?array
    {
        return $this->relationService->getRelation($relationUuid);
    }
    
    public function getRelations(string $orgUuid, ?string $direction = null): array
    {
        return $this->relationService->getRelations($orgUuid, $direction);
    }
    public function updateRelation(string $relationUuid, array $data, ?string $userId = null): array
    {
        return $this->relationService->updateRelation($relationUuid, $data, $userId);
    }
    public function deleteRelation(string $relationUuid, ?string $userId = null): bool
    {
        return $this->relationService->deleteRelation($relationUuid, $userId);
    }
    // ============================================================================
    // ENRICHED ORG DATA
    // ============================================================================
    
    public function getOrgWithDetails(string $orgUuid): ?array
    {
        return $this->enrichmentService->getOrgWithDetails($orgUuid);
    }
    
    /**
     * Holt eine Branche anhand der UUID
     */
    public function getIndustryByUuid(string $industryUuid): ?array
    {
        return $this->enrichmentService->getIndustryByUuid($industryUuid);
    }
    
    /**
     * Gibt die nächste verfügbare Kundennummer zurück (ohne sie zu vergeben)
     * 
     * @return string Numerische Kundennummer
     */
    public function getNextCustomerNumber(): string
    {
        return $this->customerNumberService->getNextCustomerNumber();
    }

    /**
     * Generiert eine neue Kundennummer basierend auf der höchsten vorhandenen Nummer
     * 
     * @return string Numerische Kundennummer
     */
    private function generateCustomerNumber(): string
    {
        return $this->customerNumberService->generateCustomerNumber();
    }
    
    // ============================================================================
    // COMMUNICATION CHANNEL MANAGEMENT
    // ============================================================================
    
    public function addCommunicationChannel(string $orgUuid, array $data, ?string $userId = null): array
    {
        return $this->communicationService->addCommunicationChannel($orgUuid, $data, $userId);
    }
    
    public function getCommunicationChannel(string $channelUuid): ?array
    {
        return $this->communicationService->getCommunicationChannel($channelUuid);
    }
    
    public function getCommunicationChannels(string $orgUuid, ?string $channelType = null): array
    {
        return $this->communicationService->getCommunicationChannels($orgUuid, $channelType);
    }
    
    public function updateCommunicationChannel(string $channelUuid, array $data, ?string $userId = null): array
    {
        return $this->communicationService->updateCommunicationChannel($channelUuid, $data, $userId);
    }
    
    public function deleteCommunicationChannel(string $channelUuid, ?string $userId = null): bool
    {
        return $this->communicationService->deleteCommunicationChannel($channelUuid, $userId);
    }
    
    /**
     * Formatiert eine Telefonnummer für die Anzeige
     */
    public function formatPhoneNumber(?string $countryCode, ?string $areaCode, ?string $number, ?string $extension = null): string
    {
        return $this->communicationService->formatPhoneNumber($countryCode, $areaCode, $number, $extension);
    }
    
    // ============================================================================
    // AUDIT TRAIL
    // ============================================================================
    
    /**
     * Resolviert einen Feldwert zu seinem Klarnamen (z.B. UUID → Branchenname)
     * Wird für Audit-Trail verwendet
     */
    public function resolveFieldValue(string $field, $value): string
    {
        return $this->auditHelperService->resolveFieldValue($field, $value);
    }
    
    /**
     * Fügt einen Eintrag ins Audit-Trail ein
     */
    private function insertAuditEntry(string $orgUuid, string $userId, string $action, ?string $fieldName, ?string $oldValue, string $changeType, ?array $metadata = null, ?array $additionalData = null): void
    {
        $this->auditHelperService->insertAuditEntry($orgUuid, $userId, $action, $fieldName, $oldValue, $changeType, $metadata, $additionalData);
    }
    
    /**
     * Holt das Audit-Trail für eine Organisation
     */
    public function getAuditTrail(string $orgUuid, int $limit = 100): array
    {
        return $this->auditTrailService->getAuditTrail('org', $orgUuid, $limit);
    }
    
    /**
     * Archiviert eine Organisation
     */
    public function archiveOrg(string $orgUuid, string $userId): array
    {
        return $this->archiveService->archiveOrg($orgUuid, $userId);
    }
    
    /**
     * Reaktiviert eine archivierte Organisation
     */
    public function unarchiveOrg(string $orgUuid, string $userId): array
    {
        return $this->archiveService->unarchiveOrg($orgUuid, $userId);
    }
}


