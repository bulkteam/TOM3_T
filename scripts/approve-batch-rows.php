<?php
/**
 * Approvt alle pending Rows eines Batches
 * 
 * Usage:
 *   php approve-batch-rows.php <batch_uuid> [--dry-run]
 *   php approve-batch-rows.php 303d31bc-e8a1-11f0-9caa-06db59a42104
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Activity\ActivityLogService;

$batchUuid = $argv[1] ?? null;
$dryRun = in_array('--dry-run', $argv);

if (!$batchUuid) {
    echo "Usage: php approve-batch-rows.php <batch_uuid> [--dry-run]\n";
    echo "Example: php approve-batch-rows.php 303d31bc-e8a1-11f0-9caa-06db59a42104\n";
    exit(1);
}

$db = DatabaseConnection::getInstance();
$activityLogService = new ActivityLogService($db);

echo "=== Approve Batch Rows ===\n\n";
echo "Batch UUID: $batchUuid\n";
echo "Dry-Run: " . ($dryRun ? 'Ja' : 'Nein') . "\n\n";

// 1. Prüfe, ob Batch existiert
$stmt = $db->prepare("SELECT batch_uuid, filename, status FROM org_import_batch WHERE batch_uuid = ?");
$stmt->execute([$batchUuid]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo "❌ Batch nicht gefunden: $batchUuid\n";
    exit(1);
}

echo "Batch gefunden:\n";
echo "  - Dateiname: " . ($batch['filename'] ?? 'N/A') . "\n";
echo "  - Status: " . ($batch['status'] ?? 'N/A') . "\n\n";

// 2. Zähle pending Rows
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM org_import_staging
    WHERE import_batch_uuid = ?
    AND disposition = 'pending'
    AND import_status != 'imported'
");
$stmt->execute([$batchUuid]);
$pendingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

echo "Pending Rows: $pendingCount\n\n";

if ($pendingCount === 0) {
    echo "✅ Keine pending Rows zum Approven gefunden.\n";
    exit(0);
}

// 3. Prüfe, ob Industry-Entscheidungen vorhanden sind
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN JSON_EXTRACT(industry_resolution, '$.decision.level1_uuid') IS NOT NULL 
                 AND JSON_EXTRACT(industry_resolution, '$.decision.level2_uuid') IS NOT NULL 
            THEN 1 ELSE 0 END) as with_industry_decision
    FROM org_import_staging
    WHERE import_batch_uuid = ?
    AND disposition = 'pending'
    AND import_status != 'imported'
");
$stmt->execute([$batchUuid]);
$industryCheck = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Industry-Entscheidungen:\n";
echo "  - Total pending: " . ($industryCheck['total'] ?? 0) . "\n";
echo "  - Mit Industry-Entscheidung: " . ($industryCheck['with_industry_decision'] ?? 0) . "\n";
echo "  - Ohne Industry-Entscheidung: " . (($industryCheck['total'] ?? 0) - ($industryCheck['with_industry_decision'] ?? 0)) . "\n\n";

if (($industryCheck['with_industry_decision'] ?? 0) < ($industryCheck['total'] ?? 0)) {
    echo "⚠️  Warnung: Nicht alle Rows haben eine Industry-Entscheidung!\n";
    echo "   Rows ohne Industry-Entscheidung können nicht importiert werden.\n";
    echo "   Möchten Sie trotzdem fortfahren? (j/n): ";
    
    if (!$dryRun) {
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);
        
        if (strtolower($line) !== 'j' && strtolower($line) !== 'y') {
            echo "Abgebrochen.\n";
            exit(0);
        }
    }
}

// 4. Approve Rows
if ($dryRun) {
    echo "\n[DRY-RUN] Würde folgende Rows approven:\n";
    $stmt = $db->prepare("
        SELECT staging_uuid, row_number, validation_status
        FROM org_import_staging
        WHERE import_batch_uuid = ?
        AND disposition = 'pending'
        AND import_status != 'imported'
        ORDER BY row_number
        LIMIT 10
    ");
    $stmt->execute([$batchUuid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        echo "  - Row #{$row['row_number']} (UUID: {$row['staging_uuid']}, Validation: {$row['validation_status']})\n";
    }
    
    if ($pendingCount > 10) {
        echo "  ... und " . ($pendingCount - 10) . " weitere\n";
    }
    
    echo "\n✅ Dry-Run abgeschlossen. Verwenden Sie ohne --dry-run, um tatsächlich zu approven.\n";
} else {
    echo "Approving Rows...\n";
    
    $db->beginTransaction();
    
    try {
        $stmt = $db->prepare("
            UPDATE org_import_staging
            SET disposition = 'approved',
                reviewed_by_user_id = 'system',
                reviewed_at = NOW()
            WHERE import_batch_uuid = ?
            AND disposition = 'pending'
            AND import_status != 'imported'
        ");
        
        $stmt->execute([$batchUuid]);
        $affected = $stmt->rowCount();
        
        // Activity-Log
        $activityLogService->logActivity(
            'system',
            'import',
            'import_batch',
            $batchUuid,
            [
                'action' => 'batch_rows_approved',
                'rows_approved' => $affected,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        );
        
        $db->commit();
        
        echo "✅ $affected Rows wurden approved.\n";
        echo "\nSie können jetzt den Import durchführen:\n";
        echo "  - Im GUI: Gehen Sie zum Review-Schritt und klicken Sie auf 'Importieren'\n";
        echo "  - Oder via API: POST /api/import/batch/$batchUuid/commit\n";
        
    } catch (Exception $e) {
        $db->rollBack();
        echo "❌ Fehler beim Approven: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n=== Fertig ===\n";

