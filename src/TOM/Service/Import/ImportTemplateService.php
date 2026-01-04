<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Activity\ActivityLogService;

/**
 * Service für Import-Mapping-Templates
 * 
 * Verwaltet wiederverwendbare Mapping-Konfigurationen mit automatischer
 * Generierung von Matching-Metadaten (required_targets, expected_headers, fingerprints)
 */
class ImportTemplateService
{
    private PDO $db;
    private ActivityLogService $activityLogService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->activityLogService = new ActivityLogService($this->db);
    }
    
    /**
     * Normalisiert einen Header-String für Matching
     * 
     * Regeln:
     * - lowercase
     * - trim
     * - Umlaute ersetzen (ä→ae, ö→oe, ü→ue, ß→ss)
     * - Satzzeichen entfernen
     * - Mehrfachspaces auf 1 reduzieren
     * 
     * @param string $header
     * @return string
     */
    public function normalizeHeader(string $header): string
    {
        $h = trim(mb_strtolower($header));
        
        // Umlaute ersetzen
        $h = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $h);
        
        // Satzzeichen entfernen (nur Buchstaben, Zahlen, Spaces behalten)
        $h = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $h);
        
        // Mehrfachspaces auf 1 reduzieren
        $h = preg_replace('/\s+/', ' ', $h);
        
        return trim($h);
    }
    
    /**
     * Berechnet Header-Set-Fingerprint (order-independent)
     * 
     * @param array $headers Array von Header-Strings
     * @return string SHA-256 Hash
     */
    public function headerSetFingerprint(array $headers): string
    {
        $normalized = [];
        
        foreach ($headers as $header) {
            $n = $this->normalizeHeader((string)$header);
            if ($n !== '') {
                $normalized[] = $n;
            }
        }
        
        // Entferne Dubletten und sortiere (für order-independent fingerprint)
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        
        return hash('sha256', implode('|', $normalized));
    }
    
    /**
     * Generiert Matching-Metadaten aus mapping_config
     * 
     * Generiert automatisch:
     * - required_targets_json: Liste aller Ziel-Felder mit required=true
     * - expected_headers_json: Liste aller normalisierten Header (excel_header + excel_headers[])
     * - header_fingerprint: SHA-256 Hash über normalisierte Header (für schnelle Erkennung)
     * 
     * @param array $mappingConfig mapping_config JSON (als Array)
     * @return array {
     *   required_targets_json: string[],
     *   expected_headers_json: string[],
     *   header_fingerprint: string,
     *   header_fingerprint_v: int
     * }
     */
    public function buildTemplateMatchMeta(array $mappingConfig): array
    {
        $requiredTargets = [];
        $expectedHeaders = [];
        
        $columnMapping = $mappingConfig['column_mapping'] ?? [];
        
        foreach ($columnMapping as $targetKey => $spec) {
            // Required targets: Sammle alle mit required=true
            if (!empty($spec['required'])) {
                $requiredTargets[] = $targetKey;
            }
            
            // Expected headers: Sammle excel_header und excel_headers[]
            $headers = [];
            
            if (!empty($spec['excel_header'])) {
                $headers[] = $spec['excel_header'];
            }
            
            if (!empty($spec['excel_headers']) && is_array($spec['excel_headers'])) {
                $headers = array_merge($headers, $spec['excel_headers']);
            }
            
            // Normalisiere und sammle
            foreach ($headers as $header) {
                $normalized = $this->normalizeHeader((string)$header);
                if ($normalized !== '') {
                    $expectedHeaders[] = $normalized;
                }
            }
        }
        
        // Entferne Dubletten und sortiere
        $requiredTargets = array_values(array_unique($requiredTargets));
        sort($requiredTargets);
        
        $expectedHeaders = array_values(array_unique($expectedHeaders));
        sort($expectedHeaders);
        
        // Header-Fingerprint berechnen
        $headerFingerprint = $this->headerSetFingerprint($expectedHeaders);
        
        return [
            'required_targets_json' => $requiredTargets,
            'expected_headers_json' => $expectedHeaders,
            'header_fingerprint' => $headerFingerprint,
            'header_fingerprint_v' => 1
        ];
    }
    
    /**
     * Erstellt neues Template
     * 
     * @param string $name Template-Name
     * @param string $importType ORG_ONLY | ORG_WITH_PERSONS | PERSON_ONLY
     * @param array $mappingConfig mapping_config JSON (als Array)
     * @param string|int $userId
     * @param bool $isDefault Als Standard-Template markieren?
     * @return string template_uuid
     */
    public function createTemplate(
        string $name,
        string $importType,
        array $mappingConfig,
        string|int $userId,
        bool $isDefault = false
    ): string {
        // Generiere Matching-Metadaten automatisch
        $meta = $this->buildTemplateMatchMeta($mappingConfig);
        
        // Wenn isDefault=true, setze alle anderen auf is_default=0
        if ($isDefault) {
            $this->clearDefaultFlag($importType);
        }
        
        $templateUuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO import_mapping_template (
                template_uuid, name, import_type, version,
                mapping_config, required_targets_json, expected_headers_json,
                header_fingerprint, header_fingerprint_v,
                is_active, is_default,
                created_by_user_id, created_at
            )
            VALUES (
                :template_uuid, :name, :import_type, 1,
                :mapping_config, :required_targets_json, :expected_headers_json,
                :header_fingerprint, :header_fingerprint_v,
                1, :is_default,
                :created_by_user_id, NOW()
            )
        ");
        
        $stmt->execute([
            'template_uuid' => $templateUuid,
            'name' => $name,
            'import_type' => $importType,
            'mapping_config' => json_encode($mappingConfig, JSON_UNESCAPED_UNICODE),
            'required_targets_json' => json_encode($meta['required_targets_json'], JSON_UNESCAPED_UNICODE),
            'expected_headers_json' => json_encode($meta['expected_headers_json'], JSON_UNESCAPED_UNICODE),
            'header_fingerprint' => $meta['header_fingerprint'],
            'header_fingerprint_v' => $meta['header_fingerprint_v'],
            'is_default' => $isDefault ? 1 : 0,
            'created_by_user_id' => (string)$userId
        ]);
        
        // Activity-Log
        $this->activityLogService->logActivity(
            (string)$userId,
            'import',
            'import_template',
            $templateUuid,
            [
                'action' => 'template_created',
                'name' => $name,
                'import_type' => $importType,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        return $templateUuid;
    }
    
    /**
     * Aktualisiert Template
     * 
     * @param string $templateUuid
     * @param string|null $name
     * @param array|null $mappingConfig
     * @param string|int|null $userId
     * @param bool|null $isDefault
     * @return void
     */
    public function updateTemplate(
        string $templateUuid,
        ?string $name = null,
        ?array $mappingConfig = null,
        string|int|null $userId = null,
        ?bool $isDefault = null
    ): void {
        $template = $this->getTemplate($templateUuid);
        if (!$template) {
            throw new \RuntimeException("Template not found: $templateUuid");
        }
        
        // Wenn mapping_config geändert wird, Metadaten neu generieren
        $meta = null;
        if ($mappingConfig !== null) {
            $meta = $this->buildTemplateMatchMeta($mappingConfig);
        }
        
        // Wenn isDefault=true, setze alle anderen auf is_default=0
        if ($isDefault === true) {
            $this->clearDefaultFlag($template['import_type']);
        }
        
        // Build UPDATE query dynamisch
        $updates = [];
        $params = ['template_uuid' => $templateUuid];
        
        if ($name !== null) {
            $updates[] = 'name = :name';
            $params['name'] = $name;
        }
        
        if ($mappingConfig !== null) {
            $updates[] = 'mapping_config = :mapping_config';
            $params['mapping_config'] = json_encode($mappingConfig, JSON_UNESCAPED_UNICODE);
            
            if ($meta) {
                $updates[] = 'required_targets_json = :required_targets_json';
                $params['required_targets_json'] = json_encode($meta['required_targets_json'], JSON_UNESCAPED_UNICODE);
                
                $updates[] = 'expected_headers_json = :expected_headers_json';
                $params['expected_headers_json'] = json_encode($meta['expected_headers_json'], JSON_UNESCAPED_UNICODE);
                
                $updates[] = 'header_fingerprint = :header_fingerprint';
                $params['header_fingerprint'] = $meta['header_fingerprint'];
                
                $updates[] = 'header_fingerprint_v = :header_fingerprint_v';
                $params['header_fingerprint_v'] = $meta['header_fingerprint_v'];
            }
        }
        
        if ($isDefault !== null) {
            $updates[] = 'is_default = :is_default';
            $params['is_default'] = $isDefault ? 1 : 0;
        }
        
        if (empty($updates)) {
            return; // Nichts zu aktualisieren
        }
        
        $updates[] = 'updated_at = NOW()';
        
        $stmt = $this->db->prepare("
            UPDATE import_mapping_template
            SET " . implode(', ', $updates) . "
            WHERE template_uuid = :template_uuid
        ");
        
        $stmt->execute($params);
        
        // Activity-Log
        if ($userId) {
            $this->activityLogService->logActivity(
                (string)$userId,
                'import',
                'import_template',
                $templateUuid,
                [
                    'action' => 'template_updated',
                    'name' => $name ?? $template['name'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
        }
    }
    
    /**
     * Lädt Template
     * 
     * @param string $templateUuid
     * @return array|null
     */
    public function getTemplate(string $templateUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                template_uuid, name, import_type, version,
                mapping_config, required_targets_json, expected_headers_json,
                header_fingerprint, header_fingerprint_v,
                is_active, is_default,
                created_by_user_id, created_at, updated_at
            FROM import_mapping_template
            WHERE template_uuid = :template_uuid
        ");
        
        $stmt->execute(['template_uuid' => $templateUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        // Parse JSON-Felder
        $row['mapping_config'] = json_decode($row['mapping_config'] ?? '{}', true);
        $row['required_targets_json'] = json_decode($row['required_targets_json'] ?? '[]', true);
        $row['expected_headers_json'] = json_decode($row['expected_headers_json'] ?? '[]', true);
        
        return $row;
    }
    
    /**
     * Listet Templates
     * 
     * @param string|null $importType Filter nach Import-Typ
     * @param bool $activeOnly Nur aktive Templates
     * @return array
     */
    public function listTemplates(?string $importType = null, bool $activeOnly = true): array
    {
        $sql = "
            SELECT 
                template_uuid, name, import_type, version,
                is_active, is_default,
                created_by_user_id, created_at, updated_at
            FROM import_mapping_template
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($importType) {
            $sql .= " AND import_type = :import_type";
            $params['import_type'] = $importType;
        }
        
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        
        $sql .= " ORDER BY is_default DESC, name ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Löscht Template (soft delete: is_active = 0)
     * 
     * @param string $templateUuid
     * @param string|int $userId
     * @return void
     */
    public function deleteTemplate(string $templateUuid, string|int $userId): void
    {
        $template = $this->getTemplate($templateUuid);
        if (!$template) {
            throw new \RuntimeException("Template not found: $templateUuid");
        }
        
        $stmt = $this->db->prepare("
            UPDATE import_mapping_template
            SET is_active = 0, updated_at = NOW()
            WHERE template_uuid = :template_uuid
        ");
        
        $stmt->execute(['template_uuid' => $templateUuid]);
        
        // Activity-Log
        $this->activityLogService->logActivity(
            (string)$userId,
            'import',
            'import_template',
            $templateUuid,
            [
                'action' => 'template_deleted',
                'name' => $template['name'],
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Setzt alle anderen Templates auf is_default=0 (für einen Import-Typ)
     * 
     * @param string $importType
     * @return void
     */
    private function clearDefaultFlag(string $importType): void
    {
        $stmt = $this->db->prepare("
            UPDATE import_mapping_template
            SET is_default = 0
            WHERE import_type = :import_type AND is_default = 1
        ");
        
        $stmt->execute(['import_type' => $importType]);
    }
    
    /**
     * Lädt bekannte Header-Tokens für Header-Row Detection
     * 
     * Sammelt aus:
     * - expected_headers_json aller aktiven Templates
     * - import_header_alias.header_alias (normalisiert)
     * 
     * @param string|null $importType Filter nach Import-Typ
     * @return array [normalized_header => true] (Set für schnelles Lookup)
     */
    public function loadKnownHeaderTokens(?string $importType = null): array
    {
        $tokens = [];
        
        // 1. Aus Templates: expected_headers_json
        $sql = "
            SELECT expected_headers_json
            FROM import_mapping_template
            WHERE is_active = 1
        ";
        $params = [];
        
        if ($importType) {
            $sql .= " AND import_type = :import_type";
            $params['import_type'] = $importType;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $headers = json_decode($row['expected_headers_json'] ?? '[]', true);
            foreach ($headers as $header) {
                $normalized = $this->normalizeHeader((string)$header);
                if ($normalized !== '') {
                    $tokens[$normalized] = true;
                }
            }
        }
        
        // 2. Aus Aliases: header_alias
        $sql = "
            SELECT header_alias
            FROM import_header_alias
            WHERE 1=1
        ";
        $params = [];
        
        if ($importType) {
            $sql .= " AND import_type = :import_type";
            $params['import_type'] = $importType;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $normalized = $this->normalizeHeader((string)$row['header_alias']);
            if ($normalized !== '') {
                $tokens[$normalized] = true;
            }
        }
        
        return $tokens;
    }
    
    /**
     * Lädt Aliases gruppiert nach Target-Key
     * 
     * @param string|null $importType Filter nach Import-Typ
     * @return array [target_key => [alias1, alias2, ...]]
     */
    public function loadAliasesByTarget(?string $importType = null): array
    {
        $aliases = [];
        
        $sql = "
            SELECT target_key, header_alias
            FROM import_header_alias
            WHERE 1=1
        ";
        $params = [];
        
        if ($importType) {
            $sql .= " AND import_type = :import_type";
            $params['import_type'] = $importType;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $targetKey = $row['target_key'];
            if (!isset($aliases[$targetKey])) {
                $aliases[$targetKey] = [];
            }
            $aliases[$targetKey][] = $row['header_alias'];
        }
        
        return $aliases;
    }
    
    /**
     * Berechnet Fit-Score für Template-Matching
     * 
     * @param array $fileHeaderSet Normalisierte Header aus Excel (als Set: [header => true])
     * @param array $template Template-Array (mit mapping_config, required_targets_json, expected_headers_json)
     * @param array $aliasesByTarget [target_key => [alias1, alias2, ...]]
     * @return array {
     *   score: float (0.0-1.0),
     *   covered_required: string[],
     *   missing_required: string[],
     *   overlap: float
     * }
     */
    public function computeTemplateFit(array $fileHeaderSet, array $template, array $aliasesByTarget): array
    {
        $mappingConfig = $template['mapping_config'] ?? [];
        $columnMapping = $mappingConfig['column_mapping'] ?? [];
        
        $requiredTargets = $template['required_targets_json'] ?? [];
        $expectedHeaders = $template['expected_headers_json'] ?? [];
        
        // 1) Required Coverage (hart)
        $missingRequired = [];
        $coveredRequired = [];
        
        foreach ($requiredTargets as $targetKey) {
            $possibleHeaders = [];
            
            // Sammle excel_header und excel_headers[]
            if (isset($columnMapping[$targetKey])) {
                $spec = $columnMapping[$targetKey];
                
                if (!empty($spec['excel_headers']) && is_array($spec['excel_headers'])) {
                    $possibleHeaders = array_merge($possibleHeaders, $spec['excel_headers']);
                }
                if (!empty($spec['excel_header'])) {
                    $possibleHeaders[] = $spec['excel_header'];
                }
            }
            
            // Plus Aliases
            foreach (($aliasesByTarget[$targetKey] ?? []) as $alias) {
                $possibleHeaders[] = $alias;
            }
            
            // Prüfe, ob mindestens einer der möglichen Header im FileHeaderSet vorkommt
            $hit = false;
            foreach ($possibleHeaders as $ph) {
                $normalized = $this->normalizeHeader((string)$ph);
                if ($normalized !== '' && isset($fileHeaderSet[$normalized])) {
                    $hit = true;
                    break;
                }
            }
            
            if ($hit) {
                $coveredRequired[] = $targetKey;
            } else {
                $missingRequired[] = $targetKey;
            }
        }
        
        // Hard rule: Wenn required fehlt, Score 0
        if (count($missingRequired) > 0) {
            return [
                'score' => 0.0,
                'covered_required' => $coveredRequired,
                'missing_required' => $missingRequired,
                'overlap' => 0.0
            ];
        }
        
        // 2) Expected Overlap (weich)
        $hits = 0;
        foreach ($expectedHeaders as $eh) {
            $normalized = $this->normalizeHeader((string)$eh); // expected already normalized, aber safe
            if ($normalized !== '' && isset($fileHeaderSet[$normalized])) {
                $hits++;
            }
        }
        $overlap = (count($expectedHeaders) > 0) ? ($hits / count($expectedHeaders)) : 0.0;
        
        // 3) Bonus für starke Felder
        $bonus = 0.0;
        $strongTargets = ['org.name', 'org.website', 'org.phone', 'org.address.postal_code'];
        foreach ($strongTargets as $target) {
            if (!isset($columnMapping[$target])) {
                continue;
            }
            $hdr = $columnMapping[$target]['excel_header'] ?? null;
            if ($hdr) {
                $normalized = $this->normalizeHeader((string)$hdr);
                if ($normalized !== '' && isset($fileHeaderSet[$normalized])) {
                    $bonus += 0.05;
                }
            }
        }
        
        // Final Score
        // required already ok; overlap ist Hauptsignal
        $score = min(1.0, 0.15 + 0.85 * $overlap + $bonus);
        
        return [
            'score' => $score,
            'covered_required' => $coveredRequired,
            'missing_required' => [],
            'overlap' => $overlap
        ];
    }
    
    /**
     * Wählt bestes Template für gegebene Header
     * 
     * @param array $headers Header-Strings aus Excel
     * @param string|null $importType Filter nach Import-Typ
     * @return array {
     *   template: array|null,
     *   score: float,
     *   fit: array,
     *   decision: string (AUTO_SUGGEST_STRONG | AUTO_SUGGEST_WEAK | NO_MATCH)
     * }
     */
    public function chooseBestTemplate(array $headers, ?string $importType = null): array
    {
        // Lade Templates
        $templates = $this->listTemplates($importType, true);
        
        if (empty($templates)) {
            return [
                'template' => null,
                'score' => 0.0,
                'fit' => null,
                'decision' => 'NO_MATCH'
            ];
        }
        
        // Lade vollständige Template-Daten
        $fullTemplates = [];
        foreach ($templates as $tpl) {
            $full = $this->getTemplate($tpl['template_uuid']);
            if ($full) {
                $fullTemplates[] = $full;
            }
        }
        
        // Build File Header Set
        $fileHeaderSet = [];
        foreach ($headers as $header) {
            $normalized = $this->normalizeHeader((string)$header);
            if ($normalized !== '') {
                $fileHeaderSet[$normalized] = true;
            }
        }
        
        // Lade Aliases
        $aliasesByTarget = $this->loadAliasesByTarget($importType);
        
        // Finde bestes Template
        $best = null;
        $bestFit = null;
        $bestScore = -1.0;
        
        foreach ($fullTemplates as $tpl) {
            $fit = $this->computeTemplateFit($fileHeaderSet, $tpl, $aliasesByTarget);
            if ($fit['score'] > $bestScore) {
                $bestScore = $fit['score'];
                $best = $tpl;
                $bestFit = $fit;
            }
        }
        
        // Klassifiziere Score
        $decision = $this->classifyScore($bestScore);
        
        return [
            'template' => $best,
            'score' => $bestScore,
            'fit' => $bestFit,
            'decision' => $decision
        ];
    }
    
    /**
     * Klassifiziert Score für UI-Entscheidung
     * 
     * @param float $score
     * @return string AUTO_SUGGEST_STRONG | AUTO_SUGGEST_WEAK | NO_MATCH
     */
    private function classifyScore(float $score): string
    {
        if ($score >= 0.85) {
            return 'AUTO_SUGGEST_STRONG';
        }
        if ($score >= 0.60) {
            return 'AUTO_SUGGEST_WEAK';
        }
        return 'NO_MATCH';
    }
}


