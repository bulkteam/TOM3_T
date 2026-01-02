<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Neuestes aktives Dokument ===\n\n";

$stmt = $db->query("
    SELECT d.document_uuid, d.title, b.scan_status, b.scan_at, d.created_at, 
           b.mime_detected, b.size_bytes, d.extraction_status
    FROM documents d
    JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
    WHERE d.status = 'active'
    ORDER BY d.created_at DESC
    LIMIT 1
");

$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if ($doc) {
    echo "Titel: {$doc['title']}\n";
    echo "UUID: {$doc['document_uuid']}\n";
    echo "Scan Status: {$doc['scan_status']}\n";
    echo "Scan At: " . ($doc['scan_at'] ?: 'NULL') . "\n";
    echo "Extraction Status: {$doc['extraction_status']}\n";
    echo "MIME: {$doc['mime_detected']}\n";
    echo "Größe: " . number_format($doc['size_bytes'], 0, ',', '.') . " Bytes\n";
    echo "Erstellt: {$doc['created_at']}\n";
    echo "\n";
    
    // Prüfe ob Scan-Job existiert
    $stmt2 = $db->prepare("
        SELECT COUNT(*) as count
        FROM outbox_event
        WHERE aggregate_type = 'blob'
          AND aggregate_uuid = (
              SELECT current_blob_uuid FROM documents WHERE document_uuid = ?
          )
          AND event_type = 'BlobScanRequested'
          AND processed_at IS NULL
    ");
    $stmt2->execute([$doc['document_uuid']]);
    $pendingScan = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    echo "Ausstehende Scan-Jobs: {$pendingScan['count']}\n";
} else {
    echo "Kein aktives Dokument gefunden\n";
}
