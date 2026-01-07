<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * Service für Validierung von Import-Daten
 */
class ImportValidationService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Validiert Row mit versionierten Regeln
     */
    public function validateRow(array $mappedData, string $ruleSetVersion = 'v1.0'): array
    {
        // Lade Regeln
        $rules = $this->loadValidationRules($ruleSetVersion);
        
        $errors = [];
        $warnings = [];
        $info = [];
        
        // Pflichtfelder
        foreach ($rules['required_fields'] ?? [] as $field) {
            if (empty($mappedData[$field])) {
                $errors[] = [
                    'code' => 'MISSING_REQUIRED_FIELD',
                    'severity' => 'ERROR',
                    'field' => $field,
                    'message' => "Pflichtfeld '$field' fehlt"
                ];
            }
        }
        
        // Format-Validierungen
        foreach ($rules['format_validations'] ?? [] as $field => $config) {
            $value = $mappedData[$field] ?? null;
            if (empty($value) && !($config['required'] ?? false)) {
                continue; // Optionales Feld ist leer, OK
            }
            
            if (!empty($value)) {
                $validation = $this->validateFormat($value, $config['type'] ?? '');
                if (!$validation['valid']) {
                    $severity = $config['required'] ?? false ? 'ERROR' : 'WARNING';
                    $errors[] = [
                        'code' => 'INVALID_FORMAT',
                        'severity' => $severity,
                        'field' => $field,
                        'message' => $validation['message']
                    ];
                }
            }
        }
        
        // Geodaten-Validierung
        if ($rules['geodata_validation']['enabled'] ?? false) {
            $geoValidation = $this->validateGeodata($mappedData);
            if (!$geoValidation['valid']) {
                $warnings[] = [
                    'code' => 'GEODATA_MISMATCH',
                    'severity' => 'WARNING',
                    'field' => 'postal_code',
                    'message' => $geoValidation['message']
                ];
            }
        }
        
        // Telefon-Vorwahl-Validierung
        if ($rules['phone_validation']['enabled'] ?? false) {
            $phoneValidation = $this->validatePhoneAreaCode($mappedData);
            if (!$phoneValidation['valid']) {
                $warnings[] = [
                    'code' => 'PHONE_AREA_CODE_MISMATCH',
                    'severity' => 'WARNING',
                    'field' => 'phone',
                    'message' => $phoneValidation['message']
                ];
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info
        ];
    }
    
    /**
     * Lädt Validierungsregeln
     */
    private function loadValidationRules(string $version): array
    {
        $stmt = $this->db->prepare("
            SELECT rules_json 
            FROM validation_rule_set 
            WHERE rule_set_id = :version
        ");
        
        $stmt->execute(['version' => $version]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // Fallback auf v1.0
            return $this->getDefaultRules();
        }
        
        return json_decode($result['rules_json'], true) ?? $this->getDefaultRules();
    }
    
    /**
     * Default-Regeln (Fallback)
     */
    private function getDefaultRules(): array
    {
        return [
            'required_fields' => ['name'],
            'format_validations' => [
                'postal_code' => ['type' => 'postal_code_de', 'required' => false],
                'email' => ['type' => 'email', 'required' => false],
                'website' => ['type' => 'url', 'required' => false]
            ],
            'geodata_validation' => [
                'enabled' => true,
                'check_postal_code_city_match' => true
            ],
            'phone_validation' => [
                'enabled' => true,
                'check_area_code' => true
            ]
        ];
    }
    
    /**
     * Validiert Format
     */
    private function validateFormat($value, string $type): array
    {
        switch ($type) {
            case 'postal_code_de':
                if (!preg_match('/^\d{5}$/', $value)) {
                    return ['valid' => false, 'message' => 'PLZ muss 5-stellig sein'];
                }
                break;
                
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false, 'message' => 'Ungültige E-Mail-Adresse'];
                }
                break;
                
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return ['valid' => false, 'message' => 'Ungültige URL'];
                }
                break;
        }
        
        return ['valid' => true];
    }
    
    /**
     * Validiert Geodaten (PLZ vs. Stadt)
     */
    private function validateGeodata(array $mappedData): array
    {
        $postalCode = $mappedData['address_postal_code'] ?? null;
        $city = $mappedData['address_city'] ?? null;
        
        if (empty($postalCode) || empty($city)) {
            return ['valid' => true]; // Keine Validierung möglich
        }
        
        // PLZ-Lookup
        require_once __DIR__ . '/../../../config/plz_mapping.php';
        /**
         * @param string $plz
         * @return array{bundesland?: string, city?: string}|false
         */
        if (!function_exists('mapPlzToBundeslandAndCity')) {
            // Fallback, falls Funktion nicht geladen wurde
            $plzInfo = false;
        } else {
            /** @var array{bundesland?: string, city?: string}|false $plzInfo */
            $plzInfo = mapPlzToBundeslandAndCity($postalCode);
        }
        
        if ($plzInfo && isset($plzInfo['city'])) {
            $expectedCity = mb_strtolower($plzInfo['city']);
            $actualCity = mb_strtolower($city);
            
            if ($expectedCity !== $actualCity) {
                return [
                    'valid' => false,
                    'message' => "PLZ $postalCode gehört zu '{$plzInfo['city']}', nicht zu '$city'"
                ];
            }
        }
        
        return ['valid' => true];
    }
    
    /**
     * Validiert Telefon-Vorwahl
     */
    private function validatePhoneAreaCode(array $mappedData): array
    {
        $phone = $mappedData['phone'] ?? null;
        $postalCode = $mappedData['address_postal_code'] ?? null;
        
        if (empty($phone) || empty($postalCode)) {
            return ['valid' => true]; // Keine Validierung möglich
        }
        
        // TODO: Vorwahl-Validierung implementieren
        // (Vorwahl aus PLZ ableiten und mit Telefonnummer vergleichen)
        
        return ['valid' => true];
    }
}

