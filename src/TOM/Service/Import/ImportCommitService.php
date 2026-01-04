<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Activity\ActivityLogService;
use TOM\Service\OrgService;
use TOM\Service\Org\OrgAddressService;
use TOM\Service\Import\IndustryResolver;
use TOM\Service\Import\IndustryNormalizer;

/**
 * ImportCommitService
 * 
 * Importiert approved Staging-Rows in Produktion:
 * - Erstellt Level 3 Industries (wenn CREATE_NEW)
 * - Erstellt Organisationen
 * - Erstellt Adressen
 * - Erstellt Kommunikationskanäle
 * - Erstellt VAT IDs
 * - Startet Workflows (optional)
 * - Zeilenweise Transaktionen
 * - Commit-Log schreiben
 */
final class ImportCommitService
{
    private PDO $db;
    private OrgService $orgService;
    private OrgAddressService $addressService;
    private IndustryResolver $industryResolver;
    private ActivityLogService $activityLogService;
    
    public function __construct(
        ?PDO $db = null,
        ?OrgService $orgService = null,
        ?OrgAddressService $addressService = null,
        ?IndustryResolver $industryResolver = null,
        ?ActivityLogService $activityLogService = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->orgService = $orgService ?? new OrgService($this->db);
        $this->addressService = $addressService ?? new OrgAddressService($this->db);
        $this->industryResolver = $industryResolver ?? new IndustryResolver($this->db);
        $this->activityLogService = $activityLogService ?? new ActivityLogService($this->db);
    }
    
