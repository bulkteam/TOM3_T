<?php
declare(strict_types=1);

namespace TOM\Service\Org;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;

/**
 * OrgAddressService
 * Handles address management for organizations
 */
class OrgAddressService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    private AuditTrailService $auditTrailService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
        $this->auditTrailService = new AuditTrailService($this->db);
    }
    
    /**
     * Fügt eine Adresse zu einer Organisation hinzu
     */
    public function addAddress(string $orgUuid, array $data, ?string $userId = null): array
    {
        $uuid = UuidHelper::generate($this->db);
        $userId = $userId ?? 'default_user';
        
        $stmt = $this->db->prepare("
            INSERT INTO org_address (
                address_uuid, org_uuid, address_type, street, address_additional, city, postal_code, 
                country, state, latitude, longitude, is_default, notes
            )
            VALUES (
                :address_uuid, :org_uuid, :address_type, :street, :address_additional, :city, :postal_code,
                :country, :state, :latitude, :longitude, :is_default, :notes
            )
        ");
        
        // Wenn diese Adresse als default markiert wird, entferne Default von anderen Adressen
        if (!empty($data['is_default'])) {
            $this->db->prepare("UPDATE org_address SET is_default = 0 WHERE org_uuid = :org_uuid")
                ->execute(['org_uuid' => $orgUuid]);
        }
        
        $stmt->execute([
            'address_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'address_type' => $data['address_type'] ?? 'other',
            'street' => $data['street'] ?? null,
            'address_additional' => $data['address_additional'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'state' => $data['state'] ?? null,
            'latitude' => isset($data['latitude']) && $data['latitude'] !== '' ? (float)$data['latitude'] : null,
            'longitude' => isset($data['longitude']) && $data['longitude'] !== '' ? (float)$data['longitude'] : null,
            'is_default' => $data['is_default'] ?? 0,
            'notes' => $data['notes'] ?? null
        ]);
        
        $address = $this->getAddress($uuid);
        
        // Protokolliere im Audit-Trail
        if ($address) {
            // Erstelle menschenlesbare Beschreibung
            $addressTypeLabels = [
                'headquarters' => 'Hauptsitz',
                'delivery' => 'Lieferadresse',
                'billing' => 'Rechnungsadresse',
                'other' => 'Sonstiges'
            ];
            $addressType = $addressTypeLabels[$address['address_type']] ?? $address['address_type'];
            $addressParts = [];
            if ($address['street']) $addressParts[] = $address['street'];
            if ($address['postal_code']) $addressParts[] = $address['postal_code'];
            if ($address['city']) $addressParts[] = $address['city'];
            $addressDescription = $addressType . (count($addressParts) > 0 ? ': ' . implode(', ', $addressParts) : '');
            
            // Speichere sowohl menschenlesbare Werte als auch vollständige JSON-Daten
            $this->insertAuditEntry(
                $orgUuid,
                $userId,
                'create',
                null,
                null,
                'address_added',
                [
                    'address_uuid' => $uuid,
                    'address_type' => $address['address_type'],
                    'city' => $address['city'],
                    'postal_code' => $address['postal_code'],
                    'country' => $address['country'],
                    'full_data' => $address // Vollständige Daten für Analyse
                ],
                ['new' => $addressDescription]
            );
        }
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgAddressAdded', $address);
        
        return $address;
    }
    
    /**
     * Holt eine einzelne Adresse
     */
    public function getAddress(string $addressUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_address WHERE address_uuid = :uuid");
        $stmt->execute(['uuid' => $addressUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Holt alle Adressen einer Organisation
     */
    public function getAddresses(string $orgUuid, ?string $addressType = null): array
    {
        $sql = "SELECT * FROM org_address WHERE org_uuid = :org_uuid";
        $params = ['org_uuid' => $orgUuid];
        
        if ($addressType) {
            $sql .= " AND address_type = :address_type";
            $params['address_type'] = $addressType;
        }
        
        $sql .= " ORDER BY is_default DESC, address_type, city";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Aktualisiert eine Adresse
     */
    public function updateAddress(string $addressUuid, array $data, ?string $userId = null): array
    {
        $userId = $userId ?? 'default_user';
        $oldAddress = $this->getAddress($addressUuid);
        
        if (!$oldAddress) {
            throw new \Exception("Adresse nicht gefunden");
        }
        
        $allowed = ['address_type', 'street', 'address_additional', 'city', 'postal_code', 'country', 'state', 'latitude', 'longitude', 'is_default', 'notes'];
        $updates = [];
        $params = ['uuid' => $addressUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                // Spezielle Behandlung für Koordinaten (können null sein)
                if (in_array($field, ['latitude', 'longitude'])) {
                    $params[$field] = ($data[$field] !== null && $data[$field] !== '') ? (float)$data[$field] : null;
                } else {
                    $params[$field] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return $oldAddress;
        }
        
        // Wenn diese Adresse als default markiert wird, entferne Default von anderen
        if (isset($data['is_default']) && $data['is_default']) {
            $this->db->prepare("UPDATE org_address SET is_default = 0 WHERE org_uuid = :org_uuid AND address_uuid != :address_uuid")
                ->execute(['org_uuid' => $oldAddress['org_uuid'], 'address_uuid' => $addressUuid]);
        }
        
        $sql = "UPDATE org_address SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE address_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $address = $this->getAddress($addressUuid);
        
        // Protokolliere Änderungen im Audit-Trail (ein Eintrag pro geändertem Feld, wie bei Stammdaten)
        if ($address) {
            $fieldLabels = [
                'address_type' => 'Adresstyp',
                'street' => 'Straße',
                'address_additional' => 'Adresszusatz',
                'city' => 'Stadt',
                'postal_code' => 'PLZ',
                'country' => 'Land',
                'state' => 'Bundesland',
                'latitude' => 'Breitengrad',
                'longitude' => 'Längengrad',
                'is_default' => 'Standardadresse',
                'notes' => 'Notizen'
            ];
            
            foreach ($allowed as $field) {
                $oldValue = $oldAddress[$field] ?? null;
                $newValue = $address[$field] ?? null;
                
                // Nur protokollieren, wenn sich der Wert geändert hat
                if ($oldValue !== $newValue) {
                    // Formatiere Werte für Anzeige
                    $oldValueStr = $this->formatAddressFieldValue($field, $oldValue);
                    $newValueStr = $this->formatAddressFieldValue($field, $newValue);
                    
                    // Erstelle einen Eintrag pro geändertem Feld (wie bei Stammdaten)
                    $this->insertAuditEntry(
                        $address['org_uuid'],
                        $userId,
                        'update',
                        'address_' . $field, // Feldname mit Präfix für eindeutige Identifikation
                        $oldValueStr,
                        'field_change',
                        [
                            'address_uuid' => $addressUuid,
                            'address_type' => $address['address_type'],
                            'city' => $address['city'] ?? '',
                            'postal_code' => $address['postal_code'] ?? '',
                            'full_data' => [
                                'old' => $oldAddress,
                                'new' => $address
                            ] // Vollständige Daten für Analyse
                        ],
                        ['old' => $oldValueStr, 'new' => $newValueStr]
                    );
                }
            }
            
            $this->eventPublisher->publish('org', $address['org_uuid'], 'OrgAddressUpdated', $address);
        }
        
        return $address;
    }
    
    /**
     * Löscht eine Adresse
     */
    public function deleteAddress(string $addressUuid, ?string $userId = null): bool
    {
        $userId = $userId ?? 'default_user';
        $address = $this->getAddress($addressUuid);
        
        if (!$address) {
            return false;
        }
        
        $orgUuid = $address['org_uuid'];
        
        $stmt = $this->db->prepare("DELETE FROM org_address WHERE address_uuid = :uuid");
        $stmt->execute(['uuid' => $addressUuid]);
        
        // Protokolliere im Audit-Trail
        $addressTypeLabels = [
            'headquarters' => 'Hauptsitz',
            'delivery' => 'Lieferadresse',
            'billing' => 'Rechnungsadresse',
            'other' => 'Sonstiges'
        ];
        $addressType = $addressTypeLabels[$address['address_type']] ?? $address['address_type'];
        $addressParts = [];
        if (!empty($address['street'])) $addressParts[] = $address['street'];
        if (!empty($address['postal_code'])) $addressParts[] = $address['postal_code'];
        if (!empty($address['city'])) $addressParts[] = $address['city'];
        $addressDescription = $addressType . (count($addressParts) > 0 ? ': ' . implode(', ', $addressParts) : '');
        
        // Speichere sowohl menschenlesbare Werte als auch vollständige JSON-Daten
        $this->insertAuditEntry(
            $orgUuid,
            $userId,
            'delete',
            null,
            $addressDescription,
            'address_deleted',
            [
                'address_uuid' => $addressUuid,
                'address_type' => $address['address_type'],
                'city' => $address['city'] ?? '',
                'postal_code' => $address['postal_code'] ?? '',
                'full_data' => $address // Vollständige Daten für Analyse
            ],
            ['old' => $addressDescription]
        );
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgAddressDeleted', ['address_uuid' => $addressUuid]);
        
        return true;
    }
    
    /**
     * Formatiert einen Adress-Feldwert für die Anzeige
     */
    private function formatAddressFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '(leer)';
        }
        
        // Spezielle Formatierung für bestimmte Felder
        if ($field === 'is_default') {
            return $value ? 'Ja' : 'Nein';
        }
        
        if ($field === 'address_type') {
            $types = [
                'headquarters' => 'Hauptsitz',
                'branch' => 'Niederlassung',
                'warehouse' => 'Lager',
                'billing' => 'Rechnungsadresse',
                'shipping' => 'Lieferadresse',
                'other' => 'Sonstiges'
            ];
            return $types[$value] ?? $value;
        }
        
        if ($field === 'country') {
            $countries = [
                'DE' => 'Deutschland',
                'AT' => 'Österreich',
                'CH' => 'Schweiz',
                'FR' => 'Frankreich',
                'IT' => 'Italien',
                'NL' => 'Niederlande',
                'BE' => 'Belgien',
                'PL' => 'Polen',
                'CZ' => 'Tschechien',
                'UK' => 'Vereinigtes Königreich'
            ];
            return $countries[$value] ?? $value;
        }
        
        return (string)$value;
    }
    
    /**
     * Fügt einen Eintrag ins Audit-Trail ein
     */
    private function insertAuditEntry(string $orgUuid, string $userId, string $action, ?string $fieldName, ?string $oldValue, string $changeType, ?array $metadata = null, ?array $additionalData = null): void
    {
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
            // Wenn additionalData direkt ein Objekt ist (z.B. alte Adresse), nicht als JSON speichern
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


