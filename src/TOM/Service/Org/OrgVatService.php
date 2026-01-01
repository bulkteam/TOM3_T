<?php
declare(strict_types=1);

namespace TOM\Service\Org;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Events\EventPublisher;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Audit\AuditTrailService;

/**
 * OrgVatService
 * Handles VAT registration (USt-ID) management for organizations
 */
class OrgVatService
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
     * Fügt eine USt-ID-Registrierung für eine Organisation hinzu
     * address_uuid ist optional (nur für Kontext)
     */
    public function addVatRegistration(string $orgUuid, array $data, ?string $userId = null): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        // Wenn diese USt-ID als primary_for_country markiert wird, entferne Primary von anderen
        if (!empty($data['is_primary_for_country'])) {
            $stmt = $this->db->prepare("
                UPDATE org_vat_registration 
                SET is_primary_for_country = 0 
                WHERE org_uuid = :org_uuid 
                  AND country_code = :country_code
                  AND (valid_to IS NULL OR valid_to >= CURDATE())
            ");
            $stmt->execute([
                'org_uuid' => $orgUuid,
                'country_code' => $data['country_code']
            ]);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO org_vat_registration (
                vat_registration_uuid, org_uuid, address_uuid, vat_id, country_code,
                valid_from, valid_to, is_primary_for_country, location_type, notes
            )
            VALUES (
                :vat_registration_uuid, :org_uuid, :address_uuid, :vat_id, :country_code,
                :valid_from, :valid_to, :is_primary_for_country, :location_type, :notes
            )
        ");
        
        // Konvertiere leere Strings oder null zu heutigem Datum
        $validFrom = $data['valid_from'] ?? null;
        if ($validFrom === '' || $validFrom === null) {
            $validFrom = date('Y-m-d'); // Default auf heutiges Datum wenn leer oder null
        }
        
        $validTo = $data['valid_to'] ?? null;
        if ($validTo === '') {
            $validTo = null;
        }
        
        $locationType = $data['location_type'] ?? null;
        if ($locationType === '') {
            $locationType = null;
        }
        
        $notes = $data['notes'] ?? null;
        if ($notes === '') {
            $notes = null;
        }
        
        $stmt->execute([
            'vat_registration_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'address_uuid' => $data['address_uuid'] ?? null,
            'vat_id' => $data['vat_id'],
            'country_code' => $data['country_code'],
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'is_primary_for_country' => $data['is_primary_for_country'] ?? 0,
            'location_type' => $locationType,
            'notes' => $notes
        ]);
        
        $vatReg = $this->getVatRegistration($uuid);
        
        // Protokolliere im Audit-Trail
        if ($vatReg) {
            $userId = $userId ?? 'default_user';
            
            // Erstelle menschenlesbare Beschreibung
            $countryName = $this->formatVatFieldValue('country_code', $vatReg['country_code']);
            $vatDescription = "USt-ID {$vatReg['vat_id']} ({$countryName})";
            if (!empty($vatReg['valid_from'])) {
                $vatDescription .= " - Gültig ab " . date('d.m.Y', strtotime($vatReg['valid_from']));
            }
            
            // Speichere sowohl menschenlesbare Werte als auch vollständige JSON-Daten
            $this->insertAuditEntry(
                $orgUuid,
                $userId,
                'create',
                null,
                null,
                'vat_added',
                [
                    'vat_registration_uuid' => $uuid,
                    'vat_id' => $vatReg['vat_id'],
                    'country_code' => $vatReg['country_code'],
                    'full_data' => $vatReg // Vollständige Daten für Analyse
                ],
                ['new' => $vatDescription]
            );
        }
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgVatRegistrationAdded', $vatReg);
        
        return $vatReg;
    }
    
    /**
     * Holt eine USt-ID-Registrierung
     */
    public function getVatRegistration(string $vatRegistrationUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT vr.*, 
                   oa.street, oa.city, oa.country, oa.location_type
            FROM org_vat_registration vr
            LEFT JOIN org_address oa ON vr.address_uuid = oa.address_uuid
            WHERE vr.vat_registration_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $vatRegistrationUuid]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Holt alle USt-ID-Registrierungen einer Organisation
     */
    public function getVatRegistrations(string $orgUuid, bool $onlyValid = true): array
    {
        $sql = "
            SELECT vr.*, 
                   oa.street, oa.city, oa.country, oa.location_type
            FROM org_vat_registration vr
            LEFT JOIN org_address oa ON vr.address_uuid = oa.address_uuid
            WHERE vr.org_uuid = :org_uuid
        ";
        
        if ($onlyValid) {
            $sql .= " AND (vr.valid_to IS NULL OR vr.valid_to >= CURDATE())";
        }
        
        $sql .= " ORDER BY vr.country_code, vr.is_primary_for_country DESC, vr.valid_from DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['org_uuid' => $orgUuid]);
        return $stmt->fetchAll();
    }
    
    /**
     * Holt die USt-ID für eine bestimmte Adresse (optional, für Kontext)
     */
    public function getVatIdForAddress(string $addressUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT vr.*
            FROM org_vat_registration vr
            WHERE vr.address_uuid = :address_uuid
              AND (vr.valid_to IS NULL OR vr.valid_to >= CURDATE())
            ORDER BY vr.is_primary_for_country DESC, vr.valid_from DESC
            LIMIT 1
        ");
        $stmt->execute(['address_uuid' => $addressUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Aktualisiert eine USt-ID-Registrierung
     */
    public function updateVatRegistration(string $vatRegistrationUuid, array $data, ?string $userId = null): array
    {
        $userId = $userId ?? 'default_user';
        $oldVatReg = $this->getVatRegistration($vatRegistrationUuid);
        
        if (!$oldVatReg) {
            throw new \Exception("USt-ID-Registrierung nicht gefunden");
        }
        
        $allowed = ['vat_id', 'country_code', 'valid_from', 'valid_to', 'is_primary_for_country', 'location_type', 'notes'];
        $updates = [];
        $params = ['uuid' => $vatRegistrationUuid];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                // Spezielle Behandlung für NULL-Werte (z.B. valid_to = null für "aktuell gültig")
                if ($data[$field] === null || $data[$field] === '') {
                    if (in_array($field, ['valid_to', 'location_type', 'notes'])) {
                        // Verwende COALESCE oder direkt NULL in SQL
                        $updates[] = "$field = NULL";
                    }
                    // Für andere Felder wird der leere Wert ignoriert
                } else {
                    $updates[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }
        }
        
        if (empty($updates)) {
            return $this->getVatRegistration($vatRegistrationUuid);
        }
        
        // Wenn is_primary_for_country gesetzt wird, entferne Primary von anderen
        if (isset($data['is_primary_for_country']) && $data['is_primary_for_country']) {
            $vatReg = $this->getVatRegistration($vatRegistrationUuid);
            if ($vatReg) {
                $stmt = $this->db->prepare("
                    UPDATE org_vat_registration 
                    SET is_primary_for_country = 0 
                    WHERE org_uuid = :org_uuid 
                      AND country_code = :country_code
                      AND vat_registration_uuid != :vat_uuid
                      AND (valid_to IS NULL OR valid_to >= CURDATE())
                ");
                $stmt->execute([
                    'org_uuid' => $vatReg['org_uuid'],
                    'country_code' => $data['country_code'] ?? $vatReg['country_code'],
                    'vat_uuid' => $vatRegistrationUuid
                ]);
            }
        }
        
        // Prüfe nochmal, ob nach der Verarbeitung noch Updates vorhanden sind
        if (empty($updates)) {
            return $this->getVatRegistration($vatRegistrationUuid);
        }
        
        $sql = "UPDATE org_vat_registration SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE vat_registration_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        
        if (!$stmt) {
            throw new \Exception("Failed to prepare SQL statement: " . implode(', ', $this->db->errorInfo()));
        }
        
        $result = $stmt->execute($params);
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new \Exception("Failed to execute SQL: " . ($errorInfo[2] ?? 'Unknown error'));
        }
        
        // Hinweis: rowCount() kann bei MySQL/MariaDB 0 zurückgeben, auch wenn die Query erfolgreich war
        // (z.B. wenn die Daten unverändert sind). Das ist kein Fehler.
        $affectedRows = $stmt->rowCount();
        
        // Hole die aktualisierten Daten (unabhängig von rowCount)
        $vatReg = $this->getVatRegistration($vatRegistrationUuid);
        
        if (!$vatReg) {
            // Fallback: Direkt aus der DB lesen (ohne JOIN)
            $fallbackStmt = $this->db->prepare("SELECT * FROM org_vat_registration WHERE vat_registration_uuid = :uuid");
            $fallbackStmt->execute(['uuid' => $vatRegistrationUuid]);
            $vatReg = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vatReg) {
                throw new \Exception("VAT registration not found after update (vat_registration_uuid: $vatRegistrationUuid, affected rows: $affectedRows)");
            }
            
            // Füge leere Adress-Felder hinzu für Konsistenz
            $vatReg['street'] = null;
            $vatReg['city'] = null;
            $vatReg['country'] = null;
        }
        
        // Protokolliere Änderungen im Audit-Trail (ein Eintrag pro geändertem Feld, wie bei Stammdaten)
        if ($vatReg && $oldVatReg) {
            $fieldLabels = [
                'vat_id' => 'USt-ID',
                'country_code' => 'Länderkennzeichen',
                'valid_from' => 'Gültig ab',
                'valid_to' => 'Gültig bis',
                'is_primary_for_country' => 'Primär für Land',
                'location_type' => 'Standorttyp',
                'notes' => 'Notizen'
            ];
            
            foreach ($allowed as $field) {
                $oldValue = $oldVatReg[$field] ?? null;
                $newValue = $vatReg[$field] ?? null;
                
                // Nur protokollieren, wenn sich der Wert geändert hat
                if ($oldValue !== $newValue) {
                    // Formatiere Werte für Anzeige
                    $oldValueStr = $this->formatVatFieldValue($field, $oldValue);
                    $newValueStr = $this->formatVatFieldValue($field, $newValue);
                    
                    // Erstelle einen Eintrag pro geändertem Feld (wie bei Stammdaten)
                    $this->insertAuditEntry(
                        $vatReg['org_uuid'],
                        $userId,
                        'update',
                        'vat_' . $field, // Feldname mit Präfix
                        $oldValueStr,
                        'field_change',
                        [
                            'vat_registration_uuid' => $vatRegistrationUuid,
                            'vat_id' => $vatReg['vat_id'] ?? '',
                            'country_code' => $vatReg['country_code'] ?? '',
                            'full_data' => [
                                'old' => $oldVatReg,
                                'new' => $vatReg
                            ] // Vollständige Daten für Analyse
                        ],
                        ['old' => $oldValueStr, 'new' => $newValueStr]
                    );
                }
            }
        }
        
        $this->eventPublisher->publish('org', $vatReg['org_uuid'], 'OrgVatRegistrationUpdated', $vatReg);
        
        return $vatReg;
    }
    
    /**
     * Löscht eine USt-ID-Registrierung
     */
    public function deleteVatRegistration(string $vatRegistrationUuid, ?string $userId = null): bool
    {
        $userId = $userId ?? 'default_user';
        $vatReg = $this->getVatRegistration($vatRegistrationUuid);
        if (!$vatReg) {
            return false;
        }
        
        $orgUuid = $vatReg['org_uuid'];
        
        $stmt = $this->db->prepare("DELETE FROM org_vat_registration WHERE vat_registration_uuid = :uuid");
        $stmt->execute(['uuid' => $vatRegistrationUuid]);
        
        // Protokolliere im Audit-Trail
        $countryName = $this->formatVatFieldValue('country_code', $vatReg['country_code']);
        $vatDescription = "USt-ID {$vatReg['vat_id']} ({$countryName})";
        
        // Speichere sowohl menschenlesbare Werte als auch vollständige JSON-Daten
        $this->insertAuditEntry(
            $orgUuid,
            $userId,
            'delete',
            null,
            $vatDescription,
            'vat_removed',
            [
                'vat_registration_uuid' => $vatRegistrationUuid,
                'vat_id' => $vatReg['vat_id'],
                'country_code' => $vatReg['country_code'],
                'full_data' => $vatReg // Vollständige Daten für Analyse
            ],
            ['old' => $vatDescription]
        );
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgVatRegistrationDeleted', ['vat_registration_uuid' => $vatRegistrationUuid]);
        
        return true;
    }
    
    /**
     * Formatiert einen USt-ID-Feldwert für die Anzeige
     */
    private function formatVatFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '(leer)';
        }
        
        // Spezielle Formatierung für bestimmte Felder
        if ($field === 'is_primary_for_country') {
            return $value ? 'Ja' : 'Nein';
        }
        
        if ($field === 'location_type') {
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
        
        if ($field === 'country_code') {
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
            // Wenn additionalData direkt ein Objekt ist (z.B. alte VAT-Registrierung), nicht als JSON speichern
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
