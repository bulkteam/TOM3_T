<?php
/**
 * TOM3 - Monitoring API
 */

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

try {
    $db = DatabaseConnection::getInstance();
} catch (Exception $e) {
    // Wenn von index.php aufgerufen, wird der Fehler dort behandelt
    if (!isset($id)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Database connection failed',
            'message' => $e->getMessage()
        ]);
        exit;
    }
    // Sonst Exception weiterwerfen, damit index.php sie behandelt
    throw $e;
}

$method = $_SERVER['REQUEST_METHOD'];

// Wenn von index.php aufgerufen, verwende $id (wird von index.php übergeben)
// Ansonsten parse den Pfad selbst
if (isset($id)) {
    // Von index.php: $id ist der Endpoint (z.B. 'status', 'outbox', etc.)
    $endpoint = $id;
} else {
    // Direkter Aufruf: Parse den Pfad selbst
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
    
    // Entferne /TOM3/public oder /tom3/public falls vorhanden (case-insensitive)
    $path = preg_replace('#^/tom3/public#i', '', $path);
    // Entferne /api/monitoring prefix
    $path = preg_replace('#^/api/monitoring/?|^api/monitoring/?#', '', $path);
    $path = trim($path, '/');
    
    $pathParts = explode('/', $path);
    $endpoint = $pathParts[0] ?? '';
}

switch ($endpoint) {
    case 'status':
        // GET /api/monitoring/status
        try {
            echo json_encode(getSystemStatus($db));
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]);
        }
        break;
        
    case 'outbox':
        // GET /api/monitoring/outbox
        echo json_encode(getOutboxMetrics($db));
        break;
        
    case 'cases':
        // GET /api/monitoring/cases
        echo json_encode(getCaseStatistics($db));
        break;
        
    case 'sync':
        // GET /api/monitoring/sync
        echo json_encode(getSyncStatistics($db));
        break;
        
    case 'errors':
        // GET /api/monitoring/errors
        echo json_encode(getRecentErrors($db));
        break;
        
    case 'event-types':
        // GET /api/monitoring/event-types
        echo json_encode(getEventTypesDistribution($db));
        break;
        
    case 'duplicates':
        // GET /api/monitoring/duplicates
        echo json_encode(getDuplicateCheckResults($db));
        break;
        
    case 'activity-log':
        // GET /api/monitoring/activity-log
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        echo json_encode(getActivityLog($db, $limit));
        break;
        
    case 'clamav':
        // GET /api/monitoring/clamav
        echo json_encode(getClamAvStatus($db));
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
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
        'clamav' => checkClamAv($db)
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


