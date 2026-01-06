<?php
declare(strict_types=1);

namespace TOM\Service\Import;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Activity\ActivityLogService;

/**
 * ImportReviewService
 * 
 * Verwaltet Review und Disposition von Staging-Rows
 */
final class ImportReviewService
{
    private PDO $db;
    private ActivityLogService $activityLogService;
    
    public function __construct(
        ?PDO $db = null,
        ?ActivityLogService $activityLogService = null
    ) {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->activityLogService = $activityLogService ?? new ActivityLogService($this->db);
    }
    
    /**
     * Setzt Disposition einer Staging-Row
     * 
     * @param string $stagingUuid
     * @param string $disposition 'approved' | 'skip' | 'needs_fix'
     * @param string $userId
     * @param string|null $notes Optionale Notizen
     * @return void
     */
    public function setDisposition(
        string $stagingUuid,
        string $disposition,
        string $userId,
        ?string $notes = null
    ): void {
        // Validiere Disposition
        $allowed = ['approved', 'skip', 'needs_fix', 'pending'];
        if (!in_array($disposition, $allowed)) {
            throw new \InvalidArgumentException("Ungültige Disposition: $disposition. Erlaubt: " . implode(', ', $allowed));
        }
        
        // Prüfe, ob Row existiert
        $stmt = $this->db->prepare("
            SELECT staging_uuid, import_batch_uuid, import_status
            FROM org_import_staging
            WHERE staging_uuid = ?
        ");
        $stmt->execute([$stagingUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            throw new \RuntimeException("Staging-Row nicht gefunden: $stagingUuid");
        }
        
        // Prüfe, ob bereits importiert
        if ($row['import_status'] === 'imported') {
            throw new \RuntimeException("Row wurde bereits importiert und kann nicht mehr geändert werden");
        }
        
        // Update Disposition
        $stmt = $this->db->prepare("
            UPDATE org_import_staging
            SET disposition = :disposition,
                reviewed_by_user_id = :user_id,
                reviewed_at = NOW(),
                review_notes = :notes
            WHERE staging_uuid = :staging_uuid
        ");
        
        $stmt->execute([
            'staging_uuid' => $stagingUuid,
            'disposition' => $disposition,
            'user_id' => $userId,
            'notes' => $notes
        ]);
        
        // Activity-Log
        $this->activityLogService->logActivity(
            $userId,
            'import',
            'import_staging',
            $stagingUuid,
            [
                'action' => 'disposition_changed',
                'disposition' => $disposition,
                'batch_uuid' => $row['import_batch_uuid'],
                'notes' => $notes,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Setzt Disposition für mehrere Rows (Bulk)
     * 
     * @param array $stagingUuids Array von Staging-UUIDs
     * @param string $disposition
     * @param string $userId
     * @return int Anzahl der aktualisierten Rows
     */
    public function setBulkDisposition(
        array $stagingUuids,
        string $disposition,
        string $userId
    ): int {
        if (empty($stagingUuids)) {
            return 0;
        }
        
        // Validiere Disposition
        $allowed = ['approved', 'skip', 'needs_fix', 'pending'];
        if (!in_array($disposition, $allowed)) {
            throw new \InvalidArgumentException("Ungültige Disposition: $disposition");
        }
        
        // Erstelle Platzhalter für IN-Clause
        $placeholders = implode(',', array_fill(0, count($stagingUuids), '?'));
        
        // Update
        $stmt = $this->db->prepare("
            UPDATE org_import_staging
            SET disposition = ?,
                reviewed_by_user_id = ?,
                reviewed_at = NOW()
            WHERE staging_uuid IN ($placeholders)
            AND import_status != 'imported'
        ");
        
        $params = array_merge([$disposition, $userId], $stagingUuids);
        $stmt->execute($params);
        
        $affected = $stmt->rowCount();
        
        // Activity-Log (pro Batch gruppiert)
        $stmt2 = $this->db->prepare("
            SELECT DISTINCT import_batch_uuid
            FROM org_import_staging
            WHERE staging_uuid IN ($placeholders)
        ");
        $stmt2->execute($stagingUuids);
        $batches = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($batches as $batchUuid) {
            $this->activityLogService->logActivity(
                $userId,
                'import',
                'import_batch',
                $batchUuid,
                [
                    'action' => 'bulk_disposition_changed',
                    'disposition' => $disposition,
                    'rows_count' => $affected,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
        }
        
        return $affected;
    }
}



