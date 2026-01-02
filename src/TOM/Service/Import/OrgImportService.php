<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;
use TOM\Infrastructure\Activity\ActivityLogService;
use TOM\Service\OrgService;
use TOM\Service\Org\OrgAddressService;

/**
 * Service für Organisationen-Import mit Sandbox/Review-Prozess
 */
class OrgImportService
{
    private PDO $db;
    private OrgService $orgService;
    private OrgAddressService $addressService;
    private ImportMappingService $mappingService;
    private ImportValidationService $validationService;
    private ImportDedupeService $dedupeService;
    private ActivityLogService $activityLogService;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->orgService = new OrgService($this->db);
        $this->addressService = new OrgAddressService($this->db);
        $this->mappingService = new ImportMappingService($this->db);
        $this->validationService = new ImportValidationService($this->db);
        $this->dedupeService = new ImportDedupeService($this->db);
        $this->activityLogService = new ActivityLogService($this->db);
    }
    
    /**
     * Erstellt neuen Import-Batch
     * 
     * @param string $sourceType excel | csv | api | manual
     * @param string $filename Dateiname
     * @param string|null $filePath Optional: Pfad zur Datei (für Hash-Berechnung)
     * @param string|int $userId User-ID (kann int oder string sein)
     * @return string batch_uuid
     */
    public function createBatch(
        string $sourceType,
        string $filename,
        ?string $filePath = null,
        string|int $userId
    ): string {
        // File-Fingerprint generieren (falls filePath vorhanden)
        $fileHash = null;
        if ($filePath && file_exists($filePath)) {
            $fileHash = hash_file('sha256', $filePath);
            
            // Prüfe Idempotenz
            $existingBatch = $this->findExistingBatch($fileHash);
            if ($existingBatch) {
                return $existingBatch;
            }
        }
        
        // Batch erstellen
        $batchUuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_import_batch (
                batch_uuid, source_type, filename, file_hash,
                uploaded_by_user_id, status, validation_rule_set_version
            )
            VALUES (
                :batch_uuid, :source_type, :filename, :file_hash,
                :uploaded_by_user_id, 'DRAFT', 'v1.0'
            )
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'source_type' => $sourceType,
            'filename' => $filename,
            'file_hash' => $fileHash,
            'uploaded_by_user_id' => (string)$userId // Konvertiere zu String für VARCHAR-Spalte
        ]);
        
        return $batchUuid;
    }
    
    /**
     * Aktualisiert file_hash eines Batches (nach Upload)
     */
    public function updateBatchFileHash(string $batchUuid, string $filePath): void
    {
        $fileHash = hash_file('sha256', $filePath);
        
        $stmt = $this->db->prepare("
            UPDATE org_import_batch 
            SET file_hash = :file_hash 
            WHERE batch_uuid = :batch_uuid
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'file_hash' => $fileHash
        ]);
    }
    
    /**
     * Analysiert Excel-Datei und generiert Mapping-Vorschlag
     */
    public function analyzeExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Header-Zeile erkennen
        $headerRow = $this->detectHeaderRow($worksheet);
        
        // Spalten lesen
        $columns = $this->readColumns($worksheet, $headerRow);
        
        // Beispiel-Zeilen lesen (für Vorschau)
        $sampleRows = $this->readSampleRows($worksheet, $headerRow, 5);
        
        // Mapping-Vorschlag generieren (mit Beispielen)
        $mappingSuggestion = $this->mappingService->suggestMapping($columns, $sampleRows);
        
        // Branchen-Validierung
        $industryValidation = null;
        if (!empty($sampleRows)) {
            $industryValidationService = new ImportIndustryValidationService($this->db);
            $industryValidation = $industryValidationService->validateIndustries($sampleRows, $mappingSuggestion);
        }
        
        return [
            'header_row' => $headerRow,
            'columns' => $columns,
            'mapping_suggestion' => $mappingSuggestion,
            'sample_rows' => $sampleRows,
            'industry_validation' => $industryValidation
        ];
    }
    
    /**
     * Speichert Mapping-Konfiguration für Batch
     */
    public function saveMapping(string $batchUuid, array $mappingConfig, ?string $userId = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE org_import_batch
            SET mapping_config = :mapping_config
            WHERE batch_uuid = :batch_uuid
        ");
        
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'mapping_config' => json_encode($mappingConfig)
        ]);
        
        // Activity-Log: Mapping gespeichert
        if ($userId) {
            $batch = $this->getBatch($batchUuid);
            $this->activityLogService->logActivity(
                $userId,
                'import',
                'import_batch',
                $batchUuid,
                [
                    'action' => 'mapping_saved',
                    'filename' => $batch['filename'] ?? null,
                    'mapped_fields' => count($mappingConfig['columns'] ?? []),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
        }
    }
    
    /**
     * Importiert Excel in Staging (Phase 2)
     */
    public function importToStaging(string $batchUuid, string $filePath): array
    {
        // Lade Batch + Mapping
        $batch = $this->getBatch($batchUuid);
        if (!$batch) {
            throw new \RuntimeException("Batch nicht gefunden: $batchUuid");
        }
        
        $mappingConfig = json_decode($batch['mapping_config'], true);
        if (!$mappingConfig) {
            throw new \RuntimeException("Mapping-Konfiguration fehlt");
        }
        
        // Lade Excel
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $headerRow = $mappingConfig['header_row'] ?? 1;
        $dataStartRow = $mappingConfig['data_start_row'] ?? ($headerRow + 1);
        
        // File-Fingerprint
        $fileFingerprint = hash_file('sha256', $filePath);
        
        // Statistiken
        $stats = [
            'total_rows' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'errors_detail' => []
        ];
        
        $highestRow = $worksheet->getHighestDataRow();
        
        // Verarbeite Zeilen
        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            $stats['total_rows']++;
            
            try {
                // Lese Zeile
                $rowData = $this->mappingService->readRow($worksheet, $row, $mappingConfig);
                
                // Validiere
                $validation = $this->validationService->validateRow(
                    $rowData,
                    $batch['validation_rule_set_version'] ?? 'v1.0'
                );
                
                // Generiere Fingerprints
                $rowFingerprint = $this->generateRowFingerprint($rowData);
                
                // Prüfe Duplikate (gegen bestehende DB)
                $duplicates = $this->dedupeService->findDuplicates($rowData);
                
                // Speichere in Staging
                $stagingUuid = $this->saveStagingRow(
                    $batchUuid,
                    $row - $dataStartRow + 1, // row_number
                    $rowData,
                    $rowFingerprint,
                    $fileFingerprint,
                    $validation,
                    $duplicates
                );
                
                // Speichere Duplikat-Kandidaten
                foreach ($duplicates as $duplicate) {
                    $this->dedupeService->saveDuplicateCandidate(
                        $stagingUuid,
                        $duplicate['org_uuid'],
                        $duplicate['score'],
                        $duplicate['reasons']
                    );
                }
                
                $stats['imported']++;
                
            } catch (\Exception $e) {
                $stats['errors']++;
                $stats['errors_detail'][] = [
                    'row' => $row,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Update Batch-Status
        $this->updateBatchStatus($batchUuid, 'STAGED', $stats);
        
        // Activity-Log: Import in Staging
        $batch = $this->getBatch($batchUuid);
        if ($batch && $batch['uploaded_by_user_id']) {
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
     * Speichert Staging-Row
     */
    private function saveStagingRow(
        string $batchUuid,
        int $rowNumber,
        array $rowData,
        string $rowFingerprint,
        string $fileFingerprint,
        array $validation,
        array $duplicates
    ): string {
        $stagingUuid = UuidHelper::generate($this->db);
        
        // Bestimme Validation-Status
        $validationStatus = 'valid';
        if (!empty($validation['errors'])) {
            $validationStatus = 'error';
        } elseif (!empty($validation['warnings']) || !empty($duplicates)) {
            $validationStatus = 'warning';
        }
        
        // Effective Data = Mapped Data (noch keine Korrekturen)
        $effectiveData = $rowData;
        
        $stmt = $this->db->prepare("
            INSERT INTO org_import_staging (
                staging_uuid, import_batch_uuid, row_number,
                raw_data, mapped_data, effective_data,
                row_fingerprint, file_fingerprint,
                validation_status, validation_errors,
                disposition, import_status
            )
            VALUES (
                :staging_uuid, :batch_uuid, :row_number,
                :raw_data, :mapped_data, :effective_data,
                :row_fingerprint, :file_fingerprint,
                :validation_status, :validation_errors,
                'pending', 'pending'
            )
        ");
        
        $stmt->execute([
            'staging_uuid' => $stagingUuid,
            'batch_uuid' => $batchUuid,
            'row_number' => $rowNumber,
            'raw_data' => json_encode($rowData), // TODO: Original Excel-Zeile
            'mapped_data' => json_encode($rowData),
            'effective_data' => json_encode($effectiveData),
            'row_fingerprint' => $rowFingerprint,
            'file_fingerprint' => $fileFingerprint,
            'validation_status' => $validationStatus,
            'validation_errors' => json_encode([
                'errors' => $validation['errors'] ?? [],
                'warnings' => $validation['warnings'] ?? []
            ])
        ]);
        
        return $stagingUuid;
    }
    
    /**
     * Generiert Row-Fingerprint
     */
    private function generateRowFingerprint(array $rowData): string
    {
        $name = $this->normalizeString($rowData['name'] ?? '');
        $domain = $this->extractDomain($rowData['website'] ?? '');
        $country = strtoupper(trim($rowData['address_country'] ?? 'DE'));
        $postalCode = $this->normalizePostalCode($rowData['address_postal_code'] ?? '');
        
        $key = sprintf("%s|%s|%s|%s", $name, $domain, $country, $postalCode);
        
        return hash('sha256', $key);
    }
    
    /**
     * Normalisiert String (für Fingerprint)
     */
    private function normalizeString(string $str): string
    {
        $str = mb_strtolower(trim($str));
        $str = preg_replace('/\s+/', ' ', $str);
        $str = preg_replace('/[^\w\s]/u', '', $str);
        return $str;
    }
    
    /**
     * Extrahiert Domain aus URL
     */
    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return '';
        }
        
        // Normalisiere URL
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        
        $parsed = parse_url($url);
        return mb_strtolower($parsed['host'] ?? '');
    }
    
    /**
     * Normalisiert PLZ
     */
    private function normalizePostalCode(string $plz): string
    {
        return preg_replace('/[^\d]/', '', $plz);
    }
    
    /**
     * Findet bestehenden Batch (Idempotenz)
     */
    private function findExistingBatch(string $fileHash): ?string
    {
        $stmt = $this->db->prepare("
            SELECT batch_uuid 
            FROM org_import_batch 
            WHERE file_hash = :file_hash 
            AND status IN ('DRAFT', 'STAGED', 'IN_REVIEW', 'APPROVED')
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        $stmt->execute(['file_hash' => $fileHash]);
        $result = $stmt->fetch();
        
        return $result ? $result['batch_uuid'] : null;
    }
    
    /**
     * Holt Batch
     */
    public function getBatch(string $batchUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM org_import_batch WHERE batch_uuid = :uuid
        ");
        
        $stmt->execute(['uuid' => $batchUuid]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Aktualisiert Batch-Status
     */
    private function updateBatchStatus(string $batchUuid, string $status, array $stats = []): void
    {
        $updates = ['status = :status'];
        $params = ['batch_uuid' => $batchUuid, 'status' => $status];
        
        if ($status === 'STAGED') {
            $updates[] = 'staged_at = NOW()';
        }
        
        if (!empty($stats)) {
            $updates[] = 'stats_json = :stats';
            $params['stats'] = json_encode($stats);
        }
        
        $stmt = $this->db->prepare("
            UPDATE org_import_batch
            SET " . implode(', ', $updates) . "
            WHERE batch_uuid = :batch_uuid
        ");
        
        $stmt->execute($params);
    }
    
    /**
     * Erkennt Header-Zeile
     * Verbesserte Heuristik: Sucht nach Zeile mit Text-ähnlichen Werten (keine Zahlen)
     */
    private function detectHeaderRow(Worksheet $worksheet): int
    {
        $maxScore = 0;
        $headerRow = 1;
        $highestRow = min(20, $worksheet->getHighestDataRow()); // Prüfe bis Zeile 20
        
        for ($row = 1; $row <= $highestRow; $row++) {
            $score = 0;
            $highestCol = $worksheet->getHighestDataColumn();
            
            // Erweitere Suche bis zur letzten Spalte mit Daten
            $lastCol = 'A';
            for ($col = 'A'; $col <= $highestCol; $col++) {
                $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
                if (!empty($value)) {
                    $lastCol = $col;
                }
            }
            
            // Prüfe alle Spalten bis zur letzten gefüllten
            for ($col = 'A'; $col <= $lastCol; $col++) {
                $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
                if (!empty($value)) {
                    // Header-Zeilen enthalten meist Text, keine Zahlen
                    // Prüfe ob Wert hauptsächlich Text ist
                    $isText = !is_numeric($value) || preg_match('/[a-zA-ZäöüÄÖÜß]/', $value);
                    if ($isText) {
                        $score += 2; // Text-Werte geben mehr Punkte
                    } else {
                        $score += 1; // Zahlen geben weniger Punkte
                    }
                    
                    // Bonus für typische Header-Wörter
                    $valueLower = mb_strtolower($value);
                    $headerKeywords = ['name', 'firma', 'adresse', 'straße', 'plz', 'ort', 'telefon', 'email', 
                                      'url', 'umsatz', 'mitarbeiter', 'ust', 'vat', 'kategorie', 'branche',
                                      'anrede', 'vorname', 'nachname', 'gf', 'geschäftsführer'];
                    foreach ($headerKeywords as $keyword) {
                        if (strpos($valueLower, $keyword) !== false) {
                            $score += 5; // Großer Bonus für Header-Keywords
                            break;
                        }
                    }
                }
            }
            
            if ($score > $maxScore) {
                $maxScore = $score;
                $headerRow = $row;
            }
        }
        
        return $headerRow;
    }
    
    /**
     * Liest Spalten aus Header-Zeile
     * Liest alle Spalten bis zur letzten Spalte mit Daten
     */
    private function readColumns(Worksheet $worksheet, int $headerRow): array
    {
        $columns = [];
        
        // Finde die letzte Spalte mit Daten (in Header-Zeile oder in den ersten Daten-Zeilen)
        // Verwende getHighestColumn() statt getHighestDataColumn() für vollständige Abdeckung
        $highestCol = $worksheet->getHighestColumn();
        $lastCol = 'A';
        
        // Konvertiere Spaltenbuchstaben zu Zahlen für Vergleich
        $highestColNum = $this->columnToNumber($highestCol);
        
        // Prüfe Header-Zeile und erste 3 Daten-Zeilen
        for ($checkRow = $headerRow; $checkRow <= min($headerRow + 3, $worksheet->getHighestDataRow()); $checkRow++) {
            for ($colNum = 1; $colNum <= $highestColNum; $colNum++) {
                $col = $this->numberToColumn($colNum);
                $cell = $worksheet->getCell($col . $checkRow);
                $value = trim($cell->getFormattedValue() ?: '');
                
                if (!empty($value)) {
                    // Vergleiche Spaltenbuchstaben
                    if ($colNum > $this->columnToNumber($lastCol)) {
                        $lastCol = $col;
                    }
                }
            }
        }
        
        // Lese alle Spalten bis zur letzten gefundenen
        $lastColNum = $this->columnToNumber($lastCol);
        for ($colNum = 1; $colNum <= $lastColNum; $colNum++) {
            $col = $this->numberToColumn($colNum);
            $cell = $worksheet->getCell($col . $headerRow);
            $value = trim($cell->getFormattedValue() ?: '');
            // Auch leere Header werden aufgenommen (können später manuell gemappt werden)
            $columns[$col] = $value ?: "Spalte $col";
        }
        
        return $columns;
    }
    
    /**
     * Konvertiert Zahl zu Spaltenbuchstaben (1=A, 2=B, ..., 27=AA, ...)
     */
    private function numberToColumn(int $num): string
    {
        $col = '';
        while ($num > 0) {
            $col = chr(65 + (($num - 1) % 26)) . $col;
            $num = intval(($num - 1) / 26);
        }
        return $col;
    }
    
    /**
     * Vergleicht zwei Spaltenbuchstaben (A < B < ... < Z < AA < AB ...)
     */
    private function compareColumns(string $col1, string $col2): int
    {
        // Konvertiere zu Zahlen für Vergleich
        $num1 = $this->columnToNumber($col1);
        $num2 = $this->columnToNumber($col2);
        return $num1 <=> $num2;
    }
    
    /**
     * Konvertiert Spaltenbuchstaben zu Zahl (A=1, B=2, ..., Z=26, AA=27, ...)
     */
    private function columnToNumber(string $col): int
    {
        $result = 0;
        $col = strtoupper($col);
        for ($i = 0; $i < strlen($col); $i++) {
            $result = $result * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $result;
    }
    
    /**
     * Liest Beispiel-Zeilen
     */
    private function readSampleRows(Worksheet $worksheet, int $headerRow, int $count): array
    {
        $samples = [];
        $dataStartRow = $headerRow + 1;
        
        // Hole alle Spalten, die in readColumns gefunden wurden
        $columns = $this->readColumns($worksheet, $headerRow);
        
        for ($i = 0; $i < $count; $i++) {
            $row = $dataStartRow + $i;
            $rowData = [];
            
            // Lese alle Spalten, auch wenn leer
            foreach (array_keys($columns) as $col) {
                $value = trim($worksheet->getCell($col . $row)->getFormattedValue());
                $rowData[$col] = $value; // Auch leere Werte speichern
            }
            
            // Nur hinzufügen, wenn mindestens ein Wert vorhanden
            if (!empty(array_filter($rowData, fn($v) => !empty(trim($v))))) {
                $samples[] = $rowData;
            }
        }
        
        return $samples;
    }
}
