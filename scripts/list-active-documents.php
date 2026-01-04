<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Aktive Dokumente ===\n\n";

$stmt = $db->query("
    SELECT d.document_uuid, d.title, d.status, d.created_at, d.extraction_status,
           b.mime_detected, b.scan_status, b.size_bytes
    FROM documents d
    LEFT JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
    WHERE d.status = 'active'
    ORDER BY d.created_at DESC
");

$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Anzahl aktiver Dokumente: " . count($docs) . "\n\n";

if (count($docs) > 0) {
    foreach ($docs as $doc) {
        echo "Titel: {$doc['title']}\n";
        echo "UUID: {$doc['document_uuid']}\n";
        echo "Erstellt: {$doc['created_at']}\n";
        echo "Status: {$doc['status']}\n";
        echo "Extraction Status: " . ($doc['extraction_status'] ?: 'NULL') . "\n";
        echo "MIME: " . ($doc['mime_detected'] ?: 'NULL') . "\n";
        echo "Scan Status: " . ($doc['scan_status'] ?: 'NULL') . "\n";
        echo "Größe: " . ($doc['size_bytes'] ? number_format($doc['size_bytes']) . ' Bytes' : 'NULL') . "\n";
        echo "---\n";
    }
} else {
    echo "Keine aktiven Dokumente gefunden.\n";
}


