<?php
/**
 * TOM3 - Monitoring API
 */

require_once __DIR__ . '/base-api-handler.php';
initApiErrorHandling();

// Security Guard: Verhindere direkten Aufruf (nur über Router)
if (!defined('TOM3_API_ROUTER')) {
    jsonError('Direct access not allowed', 403);
}

// Unterdrücke Deprecation-Warnungen von laudis/neo4j-php-client (PHP 8.1+ Kompatibilität)
// Dies muss VOR dem Autoloading geschehen, da die Klasse beim Laden bereits den Fehler wirft
$oldErrorReporting = error_reporting();
error_reporting($oldErrorReporting & ~E_DEPRECATED);

// Verhindere HTML-Fehlerausgaben
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

// Lade .env Datei (falls vorhanden) - wichtig für Neo4j-Konfiguration
$loadEnvPath = __DIR__ . '/../../config/load-env.php';
if (file_exists($loadEnvPath)) {
    require_once $loadEnvPath;
}

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Document\ClamAvService;
use TOM\Infrastructure\Neo4j\Neo4jService;
use TOM\Service\Document\BlobService;

try {
    $db = DatabaseConnection::getInstance();
} catch (Exception $e) {
    handleApiException($e, 'Database connection');
}

$method = $_SERVER['REQUEST_METHOD'];

// Security: Nur über Router aufrufbar (Guard prüft bereits TOM3_API_ROUTER)
// $id wird vom Router übergeben (z.B. 'status', 'outbox', etc.)
if (!isset($id)) {
    // Sollte nie passieren, da Guard bereits prüft - aber sicherheitshalber
    jsonError('Not found', 404);
}

$endpoint = $id;

switch ($endpoint) {
    case 'status':
        // GET /api/monitoring/status
        try {
            jsonResponse(getSystemStatus($db));
        } catch (Exception $e) {
            handleApiException($e, 'Get system status');
        }
        break;
        
    case 'outbox':
        // GET /api/monitoring/outbox
        jsonResponse(getOutboxMetrics($db));
        break;
        
    case 'cases':
        // GET /api/monitoring/cases
        jsonResponse(getCaseStatistics($db));
        break;
        
    case 'sync':
        // GET /api/monitoring/sync
        jsonResponse(getSyncStatistics($db));
        break;
        
    case 'errors':
        // GET /api/monitoring/errors
        jsonResponse(getRecentErrors($db));
        break;
        
    case 'event-types':
        // GET /api/monitoring/event-types
        jsonResponse(getEventTypesDistribution($db));
        break;
        
    case 'duplicates':
        // GET /api/monitoring/duplicates
        jsonResponse(getDuplicateCheckResults($db));
        break;
        
    case 'activity-log':
        // GET /api/monitoring/activity-log
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        jsonResponse(getActivityLog($db, $limit));
        break;
        
    case 'clamav':
        // GET /api/monitoring/clamav
        jsonResponse(getClamAvStatus($db));
        break;
        
    case 'scheduled-tasks':
        // GET /api/monitoring/scheduled-tasks
        jsonResponse(checkScheduledTasks());
        break;
        
    case 'scan-metrics':
        // GET /api/monitoring/scan-metrics
        jsonResponse(getScanMetrics($db));
        break;
        
    default:
        jsonError('Not found', 404);
        break;
}

/**
 * System Status
 */
function getSystemStatus(PDO $db): array
{
    $status = [
        'database' => checkDatabase($db),
        'neo4j' => checkNeo4j(),
        'sync_worker' => checkSyncWorker($db),
        'clamav' => checkClamAv($db),
        'scheduled_tasks' => checkScheduledTasks()
    ];
    
    return $status;
}

function checkDatabase(PDO $db): array
{
    try {
        $stmt = $db->query("SELECT 1");
        $stmt->fetch();
        return [
            'status' => 'ok',
            'message' => 'Verbunden'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Fehler: ' . $e->getMessage()
        ];
    }
}

function checkNeo4j(): array
{
    try {
        // Versuche zuerst direkt über Neo4jService (der lädt die Config selbst)
        // Das ist zuverlässiger als manuelle Pfad-Prüfung
        try {
            $neo4j = new Neo4jService();
            if ($neo4j->testConnection()) {
                $nodeCount = $neo4j->runSingle('MATCH (n) RETURN count(n) as count');
                $message = 'Verbunden';
                if ($nodeCount !== null) {
                    $message .= ' (' . number_format($nodeCount, 0, ',', '.') . ' Nodes)';
                }
                return [
                    'status' => 'ok',
                    'message' => $message
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Verbindung fehlgeschlagen'
                ];
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Prüfe ob es ein Konfigurationsproblem ist
            if (strpos($errorMessage, 'configuration') !== false || strpos($errorMessage, 'not found') !== false) {
                // Versuche manuelle Pfad-Prüfung als Fallback
                $possiblePaths = [
                    __DIR__ . '/../../config/database.php',  // Von public/api/ -> config/
                    dirname(__DIR__, 2) . '/config/database.php',  // Alternative
                    getcwd() . '/config/database.php',  // Vom aktuellen Arbeitsverzeichnis
                    $_SERVER['DOCUMENT_ROOT'] . '/TOM3/config/database.php',  // Von Document Root
                    $_SERVER['DOCUMENT_ROOT'] . '/TOM3_T/config/database.php', // New Path
                    $_SERVER['DOCUMENT_ROOT'] . '/tom3/config/database.php'  // Case-insensitive
                ];
                
                $dbConfig = null;
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $dbConfig = require $path;
                        break;
                    }
                }
                
                if (!$dbConfig || !isset($dbConfig['neo4j']) || 
                    empty($dbConfig['neo4j']['uri']) || empty($dbConfig['neo4j']['user']) || empty($dbConfig['neo4j']['password'])) {
                    return [
                        'status' => 'unknown',
                        'message' => 'Nicht konfiguriert'
                    ];
                }
                
                // Konfiguration gefunden, aber Verbindung schlägt fehl
                return [
                    'status' => 'error',
                    'message' => 'Konfiguriert, aber Verbindung fehlgeschlagen: ' . $errorMessage
                ];
            }
            
            // Anderer Fehler
            return [
                'status' => 'error',
                'message' => 'Fehler: ' . $errorMessage
            ];
        }
    } catch (\Exception $e) {
        // Unerwarteter Fehler
        $errorMessage = $e->getMessage();
        
        // Prüfe ob es ein Konfigurationsproblem ist
        if (strpos($errorMessage, 'configuration') !== false || strpos($errorMessage, 'not found') !== false) {
            return [
                'status' => 'unknown',
                'message' => 'Nicht konfiguriert'
            ];
        }
        
        return [
            'status' => 'error',
            'message' => 'Fehler: ' . $errorMessage
        ];
    }
}

