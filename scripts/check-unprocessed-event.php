<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$stmt = $db->query('SELECT * FROM outbox_event WHERE processed_at IS NULL LIMIT 1');
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if ($event) {
    echo "Event:\n";
    echo "  UUID: " . $event['event_uuid'] . "\n";
    echo "  Type: " . $event['aggregate_type'] . "::" . $event['event_type'] . "\n";
    echo "  Payload: " . $event['payload'] . "\n";
    echo "  Created: " . $event['created_at'] . "\n";
} else {
    echo "Keine unverarbeiteten Events gefunden\n";
}


