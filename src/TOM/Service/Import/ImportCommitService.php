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
use TOM\Service\Import\ImportDedupeService;

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
     * @param string $mode Commit-Mode: 'APPROVED_ONLY' (nur approved) oder 'PENDING_AUTO_APPROVE' (pending automatisch approven)
     * @return array Stats: {rows_total, rows_imported, rows_failed, created_orgs, created_level3_industries, started_workflows, row_results}
     */
    public function commitBatch(string $batchUuid, string $userId, bool $startWorkflows = true, string $mode = 'APPROVED_ONLY'): array
    {
        // 1. Wenn PENDING_AUTO_APPROVE: Setze alle pending Rows auf approved
        if ($mode === 'PENDING_AUTO_APPROVE') {
            $this->autoApprovePendingRows($batchUuid, $userId);
        }
        
        // 2. Lade approved Staging-Rows
        $rows = $this->listApprovedRows($batchUuid);
        
        $stats = [
            'rows_total' => count($rows),
            'rows_imported' => 0,
            'rows_failed' => 0,
            'rows_skipped' => 0,
            'created_orgs' => 0,
            'created_level3_industries' => 0,
            'started_workflows' => 0,
            'row_results' => []
        ];
        
        // 2. Verarbeite jede Zeile (zeilenweise Transaktionen)
        foreach ($rows as $row) {
            try {
                $result = $this->commitRow($row, $userId, $startWorkflows, $stats);
                $stats['rows_imported']++;
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
        
        // 3. Update Batch-Status
        // Korrigiere Status basierend auf tatsächlichen DB-Zahlen
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_rows,
                SUM(CASE WHEN disposition = 'approved' AND import_status = 'pending' THEN 1 ELSE 0 END) as approved_rows,
                SUM(CASE WHEN disposition = 'pending' AND import_status = 'pending' THEN 1 ELSE 0 END) as pending_rows,
                SUM(CASE WHEN duplicate_status IN ('confirmed','possible') AND import_status != 'imported' THEN 1 ELSE 0 END) as redundant_rows,
                SUM(CASE WHEN disposition = 'skip' THEN 1 ELSE 0 END) as skipped_rows,
                SUM(CASE WHEN import_status = 'imported' THEN 1 ELSE 0 END) as imported_rows,
                SUM(CASE WHEN import_status = 'failed' THEN 1 ELSE 0 END) as failed_rows
            FROM org_import_staging
            WHERE import_batch_uuid = :batch_uuid
        ");
        $stmt->execute(['batch_uuid' => $batchUuid]);
        $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalRows = (int)($agg['total_rows'] ?? 0);
        $importedRows = (int)($agg['imported_rows'] ?? 0);
        $redundantRows = (int)($agg['redundant_rows'] ?? 0);
        $skippedRows = (int)($agg['skipped_rows'] ?? 0);
        $approvedRows = (int)($agg['approved_rows'] ?? 0);
        $pendingRows = (int)($agg['pending_rows'] ?? 0);

        $actualStatus = 'STAGED';
        if ($totalRows > 0 && ($importedRows + $redundantRows + $skippedRows) >= $totalRows) {
            $actualStatus = 'IMPORTED';
        } elseif ($approvedRows > 0) {
            $actualStatus = 'APPROVED';
        }

        $stats['rows_total'] = $totalRows;
        $stats['rows_imported'] = $importedRows;
        $stats['rows_failed'] = (int)($agg['failed_rows'] ?? 0);
        $stats['rows_skipped'] = $skippedRows;

        $this->updateBatchStatus($batchUuid, $actualStatus, $stats);
        
        // 4. Activity-Log
        $this->activityLogService->logActivity(
            $userId,
            'import',
            'import_batch',
            $batchUuid,
            [
                'action' => 'batch_committed',
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
        
        // Prüfe, ob disposition noch approved ist (verhindert Race Condition:
        // wenn während des Imports disposition auf 'skip' geändert wird, wird Row nicht importiert)
        if ($row['disposition'] !== 'approved') {
            return [
                'staging_uuid' => $row['staging_uuid'],
                'status' => 'skipped',
                'reason' => 'DISPOSITION_NOT_APPROVED',
                'current_disposition' => $row['disposition']
            ];
        }

        // Harte Duplikat-Grenze: Wenn Staging bereits als Duplikat markiert ist, nicht importieren
        if (in_array($row['duplicate_status'] ?? 'none', ['confirmed', 'possible'], true)) {
            $this->markSkipped($row['staging_uuid'], 'DUPLICATE_FLAGGED_IN_STAGING', [
                'duplicate_status' => $row['duplicate_status']
            ]);
            $stats['rows_skipped'] = ($stats['rows_skipped'] ?? 0) + 1;
            return [
                'staging_uuid' => $row['staging_uuid'],
                'status' => 'skipped',
                'reason' => 'DUPLICATE_FLAGGED_IN_STAGING'
            ];
        }

        // Fingerprint-basierte Idempotenzprüfung: gleiche Zeile wurde bereits importiert
        if (!empty($row['row_fingerprint'])) {
            $stmtFp = $this->db->prepare("
                SELECT staging_uuid 
                FROM org_import_staging
                WHERE row_fingerprint = :fp
                  AND import_status = 'imported'
                LIMIT 1
            ");
            $stmtFp->execute(['fp' => $row['row_fingerprint']]);
            $fpMatch = $stmtFp->fetch(PDO::FETCH_ASSOC);
            if ($fpMatch) {
                $this->markSkipped($row['staging_uuid'], 'FINGERPRINT_DUPLICATE', [
                    'match_staging_uuid' => $fpMatch['staging_uuid']
                ]);
                $stats['rows_skipped'] = ($stats['rows_skipped'] ?? 0) + 1;
                return [
                    'staging_uuid' => $row['staging_uuid'],
                    'status' => 'skipped',
                    'reason' => 'FINGERPRINT_DUPLICATE'
                ];
            }
        }
        
        // Parse JSON-Daten
        $mappedData = json_decode($row['mapped_data'] ?? '{}', true);
        $effectiveData = json_decode($row['effective_data'] ?? '{}', true);
        $corrections = json_decode($row['corrections_json'] ?? 'null', true);
        $industryResolution = json_decode($row['industry_resolution'] ?? '{}', true);
        
        // Merge mapped_data + corrections = effective_data
        $effectiveData = $this->mergeRecursive($mappedData, $corrections ?? []);
        
        // Harte Duplikat-Prüfung vor Erstellung (Commit-Guard)
        try {
            $orgDataForDedupe = $effectiveData['org'] ?? [];
            $addressDataForDedupe = $effectiveData['address'] ?? [];
            $rowDataForDedupe = [
                'name' => $orgDataForDedupe['name'] ?? '',
                'website' => $orgDataForDedupe['website'] ?? '',
                'address_city' => $addressDataForDedupe['city'] ?? '',
                'address_postal_code' => $addressDataForDedupe['postal_code'] ?? '',
                'address_country' => $addressDataForDedupe['country'] ?? 'DE'
            ];
            $dedupe = new ImportDedupeService($this->db);
            $dupesOnCommit = $dedupe->findDuplicates($rowDataForDedupe);
            if (!empty($dupesOnCommit)) {
                $this->markSkipped($row['staging_uuid'], 'DUPLICATE_DETECTED_ON_COMMIT', [
                    'candidate' => $dupesOnCommit[0] ?? null
                ]);
                $stats['rows_skipped'] = ($stats['rows_skipped'] ?? 0) + 1;
                return [
                    'staging_uuid' => $row['staging_uuid'],
                    'status' => 'skipped',
                    'reason' => 'DUPLICATE_DETECTED_ON_COMMIT'
                ];
            }
        } catch (\Exception $e) {
            // Fehler in der Duplikat-Prüfung soll Import nicht blockieren, aber geloggt werden
            error_log("Duplicate check on commit failed: " . $e->getMessage());
        }
        
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
        
        $dedupeService = new ImportDedupeService($this->db);
        $dupRowData = [
            'name' => $orgData['name'],
            'website' => $orgData['website'] ?? null,
            'address_postal_code' => $effectiveData['address']['postal_code'] ?? null,
            'address_country' => strtoupper(trim($effectiveData['address']['country'] ?? 'DE'))
        ];
        $duplicates = $dedupeService->findDuplicates($dupRowData);
        if (!empty($duplicates)) {
            $this->markSkipped($row['staging_uuid'], 'DUPLICATE_FOUND', ['duplicates' => $duplicates]);
            $stats['rows_skipped']++;
            return [
                'staging_uuid' => $row['staging_uuid'],
                'status' => 'skipped',
                'reason' => 'DUPLICATE_FOUND',
                'duplicates' => $duplicates
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
        
        // 6. Starte Workflow (optional) - Erstelle case_item für Inside Sales Queue
        $workflowCaseUuid = null;
        if ($startWorkflows) {
            $workflowCaseUuid = $this->startQualifyCompanyWorkflow($orgUuid, $orgData['name'], $userId);
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
    private function listApprovedRows(string $batchUuid): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                staging_uuid,
                import_batch_uuid,
                row_number,
                raw_data,
                mapped_data,
                row_fingerprint,
                industry_resolution,
                corrections_json,
                validation_status,
                disposition,
                import_status,
                duplicate_status
            FROM org_import_staging
            WHERE import_batch_uuid = :batch_uuid
            AND disposition = 'approved'
            AND import_status != 'imported'
            ORDER BY row_number
        ");
        
        $stmt->execute(['batch_uuid' => $batchUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    
    private function markSkipped(string $stagingUuid, string $reason, array $details = []): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_staging
            SET import_status = 'skipped',
                commit_log = :commit_log
            WHERE staging_uuid = :staging_uuid
        ");
        
        $commitLog = [
            [
                'action' => 'ROW_SKIPPED',
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
     * Setzt alle pending Rows eines Batches automatisch auf approved
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
        
        $affected = $stmt->rowCount();
        
        // Activity-Log
        if ($affected > 0) {
            $this->activityLogService->logActivity(
                $userId,
                'import',
                'import_batch',
                $batchUuid,
                [
                    'action' => 'auto_approve_pending_rows',
                    'rows_approved' => $affected,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
        }
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
     * Startet QUALIFY_COMPANY Workflow - Erstellt case_item für Inside Sales Queue
     */
    private function startQualifyCompanyWorkflow(string $orgUuid, string $orgName, string $userId): string
    {
        // Prüfe, ob bereits ein Case für diese Org existiert (verhindert Duplikate)
        $checkStmt = $this->db->prepare("
            SELECT case_uuid
            FROM case_item
            WHERE org_uuid = :org_uuid
              AND case_type = 'LEAD'
              AND engine = 'inside_sales'
              AND stage = 'NEW'
            LIMIT 1
        ");
        $checkStmt->execute(['org_uuid' => $orgUuid]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Case existiert bereits, gib bestehenden zurück
            return $existing['case_uuid'];
        }
        
        // Generiere UUID für MySQL
        $uuidStmt = $this->db->query("SELECT UUID() as uuid");
        $uuidRow = $uuidStmt->fetch(PDO::FETCH_ASSOC);
        $caseUuid = $uuidRow ? $uuidRow['uuid'] : null;
        
        // Erstelle case_item für Inside Sales Queue
        $stmt = $this->db->prepare("
            INSERT INTO case_item (
                case_uuid, case_type, engine, phase, stage, status,
                org_uuid, title, description,
                owner_role, priority_stars, 
                created_at, opened_at
            )
            VALUES (
                :case_uuid, 'LEAD', 'inside_sales', 'QUALIFY-A', 'NEW', 'neu',
                :org_uuid, :title, :description,
                'inside_sales', 0,
                NOW(), NOW()
            )
        ");
        
        $title = "Qualifizierung: " . $orgName;
        $description = "Automatisch erstellter Qualifizierungs-Vorgang für importierte Organisation";
        
        $stmt->execute([
            'case_uuid' => $caseUuid,
            'org_uuid' => $orgUuid,
            'title' => $title,
            'description' => $description
        ]);
        
        // Activity-Log
        $this->activityLogService->logActivity(
            $userId,
            'workflow',
            'case_item',
            $caseUuid,
            [
                'action' => 'workflow_started',
                'workflow_type' => 'QUALIFY_COMPANY',
                'org_uuid' => $orgUuid,
                'org_name' => $orgName,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        return $caseUuid;
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

