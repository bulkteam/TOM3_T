<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * IndustryDecisionService
 * 
 * State-Engine für Industry-Entscheidungen:
 * - Verarbeitet UI-Entscheidungen (Level 1/2/3 bestätigen/ändern)
 * - Prüft Guards (Konsistenz, Validierung)
 * - Baut Dropdown-Optionen für kaskadierende UI
 * - Persistiert industry_resolution in staging
 */
final class IndustryDecisionService
{
    private PDO $db;
    private IndustryResolver $resolver;
    private IndustryNormalizer $normalizer;
    
    public function __construct(
        ?PDO $db = null,
        ?IndustryResolver $resolver = null,
        ?IndustryNormalizer $normalizer = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->normalizer = $normalizer ?? new IndustryNormalizer();
        $this->resolver = $resolver ?? new IndustryResolver($this->db, $this->normalizer);
    }
    
    /**
     * Verarbeitet Industry-Entscheidung von UI
     * 
     * @param string $stagingUuid
     * @param array $request {
     *   level1_uuid?: string,
     *   level2_uuid?: string,
     *   level3_uuid?: string,
     *   level3_action?: 'UNDECIDED'|'SELECT_EXISTING'|'CREATE_NEW',
     *   level3_new_name?: string,
     *   confirm_level1?: bool,
     *   confirm_level2?: bool
     * }
     * @param string $userId
     * @return array {
     *   staging_uuid: string,
     *   industry_resolution: array,
     *   dropdown_options: array,
     *   guards: array
     * }
     * @throws \RuntimeException Bei Validierungsfehlern
     */
    public function applyDecision(string $stagingUuid, array $request, string $userId): array
    {
        // 1. Lade aktuelle Staging-Row
        $row = $this->getStagingRow($stagingUuid);
        if (!$row) {
            throw new \RuntimeException("Staging row not found: $stagingUuid");
        }
        
        // 2. Lade aktuelle industry_resolution
        $resolution = json_decode($row['industry_resolution'] ?? '{}', true);
        $resolution['decision'] = $resolution['decision'] ?? [];
        
        // 3. Wende Request an
        $decision = &$resolution['decision'];
        
        if (isset($request['level1_uuid'])) {
            $decision['level1_uuid'] = $request['level1_uuid'];
        }
        if (isset($request['level2_uuid'])) {
            $decision['level2_uuid'] = $request['level2_uuid'];
        }
        if (isset($request['level3_uuid'])) {
            $decision['level3_uuid'] = $request['level3_uuid'];
        }
        if (isset($request['level3_action'])) {
            $decision['level3_action'] = $request['level3_action'];
        }
        if (isset($request['level3_new_name'])) {
            $decision['level3_new_name'] = trim($request['level3_new_name'] ?? '');
        }
        if (isset($request['confirm_level1'])) {
            $decision['level1_confirmed'] = (bool)$request['confirm_level1'];
        }
        if (isset($request['confirm_level2'])) {
            $decision['level2_confirmed'] = (bool)$request['confirm_level2'];
        }
        
        // 4. Guards: Konsistenz prüfen
        $this->validateGuards($decision);
        
        // 5. Auto-Korrekturen (z.B. Level 1 aus Level 2 ableiten)
        $this->autoCorrect($decision);
        
        // 6. Level 3 Duplikat-Prüfung (wenn CREATE_NEW)
        if (($decision['level3_action'] ?? '') === 'CREATE_NEW') {
            $this->checkLevel3Duplicate($decision);
        }
        
        // 7. Status aktualisieren
        $decision['status'] = $this->computeStatus($decision);
        
        // 8. Persistiere
        $this->updateIndustryResolution($stagingUuid, $resolution);
        
        // 9. Baue Dropdown-Optionen
        $dropdownOptions = $this->buildDropdownOptions($decision);
        
        // 10. Guards für UI
        $guards = $this->computeGuards($decision);
        
        return [
            'staging_uuid' => $stagingUuid,
            'industry_resolution' => $resolution,
            'dropdown_options' => $dropdownOptions,
            'guards' => $guards
        ];
    }
    
