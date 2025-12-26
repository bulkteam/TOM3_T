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
$pathParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$endpoint = $pathParts[1] ?? '';

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
              AND created_at < now() - INTERVAL '5 minutes'
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
        WHERE processed_at >= now() - INTERVAL '24 hours'
    ");
    $processed24h = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Errors in last 24h (Events ohne processed_at, aber älter als 1h)
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM outbox_event
        WHERE processed_at IS NULL
          AND created_at < now() - INTERVAL '1 hour'
    ");
    $errors24h = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Average lag (Zeit zwischen created_at und processed_at)
    $stmt = $db->query("
        SELECT AVG(EXTRACT(EPOCH FROM (processed_at - created_at))) as avg_lag
        FROM outbox_event
        WHERE processed_at IS NOT NULL
          AND processed_at >= now() - INTERVAL '1 hour'
    ");
    $avgLag = (float)($stmt->fetch(PDO::FETCH_ASSOC)['avg_lag'] ?? 0);
    
    // Hourly data (last 24 hours)
    $stmt = $db->query("
        SELECT 
            TO_CHAR(created_at, 'YYYY-MM-DD HH24:00') as hour,
            COUNT(*) FILTER (WHERE processed_at IS NOT NULL) as processed,
            COUNT(*) FILTER (WHERE processed_at IS NULL AND created_at < now() - INTERVAL '1 hour') as errors
        FROM outbox_event
        WHERE created_at >= now() - INTERVAL '24 hours'
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
        WHERE processed_at >= now() - INTERVAL '1 hour'
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
          AND created_at < now() - INTERVAL '1 hour'
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
        WHERE created_at >= now() - INTERVAL '24 hours'
        GROUP BY event_type
        ORDER BY count DESC
    ");
    
    $distribution = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $distribution[$row['event_type']] = (int)$row['count'];
    }
    
    return $distribution;
}