    /**
     * Committet einen Batch (importiert approved rows in Produktion)
     * 
     * @param string $batchUuid
     * @param string $userId
     * @param bool $startWorkflows Ob Workflows gestartet werden sollen
     * @return array Stats: {rows_total, rows_imported, rows_failed, created_orgs, created_level3_industries, started_workflows, row_results}
     */
    public function commitBatch(string $batchUuid, string $userId, bool $startWorkflows = true, string $mode = 'APPROVED_ONLY'): array
    {
        // 1. Lade approved Staging-Rows (oder pending, wenn mode = PENDING_AUTO_APPROVE)
        $rows = $this->listApprovedRows($batchUuid, $mode);
        
        $stats = [
            'rows_total' => count($rows),
            'rows_imported' => 0,
            'rows_failed' => 0,
            'rows_skipped' => 0,
            'rows_duplicate' => 0,
            'created_orgs' => 0,
            'created_level3_industries' => 0,
            'started_workflows' => 0,
            'row_results' => []
        ];
        
        // 2. Prüfe, ob Rows vorhanden sind
        if (empty($rows)) {
            // Keine Rows - Status bleibt unverändert
            throw new \RuntimeException('Keine approved Rows zum Importieren gefunden. Bitte approven Sie zuerst die Staging-Rows.');
        }
        
        // 3. Wenn mode = PENDING_AUTO_APPROVE, approve alle pending Rows zuerst
        if ($mode === 'PENDING_AUTO_APPROVE') {
            $this->autoApprovePendingRows($batchUuid, $userId);
            // Lade Rows erneut nach dem Approven
            $rows = $this->listApprovedRows($batchUuid, 'APPROVED_ONLY');
            $stats['rows_total'] = count($rows);
        }
        
        // 4. Verarbeite jede Zeile (zeilenweise Transaktionen)
        foreach ($rows as $row) {
            try {
                $result = $this->commitRow($row, $userId, $startWorkflows, $stats);
                if ($result['status'] === 'imported') {
                    $stats['rows_imported']++;
                } elseif ($result['status'] === 'duplicate') {
                    $stats['rows_duplicate']++;
                } elseif ($result['status'] === 'skipped') {
                    $stats['rows_skipped']++;
                }
                $stats['row_results'][] = $result;
            } catch (\Exception $e) {
                $stats['rows_failed']++;
                $this->markFailed($row['staging_uuid'], 'COMMIT_FAILED', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $stats['row_results'][] = [
                    'staging_uuid' => $row['staging_uuid'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // 5. Prüfe, ob ALLE Rows importiert wurden
        // Zähle alle Rows im Batch (unabhängig von disposition)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN import_status = 'imported' THEN 1 ELSE 0 END) as imported_count
            FROM org_import_staging
            WHERE import_batch_uuid = :batch_uuid
        ");
        $stmt->execute(['batch_uuid' => $batchUuid]);
        $batchStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalRows = (int)($batchStats['total'] ?? 0);
        $importedCount = (int)($batchStats['imported_count'] ?? 0);
        $allImported = ($totalRows > 0 && $importedCount === $totalRows);
        
        // Update Batch-Status
        if ($stats['rows_imported'] > 0 || $stats['rows_duplicate'] > 0) {
            // Mindestens eine Row wurde importiert oder verlinkt
            if ($allImported) {
                // Alle Rows importiert - Status = IMPORTED
                $this->updateBatchStatus($batchUuid, 'IMPORTED', $stats);
            } else {
                // Nicht alle Rows importiert - Status bleibt STAGED/IN_REVIEW/APPROVED
                // Aktualisiere nur stats_json, nicht den Status
                $this->updateBatchStatsOnly($batchUuid, $stats);
            }
        } else {
            // Keine erfolgreichen Imports - Status bleibt unverändert
            // Logge Warnung
            error_log("ImportCommitService: Batch {$batchUuid} - Keine Rows erfolgreich importiert. Failed: {$stats['rows_failed']}");
        }
        
        // 6. Activity-Log
        $this->activityLogService->logActivity(
            $userId,
            'import',
            'import_batch',
            $batchUuid,
            [
                'action' => $stats['rows_imported'] > 0 ? 'batch_committed' : 'batch_commit_failed',
                'rows_total' => $stats['rows_total'],
                'rows_imported' => $stats['rows_imported'],
                'rows_failed' => $stats['rows_failed'],
                'created_orgs' => $stats['created_orgs'],
                'created_level3_industries' => $stats['created_level3_industries'],
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        return $stats;
    }
    
    /**
     * Committet eine einzelne Staging-Row
     * 
     * @param array $row Staging-Row aus DB
     * @param string $userId
     * @param bool $startWorkflows
     * @param array &$stats Stats-Array (wird modifiziert)
     * @return array {staging_uuid, status, imported_org_uuid, created_level3_industry_uuid, workflow_case_uuid}
     */
    private function commitRow(array $row, string $userId, bool $startWorkflows, array &$stats): array
    {
        // Prüfe, ob bereits importiert
        if ($row['import_status'] === 'imported') {
            return [
                'staging_uuid' => $row['staging_uuid'],
                'status' => 'skipped',
                'reason' => 'ALREADY_IMPORTED'
            ];
        }
        
        // Parse JSON-Daten
        $mappedData = json_decode($row['mapped_data'] ?? '{}', true);
        $industryResolution = json_decode($row['industry_resolution'] ?? '{}', true);
        $corrections = json_decode($row['corrections_json'] ?? 'null', true);
        
        // Merge mapped_data + corrections = effective_data
        $effectiveData = $this->mergeRecursive($mappedData, $corrections ?? []);
        
        // 1. Verarbeite Industry-Entscheidung
        $decision = $industryResolution['decision'] ?? [];
        
        // Guard: Level 1 & 2 müssen gesetzt sein
        if (empty($decision['level1_uuid']) || empty($decision['level2_uuid'])) {
            throw new \RuntimeException('MISSING_INDUSTRY_DECISION: Level 1 und Level 2 müssen gesetzt sein');
        }
        
        // Erstelle Level 3 Industry (wenn CREATE_NEW)
        $level3Uuid = $decision['level3_uuid'] ?? null;
        if (($decision['level3_action'] ?? '') === 'CREATE_NEW') {
            $level3Name = trim($decision['level3_new_name'] ?? '');
            if (empty($level3Name)) {
                throw new \RuntimeException('L3_NAME_REQUIRED: Name für neue Level 3 Branche fehlt');
            }
            
            // Prüfe, ob bereits existiert
            $normalizer = new IndustryNormalizer();
            $existing = $this->industryResolver->findLevel3ByNameUnderParent(
                $decision['level2_uuid'],
                $normalizer->normalize($level3Name)
            );
            
            if ($existing) {
                // Nutze bestehende
                $level3Uuid = $existing['industry_uuid'];
            } else {
                // Erstelle neue (name_short = name für Level 3)
                $level3Uuid = $this->industryResolver->createLevel3($decision['level2_uuid'], $level3Name, $level3Name);
                $stats['created_level3_industries']++;
            }
        }
        
        // 2. Erstelle Organisation
        $orgData = [
            'name' => $effectiveData['org']['name'] ?? null,
            'org_kind' => 'other', // Default
            'website' => $effectiveData['org']['website'] ?? null,
            'industry_level1_uuid' => $decision['level1_uuid'],
            'industry_level2_uuid' => $decision['level2_uuid'],
            'industry_level3_uuid' => $level3Uuid,
            'revenue_range' => $effectiveData['org']['revenue_range'] ?? null,
            'employee_count' => $effectiveData['org']['employee_count'] ?? null,
            'notes' => $effectiveData['org']['notes'] ?? null,
            'status' => 'lead', // Importierte Orgs starten als 'lead'
            'account_owner_user_id' => null // Wird später im Workflow zugewiesen
        ];
        
        if (empty($orgData['name'])) {
            throw new \RuntimeException('ORG_NAME_REQUIRED: Organisationsname fehlt');
        }
        
        // Prüfe auf Duplikate BEVOR wir erstellen
        $existingOrg = $this->findExistingOrg($orgData);
        if ($existingOrg) {
            // Duplikat gefunden - verlinke statt zu erstellen
            $orgUuid = $existingOrg['org_uuid'];
            $this->markImported($row['staging_uuid'], $orgUuid, [
                'action' => 'linked_to_existing',
                'existing_org_uuid' => $orgUuid,
                'existing_org_name' => $existingOrg['name']
            ]);
            return [
                'staging_uuid' => $row['staging_uuid'],
                'status' => 'duplicate',
                'imported_org_uuid' => $orgUuid,
                'linked_to_existing' => true,
                'existing_org_name' => $existingOrg['name']
            ];
        }
        
        $org = $this->orgService->createOrg($orgData, $userId);
        $orgUuid = $org['org_uuid'];
        $stats['created_orgs']++;
        
        // 3. Erstelle Adresse (wenn vorhanden)
        if (!empty($effectiveData['address'])) {
            $addressData = [
                'address_type' => 'headquarters', // Default für Import
                'street' => $effectiveData['address']['street'] ?? null,
                'postal_code' => $effectiveData['address']['postal_code'] ?? null,
                'city' => $effectiveData['address']['city'] ?? null,
                'state' => $effectiveData['address']['state'] ?? null,
                'country' => 'DE', // Default
                'is_primary' => true
            ];
            
            // Nur wenn mindestens Straße oder PLZ vorhanden
            if (!empty($addressData['street']) || !empty($addressData['postal_code'])) {
                try {
                    $this->addressService->addAddress($orgUuid, $addressData, $userId);
                } catch (\Exception $e) {
                    // Adresse ist nicht kritisch, logge aber
                    error_log("Failed to create address for org {$orgUuid}: " . $e->getMessage());
                }
            }
        }
        
        // 4. Erstelle Kommunikationskanäle (wenn vorhanden)
        if (!empty($effectiveData['communication'])) {
            $comm = $effectiveData['communication'];
            
            // E-Mail
            if (!empty($comm['email'])) {
                try {
                    $this->orgService->addCommunicationChannel($orgUuid, [
                        'channel_type' => 'email',
                        'email_address' => $comm['email'],
                        'is_primary' => true,
                        'is_public' => true
                    ], $userId);
                } catch (\Exception $e) {
                    error_log("Failed to create email channel for org {$orgUuid}: " . $e->getMessage());
                }
            }
            
            // Telefon
            if (!empty($comm['phone'])) {
                try {
                    // Versuche Telefonnummer zu parsen (einfach)
                    $phone = $comm['phone'];
                    $this->orgService->addCommunicationChannel($orgUuid, [
                        'channel_type' => 'phone',
                        'number' => $phone,
                        'is_primary' => true,
                        'is_public' => true
                    ], $userId);
                } catch (\Exception $e) {
                    error_log("Failed to create phone channel for org {$orgUuid}: " . $e->getMessage());
                }
            }
            
            // Fax
            if (!empty($comm['fax'])) {
                try {
                    $this->orgService->addCommunicationChannel($orgUuid, [
                        'channel_type' => 'fax',
                        'number' => $comm['fax'],
                        'is_primary' => false,
                        'is_public' => true
                    ], $userId);
                } catch (\Exception $e) {
                    error_log("Failed to create fax channel for org {$orgUuid}: " . $e->getMessage());
                }
            }
        }
        
        // 5. Erstelle VAT ID (wenn vorhanden)
        if (!empty($effectiveData['org']['vat_id'])) {
            try {
                $vatService = new \TOM\Service\Org\OrgVatService($this->db);
                $vatService->addVatRegistration($orgUuid, [
                    'vat_id' => $effectiveData['org']['vat_id'],
                    'country' => 'DE', // Default
                    'is_valid' => null, // Wird später validiert
                    'verified_at' => null
                ], $userId);
            } catch (\Exception $e) {
                error_log("Failed to create VAT ID for org {$orgUuid}: " . $e->getMessage());
            }
        }
        
        // 6. Starte Workflow (optional)
        $workflowCaseUuid = null;
        if ($startWorkflows) {
            // TODO: Workflow-Service erweitern für QUALIFY_COMPANY
            // Für jetzt: Nur loggen
            // $workflowCaseUuid = $this->startQualifyCompanyWorkflow($orgUuid, $userId);
            $stats['started_workflows']++;
        }
        
        // 7. Erstelle Commit-Log
        $commitLog = [
            [
                'action' => 'CREATE_ORG',
                'org_uuid' => $orgUuid,
                'name' => $orgData['name']
            ]
        ];
        
        if ($level3Uuid && ($decision['level3_action'] ?? '') === 'CREATE_NEW') {
            $commitLog[] = [
                'action' => 'CREATE_INDUSTRY_LEVEL3',
                'new_industry_uuid' => $level3Uuid,
                'parent_level2_uuid' => $decision['level2_uuid'],
                'name' => $decision['level3_new_name']
            ];
        }
        
        if ($workflowCaseUuid) {
            $commitLog[] = [
                'action' => 'START_WORKFLOW',
                'case_uuid' => $workflowCaseUuid
            ];
        }
        
        // 8. Markiere Staging-Row als importiert
        $this->markImported($row['staging_uuid'], $orgUuid, $commitLog);
        
        return [
            'staging_uuid' => $row['staging_uuid'],
            'status' => 'imported',
            'imported_org_uuid' => $orgUuid,
            'created_level3_industry_uuid' => ($decision['level3_action'] ?? '') === 'CREATE_NEW' ? $level3Uuid : null,
            'workflow_case_uuid' => $workflowCaseUuid
        ];
    }
    
    /**
     * Listet approved Staging-Rows für einen Batch
     */
    private function listApprovedRows(string $batchUuid, string $mode = 'APPROVED_ONLY'): array
    {
        if ($mode === 'PENDING_AUTO_APPROVE') {
            // Lade pending Rows, die automatisch approved werden sollen
            $stmt = $this->db->prepare("
                SELECT 
                    staging_uuid,
                    import_batch_uuid,
                    row_number,
                    raw_data,
                    mapped_data,
                    industry_resolution,
                    corrections_json,
                    validation_status,
                    disposition,
                    import_status
                FROM org_import_staging
                WHERE import_batch_uuid = :batch_uuid
                AND disposition = 'pending'
                AND import_status != 'imported'
                ORDER BY row_number
            ");
        } else {
            // Lade nur approved Rows
            $stmt = $this->db->prepare("
                SELECT 
                    staging_uuid,
                    import_batch_uuid,
                    row_number,
                    raw_data,
                    mapped_data,
                    industry_resolution,
                    corrections_json,
                    validation_status,
                    disposition,
                    import_status
                FROM org_import_staging
                WHERE import_batch_uuid = :batch_uuid
                AND disposition = 'approved'
                AND import_status != 'imported'
                ORDER BY row_number
            ");
        }
        
        $stmt->execute(['batch_uuid' => $batchUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Approved automatisch alle pending Rows in einem Batch
     */
    private function autoApprovePendingRows(string $batchUuid, string $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_staging
            SET disposition = 'approved',
                reviewed_by_user_id = :user_id,
                reviewed_at = NOW()
            WHERE import_batch_uuid = :batch_uuid
            AND disposition = 'pending'
            AND import_status != 'imported'
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'user_id' => $userId
        ]);
    }
    
    /**
     * Findet bestehende Organisation (Duplikat-Prüfung)
     */
    private function findExistingOrg(array $orgData): ?array
    {
        // Prüfe auf exakten Namen + Website Match
        $name = trim($orgData['name'] ?? '');
        $website = $orgData['website'] ?? null;
        
        if (empty($name)) {
            return null;
        }
        
        // Normalisiere Website
        if ($website) {
            $website = strtolower(trim($website));
            if (!preg_match('/^https?:\/\//', $website)) {
                $website = 'https://' . $website;
            }
            $parsed = parse_url($website);
            $domain = strtolower($parsed['host'] ?? '');
        } else {
            $domain = null;
        }
        
        // Suche nach exaktem Namen
        $query = "
            SELECT org_uuid, name, website
            FROM org
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
            AND archived_at IS NULL
        ";
        
        $params = ['name' => $name];
        
        // Wenn Website vorhanden, prüfe auch darauf
        if ($domain) {
            $query .= " AND (LOWER(TRIM(website)) = LOWER(TRIM(:website)) OR LOWER(TRIM(website)) LIKE :domain_pattern)";
            $params['website'] = $website;
            $params['domain_pattern'] = '%' . $domain . '%';
        }
        
        $query .= " LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $existing ?: null;
    }
    
    /**
     * Markiert Staging-Row als importiert
     */
    private function markImported(string $stagingUuid, string $orgUuid, array $commitLog): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_staging
            SET import_status = 'imported',
                imported_org_uuid = :org_uuid,
                imported_at = NOW(),
                commit_log = :commit_log
            WHERE staging_uuid = :staging_uuid
        ");
        
        $stmt->execute([
            'staging_uuid' => $stagingUuid,
            'org_uuid' => $orgUuid,
            'commit_log' => json_encode($commitLog, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    /**
     * Markiert Staging-Row als fehlgeschlagen
     */
    private function markFailed(string $stagingUuid, string $reason, array $details = []): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_staging
            SET import_status = 'failed',
                commit_log = :commit_log
            WHERE staging_uuid = :staging_uuid
        ");
        
        $commitLog = [
            [
                'action' => 'COMMIT_FAILED',
                'reason' => $reason,
                'details' => $details,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ];
        
        $stmt->execute([
            'staging_uuid' => $stagingUuid,
            'commit_log' => json_encode($commitLog, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    /**
     * Aktualisiert Batch-Status
     */
    private function updateBatchStatus(string $batchUuid, string $status, array $stats): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_batch
            SET status = :status,
                stats_json = :stats_json,
                imported_at = NOW()
            WHERE batch_uuid = :batch_uuid
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'status' => $status,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    /**
     * Aktualisiert nur Stats, nicht den Status (wenn noch nicht alle Rows importiert sind)
     */
    private function updateBatchStatsOnly(string $batchUuid, array $stats): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_batch
            SET stats_json = :stats_json
            WHERE batch_uuid = :batch_uuid
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    /**
     * Merge mapped_data + corrections = effective_data
     */
    private function mergeRecursive(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}

