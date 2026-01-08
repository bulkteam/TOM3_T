<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Activity\ActivityLogService;
use TOM\Service\Import\ImportDedupeService;

/**
 * ImportStagingService
 * 
 * Erstellt Staging-Rows aus Excel:
 * - Liest Excel-Datei
 * - Erstellt raw_data (Original Excel-Zeile)
 * - Erstellt mapped_data (strukturiert: org, industry, address)
 * - Erstellt industry_resolution (Vorschläge + Entscheidung)
 * - Nutzt IndustryResolver für Matching
 */
final class ImportStagingService
{
    private PDO $db;
    private IndustryResolver $resolver;
    private ImportMappingService $mappingService;
    private ActivityLogService $activityLogService;
    private ImportDedupeService $dedupeService;
    
    public function __construct(
        ?PDO $db = null,
        ?IndustryResolver $resolver = null,
        ?ImportMappingService $mappingService = null,
        ?ActivityLogService $activityLogService = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->resolver = $resolver ?? new IndustryResolver($this->db);
        $this->mappingService = $mappingService ?? new ImportMappingService($this->db);
        $this->activityLogService = $activityLogService ?? new ActivityLogService($this->db);
        $this->dedupeService = new ImportDedupeService($this->db);
    }
    
    /**
     * Erstellt Staging-Rows für einen Batch
     * 
     * @param string $batchUuid
     * @param string $filePath Pfad zur Excel-Datei
     * @return array Stats: {total_rows, imported, errors, errors_detail}
     */
    public function stageBatch(string $batchUuid, string $filePath): array
    {
        // 1. Lade Batch + Mapping-Config
        $batch = $this->getBatch($batchUuid);
        if (!$batch) {
            throw new \RuntimeException("Batch not found: $batchUuid");
        }
        
        $mappingConfig = json_decode($batch['mapping_config'] ?? '{}', true);
        if (empty($mappingConfig)) {
            throw new \RuntimeException("Mapping config not found for batch: $batchUuid");
        }
        
        // 2. Lade Excel
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // 3. Bestimme Header-Row und Data-Start
        $headerRow = $mappingConfig['header_row'] ?? 1;
        $dataStartRow = $mappingConfig['data_start_row'] ?? ($headerRow + 1);
        
        // 4. Lese Header (für raw_data)
        $headers = $this->readHeaders($worksheet, $headerRow);
        
        // 5. File-Fingerprint
        $fileFingerprint = hash_file('sha256', $filePath);
        
        // 6. Statistiken
        $stats = [
            'total_rows' => 0,
            'imported' => 0,
            'errors' => 0,
            'errors_detail' => []
        ];
        
        $highestRow = $worksheet->getHighestDataRow();
        
        // Prüfe, ob Daten vorhanden sind
        if ($highestRow < $dataStartRow) {
            throw new \RuntimeException("Keine Datenzeilen gefunden. Excel-Datei enthält nur Header oder ist leer.");
        }
        
        // 7. Verarbeite Zeilen
        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $stats['total_rows']++;
            
            try {
                // 7.1 Lese raw_data (Original Excel-Zeile)
                $rawData = $this->readRawRow($worksheet, $row, $headers);
                
                // 7.2 Lese mapped_data (strukturiert)
                $mappedData = $this->readMappedRow($worksheet, $row, $mappingConfig);
                
                // 7.2.1 Extrahiere Branchendaten aus raw_data (falls nicht im Mapping)
                $this->extractIndustryFromRawData($rawData, $mappedData);
                
                // 7.3 Erstelle industry_resolution
                $industryResolution = $this->buildIndustryResolution($mappedData, $mappingConfig);
                
                // 7.4 Generiere Fingerprints
                $rowFingerprint = $this->generateRowFingerprint($mappedData);
                
                // 7.5 Speichere Staging-Row
                $stagingUuid = $this->saveStagingRow(
                    $batchUuid,
                    $row - $dataStartRow + 1, // row_number (1-basiert)
                    $rawData,
                    $mappedData,
                    $industryResolution,
                    $rowFingerprint,
                    $fileFingerprint
                );

                // 7.6 Duplikat-Erkennung und Status-Update
                $dedupeInput = [
                    'name' => $mappedData['org']['name'] ?? '',
                    'website' => $mappedData['org']['website'] ?? '',
                    'address_postal_code' => $mappedData['address']['postal_code'] ?? '',
                    'address_city' => $mappedData['address']['city'] ?? ''
                ];
                $duplicates = $this->dedupeService->findDuplicates($dedupeInput);

                $dupStatus = 'none';
                $dupSummary = null;
                if (!empty($duplicates)) {
                    $best = $duplicates[0];
                    $bestScore = (float)($best['score'] ?? 0.0);
                    $dupStatus = $bestScore >= 0.8 ? 'confirmed' : 'possible';
                    $dupSummary = [
                        'count' => count($duplicates),
                        'best_score' => $bestScore,
                        'best_org_uuid' => $best['org_uuid'] ?? null,
                        'best_reasons' => $best['reasons'] ?? []
                    ];
                }

                $stmtUpdateDup = $this->db->prepare("
                    UPDATE org_import_staging
                    SET duplicate_status = :dup_status,
                        duplicate_summary = :dup_summary
                    WHERE staging_uuid = :staging_uuid
                ");
                $stmtUpdateDup->execute([
                    'dup_status' => $dupStatus,
                    'dup_summary' => $dupSummary ? json_encode($dupSummary, JSON_UNESCAPED_UNICODE) : null,
                    'staging_uuid' => $stagingUuid
                ]);

                // 7.7 Fingerprint-basierte Idempotenz: Falls gleiche Zeile früher bereits importiert wurde
                $stmtFp = $this->db->prepare("
                    SELECT staging_uuid, imported_at
                    FROM org_import_staging
                    WHERE row_fingerprint = :fp
                      AND import_status = 'imported'
                    LIMIT 1
                ");
                $stmtFp->execute(['fp' => $rowFingerprint]);
                $fpMatch = $stmtFp->fetch(PDO::FETCH_ASSOC);
                if ($fpMatch) {
                    $dupStatus = 'confirmed';
                    $dupSummary = [
                        'count' => ($dupSummary['count'] ?? 0) + 1,
                        'best_score' => max($dupSummary['best_score'] ?? 0.0, 1.0),
                        'fingerprint_match' => true,
                        'imported_at' => $fpMatch['imported_at'] ?? null
                    ];
                    $stmtUpdateDup = $this->db->prepare("
                        UPDATE org_import_staging
                        SET duplicate_status = :dup_status,
                            duplicate_summary = :dup_summary
                        WHERE staging_uuid = :staging_uuid
                    ");
                    $stmtUpdateDup->execute([
                        'dup_status' => $dupStatus,
                        'dup_summary' => json_encode($dupSummary, JSON_UNESCAPED_UNICODE),
                        'staging_uuid' => $stagingUuid
                    ]);
                }
                
                $stats['imported']++;
                
            } catch (\Exception $e) {
                $stats['errors']++;
                $errorDetail = [
                    'row' => $row,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ];
                $stats['errors_detail'][] = $errorDetail;
                
                // Log für Debugging
                error_log("Staging Import Error (Row $row): " . $e->getMessage());
            }
        }
        
        // 8. Update Batch-Status
        $this->updateBatchStatus($batchUuid, 'STAGED', $stats);
        
        // 9. Activity-Log
        if ($batch['uploaded_by_user_id']) {
            $this->activityLogService->logActivity(
                $batch['uploaded_by_user_id'],
                'import',
                'import_batch',
                $batchUuid,
                [
                    'action' => 'staging_import',
                    'filename' => $batch['filename'] ?? null,
                    'total_rows' => $stats['total_rows'],
                    'imported' => $stats['imported'],
                    'errors' => $stats['errors'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
        }
        
        return $stats;
    }
    
    /**
     * Liest Header-Zeile aus Excel
     */
    private function readHeaders(Worksheet $worksheet, int $headerRow): array
    {
        $headers = [];
        $highestColumn = $worksheet->getHighestDataColumn();
        $columnIndex = 1;
        
        while ($columnIndex <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn)) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
            $cellValue = trim($worksheet->getCell($columnLetter . $headerRow)->getFormattedValue());
            
            if (!empty($cellValue)) {
                $headers[$columnLetter] = $cellValue;
            }
            
            $columnIndex++;
        }
        
        return $headers;
    }
    
    /**
     * Liest raw_data (Original Excel-Zeile)
     */
    private function readRawRow(Worksheet $worksheet, int $row, array $headers): array
    {
        $rawData = [
            'row_number' => $row
        ];
        
        foreach ($headers as $columnLetter => $headerName) {
            $cellValue = trim($worksheet->getCell($columnLetter . $row)->getFormattedValue());
            $rawData[$headerName] = $cellValue;
        }
        
        return $rawData;
    }
    
    /**
     * Liest mapped_data (strukturiert)
     */
    private function readMappedRow(Worksheet $worksheet, int $row, array $mappingConfig): array
    {
        // Nutze ImportMappingService für Mapping
        $flatData = $this->mappingService->readRow($worksheet, $row, $mappingConfig);
        
        // Strukturiere nach Konzept: org, industry, address
        $mappedData = [
            'org' => [],
            'industry' => [],
            'address' => [],
            'communication' => []
        ];
        
        // Org-Daten
        if (isset($flatData['name'])) {
            $mappedData['org']['name'] = $flatData['name'];
        }
        if (isset($flatData['website'])) {
            $mappedData['org']['website'] = $this->normalizeUrl($flatData['website']);
        }
        if (isset($flatData['employee_count'])) {
            $mappedData['org']['employee_count'] = (int)$flatData['employee_count'];
        }
        if (isset($flatData['revenue_range'])) {
            $mappedData['org']['revenue_range'] = $flatData['revenue_range'];
        }
        if (isset($flatData['notes'])) {
            $mappedData['org']['notes'] = $flatData['notes'];
        }
        
        // Industry-Daten (Excel-Labels für Resolution)
        if (isset($flatData['industry_level2']) || isset($flatData['industry_main'])) {
            $mappedData['industry']['excel_level2_label'] = $flatData['industry_level2'] ?? $flatData['industry_main'] ?? null;
        }
        if (isset($flatData['industry_level3']) || isset($flatData['industry_sub'])) {
            $mappedData['industry']['excel_level3_label'] = $flatData['industry_level3'] ?? $flatData['industry_sub'] ?? null;
        }
        
        // Adress-Daten
        if (isset($flatData['address_street'])) {
            $mappedData['address']['street'] = $flatData['address_street'];
        }
        if (isset($flatData['address_postal_code'])) {
            $mappedData['address']['postal_code'] = $flatData['address_postal_code'];
        }
        if (isset($flatData['address_city'])) {
            $mappedData['address']['city'] = $flatData['address_city'];
        }
        if (isset($flatData['address_state'])) {
            $mappedData['address']['state'] = $flatData['address_state'];
        }
        
        // Kommunikationskanäle
        if (isset($flatData['email'])) {
            $mappedData['communication']['email'] = $flatData['email'];
        }
        if (isset($flatData['fax'])) {
            $mappedData['communication']['fax'] = $flatData['fax'];
        }
        if (isset($flatData['phone'])) {
            $mappedData['communication']['phone'] = $flatData['phone'];
        }
        
        // VAT ID
        if (isset($flatData['vat_id'])) {
            $mappedData['org']['vat_id'] = $flatData['vat_id'];
        }
        
        return $mappedData;
    }
    
    /**
     * Extrahiert Branchendaten aus raw_data (falls nicht im Mapping vorhanden)
     * 
     * Sucht nach typischen Excel-Header-Namen für Branchenfelder
     */
    private function extractIndustryFromRawData(array $rawData, array &$mappedData): void
    {
        // Wenn bereits Branchendaten vorhanden, nichts tun
        if (!empty($mappedData['industry']['excel_level2_label']) || !empty($mappedData['industry']['excel_level3_label'])) {
            return;
        }
        
        // Typische Header-Namen für Branchenfelder
        $level2Headers = ['Oberkategorie', 'Branche', 'Hauptbranche', 'Industry Main', 'Sektor'];
        $level3Headers = ['Kategorie', 'Unterbranche', 'Sub-Branche', 'Industry Sub', 'Kategorie'];
        
        // Suche Level 2 Label
        foreach ($level2Headers as $header) {
            if (isset($rawData[$header]) && !empty(trim($rawData[$header]))) {
                $mappedData['industry']['excel_level2_label'] = trim($rawData[$header]);
                break;
            }
        }
        
        // Suche Level 3 Label
        foreach ($level3Headers as $header) {
            if (isset($rawData[$header]) && !empty(trim($rawData[$header]))) {
                $mappedData['industry']['excel_level3_label'] = trim($rawData[$header]);
                break;
            }
        }
    }
    
    /**
     * Erstellt industry_resolution (Vorschläge + Entscheidung)
     */
    private function buildIndustryResolution(array $mappedData, array $mappingConfig): array
    {
        $resolution = [
            'excel' => [
                'level2_label' => $mappedData['industry']['excel_level2_label'] ?? null,
                'level3_label' => $mappedData['industry']['excel_level3_label'] ?? null
            ],
            'suggestions' => [
                'level2_candidates' => [],
                'derived_level1' => null,
                'level3_candidates' => []
            ],
            'decision' => [
                'status' => 'PENDING',
                'level1_uuid' => null,
                'level2_uuid' => null,
                'level3_uuid' => null,
                'level1_confirmed' => false,
                'level2_confirmed' => false,
                'level3_action' => 'UNDECIDED',
                'level3_new_name' => null
            ]
        ];
        
        $level2Label = $mappedData['industry']['excel_level2_label'] ?? null;
        $level3Label = $mappedData['industry']['excel_level3_label'] ?? null;
        
        // Wenn Level 2 Label vorhanden, suche Kandidaten
        if ($level2Label) {
            $candidates = $this->resolver->suggestLevel2($level2Label, 5);
            
            $resolution['suggestions']['level2_candidates'] = array_map(function($c) {
                return [
                    'industry_uuid' => $c['industry_uuid'],
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'score' => $c['score']
                ];
            }, $candidates);
            
            // Wenn Kandidaten gefunden, nimm besten und leite Level 1 ab
            if (!empty($candidates)) {
                $best = $candidates[0];
                $resolution['decision']['level2_uuid'] = $best['industry_uuid'];
                
                // Leite Level 1 ab
                $derivedL1 = $this->resolver->deriveLevel1FromLevel2($best['industry_uuid']);
                if ($derivedL1) {
                    $resolution['suggestions']['derived_level1'] = [
                        'industry_uuid' => $derivedL1['industry_uuid'],
                        'code' => $derivedL1['code'],
                        'name' => $derivedL1['name'],
                        'derived_from_level2_uuid' => $best['industry_uuid']
                    ];
                    $resolution['decision']['level1_uuid'] = $derivedL1['industry_uuid'];
                }
                
                // Wenn Level 3 Label vorhanden, suche Level 3 Kandidaten
                if ($level3Label && !empty($best['industry_uuid'])) {
                    $l3Candidates = $this->resolver->suggestLevel3UnderLevel2(
                        $best['industry_uuid'],
                        $level3Label,
                        5
                    );
                    
                    $resolution['suggestions']['level3_candidates'] = array_map(function($c) {
                        return [
                            'industry_uuid' => $c['industry_uuid'] ?? null,
                            'code' => $c['code'] ?? null,
                            'name' => $c['name'],
                            'score' => $c['score']
                        ];
                    }, $l3Candidates);
                }
            }
        }
        
        return $resolution;
    }
    
    /**
     * Generiert Row-Fingerprint für Idempotenz
     */
    private function generateRowFingerprint(array $mappedData): string
    {
        $keyFields = [
            $mappedData['org']['name'] ?? '',
            $mappedData['org']['website'] ?? '',
            $mappedData['address']['postal_code'] ?? '',
            $mappedData['address']['city'] ?? '',
            $mappedData['org']['vat_id'] ?? ''
        ];
        
        $normalized = array_map(function($v) {
            return mb_strtolower(trim($v));
        }, $keyFields);
        
        $fingerprint = implode('|', $normalized);
        return hash('sha256', $fingerprint);
    }
    
    /**
     * Speichert Staging-Row in DB
     */
    private function saveStagingRow(
        string $batchUuid,
        int $rowNumber,
        array $rawData,
        array $mappedData,
        array $industryResolution,
        string $rowFingerprint,
        string $fileFingerprint
    ): string {
        $stagingUuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_import_staging (
                staging_uuid, import_batch_uuid, row_number,
                raw_data, mapped_data, industry_resolution,
                row_fingerprint, file_fingerprint,
                validation_status, disposition, import_status
            )
            VALUES (
                :staging_uuid, :import_batch_uuid, :row_number,
                :raw_data, :mapped_data, :industry_resolution,
                :row_fingerprint, :file_fingerprint,
                'pending', 'pending', 'pending'
            )
        ");
        
        $stmt->execute([
            'staging_uuid' => $stagingUuid,
            'import_batch_uuid' => $batchUuid,
            'row_number' => $rowNumber,
            'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE),
            'mapped_data' => json_encode($mappedData, JSON_UNESCAPED_UNICODE),
            'industry_resolution' => json_encode($industryResolution, JSON_UNESCAPED_UNICODE),
            'row_fingerprint' => $rowFingerprint,
            'file_fingerprint' => $fileFingerprint
        ]);
        
        return $stagingUuid;
    }
    
    /**
     * Holt Batch aus DB
     */
    private function getBatch(string $batchUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT batch_uuid, filename, mapping_config, uploaded_by_user_id, status
            FROM org_import_batch
            WHERE batch_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $batchUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Aktualisiert Batch-Status
     */
    private function updateBatchStatus(string $batchUuid, string $status, array $stats): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_batch
            SET status = :status,
                stats_json = :stats_json
            WHERE batch_uuid = :batch_uuid
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'status' => $status,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    /**
     * Normalisiert URL
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) {
            return '';
        }
        
        // Wenn kein Protokoll, füge https:// hinzu
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        
        return $url;
    }
    
    /**
     * Holt einzelne Staging-Row aus DB
     * 
     * @param string $stagingUuid
     * @return array|null
     */
    public function getStagingRow(string $stagingUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                staging_uuid,
                import_batch_uuid,
                row_number,
                raw_data,
                mapped_data,
                corrections_json,
                industry_resolution,
                validation_status,
                validation_errors,
                disposition,
                review_notes,
                duplicate_status,
                duplicate_summary,
                import_status
            FROM org_import_staging
            WHERE staging_uuid = :uuid
        ");
        
        $stmt->execute(['uuid' => $stagingUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ?: null;
    }
    
    /**
     * Holt alle Staging-Rows für einen Batch
     * 
     * @param string $batchUuid
     * @param string|null $reviewStatus Filter nach review_status
     * @param int|null $limit Maximale Anzahl
     * @param int|null $offset Offset für Pagination
     * @return array
     */
    public function getStagingRowsForBatch(
        string $batchUuid,
        ?string $reviewStatus = null,
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $sql = "
            SELECT 
                staging_uuid,
                import_batch_uuid,
                row_number,
                raw_data,
                mapped_data,
                industry_resolution,
                validation_status,
                validation_errors,
                disposition,
                review_notes,
                duplicate_status,
                duplicate_summary,
                import_status
            FROM org_import_staging
            WHERE import_batch_uuid = :batch_uuid
        ";
        
        $params = ['batch_uuid' => $batchUuid];
        
        if ($reviewStatus) {
            // Verwende disposition statt review_status
            $sql .= " AND disposition = :disposition";
            $params['disposition'] = $reviewStatus;
        }
        
        $sql .= " ORDER BY row_number ASC";
        
        if ($limit) {
            $sql .= " LIMIT :limit";
            $params['limit'] = $limit;
            
            if ($offset) {
                $sql .= " OFFSET :offset";
                $params['offset'] = $offset;
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'staging_uuid' => $row['staging_uuid'],
                'batch_uuid' => $row['import_batch_uuid'],
                'row_number' => $row['row_number'],
                'raw_data' => json_decode($row['raw_data'] ?? '{}', true),
                'mapped_data' => json_decode($row['mapped_data'] ?? '{}', true),
                'industry_resolution' => json_decode($row['industry_resolution'] ?? '{}', true),
                'validation_status' => $row['validation_status'],
                'validation_errors' => json_decode($row['validation_errors'] ?? '[]', true),
                'disposition' => $row['disposition'] ?? 'pending', // Korrekte Feldname
                'review_status' => $row['disposition'] ?? 'pending', // Für Rückwärtskompatibilität
                'review_notes' => $row['review_notes'] ?? null,
                'duplicate_status' => $row['duplicate_status'] ?? 'unknown',
                'duplicate_summary' => isset($row['duplicate_summary']) ? json_decode($row['duplicate_summary'] ?? 'null', true) : null,
                'import_status' => $row['import_status']
            ];
        }
        
        return $rows;
    }
}