    /**
     * Holt Staging-Row
     */
    private function getStagingRow(string $stagingUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT staging_uuid, import_batch_uuid, row_number, industry_resolution
            FROM org_import_staging
            WHERE staging_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $stagingUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Validiert Guards (Konsistenz)
     */
    private function validateGuards(array $decision): void
    {
        // Guard 1: Wenn Level 2 gesetzt, muss Level 1 konsistent sein
        if (!empty($decision['level2_uuid'])) {
            $expectedLevel1 = $this->resolver->getParentUuid($decision['level2_uuid']);
            
            if ($expectedLevel1 && !empty($decision['level1_uuid'])) {
                if ($decision['level1_uuid'] !== $expectedLevel1) {
                    // Option: Auto-Korrektur statt Fehler
                    // throw new \RuntimeException("INCONSISTENT_PARENT: Level 2 gehört nicht zu Level 1");
                    $decision['level1_uuid'] = $expectedLevel1; // Auto-Korrektur
                }
            }
        }
        
        // Guard 2: CREATE_NEW erfordert bestätigtes Level 2
        if (($decision['level3_action'] ?? '') === 'CREATE_NEW') {
            if (empty($decision['level2_uuid']) || empty($decision['level2_confirmed'])) {
                throw new \RuntimeException("L3_CREATE_REQUIRES_CONFIRMED_L2");
            }
            
            $name = trim($decision['level3_new_name'] ?? '');
            if ($name === '') {
                throw new \RuntimeException("L3_NAME_REQUIRED");
            }
        }
        
        // Guard 3: SELECT_EXISTING erfordert UUID
        if (($decision['level3_action'] ?? '') === 'SELECT_EXISTING') {
            if (empty($decision['level3_uuid'])) {
                throw new \RuntimeException("L3_UUID_REQUIRED");
            }
        }
    }
    
    /**
     * Auto-Korrekturen (z.B. Level 1 aus Level 2 ableiten)
     */
    private function autoCorrect(array &$decision): void
    {
        // Wenn Level 2 gesetzt, aber Level 1 nicht → ableiten
        if (!empty($decision['level2_uuid']) && empty($decision['level1_uuid'])) {
            $derived = $this->resolver->deriveLevel1FromLevel2($decision['level2_uuid']);
            if ($derived) {
                $decision['level1_uuid'] = $derived['industry_uuid'];
            }
        }
    }
    
    /**
     * Prüft, ob Level 3 Name bereits existiert (verhindert Duplikate)
     */
    private function checkLevel3Duplicate(array &$decision): void
    {
        if (empty($decision['level2_uuid']) || empty($decision['level3_new_name'])) {
            return;
        }
        
        $nameNorm = $this->normalizer->normalize($decision['level3_new_name']);
        $existing = $this->resolver->findLevel3ByNameUnderParent(
            $decision['level2_uuid'],
            $nameNorm
        );
        
        if ($existing) {
            // Auto-Switch zu SELECT_EXISTING
            $decision['level3_action'] = 'SELECT_EXISTING';
            $decision['level3_uuid'] = $existing['industry_uuid'];
            $decision['level3_new_name'] = null;
        }
    }
    
    /**
     * Berechnet Status basierend auf Entscheidung
     */
    private function computeStatus(array $decision): string
    {
        $hasL1 = !empty($decision['level1_uuid']);
        $hasL2 = !empty($decision['level2_uuid']) && !empty($decision['level2_confirmed']);
        $hasL3 = false;
        
        if (($decision['level3_action'] ?? '') === 'SELECT_EXISTING') {
            $hasL3 = !empty($decision['level3_uuid']);
        } elseif (($decision['level3_action'] ?? '') === 'CREATE_NEW') {
            $hasL3 = !empty(trim($decision['level3_new_name'] ?? ''));
        }
        
        if ($hasL1 && $hasL2 && $hasL3) {
            return 'APPROVED';
        }
        
        return 'PENDING';
    }
    
    /**
     * Baut Dropdown-Optionen für kaskadierende UI
     */
    private function buildDropdownOptions(array $decision): array
    {
        $options = [
            'level1' => [],
            'level2' => [],
            'level3' => [],
            'level3_create_allowed' => false
        ];
        
        // Level 1: Alle Branchenbereiche
        $stmt = $this->db->prepare("
            SELECT industry_uuid, name, code
            FROM industry
            WHERE parent_industry_uuid IS NULL
            ORDER BY name
        ");
        $stmt->execute();
        $level1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $options['level1'] = array_map([$this, 'rowToOption'], $level1);
        
        // Level 2: Nur wenn Level 1 gesetzt
        if (!empty($decision['level1_uuid'])) {
            $stmt = $this->db->prepare("
                SELECT industry_uuid, name, name_short, code
                FROM industry
                WHERE parent_industry_uuid = :parent_uuid
                ORDER BY name
            ");
            $stmt->execute(['parent_uuid' => $decision['level1_uuid']]);
            $level2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $options['level2'] = array_map([$this, 'rowToOption'], $level2);
        }
        
        // Level 3: Nur wenn Level 2 gesetzt und bestätigt
        if (!empty($decision['level2_uuid']) && !empty($decision['level2_confirmed'])) {
            $stmt = $this->db->prepare("
                SELECT industry_uuid, name, code
                FROM industry
                WHERE parent_industry_uuid = :parent_uuid
                ORDER BY name
            ");
            $stmt->execute(['parent_uuid' => $decision['level2_uuid']]);
            $level3 = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $options['level3'] = array_map([$this, 'rowToOption'], $level3);
            $options['level3_create_allowed'] = true;
        }
        
        return $options;
    }
    
    /**
     * Konvertiert DB-Row zu Option-Format
     */
    private function rowToOption(array $row): array
    {
        return [
            'industry_uuid' => $row['industry_uuid'],
            'code' => $row['code'] ?? null,
            'name' => $row['name'],
            'name_short' => $row['name_short'] ?? null,
            'display_name' => $row['name_short'] ?? $row['name']
        ];
    }
    
    /**
     * Berechnet Guards für UI (welche Felder aktiviert/deaktiviert)
     */
    private function computeGuards(array $decision): array
    {
        $level2Enabled = !empty($decision['level1_uuid']);
        $level3Enabled = !empty($decision['level2_uuid']) && !empty($decision['level2_confirmed']);
        
        $approveEnabled = !empty($decision['level1_uuid'])
            && !empty($decision['level2_uuid'])
            && !empty($decision['level2_confirmed'])
            && (
                (($decision['level3_action'] ?? '') === 'SELECT_EXISTING' && !empty($decision['level3_uuid']))
                || (($decision['level3_action'] ?? '') === 'CREATE_NEW' && !empty(trim($decision['level3_new_name'] ?? '')))
            );
        
        return [
            'level2_enabled' => $level2Enabled,
            'level3_enabled' => $level3Enabled,
            'approve_enabled' => $approveEnabled,
            'messages' => []
        ];
    }
    
    /**
     * Aktualisiert industry_resolution in DB
     */
    private function updateIndustryResolution(string $stagingUuid, array $resolution): void
    {
        try {
            // Prüfe zuerst, ob die Row existiert
            $checkStmt = $this->db->prepare("
                SELECT staging_uuid FROM org_import_staging WHERE staging_uuid = :uuid
            ");
            $checkStmt->execute(['uuid' => $stagingUuid]);
            if (!$checkStmt->fetch()) {
                throw new \RuntimeException("Staging row not found: $stagingUuid");
            }
            
            // Führe Update aus
            $stmt = $this->db->prepare("
                UPDATE org_import_staging
                SET industry_resolution = :resolution
                WHERE staging_uuid = :uuid
            ");
            
            $stmt->execute([
                'uuid' => $stagingUuid,
                'resolution' => json_encode($resolution, JSON_UNESCAPED_UNICODE)
            ]);
            
            // rowCount() kann 0 sein, wenn sich die Daten nicht geändert haben
            // Das ist OK, solange die Row existiert (was wir oben geprüft haben)
            
        } catch (\PDOException $e) {
            error_log("Failed to update industry_resolution for staging $stagingUuid: " . $e->getMessage());
            throw new \RuntimeException("Failed to update industry resolution: " . $e->getMessage());
        }
    }
}
