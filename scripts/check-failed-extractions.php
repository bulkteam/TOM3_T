<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Dokumente mit failed Extraction ===\n\n";

$stmt = $db->query("
    SELECT d.document_uuid, d.title, d.extraction_status, b.mime_detected, d.created_at
    FROM documents d
    JOIN blobs b ON d.current_blob_uuid = b.blob_uuid
    WHERE d.extraction_status = 'failed' 
      AND d.status = 'active'
    ORDER BY d.created_at DESC
    LIMIT 10
");

$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($docs)) {
    echo "✅ Keine fehlgeschlagenen Extraktionen gefunden.\n";
} else {
    echo "Gefunden: " . count($docs) . " Dokumente\n\n";
    foreach ($docs as $doc) {
        echo "UUID: {$doc['document_uuid']}\n";
        echo "Titel: {$doc['title']}\n";
        echo "MIME: {$doc['mime_detected']}\n";
        echo "Status: {$doc['extraction_status']}\n";
        echo "Erstellt: {$doc['created_at']}\n";
        echo "---\n\n";
    }
    
    echo "\n💡 Tipp: Diese Jobs können neu erstellt werden mit:\n";
    echo "   php scripts/create-missing-extraction-jobs.php\n";
}


