<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * ImportBatchService
 * 
 * Verwaltet Import-Batches und deren Status
 */
final class ImportBatchService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Listet alle Batches mit Status-Statistiken
     * 
     * @param string|null $userId Filter nach User-ID (optional)
     * @param int|null $limit Maximale Anzahl
     * @return array<int, array{
     *     batch_uuid: string,
     *     filename: string,
     *     status: string,
     *     source_type: string,
     *     uploaded_by_user_id: string|null,
     *     uploaded_by_name: string|null,
     *     uploaded_by_email: string|null,
     *     created_at: string,
     *     staged_at: string|null,
     *     imported_at: string|null,
     *     stats: array{
     *         total_rows: int,
     *         approved_rows: int,
     *         pending_rows: int,
     *         skipped_rows: int,
     *         imported_rows: int,
     *         failed_rows: int
     *     },
     *     server_stats: array<string, mixed>
     * }>
     */
    public function listBatches(?string $userId = null, ?int $limit = 50): array
    {
        $sql = "
            SELECT 
                b.batch_uuid,
                b.filename,
                b.status,
                b.source_type,
                b.uploaded_by_user_id,
                u.name as uploaded_by_name,
                u.email as uploaded_by_email,
                b.created_at,
                b.staged_at,
                b.imported_at,
                b.stats_json,
                COUNT(DISTINCT s.staging_uuid) as total_rows,
                SUM(CASE WHEN s.disposition = 'approved' AND s.import_status = 'pending' THEN 1 ELSE 0 END) as approved_rows,
                SUM(CASE WHEN s.disposition = 'pending' AND s.import_status = 'pending' THEN 1 ELSE 0 END) as pending_rows,
                SUM(CASE WHEN s.duplicate_status IN ('confirmed','possible') AND s.import_status != 'imported' THEN 1 ELSE 0 END) as redundant_rows,
                SUM(CASE WHEN s.disposition = 'skip' THEN 1 ELSE 0 END) as skipped_rows,
                SUM(CASE WHEN s.import_status = 'imported' THEN 1 ELSE 0 END) as imported_rows,
                SUM(CASE WHEN s.import_status = 'failed' THEN 1 ELSE 0 END) as failed_rows
            FROM org_import_batch b
            LEFT JOIN users u ON b.uploaded_by_user_id = u.user_id
            LEFT JOIN org_import_staging s ON b.batch_uuid = s.import_batch_uuid
        ";
        
        $params = [];
        
        if ($userId) {
            $sql .= " WHERE b.uploaded_by_user_id = :user_id";
            $params['user_id'] = $userId;
        }
        
        $sql .= " GROUP BY b.batch_uuid";
        $sql .= " ORDER BY b.created_at DESC";
        
        if ($limit) {
            $sql .= " LIMIT :limit";
            $params['limit'] = $limit;
        }
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'limit') {
                $stmt->bindValue(':limit', $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $value);
            }
        }
        $stmt->execute();
        
        /** @var array<int, array<string, mixed>> $batches */
        $batches = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            /** @var array<string, mixed> $row */
            $stats = $row['stats_json'] ? json_decode($row['stats_json'], true) : [];
            
            // Berechne korrekten Status basierend auf tatsächlichen importierten Rows
            $totalRows = (int)($row['total_rows'] ?? 0);
            $importedRows = (int)($row['imported_rows'] ?? 0);
            $redundantRows = (int)($row['redundant_rows'] ?? 0);
            $skippedRows = (int)($row['skipped_rows'] ?? 0);
            $actualStatus = $row['status'];
            
            // Wenn alle Rows entweder importiert, redundant oder explizit übersprungen sind → IMPORTED
            if ($totalRows > 0 && ($importedRows + $redundantRows + $skippedRows) >= $totalRows) {
                $actualStatus = 'IMPORTED';
            } else {
                // Wenn Status IMPORTED ist, aber noch offene Rows existieren, korrigiere Status
                if ($actualStatus === 'IMPORTED' && $totalRows > 0 && $importedRows < $totalRows) {
                    $approvedRows = (int)($row['approved_rows'] ?? 0);
                    $pendingRows = (int)($row['pending_rows'] ?? 0);
                    
                    if ($approvedRows > 0) {
                        $actualStatus = 'APPROVED';
                    } else if ($pendingRows > 0) {
                        $actualStatus = 'STAGED';
                    } else {
                        $actualStatus = 'STAGED'; // Fallback
                    }
                }
            }
            
            $batches[] = [
                'batch_uuid' => $row['batch_uuid'],
                'filename' => $row['filename'],
                'status' => $actualStatus, // Verwende korrigierten Status
                'source_type' => $row['source_type'],
                'uploaded_by_user_id' => $row['uploaded_by_user_id'],
                'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
                'uploaded_by_email' => $row['uploaded_by_email'] ?? null,
                'created_at' => $row['created_at'],
                'staged_at' => $row['staged_at'],
                'imported_at' => $row['imported_at'],
                'stats' => [
                    'total_rows' => $totalRows,
                    'approved_rows' => (int)($row['approved_rows'] ?? 0),
                    'pending_rows' => (int)($row['pending_rows'] ?? 0),
                    'redundant_rows' => (int)($row['redundant_rows'] ?? 0),
                    'skipped_rows' => (int)($row['skipped_rows'] ?? 0),
                    'imported_rows' => $importedRows,
                    'failed_rows' => (int)($row['failed_rows'] ?? 0)
                ],
                'server_stats' => $stats
            ];
        }
        
        return $batches;
    }
    
    /**
     * Holt Batch mit detaillierten Statistiken
     * 
     * @param string $batchUuid UUID des Batches
     * @return array{
     *     batch_uuid: string,
     *     filename: string,
     *     status: string,
     *     source_type: string,
     *     uploaded_by_user_id: string|null,
     *     created_at: string,
     *     staged_at: string|null,
     *     imported_at: string|null,
     *     mapping_config: array<string, mixed>|null,
     *     stats: array{
     *         total_rows: int,
     *         approved_rows: int,
     *         pending_rows: int,
     *         skipped_rows: int,
     *         imported_rows: int,
     *         failed_rows: int,
     *         valid_rows: int,
     *         warning_rows: int,
     *         error_rows: int
     *     },
     *     server_stats: array<string, mixed>
     * }|null
     */
    public function getBatchWithStats(string $batchUuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                b.*,
                COUNT(DISTINCT s.staging_uuid) as total_rows,
                SUM(CASE WHEN s.disposition = 'approved' AND s.import_status = 'pending' THEN 1 ELSE 0 END) as approved_rows,
                SUM(CASE WHEN s.disposition = 'pending' AND s.import_status = 'pending' THEN 1 ELSE 0 END) as pending_rows,
                SUM(CASE WHEN s.duplicate_status IN ('confirmed','possible') AND s.import_status != 'imported' THEN 1 ELSE 0 END) as redundant_rows,
                SUM(CASE WHEN s.disposition = 'skip' THEN 1 ELSE 0 END) as skipped_rows,
                SUM(CASE WHEN s.import_status = 'imported' THEN 1 ELSE 0 END) as imported_rows,
                SUM(CASE WHEN s.import_status = 'failed' THEN 1 ELSE 0 END) as failed_rows,
                SUM(CASE WHEN s.validation_status = 'valid' THEN 1 ELSE 0 END) as valid_rows,
                SUM(CASE WHEN s.validation_status = 'warning' THEN 1 ELSE 0 END) as warning_rows,
                SUM(CASE WHEN s.validation_status = 'error' THEN 1 ELSE 0 END) as error_rows
            FROM org_import_batch b
            LEFT JOIN org_import_staging s ON b.batch_uuid = s.import_batch_uuid
            WHERE b.batch_uuid = :batch_uuid
            GROUP BY b.batch_uuid
        ");
        
        $stmt->execute(['batch_uuid' => $batchUuid]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row === false) {
            return null;
        }
        
        $stats = $row['stats_json'] ? json_decode($row['stats_json'], true) : [];
        
        // Berechne korrekten Status basierend auf tatsächlichen importierten Rows
        $totalRows = (int)($row['total_rows'] ?? 0);
        $importedRows = (int)($row['imported_rows'] ?? 0);
        $actualStatus = $row['status'];
        
        // Wenn Status IMPORTED ist, aber nicht alle Rows importiert wurden, korrigiere Status
        if ($actualStatus === 'IMPORTED' && $totalRows > 0 && $importedRows < $totalRows) {
            // Nicht alle Rows importiert - Status sollte nicht IMPORTED sein
            // Bestimme korrekten Status basierend auf approved/pending Rows
            $approvedRows = (int)($row['approved_rows'] ?? 0);
            $pendingRows = (int)($row['pending_rows'] ?? 0);
            
            if ($approvedRows > 0) {
                $actualStatus = 'APPROVED';
            } else if ($pendingRows > 0) {
                $actualStatus = 'STAGED';
            } else {
                $actualStatus = 'STAGED'; // Fallback
            }
        }
        
        return [
            'batch_uuid' => $row['batch_uuid'],
            'filename' => $row['filename'],
            'status' => $actualStatus, // Verwende korrigierten Status
            'source_type' => $row['source_type'],
            'uploaded_by_user_id' => $row['uploaded_by_user_id'],
            'created_at' => $row['created_at'],
            'staged_at' => $row['staged_at'],
            'imported_at' => $row['imported_at'],
            'mapping_config' => isset($row['mapping_config']) && $row['mapping_config'] ? json_decode($row['mapping_config'], true) : null,
                'stats' => [
                    'total_rows' => $totalRows,
                    'approved_rows' => (int)($row['approved_rows'] ?? 0),
                    'pending_rows' => (int)($row['pending_rows'] ?? 0),
                    'redundant_rows' => (int)($row['redundant_rows'] ?? 0),
                    'skipped_rows' => (int)($row['skipped_rows'] ?? 0),
                    'imported_rows' => $importedRows,
                    'failed_rows' => (int)($row['failed_rows'] ?? 0),
                    'valid_rows' => (int)($row['valid_rows'] ?? 0),
                    'warning_rows' => (int)($row['warning_rows'] ?? 0),
                'error_rows' => (int)($row['error_rows'] ?? 0)
            ],
            'server_stats' => $stats
        ];
    }
    
    /**
     * Löscht einen Batch (nur DRAFT-Status erlaubt)
     * 
     * Löscht:
     * - Batch aus org_import_batch
     * - Alle Staging-Rows (CASCADE)
     * - Alle zugehörigen Dokumente/Attachments
     * 
     * @param string $batchUuid
     * @param string $userId User-ID für Audit-Log
     * @return bool Erfolg
     * @throws \RuntimeException Wenn Batch nicht gelöscht werden kann
     */
    public function deleteBatch(string $batchUuid, string $userId): bool
    {
        // 1. Prüfe, ob Batch existiert und Status
        $stmt = $this->db->prepare("
            SELECT batch_uuid, status, filename
            FROM org_import_batch
            WHERE batch_uuid = ?
        ");
        $stmt->execute([$batchUuid]);
        /** @var array<string, mixed>|false $batch */
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch === false) {
            throw new \RuntimeException("Batch nicht gefunden: $batchUuid");
        }
        
        // 2. Prüfe, ob ALLE Daten importiert wurden
        // Ein Batch kann nur gelöscht werden, wenn NICHT ALLE Rows importiert wurden
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN import_status = 'imported' THEN 1 ELSE 0 END) as imported_count
            FROM org_import_staging
            WHERE import_batch_uuid = ?
        ");
        $stmt->execute([$batchUuid]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $row = [];
        }
        $totalCount = (int)($row['total_count'] ?? 0);
        $importedCount = (int)($row['imported_count'] ?? 0);
        
        // Wenn alle Rows importiert wurden, kann der Batch nicht gelöscht werden
        if ($totalCount > 0 && $importedCount === $totalCount) {
            throw new \RuntimeException("Batch kann nicht gelöscht werden. Alle {$totalCount} Zeile(n) wurden bereits in die Produktivtabellen importiert.");
        }
        
        // 3. Prüfe Status - IMPORTED-Batches können nicht gelöscht werden, ABER nur wenn wirklich alle importiert sind
        // Der Status in der DB kann falsch sein, daher prüfen wir den tatsächlichen Import-Status
        if ($batch['status'] === 'IMPORTED' && $totalCount > 0 && $importedCount === $totalCount) {
            throw new \RuntimeException("Batch kann nicht gelöscht werden. Status: IMPORTED. Alle {$totalCount} Zeile(n) wurden bereits in die Produktivtabellen importiert.");
        }
        
        // 4. Hole zugehörige Dokumente/Attachments
        $stmt = $this->db->prepare("
            SELECT da.attachment_uuid, da.document_uuid
            FROM document_attachments da
            WHERE da.entity_type = 'import_batch'
            AND da.entity_uuid = ?
        ");
        $stmt->execute([$batchUuid]);
        /** @var array<int, array<string, string>> $attachments */
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 5. Beginne Transaktion
        $this->db->beginTransaction();
        
        try {
            // 6. Lösche Attachments (soft delete)
            /** @var array<string, string> $attachment */
            foreach ($attachments as $attachment) {
                $stmt = $this->db->prepare("DELETE FROM document_attachments WHERE attachment_uuid = ?");
                $stmt->execute([$attachment['attachment_uuid']]);
                
                // Prüfe, ob Document noch verwendet wird
                $stmt2 = $this->db->prepare("
                    SELECT COUNT(*) as count
                    FROM document_attachments
                    WHERE document_uuid = ?
                ");
                $stmt2->execute([$attachment['document_uuid']]);
                /** @var array<string, mixed>|false $usageRow */
                $usageRow = $stmt2->fetch(PDO::FETCH_ASSOC);
                $usageCount = $usageRow !== false ? (int)($usageRow['count'] ?? 0) : 0;
                
                // Wenn Document nicht mehr verwendet wird, soft delete
                if ($usageCount === 0) {
                    $stmt3 = $this->db->prepare("
                        UPDATE documents 
                        SET status = 'deleted' 
                        WHERE document_uuid = ?
                    ");
                    $stmt3->execute([$attachment['document_uuid']]);
                }
            }
            
            // 7. Lösche Batch (CASCADE löscht automatisch Staging-Rows)
            $stmt = $this->db->prepare("DELETE FROM org_import_batch WHERE batch_uuid = ?");
            $stmt->execute([$batchUuid]);
            
            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException("Batch konnte nicht gelöscht werden");
            }
            
            // 8. Activity-Log
            $activityLogService = new \TOM\Infrastructure\Activity\ActivityLogService($this->db);
            $activityLogService->logActivity(
                $userId,
                'import',
                'import_batch',
                $batchUuid,
                [
                    'action' => 'batch_deleted',
                    'filename' => $batch['filename'],
                    'status' => $batch['status'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
            
            // 9. Commit
            $this->db->commit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}