function checkSyncWorker(PDO $db): array
{
    try {
        // Prüfe, ob es unverarbeitete Events gibt (älter als 5 Minuten)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM outbox_event
            WHERE processed_at IS NULL
              AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stuckEvents = (int)$result['count'];
        
        if ($stuckEvents > 10) {
            return [
                'status' => 'warning',
                'message' => "$stuckEvents Events hängen"
            ];
        } elseif ($stuckEvents > 0) {
            return [
                'status' => 'warning',
                'message' => "$stuckEvents Event(s) hängen"
            ];
        } else {
            return [
                'status' => 'ok',
                'message' => 'Läuft'
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Fehler: ' . $e->getMessage()
        ];
    }
}

function checkClamAv(PDO $db): array
{
    try {
        $clamAvService = new ClamAvService();
        
        if (!$clamAvService->isAvailable()) {
            return [
                'status' => 'error',
                'message' => 'ClamAV nicht verfügbar',
                'available' => false
            ];
        }
        
        // Prüfe Update-Status der Virendefinitionen
        $updateStatus = getClamAvUpdateStatus();
        
        // Prüfe Scan-Worker Status
        $workerStatus = checkScanWorker($db);
        
        // Prüfe infizierte Dateien
        $infectedCount = getInfectedDocumentsCount($db);
        
        $overallStatus = 'ok';
        $message = 'Läuft';
        
        if ($updateStatus['age_hours'] > 48) {
            $overallStatus = 'warning';
            $message = 'Definitionen veraltet (' . round($updateStatus['age_hours'], 1) . 'h)';
        }
        
        if ($infectedCount > 0) {
            if ($overallStatus === 'ok') {
                $overallStatus = 'warning';
            }
            $message .= ($message !== 'Läuft' ? ', ' : '') . "$infectedCount infizierte Datei(en)";
        }
        
        if ($workerStatus['status'] !== 'ok') {
            $overallStatus = $workerStatus['status'];
            $message = $workerStatus['message'];
        }
        
        return [
            'status' => $overallStatus,
            'message' => $message,
            'available' => true,
            'version' => $clamAvService->getVersion(),
            'update_status' => $updateStatus,
            'worker_status' => $workerStatus,
            'infected_count' => $infectedCount
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Fehler: ' . $e->getMessage(),
            'available' => false
        ];
    }
}

/**
 * ClamAV Status (detailliert)
 */
function getClamAvStatus(PDO $db): array
{
    try {
        $clamAvService = new ClamAvService();
        
        if (!$clamAvService->isAvailable()) {
            return [
                'available' => false,
                'error' => 'ClamAV nicht verfügbar'
            ];
        }
        
        // Update-Status
        $updateStatus = getClamAvUpdateStatus();
        
        // Worker-Status
        $workerStatus = checkScanWorker($db);
        
        // Scan-Statistiken
        $scanStats = getScanStatistics($db);
        
        // Infizierte Dateien
        $infectedFiles = getInfectedDocuments($db);
        
        return [
            'available' => true,
            'version' => $clamAvService->getVersion(),
            'update_status' => $updateStatus,
            'worker_status' => $workerStatus,
            'scan_statistics' => $scanStats,
            'infected_files' => $infectedFiles
        ];
    } catch (Exception $e) {
        return [
            'available' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Prüft Update-Status der ClamAV Virendefinitionen
 */
function getClamAvUpdateStatus(): array
{
    try {
        // Prüfe Docker-Container
        $containerName = getenv('CLAMAV_CONTAINER') ?: 'tom3-clamav';
        
        // ClamAV verwendet hybrides System: main.cvd (Basis) + daily.cld (tägliche Updates)
        // Prüfe beide und verwende das neueste Datum
        $filesToCheck = ['/var/lib/clamav/main.cvd', '/var/lib/clamav/daily.cld'];
        $latestTimestamp = 0;
        $latestFile = null;
        
        foreach ($filesToCheck as $file) {
            $command = sprintf(
                'docker exec %s stat -c %%Y %s 2>&1',
                escapeshellarg($containerName),
                escapeshellarg($file)
            );
            $output = []; // Reset output array für jede Iteration
            exec($command, $output, $returnCode);
            
            // Prüfe ob Output gültig ist (nicht Fehlermeldung)
            if ($returnCode === 0 && !empty($output)) {
                $outputLine = trim($output[0]);
                // Prüfe ob es eine Zahl ist (nicht eine Fehlermeldung)
                if (is_numeric($outputLine)) {
                    $timestamp = (int)$outputLine;
                    if ($timestamp > 0 && $timestamp > $latestTimestamp) {
                        $latestTimestamp = $timestamp;
                        $latestFile = $file;
                    }
                }
            }
        }
        
        if ($latestTimestamp > 0) {
            $ageHours = (time() - $latestTimestamp) / 3600;
            
            return [
                'status' => $ageHours > 48 ? 'stale' : ($ageHours > 24 ? 'warning' : 'current'),
                'last_update' => date('Y-m-d H:i:s', $latestTimestamp),
                'age_hours' => round($ageHours, 1),
                'source_file' => basename($latestFile)
            ];
        }
        
        // Fallback: Versuche ls -lh und parse Datum
        $command = sprintf(
            'docker exec %s ls -lh /var/lib/clamav/main.cvd 2>&1',
            escapeshellarg($containerName)
        );
        exec($command, $output2, $returnCode2);
        
        if ($returnCode2 === 0 && !empty($output2)) {
            $outputStr = implode("\n", $output2);
            // Parse ls -lh Output: -rw-r--r-- 1 clamav clamav 50M Dec 28 07:26 main.cvd
            if (preg_match('/(\w{3})\s+(\d{1,2})\s+(\d{1,2}):(\d{2})/', $outputStr, $matches)) {
                $month = $matches[1];
                $day = (int)$matches[2];
                $hour = (int)$matches[3];
                $minute = (int)$matches[4];
                
                $currentYear = (int)date('Y');
                $monthMap = [
                    'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
                    'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
                    'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12
                ];
                $monthNum = $monthMap[$month] ?? 1;
                
                $lastUpdate = mktime($hour, $minute, 0, $monthNum, $day, $currentYear);
                $ageHours = (time() - $lastUpdate) / 3600;
                
                return [
                    'status' => $ageHours > 48 ? 'stale' : ($ageHours > 24 ? 'warning' : 'current'),
                    'last_update' => date('Y-m-d H:i:s', $lastUpdate),
                    'age_hours' => round($ageHours, 1)
                ];
            }
        }
        
        // Wenn alles fehlschlägt: Prüfe ob Datei existiert
        $command = sprintf(
            'docker exec %s test -f /var/lib/clamav/main.cvd && echo "exists" 2>&1',
            escapeshellarg($containerName)
        );
        exec($command, $output3, $returnCode3);
        
        if ($returnCode3 === 0 && in_array('exists', $output3)) {
            // Datei existiert, aber Datum nicht ermittelbar
            return [
                'status' => 'unknown',
                'last_update' => null,
                'age_hours' => null,
                'message' => 'Definitionen vorhanden, Alter unbekannt'
            ];
        }
        
        return [
            'status' => 'error',
            'last_update' => null,
            'age_hours' => null,
            'error' => 'Definitionen-Datei nicht gefunden'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'last_update' => null,
            'age_hours' => null,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Prüft Scan-Worker Status
 */
function checkScanWorker(PDO $db): array
{
    try {
        // Prüfe, ob es ausstehende Scan-Jobs gibt (älter als 10 Minuten)
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM outbox_event
            WHERE aggregate_type = 'blob'
              AND event_type = 'BlobScanRequested'
              AND processed_at IS NULL
              AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stuckJobs = (int)$result['count'];
        
        if ($stuckJobs > 10) {
            return [
                'status' => 'error',
                'message' => "$stuckJobs Scan-Jobs hängen",
                'stuck_jobs' => $stuckJobs
            ];
        } elseif ($stuckJobs > 0) {
            return [
                'status' => 'warning',
                'message' => "$stuckJobs Scan-Job(s) hängen",
                'stuck_jobs' => $stuckJobs
            ];
        } else {
            // Prüfe, ob Worker in letzter Zeit aktiv war (verarbeitete Jobs in letzten 30 Minuten)
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM outbox_event
                WHERE aggregate_type = 'blob'
                  AND event_type = 'BlobScanRequested'
                  AND processed_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            $stmt->execute();
            $recentProcessed = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Prüfe, ob es neue Scan-Requests gibt, die noch nicht verarbeitet wurden
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM outbox_event
                WHERE aggregate_type = 'blob'
                  AND event_type = 'BlobScanRequested'
                  AND processed_at IS NULL
            ");
            $stmt->execute();
            $pendingJobs = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Prüfe, wann der letzte Scan verarbeitet wurde
            $stmt = $db->prepare("
                SELECT MAX(processed_at) as last_processed
                FROM outbox_event
                WHERE aggregate_type = 'blob'
                  AND event_type = 'BlobScanRequested'
                  AND processed_at IS NOT NULL
            ");
            $stmt->execute();
            $lastProcessed = $stmt->fetch(PDO::FETCH_ASSOC)['last_processed'];
            
            // Prüfe, ob der letzte Scan zu lange her ist (>2 Stunden)
            if ($lastProcessed === null) {
                // Kein Scan wurde jemals verarbeitet - Worker läuft möglicherweise nicht
                return [
                    'status' => 'warning',
                    'message' => 'Keine Scans verarbeitet (Worker möglicherweise nicht aktiv)',
                    'recent_processed' => 0,
                    'pending_jobs' => $pendingJobs,
                    'stuck_jobs' => 0,
                    'last_processed' => null
                ];
            } else {
                $stmt = $db->prepare("
                    SELECT TIMESTAMPDIFF(MINUTE, MAX(processed_at), NOW()) as minutes_ago
                    FROM outbox_event
                    WHERE aggregate_type = 'blob'
                      AND event_type = 'BlobScanRequested'
                      AND processed_at IS NOT NULL
                ");
                $stmt->execute();
                $minutesAgo = (int)$stmt->fetch(PDO::FETCH_ASSOC)['minutes_ago'];
                
                if ($minutesAgo > 120) {
                    // Keine Aktivität seit >2 Stunden - Worker könnte ausgefallen sein
                    return [
                        'status' => 'warning',
                        'message' => "Keine Aktivität seit " . round($minutesAgo / 60, 1) . " Stunden",
                        'recent_processed' => $recentProcessed,
                        'pending_jobs' => $pendingJobs,
                        'stuck_jobs' => 0,
                        'last_processed' => $lastProcessed,
                        'minutes_since_last' => $minutesAgo
                    ];
                } elseif ($recentProcessed === 0 && $pendingJobs > 0) {
                    // Es gibt ausstehende Jobs, aber keine wurden in letzter Zeit verarbeitet
                    return [
                        'status' => 'warning',
                        'message' => "$pendingJobs Job(s) ausstehend, keine Verarbeitung in letzter Zeit",
                        'recent_processed' => 0,
                        'pending_jobs' => $pendingJobs,
                        'stuck_jobs' => 0,
                        'last_processed' => $lastProcessed
                    ];
                } else {
                    // Prüfe auf pending Blobs mit verarbeiteten Jobs (Problem: Scan erfolgreich, aber Status nicht aktualisiert)
                    $pendingBlobs = checkPendingBlobsWithProcessedJobs($db);
                    
                    if ($pendingBlobs['count'] > 0) {
                        // Problem gefunden - versuche automatische Behebung
                        $fixed = fixPendingBlobs($db, $pendingBlobs['count']);
                        
                        // Speichere Metrik
                        recordMonitoringMetric($db, 'scan_pending_fix', 'pending_blobs_with_processed_jobs', $pendingBlobs['count'], [
                            'fixed_count' => $fixed,
                            'blob_uuids' => $pendingBlobs['blob_uuids']
                        ]);
                        
                        if ($fixed > 0) {
                            return [
                                'status' => 'warning',
                                'message' => "{$pendingBlobs['count']} Blob(s) mit pending Status gefunden, {$fixed} behoben",
                                'recent_processed' => $recentProcessed,
                                'pending_jobs' => $pendingJobs,
                                'stuck_jobs' => 0,
                                'last_processed' => $lastProcessed,
                                'pending_blobs' => $pendingBlobs['count'],
                                'fixed_blobs' => $fixed
                            ];
                        } else {
                            return [
                                'status' => 'warning',
                                'message' => "{$pendingBlobs['count']} Blob(s) mit pending Status gefunden, Behebung fehlgeschlagen",
                                'recent_processed' => $recentProcessed,
                                'pending_jobs' => $pendingJobs,
                                'stuck_jobs' => 0,
                                'last_processed' => $lastProcessed,
                                'pending_blobs' => $pendingBlobs['count'],
                                'fixed_blobs' => 0
                            ];
                        }
                    }
                    
                    // Alles OK
                    return [
                        'status' => 'ok',
                        'message' => 'Läuft',
                        'recent_processed' => $recentProcessed,
                        'pending_jobs' => $pendingJobs,
                        'stuck_jobs' => 0,
                        'last_processed' => $lastProcessed
                    ];
                }
            }
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Fehler: ' . $e->getMessage(),
            'stuck_jobs' => 0
        ];
    }
}

/**
 * Prüft auf Blobs mit pending Status, die bereits verarbeitete Jobs haben
 */
function checkPendingBlobsWithProcessedJobs(PDO $db): array
{
    $stmt = $db->prepare("
        SELECT DISTINCT b.blob_uuid
        FROM blobs b
        INNER JOIN outbox_event o ON o.aggregate_uuid = b.blob_uuid
        WHERE b.scan_status = 'pending'
          AND o.aggregate_type = 'blob'
          AND o.event_type = 'BlobScanRequested'
          AND o.processed_at IS NOT NULL
          AND o.processed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ORDER BY b.created_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $blobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'count' => count($blobs),
        'blob_uuids' => array_column($blobs, 'blob_uuid')
    ];
}

/**
 * Behebt pending Blobs automatisch
 */
function fixPendingBlobs(PDO $db, int $maxFixes = 10): int
{
    try {
        $blobService = new BlobService($db);
        $clamAv = new ClamAvService();
        
        // Prüfe, ob ClamAV verfügbar ist
        if (!$clamAv->isAvailable()) {
            return 0;
        }
        
        $pendingBlobs = checkPendingBlobsWithProcessedJobs($db);
        if ($pendingBlobs['count'] === 0) {
            return 0;
        }
        
        $fixed = 0;
        $fixedCount = min($pendingBlobs['count'], $maxFixes);
        
        foreach (array_slice($pendingBlobs['blob_uuids'], 0, $fixedCount) as $blobUuid) {
            try {
                // Prüfe, ob Blob noch existiert und Status noch pending ist
                $blob = $blobService->getBlob($blobUuid);
                if (!$blob || $blob['scan_status'] !== 'pending') {
                    continue;
                }
                
                // Prüfe, ob Datei existiert
                $filePath = $blobService->getBlobFilePath($blobUuid);
                if (!$filePath || !file_exists($filePath)) {
                    // Markiere als Error
                    $stmt = $db->prepare("
                        UPDATE blobs
                        SET scan_status = 'error',
                            scan_engine = 'clamav',
                            scan_at = NOW(),
                            scan_result = :result
                        WHERE blob_uuid = :blob_uuid
                    ");
                    $stmt->execute([
                        'blob_uuid' => $blobUuid,
                        'result' => json_encode(['error' => 'Datei nicht gefunden', 'fixed_by' => 'monitoring'])
                    ]);
                    $fixed++;
                    continue;
                }
                
                // Führe Scan durch
                $scanResult = $clamAv->scan($filePath);
                
                // Update Blob-Status
                $stmt = $db->prepare("
                    UPDATE blobs
                    SET scan_status = :status,
                        scan_engine = 'clamav',
                        scan_at = NOW(),
                        scan_result = :result
                    WHERE blob_uuid = :blob_uuid
                ");
                $stmt->execute([
                    'blob_uuid' => $blobUuid,
                    'status' => $scanResult['status'],
                    'result' => json_encode($scanResult)
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $fixed++;
                }
                
            } catch (\Exception $e) {
                // Fehler beim Fix - markiere als Error
                try {
                    $stmt = $db->prepare("
                        UPDATE blobs
                        SET scan_status = 'error',
                            scan_engine = 'clamav',
                            scan_at = NOW(),
                            scan_result = :result
                        WHERE blob_uuid = :blob_uuid
                    ");
                    $stmt->execute([
                        'blob_uuid' => $blobUuid,
                        'result' => json_encode(['error' => $e->getMessage(), 'fixed_by' => 'monitoring'])
                    ]);
                    $fixed++;
                } catch (\Exception $updateError) {
                    // Ignoriere Update-Fehler
                }
            }
        }
        
        return $fixed;
        
    } catch (\Exception $e) {
        error_log("Fehler beim Fix von pending Blobs: " . $e->getMessage());
        return 0;
    }
}

/**
 * Speichert Monitoring-Metrik
 */
function recordMonitoringMetric(PDO $db, string $metricType, string $metricName, int $metricValue, array $metricData = []): void
{
    try {
        // Prüfe, ob Metrik bereits existiert
        $stmt = $db->prepare("
            SELECT metric_uuid, fixed_count
            FROM monitoring_metrics
            WHERE metric_name = :metric_name
            ORDER BY occurred_at DESC
            LIMIT 1
        ");
        $stmt->execute(['metric_name' => $metricName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Aktualisiere bestehende Metrik
            $fixedCount = (int)($existing['fixed_count'] ?? 0);
            if (isset($metricData['fixed_count'])) {
                $fixedCount += (int)$metricData['fixed_count'];
            }
            
            $stmt = $db->prepare("
                UPDATE monitoring_metrics
                SET metric_value = :metric_value,
                    metric_data = :metric_data,
                    occurred_at = NOW(),
                    fixed_at = CASE WHEN :fixed_count > 0 THEN NOW() ELSE fixed_at END,
                    fixed_count = :fixed_count,
                    updated_at = NOW()
                WHERE metric_uuid = :metric_uuid
            ");
            $stmt->execute([
                'metric_uuid' => $existing['metric_uuid'],
                'metric_value' => $metricValue,
                'metric_data' => json_encode($metricData),
                'fixed_count' => $fixedCount
            ]);
        } else {
            // Erstelle neue Metrik
            $metricUuid = bin2hex(random_bytes(16));
            $metricUuid = substr($metricUuid, 0, 8) . '-' . substr($metricUuid, 8, 4) . '-' . substr($metricUuid, 12, 4) . '-' . substr($metricUuid, 16, 4) . '-' . substr($metricUuid, 20, 12);
            
            $fixedCount = 0;
            if (isset($metricData['fixed_count'])) {
                $fixedCount = (int)$metricData['fixed_count'];
            }
            
            $stmt = $db->prepare("
                INSERT INTO monitoring_metrics (
                    metric_uuid, metric_type, metric_name, metric_value, metric_data,
                    fixed_at, fixed_count
                ) VALUES (
                    :metric_uuid, :metric_type, :metric_name, :metric_value, :metric_data,
                    CASE WHEN :fixed_count > 0 THEN NOW() ELSE NULL END, :fixed_count
                )
            ");
            $stmt->execute([
                'metric_uuid' => $metricUuid,
                'metric_type' => $metricType,
                'metric_name' => $metricName,
                'metric_value' => $metricValue,
                'metric_data' => json_encode($metricData),
                'fixed_count' => $fixedCount
            ]);
        }
    } catch (\Exception $e) {
        // Fehler beim Speichern der Metrik nicht kritisch - loggen und ignorieren
        error_log("Fehler beim Speichern der Monitoring-Metrik: " . $e->getMessage());
    }
}

/**
 * Scan-Statistiken
 */
function getScanStatistics(PDO $db): array
{
    try {
        // Status-Verteilung
        $stmt = $db->query("
            SELECT scan_status, COUNT(*) as count
            FROM blobs
            GROUP BY scan_status
        ");
        
        $statusDistribution = [];
        $total = 0;
        $pending = 0;
        $clean = 0;
        $infected = 0;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = $row['scan_status'];
            $count = (int)$row['count'];
            $statusDistribution[$status] = $count;
            $total += $count;
            
            if ($status === 'pending') {
                $pending = $count;
            } elseif ($status === 'clean') {
                $clean = $count;
            } elseif ($status === 'infected') {
                $infected = $count;
            }
        }
        
        // Scans in letzten 24h
        $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM blobs
            WHERE scan_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $scans24h = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Ausstehende Scan-Jobs
        $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM outbox_event
            WHERE aggregate_type = 'blob'
              AND event_type = 'BlobScanRequested'
              AND processed_at IS NULL
        ");
        $pendingJobs = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        return [
            'total' => $total,
            'pending' => $pending,
            'clean' => $clean,
            'infected' => $infected,
            'scans_24h' => $scans24h,
            'pending_jobs' => $pendingJobs,
            'status_distribution' => $statusDistribution
        ];
    } catch (Exception $e) {
        return [
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Anzahl infizierter Dokumente
 */
function getInfectedDocumentsCount(PDO $db): int
{
    try {
        $stmt = $db->query("
            SELECT COUNT(DISTINCT d.document_uuid) as count
            FROM documents d
            INNER JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
            WHERE b.scan_status = 'infected'
              AND d.status != 'deleted'
        ");
        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Liste infizierter Dokumente
 */
function getInfectedDocuments(PDO $db, int $limit = 20): array
{
    try {
        $stmt = $db->prepare("
            SELECT 
                d.document_uuid,
                d.title,
                d.created_at,
                d.created_by_user_id,
                b.scan_at,
                b.scan_result,
                b.original_filename
            FROM documents d
            INNER JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
            WHERE b.scan_status = 'infected'
              AND d.status != 'deleted'
            ORDER BY b.scan_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $documents = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $scanResult = json_decode($row['scan_result'], true);
            $documents[] = [
                'document_uuid' => $row['document_uuid'],
                'title' => $row['title'],
                'original_filename' => $row['original_filename'],
                'created_at' => $row['created_at'],
                'created_by_user_id' => $row['created_by_user_id'],
                'scan_at' => $row['scan_at'],
                'threats' => $scanResult['threats'] ?? [],
                'message' => $scanResult['message'] ?? 'Threat detected'
            ];
        }
        
        return $documents;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Outbox Metrics
 */
function getOutboxMetrics(PDO $db): array
{
    // Pending events
    $stmt = $db->query("SELECT COUNT(*) as count FROM outbox_event WHERE processed_at IS NULL");
    $pending = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Processed in last 24h
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM outbox_event
        WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $processed24h = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Errors in last 24h (Events ohne processed_at, aber älter als 1h)
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM outbox_event
        WHERE processed_at IS NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $errors24h = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Average lag (Zeit zwischen created_at und processed_at)
    $stmt = $db->query("
        SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) as avg_lag
        FROM outbox_event
        WHERE processed_at IS NOT NULL
          AND processed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $avgLag = (float)($stmt->fetch(PDO::FETCH_ASSOC)['avg_lag'] ?? 0);
    
    // Hourly data (last 24 hours)
    $stmt = $db->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
            SUM(CASE WHEN processed_at IS NOT NULL THEN 1 ELSE 0 END) as processed,
            SUM(CASE WHEN processed_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as errors
        FROM outbox_event
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY hour
        ORDER BY hour
    ");
    $hourlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'pending' => $pending,
        'processed_24h' => $processed24h,
        'errors_24h' => $errors24h,
        'avg_lag_seconds' => $avgLag,
        'hourly_data' => $hourlyData
    ];
}

/**
 * Case Statistics
 */
function getCaseStatistics(PDO $db): array
{
    // Total
    $stmt = $db->query("SELECT COUNT(*) as count FROM case_item");
    $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // By status
    $stmt = $db->query("
        SELECT status, COUNT(*) as count
        FROM case_item
        GROUP BY status
    ");
    $statusDistribution = [];
    $active = 0;
    $waiting = 0;
    $blocked = 0;
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $count = (int)$row['count'];
        $statusDistribution[$status] = $count;
        
        if ($status === 'in_bearbeitung') {
            $active = $count;
        } elseif (in_array($status, ['wartend_intern', 'wartend_extern'])) {
            $waiting += $count;
        } elseif ($status === 'blockiert') {
            $blocked = $count;
        }
    }
    
    return [
        'total' => $total,
        'active' => $active,
        'waiting' => $waiting,
        'blocked' => $blocked,
        'status_distribution' => $statusDistribution
    ];
}

/**
 * Sync Statistics
 */
function getSyncStatistics(PDO $db): array
{
    // Total synced events
    $stmt = $db->query("SELECT COUNT(*) as count FROM outbox_event WHERE processed_at IS NOT NULL");
    $totalSynced = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Events per minute (last hour)
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM outbox_event
        WHERE processed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $eventsLastHour = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $eventsPerMinute = $eventsLastHour / 60.0;
    
    // Neo4j counts - ECHTE Daten aus Neo4j
    $orgsCount = 0;
    $personsCount = 0;
    
    try {
        $neo4j = new Neo4jService();
        
        // Zähle Organisationen in Neo4j (Label ist "Org", nicht "Organization")
        $orgsCount = (int)($neo4j->runSingle('MATCH (o:Org) RETURN count(o) as count') ?? 0);
        
        // Zähle Personen in Neo4j
        $personsCount = (int)($neo4j->runSingle('MATCH (p:Person) RETURN count(p) as count') ?? 0);
    } catch (\Exception $e) {
        // Fallback: Wenn Neo4j nicht verfügbar ist, verwende SQL-Annäherung
        $stmt = $db->query("SELECT COUNT(*) as count FROM org");
        $orgsCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $db->query("SELECT COUNT(*) as count FROM person");
        $personsCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    return [
        'total_synced' => $totalSynced,
        'events_per_minute' => $eventsPerMinute,
        'orgs_count' => $orgsCount,
        'persons_count' => $personsCount
    ];
}

/**
 * Recent Errors
 */
function getRecentErrors(PDO $db): array
{
    // Events ohne processed_at, älter als 1 Stunde
    $stmt = $db->query("
        SELECT 
            event_uuid,
            aggregate_type,
            event_type,
            payload,
            created_at
        FROM outbox_event
        WHERE processed_at IS NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY created_at DESC
        LIMIT 50
    ");
    
    $errors = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $errors[] = [
            'type' => $row['event_type'],
            'aggregate_type' => $row['aggregate_type'],
            'message' => "Event nicht verarbeitet: {$row['event_type']}",
            'details' => $row['payload'],
            'created_at' => $row['created_at']
        ];
    }
    
    return $errors;
}

/**
 * Event Types Distribution
 */
function getEventTypesDistribution(PDO $db): array
{
    $stmt = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM outbox_event
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY event_type
        ORDER BY count DESC
    ");
    
    $distribution = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $distribution[$row['event_type']] = (int)$row['count'];
    }
    
    return $distribution;
}

/**
 * Duplikaten-Prüfung Ergebnisse
 */
function getDuplicateCheckResults(PDO $db): array
{
    try {
        // Prüfe ob Tabelle existiert
        $db->query("SELECT 1 FROM duplicate_check_results LIMIT 1");
    } catch (Exception $e) {
        return [
            'checks' => [],
            'current_duplicates' => ['org_duplicates' => [], 'person_duplicates' => []],
            'latest_check' => null,
            'error' => 'Tabelle duplicate_check_results existiert noch nicht. Führen Sie Migration 033 aus.'
        ];
    }
    
    try {
        // Hole die letzten 30 Prüfungen
        $stmt = $db->query("
            SELECT 
                check_id,
                check_date,
                org_duplicates,
                person_duplicates,
                total_pairs,
                results_json
            FROM duplicate_check_results
            ORDER BY check_date DESC
            LIMIT 30
        ");
        $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hole die aktuellsten Duplikate
        $latestCheck = $checks[0] ?? null;
        $currentDuplicates = [
            'org_duplicates' => [],
            'person_duplicates' => []
        ];
        
        if ($latestCheck && $latestCheck['results_json']) {
            $decoded = json_decode($latestCheck['results_json'], true);
            if ($decoded) {
                $currentDuplicates = [
                    'org_duplicates' => $decoded['org_duplicates'] ?? [],
                    'person_duplicates' => $decoded['person_duplicates'] ?? []
                ];
            }
        }
        
        return [
            'checks' => $checks,
            'current_duplicates' => $currentDuplicates,
            'latest_check' => $latestCheck ? [
                'date' => $latestCheck['check_date'],
                'org_count' => (int)$latestCheck['org_duplicates'],
                'person_count' => (int)$latestCheck['person_duplicates'],
                'total_pairs' => (int)$latestCheck['total_pairs']
            ] : null
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Activity Log
 */
function getActivityLog(PDO $db, int $limit = 100): array
{
    try {
        // Prüfe ob Tabelle existiert
        $db->query("SELECT 1 FROM activity_log LIMIT 1");
    } catch (Exception $e) {
        return [
            'activities' => [],
            'total' => 0,
            'error' => 'Tabelle activity_log existiert noch nicht. Führen Sie Migration 035 aus.'
        ];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                a.*,
                u.name as user_name
            FROM activity_log a
            LEFT JOIN users u ON a.user_id = u.user_id
            ORDER BY a.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON-Details
        foreach ($activities as &$activity) {
            if ($activity['details']) {
                $activity['details'] = json_decode($activity['details'], true);
            }
        }
        
        // Zähle Gesamtanzahl
        $countStmt = $db->query("SELECT COUNT(*) as total FROM activity_log");
        $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        return [
            'activities' => $activities,
            'total' => $total
        ];
    } catch (Exception $e) {
        return [
            'activities' => [],
            'total' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Prüft Windows Scheduled Tasks Status
 */
function checkScheduledTasks(): array
{
    try {
        // Liste der zu überwachenden Tasks (basierend auf WINDOWS-SCHEDULER-JOBS.md)
        $monitoredTasks = [
            'TOM3-Neo4j-Sync-Worker' => ['required' => true, 'description' => 'Neo4j Sync Worker'],
            'TOM3-ClamAV-Scan-Worker' => ['required' => false, 'description' => 'ClamAV Scan Worker'],
            'TOM3-ExtractTextWorker' => ['required' => true, 'description' => 'Extract Text Worker'],
            'TOM3-FixPendingScans' => ['required' => false, 'description' => 'Fix Pending Scans Worker'],
            'TOM3-DuplicateCheck' => ['required' => false, 'description' => 'Duplicate Check'],
            'TOM3-ActivityLog-Maintenance' => ['required' => false, 'description' => 'Activity Log Maintenance'],
            'MySQL-Auto-Recovery' => ['required' => false, 'description' => 'MySQL Auto Recovery'],
            'MySQL-Daily-Backup' => ['required' => false, 'description' => 'MySQL Daily Backup']
        ];
        
        // PowerShell-Befehl zum Abrufen der Task-Informationen
        // Verwendet zwei Methoden:
        // 1. Get-ScheduledTask (findet Tasks des aktuellen Benutzers)
        // 2. schtasks (findet auch SYSTEM-Tasks)
        // Konvertiere DateTime-Objekte zu ISO-8601 Strings für bessere Kompatibilität
        $psScript = '
$ErrorActionPreference = "Continue"
$tasks = @()
$foundTaskNames = @()

# Liste der zu suchenden Tasks
$taskNamesToFind = @("TOM3-Neo4j-Sync-Worker", "TOM3-ClamAV-Scan-Worker", "TOM3-ExtractTextWorker", "TOM3-FixPendingScans", "TOM3-DuplicateCheck", "TOM3-ActivityLog-Maintenance", "MySQL-Auto-Recovery", "MySQL-Daily-Backup")

# Methode 1: Get-ScheduledTask (findet Tasks des aktuellen Benutzers)
try {
    $allTasks = Get-ScheduledTask -ErrorAction SilentlyContinue
    if ($null -ne $allTasks) {
        foreach ($task in $allTasks) {
            if ($null -eq $task) { continue }
            $taskName = $task.TaskName
            if ([string]::IsNullOrEmpty($taskName)) { continue }
            
            # Prüfe ob Task-Name in unserer Liste ist
            if ($taskNamesToFind -contains $taskName) {
                try {
                    $info = Get-ScheduledTaskInfo -TaskName $taskName -ErrorAction SilentlyContinue
                    if ($info) {
                        $lastRun = $null
                        if ($info.LastRunTime -and $info.LastRunTime -ne [DateTime]::MinValue) { 
                            $lastRun = $info.LastRunTime.ToString("yyyy-MM-dd HH:mm:ss")
                        }
                        $nextRun = $null
                        if ($info.NextRunTime -and $info.NextRunTime -ne [DateTime]::MinValue) { 
                            $nextRun = $info.NextRunTime.ToString("yyyy-MM-dd HH:mm:ss")
                        }
                        $tasks += [PSCustomObject]@{
                            TaskName = $taskName
                            State = $task.State
                            LastRunTime = $lastRun
                            NextRunTime = $nextRun
                            LastTaskResult = $info.LastTaskResult
                        }
                        $foundTaskNames += $taskName
                    }
                } catch {
                    # Fehler beim Abrufen der Task-Info, überspringen
                }
            }
        }
    }
} catch {
    # Get-ScheduledTask fehlgeschlagen, weiter mit schtasks
}

# Methode 2: schtasks (findet auch SYSTEM-Tasks)
foreach ($taskName in $taskNamesToFind) {
    if ($foundTaskNames -contains $taskName) { continue }  # Bereits gefunden
    
    try {
        $result = schtasks /query /tn $taskName /fo LIST /v 2>&1
        if ($LASTEXITCODE -eq 0 -and $result -notmatch "FEHLER|ERROR|nicht gefunden|not found") {
            # Parse Status
            $taskState = "Unknown"
            $statusLine = $result | Select-String "Status:"
            if ($statusLine) {
                $taskState = ($statusLine -split "Status:")[1].Trim()
            }
            
            # Parse Last Run Time
            $lastRun = $null
            $lastRunLine = $result | Select-String "Letzte Ausführungszeit:|Last Run Time:"
            if ($lastRunLine) {
                $dateStr = ($lastRunLine -split ":")[1..-1] -join ":" | ForEach-Object { $_.Trim() }
                if ($dateStr -and $dateStr -ne "N/A" -and $dateStr -ne "") {
                    try {
                        $parsedDate = [DateTime]::Parse($dateStr)
                        $lastRun = $parsedDate.ToString("yyyy-MM-dd HH:mm:ss")
                    } catch {
                        # Parsing fehlgeschlagen, ignoriere
                    }
                }
            }
            
            # Parse Next Run Time
            $nextRun = $null
            $nextRunLine = $result | Select-String "Nächste Ausführungszeit:|Next Run Time:"
            if ($nextRunLine) {
                $dateStr = ($nextRunLine -split ":")[1..-1] -join ":" | ForEach-Object { $_.Trim() }
                if ($dateStr -and $dateStr -ne "N/A" -and $dateStr -ne "") {
                    try {
                        $parsedDate = [DateTime]::Parse($dateStr)
                        $nextRun = $parsedDate.ToString("yyyy-MM-dd HH:mm:ss")
                    } catch {
                        # Parsing fehlgeschlagen, ignoriere
                    }
                }
            }
            
            # Parse Last Result
            $lastResult = $null
            $resultLine = $result | Select-String "Letztes Ergebnis:|Last Result:"
            if ($resultLine) {
                $lastResultStr = ($resultLine -split ":")[1].Trim()
                if ($lastResultStr -match "0x([0-9a-fA-F]+)") {
                    $lastResult = [int]("0x" + $matches[1])
                } elseif ($lastResultStr -match "(\d+)") {
                    $lastResult = [int]$matches[1]
                } elseif ($lastResultStr -match "erfolgreich|success") {
                    $lastResult = 0
                }
            }
            
            $tasks += [PSCustomObject]@{
                TaskName = $taskName
                State = $taskState
                LastRunTime = $lastRun
                NextRunTime = $nextRun
                LastTaskResult = $lastResult
            }
            $foundTaskNames += $taskName
        }
    } catch {
        # schtasks fehlgeschlagen für diesen Task, überspringen
    }
}

# Ausgabe als JSON-Array (auch wenn nur ein Task gefunden wurde)
if ($tasks.Count -eq 0) {
    Write-Output "[]"
} elseif ($tasks.Count -eq 1) {
    # Einzelnes Objekt als Array ausgeben
    Write-Output "[$($tasks[0] | ConvertTo-Json -Compress)]"
} else {
    # Mehrere Objekte als Array ausgeben
    Write-Output ($tasks | ConvertTo-Json -Compress)
}
';
        
        // Versuche zuerst mit -Command (direkter Befehl)
        // Escaping für PowerShell-Command
        $psScriptEscaped = str_replace('"', '`"', $psScript);
        $psScriptEscaped = str_replace('$', '`$', $psScriptEscaped);
        
        $command = sprintf(
            'powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "%s"',
            $psScriptEscaped
        );
        
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        // Falls das fehlschlägt, versuche es mit temporärer Datei
        if ($returnCode !== 0 || empty($output) || (count($output) === 1 && strpos($output[0], '{') === false && strpos($output[0], '[') === false)) {
            $tempScript = tempnam(sys_get_temp_dir(), 'tom3_tasks_');
            // Verwende .ps1 Extension für bessere Kompatibilität
            $tempScript = $tempScript . '.ps1';
            file_put_contents($tempScript, $psScript);
            
            $command = sprintf(
                'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%s"',
                $tempScript
            );
            
            $output = [];
            $returnCode = 0;
            exec($command . ' 2>&1', $output, $returnCode);
            
            // Lösche temporäre Datei
            @unlink($tempScript);
        }
        
        $tasks = [];
        $overallStatus = 'ok';
        $totalTasks = 0;
        $runningTasks = 0;
        $stoppedTasks = 0;
        $missingRequiredTasks = [];
        
        $jsonOutput = '[]';
        $decodedTasks = [];
        
        // Prüfe auch wenn Return Code nicht 0 ist - möglicherweise gibt es trotzdem JSON-Ausgabe
        if (!empty($output)) {
            // Entferne mögliche Fehlermeldungen und leere Zeilen
            $cleanOutput = array_filter($output, function($line) {
                $line = trim($line);
                return !empty($line) && 
                       !preg_match('/^(Warning|Error|Exception)/i', $line) &&
                       !preg_match('/^Cannot find/i', $line) &&
                       !preg_match('/^Die Benennung/i', $line); // Deutsche Fehlermeldungen
            });
            
            if (empty($cleanOutput)) {
                // Keine Tasks gefunden oder leere Ausgabe
                $jsonOutput = '[]';
            } else {
                // Kombiniere alle Zeilen zu einem String
                $jsonOutput = implode('', $cleanOutput);
                
                // Entferne mögliche BOM oder unsichtbare Zeichen am Anfang
                $jsonOutput = trim($jsonOutput);
                $jsonOutput = preg_replace('/^\xEF\xBB\xBF/', '', $jsonOutput); // UTF-8 BOM entfernen
                
                // Prüfe ob JSON-String mit [ beginnt (Array) oder { beginnt (Objekt)
                // Wenn es mit { beginnt und nicht mit [{ beginnt, mache es zu einem Array
                $trimmedJson = trim($jsonOutput);
                if (!empty($trimmedJson) && substr($trimmedJson, 0, 1) === '{' && substr($trimmedJson, 0, 2) !== '[{') {
                    $jsonOutput = '[' . $jsonOutput . ']';
                }
            }
            
            // PowerShell gibt JSON aus - kann ein Array oder einzelnes Objekt sein
            
            // Versuche zuerst als Array zu dekodieren
            $decoded = json_decode($jsonOutput, true);
            
            // Wenn JSON-Dekodierung fehlschlägt, prüfe auf JSON-Fehler
            if ($decoded === null && !empty($jsonOutput)) {
                $jsonError = json_last_error_msg();
                // Versuche die JSON-Ausgabe zu reparieren
                $jsonOutput = preg_replace('/,\s*}/', '}', $jsonOutput); // Entferne trailing commas
                $jsonOutput = preg_replace('/,\s*]/', ']', $jsonOutput); // Entferne trailing commas in Arrays
                $decoded = json_decode($jsonOutput, true);
            }
            
            if ($decoded !== null) {
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    // Es ist ein Array von Objekten
                    $decodedTasks = $decoded;
                } elseif (is_array($decoded) && isset($decoded['TaskName'])) {
                    // Es ist ein einzelnes Objekt
                    $decodedTasks = [$decoded];
                } elseif (is_array($decoded)) {
                    // Versuche es als Array zu behandeln
                    $decodedTasks = $decoded;
                }
            } else {
                // JSON-Dekodierung fehlgeschlagen - versuche Zeile für Zeile
                $jsonLines = explode("\n", trim($jsonOutput));
                foreach ($jsonLines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    $decoded = json_decode($line, true);
                    if ($decoded !== null) {
                        if (isset($decoded[0]) && is_array($decoded[0])) {
                            $decodedTasks = array_merge($decodedTasks, $decoded);
                        } else {
                            $decodedTasks[] = $decoded;
                        }
                    }
                }
            }
            
            foreach ($decodedTasks as $taskData) {
                $taskName = $taskData['TaskName'] ?? '';
                if (empty($taskName)) continue;
                
                $totalTasks++;
                $state = $taskData['State'] ?? 'Unknown';
                $lastRunTime = $taskData['LastRunTime'] ?? null;
                $nextRunTime = $taskData['NextRunTime'] ?? null;
                $lastTaskResult = $taskData['LastTaskResult'] ?? null;
                
                // Konvertiere State zu unserem Status-Format
                // Windows Task Scheduler States: 0=Unknown, 1=Disabled, 2=Queued, 3=Ready, 4=Running
                $taskStatus = 'unknown';
                $statusMessage = 'Unbekannt';
                $stateString = '';
                
                // Handle numerische States
                if (is_numeric($state)) {
                    $stateNum = (int)$state;
                    switch ($stateNum) {
                        case 0:
                            $stateString = 'Unknown';
                            $taskStatus = 'unknown';
                            $statusMessage = 'Unbekannt';
                            break;
                        case 1:
                            $stateString = 'Disabled';
                            $taskStatus = 'warning';
                            $statusMessage = 'Deaktiviert';
                            $stoppedTasks++;
                            break;
                        case 2:
                            $stateString = 'Queued';
                            $taskStatus = 'warning';
                            $statusMessage = 'In Warteschlange';
                            $stoppedTasks++;
                            break;
                        case 3:
                            $stateString = 'Ready';
                            $taskStatus = 'ok';
                            $statusMessage = 'Bereit';
                            $runningTasks++;
                            break;
                        case 4:
                            $stateString = 'Running';
                            $taskStatus = 'ok';
                            $statusMessage = 'Läuft';
                            $runningTasks++;
                            break;
                        default:
                            $stateString = 'Unknown (' . $stateNum . ')';
                            $taskStatus = 'unknown';
                            $statusMessage = 'Unbekannt (' . $stateNum . ')';
                            break;
                    }
                } else {
                    // Handle String-States
                    $stateString = $state;
                    if ($state === 'Running') {
                        $taskStatus = 'ok';
                        $statusMessage = 'Läuft';
                        $runningTasks++;
                    } elseif ($state === 'Ready') {
                        $taskStatus = 'ok';
                        $statusMessage = 'Bereit';
                        $runningTasks++;
                    } elseif ($state === 'Disabled') {
                        $taskStatus = 'warning';
                        $statusMessage = 'Deaktiviert';
                        $stoppedTasks++;
                    } elseif ($state === 'Queued') {
                        $taskStatus = 'warning';
                        $statusMessage = 'In Warteschlange';
                        $stoppedTasks++;
                    } else {
                        $taskStatus = 'error';
                        $statusMessage = 'Fehler: ' . $state;
                        $stoppedTasks++;
                    }
                }
                
                // Prüfe LastTaskResult (0 = Erfolg, andere Werte = Fehler)
                if ($lastTaskResult !== null && $lastTaskResult !== 0 && $lastTaskResult !== 267009) {
                    // 267009 = Task wurde noch nicht ausgeführt
                    if ($lastTaskResult !== 267009) {
                        $taskStatus = 'error';
                        $statusMessage = 'Fehler (Code: ' . $lastTaskResult . ')';
                    }
                }
                
                // Prüfe ob Task zu lange nicht gelaufen ist (wenn erwartet wird, dass er regelmäßig läuft)
                if ($lastRunTime && in_array($taskName, ['TOM3-Neo4j-Sync-Worker', 'TOM3-ClamAV-Scan-Worker', 'TOM3-ExtractTextWorker'])) {
                    try {
                        $lastRunTimestamp = strtotime($lastRunTime);
                        $minutesSinceLastRun = (time() - $lastRunTimestamp) / 60;
                        
                        // Wenn Task länger als 15 Minuten nicht gelaufen ist, markiere als Warnung
                        if ($minutesSinceLastRun > 15 && $taskStatus === 'ok') {
                            $taskStatus = 'warning';
                            $statusMessage = 'Läuft, aber letzte Ausführung vor ' . round($minutesSinceLastRun) . ' Min';
                        }
                    } catch (Exception $e) {
                        // Ignoriere Parse-Fehler
                    }
                }
                
                $taskInfo = $monitoredTasks[$taskName] ?? ['required' => false, 'description' => $taskName];
                
                // Formatiere Datumswerte für bessere JavaScript-Kompatibilität
                $formattedLastRun = null;
                if ($lastRunTime) {
                    try {
                        // PowerShell gibt DateTime-Objekte als ISO-8601 Strings zurück
                        // Konvertiere zu Unix-Timestamp für JavaScript
                        $dateTime = new DateTime($lastRunTime);
                        $formattedLastRun = $dateTime->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $formattedLastRun = $lastRunTime;
                    }
                }
                
                $formattedNextRun = null;
                if ($nextRunTime) {
                    try {
                        $dateTime = new DateTime($nextRunTime);
                        $formattedNextRun = $dateTime->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $formattedNextRun = $nextRunTime;
                    }
                }
                
                $tasks[] = [
                    'name' => $taskName,
                    'description' => $taskInfo['description'],
                    'required' => $taskInfo['required'],
                    'status' => $taskStatus,
                    'message' => $statusMessage,
                    'state' => $stateString ?: (string)$state,
                    'state_numeric' => is_numeric($state) ? (int)$state : null,
                    'last_run_time' => $formattedLastRun,
                    'next_run_time' => $formattedNextRun,
                    'last_task_result' => $lastTaskResult
                ];
                
                // Aktualisiere Gesamtstatus
                if ($taskInfo['required'] && $taskStatus !== 'ok') {
                    $overallStatus = 'error';
                } elseif ($overallStatus === 'ok' && $taskStatus === 'warning') {
                    $overallStatus = 'warning';
                }
            }
        }
        
        // Prüfe ob erforderliche Tasks fehlen
        foreach ($monitoredTasks as $taskName => $taskInfo) {
            if ($taskInfo['required']) {
                $found = false;
                foreach ($tasks as $task) {
                    if ($task['name'] === $taskName) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingRequiredTasks[] = $taskName;
                    $overallStatus = 'error';
                }
            }
        }
        
        // Erstelle Gesamtmeldung
        $message = '';
        
        if (!empty($missingRequiredTasks)) {
            $message = 'Fehlende Tasks: ' . implode(', ', $missingRequiredTasks);
        } elseif ($stoppedTasks > 0) {
            $message = "$runningTasks laufend, $stoppedTasks gestoppt";
        } elseif ($totalTasks > 0) {
            $message = "$totalTasks Task(s) aktiv";
        } else {
            $message = 'Keine Tasks gefunden';
        }
        
        return [
            'status' => $overallStatus,
            'message' => $message,
            'total_tasks' => $totalTasks,
            'running_tasks' => $runningTasks,
            'stopped_tasks' => $stoppedTasks,
            'missing_required_tasks' => $missingRequiredTasks,
            'tasks' => $tasks
        ];
        
        } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Fehler: ' . $e->getMessage(),
            'total_tasks' => 0,
            'running_tasks' => 0,
            'stopped_tasks' => 0,
            'missing_required_tasks' => [],
            'tasks' => [],
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Gibt Scan-Metriken zurück (inkl. Statistiken über behobene Probleme)
 */
function getScanMetrics(PDO $db): array
{
    try {
        // Hole Metriken für scan_pending_fix
        $stmt = $db->prepare("
            SELECT 
                metric_name,
                metric_value,
                metric_data,
                occurred_at,
                fixed_at,
                fixed_count,
                updated_at
            FROM monitoring_metrics
            WHERE metric_type = 'scan_pending_fix'
            ORDER BY occurred_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse metric_data JSON
        foreach ($metrics as &$metric) {
            if ($metric['metric_data']) {
                $metric['metric_data'] = json_decode($metric['metric_data'], true);
            }
        }
        
        // Gesamt-Statistiken
        $stmt = $db->prepare("
            SELECT 
                SUM(metric_value) as total_occurrences,
                SUM(fixed_count) as total_fixed,
                COUNT(*) as total_events,
                MAX(occurred_at) as last_occurrence
            FROM monitoring_metrics
            WHERE metric_type = 'scan_pending_fix'
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Aktuelle pending Blobs
        $pendingBlobs = checkPendingBlobsWithProcessedJobs($db);
        
        return [
            'current_pending_blobs' => $pendingBlobs['count'],
            'total_occurrences' => (int)($stats['total_occurrences'] ?? 0),
            'total_fixed' => (int)($stats['total_fixed'] ?? 0),
            'total_events' => (int)($stats['total_events'] ?? 0),
            'last_occurrence' => $stats['last_occurrence'],
            'recent_metrics' => $metrics
        ];
    } catch (Exception $e) {
        return [
            'error' => $e->getMessage()
        ];
    }
}


