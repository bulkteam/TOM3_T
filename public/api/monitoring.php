<?php
/**
 * TOM3 - Monitoring API
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'message' => $e->getMessage()
    ]);
    exit;
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
    
    // Entferne /TOM3/public falls vorhanden
    $path = preg_replace('#^/TOM3/public#', '', $path);
    // Entferne /api/monitoring prefix
    $path = preg_replace('#^/api/monitoring/?|^api/monitoring/?#', '', $path);
    $path = trim($path, '/');
    
    $pathParts = explode('/', $path);
    $endpoint = $pathParts[0] ?? '';
}

switch ($endpoint) {
    case 'status':
        // GET /api/monitoring/status
        echo json_encode(getSystemStatus($db));
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
        'sync_worker' => checkSyncWorker($db)
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
    // TODO: Implementiere Neo4j-Status-Check
    // Für jetzt: immer "unknown"
    return [
        'status' => 'unknown',
        'message' => 'Nicht geprüft'
    ];
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
    
    // Neo4j counts (approximiert durch SQL - in Produktion sollte das aus Neo4j kommen)
    // Für MVP: zählen wir einfach die Orgs/Personen in SQL
    $stmt = $db->query("SELECT COUNT(*) as count FROM org");
    $orgsCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM person");
    $personsCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
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


