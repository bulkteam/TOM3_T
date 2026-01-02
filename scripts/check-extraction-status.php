<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Extraction Status Check ===\n\n";

// Ausstehende Jobs
$stmt = $db->query("
    SELECT COUNT(*) as count 
    FROM outbox_event 
    WHERE aggregate_type = 'document' 
      AND event_type = 'DocumentExtractionRequested' 
      AND processed_at IS NULL
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Ausstehende Extraction-Jobs: " . $result['count'] . "\n\n";

// Document Extraction Status
$stmt = $db->query("
    SELECT extraction_status, COUNT(*) as count 
    FROM documents 
    GROUP BY extraction_status
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Document Extraction Status:\n";
foreach ($results as $row) {
    echo "  " . ($row['extraction_status'] ?: 'NULL') . ": " . $row['count'] . "\n";
}

echo "\n";

// Letzte verarbeitete Jobs
$stmt = $db->query("
    SELECT COUNT(*) as count,
           MAX(processed_at) as last_processed
    FROM outbox_event 
    WHERE aggregate_type = 'document' 
      AND event_type = 'DocumentExtractionRequested' 
      AND processed_at IS NOT NULL
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Verarbeitete Extraction-Jobs: " . $result['count'] . "\n";
if ($result['last_processed']) {
    echo "Letzte Verarbeitung: " . $result['last_processed'] . "\n";
}
