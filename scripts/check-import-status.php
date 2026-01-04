<?php
/**
 * Prüft Import-Status und verifiziert, ob Daten in Produktivtabellen geschrieben wurden
 * 
 * Usage:
 *   php check-import-status.php [batch_uuid]
 *   php check-import-status.php                    # Zeigt alle Batches
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

// Batch UUID aus Kommandozeile
$batchUuid = $argv[1] ?? null;

echo "=== Import-Status Prüfung ===\n\n";

if ($batchUuid) {
    // Prüfe spezifischen Batch
    checkBatch($db, $batchUuid);
} else {
    // Zeige alle Batches
    listBatches($db);
}

/**
 * Listet alle Batches mit Status
 */
function listBatches($db) {
    try {
        $stmt = $db->query("
            SELECT 
                batch_uuid,
                filename,
                status,
                uploaded_by_user_id,
                created_at,
                imported_at,
                stats_json
            FROM org_import_batch
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($batches)) {
            echo "⚠️  Keine Batches gefunden!\n";
            return;
        }
        
        echo "📦 Gefundene Batches:\n";
        echo str_repeat("=", 100) . "\n";
        
        foreach ($batches as $batch) {
            echo "Batch UUID: " . $batch['batch_uuid'] . "\n";
            echo "  - Dateiname: " . ($batch['filename'] ?? 'N/A') . "\n";
            echo "  - Status: " . ($batch['status'] ?? 'N/A') . "\n";
            echo "  - Upload von User: " . ($batch['uploaded_by_user_id'] ?? 'N/A') . "\n";
            echo "  - Erstellt: " . ($batch['created_at'] ?? 'N/A') . "\n";
            echo "  - Importiert: " . ($batch['imported_at'] ?? 'N/A') . "\n";
            
            // Zeige Staging-Statistiken
            $stmt2 = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN import_status = 'imported' THEN 1 ELSE 0 END) as imported,
                    SUM(CASE WHEN import_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN import_status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN disposition = 'approved' THEN 1 ELSE 0 END) as approved
                FROM org_import_staging
                WHERE import_batch_uuid = ?
            ");
            $stmt2->execute([$batch['batch_uuid']]);
            $stats = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($stats && $stats['total'] > 0) {
                echo "  - Staging-Rows: " . $stats['total'] . "\n";
                echo "    - Importiert: " . ($stats['imported'] ?? 0) . "\n";
                echo "    - Pending: " . ($stats['pending'] ?? 0) . "\n";
                echo "    - Failed: " . ($stats['failed'] ?? 0) . "\n";
                echo "    - Approved: " . ($stats['approved'] ?? 0) . "\n";
            }
            
            echo "\n";
        }
        
        echo "\n💡 Tipp: Verwende 'php check-import-status.php <batch_uuid>' für detaillierte Prüfung\n";
        
    } catch (Exception $e) {
        echo "❌ Fehler: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Prüft einen spezifischen Batch detailliert
 */
function checkBatch($db, $batchUuid) {
    echo "Prüfe Batch: $batchUuid\n";
    echo str_repeat("=", 100) . "\n\n";
    
    // 1. Hole Batch-Details
    try {
        $stmt = $db->prepare("
            SELECT 
                batch_uuid,
                filename,
                status,
                uploaded_by_user_id,
                created_at,
                imported_at,
                stats_json
            FROM org_import_batch
            WHERE batch_uuid = ?
        ");
        $stmt->execute([$batchUuid]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            echo "❌ Batch nicht gefunden: $batchUuid\n";
            exit(1);
        }
        
        echo "📦 Batch-Details:\n";
        echo "  - Dateiname: " . ($batch['filename'] ?? 'N/A') . "\n";
        echo "  - Status: " . ($batch['status'] ?? 'N/A') . "\n";
        echo "  - Upload von User: " . ($batch['uploaded_by_user_id'] ?? 'N/A') . "\n";
        echo "  - Erstellt: " . ($batch['created_at'] ?? 'N/A') . "\n";
        echo "  - Importiert: " . ($batch['imported_at'] ?? 'N/A') . "\n";
        
        if ($batch['stats_json']) {
            $stats = json_decode($batch['stats_json'], true);
            if ($stats) {
                echo "  - Stats:\n";
                echo "    - Rows total: " . ($stats['rows_total'] ?? 0) . "\n";
                echo "    - Rows imported: " . ($stats['rows_imported'] ?? 0) . "\n";
                echo "    - Rows failed: " . ($stats['rows_failed'] ?? 0) . "\n";
                echo "    - Created orgs: " . ($stats['created_orgs'] ?? 0) . "\n";
                echo "    - Created Level 3 industries: " . ($stats['created_level3_industries'] ?? 0) . "\n";
            }
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "❌ Fehler beim Laden des Batches: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // 2. Prüfe Staging-Rows
    echo "📊 Staging-Rows Status:\n";
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN import_status = 'imported' THEN 1 ELSE 0 END) as imported,
                SUM(CASE WHEN import_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN import_status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN disposition = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN disposition = 'pending' THEN 1 ELSE 0 END) as disposition_pending,
                SUM(CASE WHEN disposition = 'skip' THEN 1 ELSE 0 END) as skipped
            FROM org_import_staging
            WHERE import_batch_uuid = ?
        ");
        $stmt->execute([$batchUuid]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stats && $stats['total'] > 0) {
            echo "  - Gesamt: " . $stats['total'] . "\n";
            echo "  - Import-Status:\n";
            echo "    - Importiert: " . ($stats['imported'] ?? 0) . "\n";
            echo "    - Pending: " . ($stats['pending'] ?? 0) . "\n";
            echo "    - Failed: " . ($stats['failed'] ?? 0) . "\n";
            echo "  - Disposition:\n";
            echo "    - Approved: " . ($stats['approved'] ?? 0) . "\n";
            echo "    - Pending: " . ($stats['disposition_pending'] ?? 0) . "\n";
            echo "    - Skipped: " . ($stats['skipped'] ?? 0) . "\n";
        } else {
            echo "  ⚠️  Keine Staging-Rows gefunden!\n";
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "❌ Fehler beim Prüfen der Staging-Rows: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    // 3. Verifiziere, ob importierte Orgs in Produktivtabellen existieren
    echo "🔍 Verifikation: Existieren importierte Orgs in Produktivtabellen?\n";
    try {
        $stmt = $db->prepare("
            SELECT 
                staging_uuid,
                row_number,
                imported_org_uuid,
                import_status,
                imported_at,
                commit_log
            FROM org_import_staging
            WHERE import_batch_uuid = ?
            AND import_status = 'imported'
            ORDER BY row_number
        ");
        $stmt->execute([$batchUuid]);
        $importedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($importedRows)) {
            echo "  ⚠️  Keine importierten Rows gefunden!\n";
        } else {
            $verified = 0;
            $missing = 0;
            $missingOrgs = [];
            
            foreach ($importedRows as $row) {
                $orgUuid = $row['imported_org_uuid'];
                
                if (!$orgUuid) {
                    $missing++;
                    $missingOrgs[] = [
                        'staging_uuid' => $row['staging_uuid'],
                        'row_number' => $row['row_number'],
                        'reason' => 'Keine imported_org_uuid gesetzt'
                    ];
                    continue;
                }
                
                // Prüfe, ob Org existiert
                $stmt2 = $db->prepare("SELECT org_uuid, name, created_at FROM org WHERE org_uuid = ?");
                $stmt2->execute([$orgUuid]);
                $org = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                if ($org) {
                    $verified++;
                    
                    // Prüfe auch Adressen
                    $stmt3 = $db->prepare("SELECT COUNT(*) as count FROM org_address WHERE org_uuid = ?");
                    $stmt3->execute([$orgUuid]);
                    $addressCount = $stmt3->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Prüfe Kommunikationskanäle
                    $stmt4 = $db->prepare("SELECT COUNT(*) as count FROM org_communication WHERE org_uuid = ?");
                    $stmt4->execute([$orgUuid]);
                    $commCount = $stmt4->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    // Prüfe VAT IDs
                    $stmt5 = $db->prepare("SELECT COUNT(*) as count FROM org_vat_registration WHERE org_uuid = ?");
                    $stmt5->execute([$orgUuid]);
                    $vatCount = $stmt5->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    echo "  ✅ Row #{$row['row_number']}: Org {$orgUuid} existiert\n";
                    echo "     - Name: " . ($org['name'] ?? 'N/A') . "\n";
                    echo "     - Erstellt: " . ($org['created_at'] ?? 'N/A') . "\n";
                    echo "     - Adressen: {$addressCount}\n";
                    echo "     - Kommunikationskanäle: {$commCount}\n";
                    echo "     - VAT IDs: {$vatCount}\n";
                    
                    // Zeige Commit-Log
                    if ($row['commit_log']) {
                        $commitLog = json_decode($row['commit_log'], true);
                        if ($commitLog && is_array($commitLog)) {
                            echo "     - Commit-Log:\n";
                            foreach ($commitLog as $logEntry) {
                                $action = $logEntry['action'] ?? 'UNKNOWN';
                                echo "       - {$action}\n";
                            }
                        }
                    }
                    echo "\n";
                    
                } else {
                    $missing++;
                    $missingOrgs[] = [
                        'staging_uuid' => $row['staging_uuid'],
                        'row_number' => $row['row_number'],
                        'org_uuid' => $orgUuid,
                        'reason' => 'Org existiert nicht in org-Tabelle'
                    ];
                    echo "  ❌ Row #{$row['row_number']}: Org {$orgUuid} existiert NICHT!\n";
                }
            }
            
            echo "\n  📈 Zusammenfassung:\n";
            echo "    - Verifiziert: {$verified}\n";
            echo "    - Fehlend: {$missing}\n";
            
            if (!empty($missingOrgs)) {
                echo "\n  ⚠️  Fehlende Orgs:\n";
                foreach ($missingOrgs as $missing) {
                    echo "    - Row #{$missing['row_number']}: {$missing['reason']}\n";
                    if (isset($missing['org_uuid'])) {
                        echo "      Org UUID: {$missing['org_uuid']}\n";
                    }
                }
            }
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "❌ Fehler bei der Verifikation: " . $e->getMessage() . "\n";
        echo "Stack Trace: " . $e->getTraceAsString() . "\n";
        exit(1);
    }
    
    // 4. Zeige fehlgeschlagene Imports
    echo "❌ Fehlgeschlagene Imports:\n";
    try {
        $stmt = $db->prepare("
            SELECT 
                staging_uuid,
                row_number,
                import_status,
                commit_log
            FROM org_import_staging
            WHERE import_batch_uuid = ?
            AND import_status = 'failed'
            ORDER BY row_number
        ");
        $stmt->execute([$batchUuid]);
        $failedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($failedRows)) {
            echo "  ✅ Keine fehlgeschlagenen Imports\n";
        } else {
            foreach ($failedRows as $row) {
                echo "  - Row #{$row['row_number']}\n";
                if ($row['commit_log']) {
                    $commitLog = json_decode($row['commit_log'], true);
                    if ($commitLog && is_array($commitLog)) {
                        foreach ($commitLog as $logEntry) {
                            $reason = $logEntry['reason'] ?? 'UNKNOWN';
                            $error = $logEntry['details']['error'] ?? 'Keine Details';
                            echo "    - Grund: {$reason}\n";
                            echo "    - Fehler: {$error}\n";
                        }
                    }
                }
            }
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "❌ Fehler beim Prüfen fehlgeschlagener Imports: " . $e->getMessage() . "\n";
    }
    
    // 5. Zeige approved Rows, die noch nicht importiert wurden
    echo "⏳ Approved Rows, die noch nicht importiert wurden:\n";
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as count
            FROM org_import_staging
            WHERE import_batch_uuid = ?
            AND disposition = 'approved'
            AND import_status != 'imported'
        ");
        $stmt->execute([$batchUuid]);
        $pendingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        if ($pendingCount > 0) {
            echo "  ⚠️  {$pendingCount} approved Rows warten noch auf Import\n";
            
            // Zeige Details
            $stmt2 = $db->prepare("
                SELECT 
                    staging_uuid,
                    row_number,
                    import_status
                FROM org_import_staging
                WHERE import_batch_uuid = ?
                AND disposition = 'approved'
                AND import_status != 'imported'
                ORDER BY row_number
                LIMIT 10
            ");
            $stmt2->execute([$batchUuid]);
            $pendingRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($pendingRows as $row) {
                echo "    - Row #{$row['row_number']} (Status: {$row['import_status']})\n";
            }
            
            if ($pendingCount > 10) {
                echo "    ... und " . ($pendingCount - 10) . " weitere\n";
            }
        } else {
            echo "  ✅ Alle approved Rows wurden importiert\n";
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "❌ Fehler: " . $e->getMessage() . "\n";
    }
    
    echo "=== Prüfung abgeschlossen ===\n";
}

