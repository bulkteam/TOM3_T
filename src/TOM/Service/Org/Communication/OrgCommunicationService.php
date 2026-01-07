<?php
declare(strict_types=1);

namespace TOM\Service\Org\Communication;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Events\EventPublisher;

/**
 * OrgCommunicationService
 * 
 * Handles communication channel management for organizations:
 * - Email, Phone, Mobile, Fax, Website channels
 * - Primary channel management
 * - Audit trail logging
 */
class OrgCommunicationService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    private $auditEntryCallback;
    
    /**
     * @param PDO|null $db
     * @param callable|null $auditEntryCallback Callback to insert audit entries: function(string $orgUuid, string $userId, string $action, ?string $fieldName, ?string $oldValue, string $changeType, ?array $metadata, ?array $additionalData): void
     */
    public function __construct(?PDO $db = null, ?callable $auditEntryCallback = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->eventPublisher = new EventPublisher($this->db);
        $this->auditEntryCallback = $auditEntryCallback;
    }
    
    public function addCommunicationChannel(string $orgUuid, array $data, ?string $userId = null): array
    {
        $uuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_communication_channel (
                channel_uuid, org_uuid, channel_type, country_code, area_code, number, extension,
                email_address, label, is_primary, is_public, notes
            )
            VALUES (
                :channel_uuid, :org_uuid, :channel_type, :country_code, :area_code, :number, :extension,
                :email_address, :label, :is_primary, :is_public, :notes
            )
        ");
        
        // Wenn dieser Kanal als primary markiert wird, entferne primary von anderen Kanälen desselben Typs
        if (!empty($data['is_primary'])) {
            $this->db->prepare("
                UPDATE org_communication_channel 
                SET is_primary = 0 
                WHERE org_uuid = :org_uuid AND channel_type = :channel_type
            ")->execute([
                'org_uuid' => $orgUuid,
                'channel_type' => $data['channel_type']
            ]);
        }
        
        $stmt->execute([
            'channel_uuid' => $uuid,
            'org_uuid' => $orgUuid,
            'channel_type' => $data['channel_type'] ?? 'other',
            'country_code' => $data['country_code'] ?? null,
            'area_code' => $data['area_code'] ?? null,
            'number' => $data['number'] ?? null,
            'extension' => $data['extension'] ?? null,
            'email_address' => $data['email_address'] ?? null,
            'label' => $data['label'] ?? null,
            'is_primary' => $data['is_primary'] ?? 0,
            'is_public' => $data['is_public'] ?? 1,
            'notes' => $data['notes'] ?? null
        ]);
        
        $channel = $this->getCommunicationChannel($uuid);
        
        // Protokolliere im Audit-Trail
        if ($channel && $this->auditEntryCallback) {
            $userId = $userId ?? 'default_user';
            
            // Erstelle menschenlesbare Beschreibung
            $channelDescription = $this->formatChannelDescription($channel);
            
            call_user_func($this->auditEntryCallback,
                $orgUuid,
                $userId,
                'create',
                null,
                null,
                'channel_added',
                [
                    'channel_uuid' => $uuid,
                    'channel_type' => $channel['channel_type'],
                    'label' => $channel['label'],
                    'full_data' => $channel
                ],
                ['new' => $channelDescription]
            );
        }
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgCommunicationChannelAdded', $channel);
        
        return $channel;
    }
    
    public function getCommunicationChannel(string $channelUuid): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM org_communication_channel WHERE channel_uuid = :uuid");
        $stmt->execute(['uuid' => $channelUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public function getCommunicationChannels(string $orgUuid, ?string $channelType = null): array
    {
        $sql = "SELECT * FROM org_communication_channel WHERE org_uuid = :org_uuid";
        $params = ['org_uuid' => $orgUuid];
        
        if ($channelType) {
            $sql .= " AND channel_type = :channel_type";
            $params['channel_type'] = $channelType;
        }
        
        $sql .= " ORDER BY is_primary DESC, channel_type, label";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
    public function updateCommunicationChannel(string $channelUuid, array $data, ?string $userId = null): array
    {
        $userId = $userId ?? 'default_user';
        $oldChannel = $this->getCommunicationChannel($channelUuid);
        
        if (!$oldChannel) {
            throw new \Exception("Kommunikationskanal nicht gefunden");
        }
        
        $allowed = ['channel_type', 'country_code', 'area_code', 'number', 'extension', 
                   'email_address', 'label', 'is_primary', 'is_public', 'notes'];
        $updates = [];
        $params = ['uuid' => $channelUuid];
        
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                // Konvertiere leere Strings zu null für optionale Felder
                $value = $data[$field];
                if ($value === '' && in_array($field, ['country_code', 'area_code', 'extension', 'label', 'notes'])) {
                    $params[$field] = null;
                } else {
                    $params[$field] = $value;
                }
            }
        }
        
        if (empty($updates)) {
            return $this->getCommunicationChannel($channelUuid);
        }
        
        // Wenn dieser Kanal als primary markiert wird, entferne primary von anderen
        if (isset($data['is_primary']) && $data['is_primary']) {
            $channel = $this->getCommunicationChannel($channelUuid);
            if ($channel) {
                $this->db->prepare("
                    UPDATE org_communication_channel 
                    SET is_primary = 0 
                    WHERE org_uuid = :org_uuid 
                    AND channel_type = :channel_type 
                    AND channel_uuid != :channel_uuid
                ")->execute([
                    'org_uuid' => $channel['org_uuid'],
                    'channel_type' => $channel['channel_type'],
                    'channel_uuid' => $channelUuid
                ]);
            }
        }
        
        $sql = "UPDATE org_communication_channel SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE channel_uuid = :uuid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $channel = $this->getCommunicationChannel($channelUuid);
        
        // Protokolliere Änderungen im Audit-Trail (ein Eintrag pro geändertem Feld, wie bei Stammdaten)
        if ($channel && $oldChannel && $this->auditEntryCallback) {
            foreach ($allowed as $field) {
                $oldValue = $oldChannel[$field] ?? null;
                $newValue = $channel[$field] ?? null;
                
                // Nur protokollieren, wenn sich der Wert geändert hat
                if ($oldValue !== $newValue) {
                    // Formatiere Werte für Anzeige
                    $oldValueStr = $this->formatChannelFieldValue($field, $oldValue);
                    $newValueStr = $this->formatChannelFieldValue($field, $newValue);
                    
                    // Erstelle einen Eintrag pro geändertem Feld (wie bei Stammdaten)
                    call_user_func($this->auditEntryCallback,
                        $channel['org_uuid'],
                        $userId,
                        'update',
                        'channel_' . $field, // Feldname mit Präfix
                        $oldValueStr,
                        'field_change',
                        [
                            'channel_uuid' => $channelUuid,
                            'channel_type' => $channel['channel_type'] ?? '',
                            'label' => $channel['label'] ?? '',
                            'full_data' => [
                                'old' => $oldChannel,
                                'new' => $channel
                            ]
                        ],
                        ['old' => $oldValueStr, 'new' => $newValueStr]
                    );
                }
            }
            
            $this->eventPublisher->publish('org', $channel['org_uuid'], 'OrgCommunicationChannelUpdated', $channel);
        }
        
        return $channel;
    }
    
    public function deleteCommunicationChannel(string $channelUuid, ?string $userId = null): bool
    {
        $userId = $userId ?? 'default_user';
        $channel = $this->getCommunicationChannel($channelUuid);
        if (!$channel) {
            return false;
        }
        
        $orgUuid = $channel['org_uuid'];
        
        $stmt = $this->db->prepare("DELETE FROM org_communication_channel WHERE channel_uuid = :uuid");
        $stmt->execute(['uuid' => $channelUuid]);
        
        // Protokolliere im Audit-Trail
        if ($this->auditEntryCallback) {
            $channelDescription = $this->formatChannelDescription($channel);
            
            call_user_func($this->auditEntryCallback,
                $orgUuid,
                $userId,
                'delete',
                null,
                $channelDescription,
                'channel_removed',
                [
                    'channel_uuid' => $channelUuid,
                    'channel_type' => $channel['channel_type'],
                    'label' => $channel['label'],
                    'full_data' => $channel
                ],
                ['old' => $channelDescription]
            );
        }
        
        $this->eventPublisher->publish('org', $orgUuid, 'OrgCommunicationChannelDeleted', ['channel_uuid' => $channelUuid]);
        
        return true;
    }
    
    /**
     * Formatiert eine Telefonnummer für die Anzeige
     */
    public function formatPhoneNumber(?string $countryCode, ?string $areaCode, ?string $number, ?string $extension = null): string
    {
        $parts = [];
        if ($countryCode) $parts[] = $countryCode;
        if ($areaCode) $parts[] = $areaCode;
        if ($number) $parts[] = $number;
        
        $formatted = implode(' ', $parts);
        if ($extension) {
            $formatted .= ' Durchwahl ' . $extension;
        }
        
        return $formatted ?: '';
    }
    
    /**
     * Formatiert eine Kanal-Beschreibung für die Anzeige
     */
    private function formatChannelDescription(array $channel): string
    {
        $channelTypeLabels = [
            'email' => 'E-Mail',
            'phone' => 'Telefon',
            'mobile' => 'Mobil',
            'fax' => 'Fax',
            'website' => 'Website',
            'other' => 'Sonstiges'
        ];
        $channelType = $channelTypeLabels[$channel['channel_type']] ?? $channel['channel_type'];
        $channelValue = '';
        if ($channel['channel_type'] === 'email' && $channel['email_address']) {
            $channelValue = $channel['email_address'];
        } elseif (in_array($channel['channel_type'], ['phone', 'mobile', 'fax']) && $channel['number']) {
            $parts = [];
            if ($channel['country_code']) $parts[] = $channel['country_code'];
            if ($channel['area_code']) $parts[] = $channel['area_code'];
            if ($channel['number']) $parts[] = $channel['number'];
            $channelValue = implode(' ', $parts);
        }
        $channelDescription = $channelType;
        if ($channel['label']) $channelDescription .= ' (' . $channel['label'] . ')';
        if ($channelValue) $channelDescription .= ': ' . $channelValue;
        
        return $channelDescription;
    }
    
    /**
     * Formatiert einen Kommunikationskanal-Feldwert für die Anzeige
     */
    private function formatChannelFieldValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '(leer)';
        }
        
        // Spezielle Formatierung für bestimmte Felder
        if ($field === 'is_primary') {
            return $value ? 'Ja' : 'Nein';
        }
        
        if ($field === 'is_public') {
            return $value ? 'Ja' : 'Nein';
        }
        
        if ($field === 'channel_type') {
            $types = [
                'email' => 'E-Mail',
                'phone' => 'Telefon',
                'mobile' => 'Mobil',
                'fax' => 'Fax',
                'website' => 'Website',
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
}




