<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Utils\UuidHelper;

$db = DatabaseConnection::getInstance();

echo "=== Erstelle fehlende Extraction-Jobs ===\n\n";

// Finde Dokumente mit pending Status, aber ohne Job
$stmt = $db->query("
    SELECT d.document_uuid, d.title, d.extraction_status
    FROM documents d
    WHERE d.extraction_status = 'pending'
      AND NOT EXISTS (
          SELECT 1 FROM outbox_event oe
          WHERE oe.aggregate_type = 'document'
            AND oe.aggregate_uuid = d.document_uuid
            AND oe.event_type = 'DocumentExtractionRequested'
            AND oe.processed_at IS NULL
      )
");

$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$count = count($documents);

echo "Gefunden: $count Dokumente ohne Extraction-Job\n\n";

if ($count === 0) {
    echo "Keine fehlenden Jobs gefunden.\n";
    exit(0);
}

$created = 0;
foreach ($documents as $doc) {
    try {
        $eventUuid = UuidHelper::generate($db);
        
        $stmt = $db->prepare("
            INSERT INTO outbox_event (
                event_uuid, aggregate_type, aggregate_uuid, event_type, payload
            ) VALUES (
                :event_uuid, 'document', :document_uuid, 'DocumentExtractionRequested', :payload
            )
        ");
        
        $stmt->execute([
            'event_uuid' => $eventUuid,
            'document_uuid' => $doc['document_uuid'],
            'payload' => json_encode([
                'document_uuid' => $doc['document_uuid'],
                'job_type' => 'extract_text',
                'created_at' => date('Y-m-d H:i:s')
            ])
        ]);
        
        $created++;
        echo "✓ Job erstellt für: {$doc['title']} ({$doc['document_uuid']})\n";
    } catch (\Exception $e) {
        echo "✗ Fehler bei {$doc['document_uuid']}: " . $e->getMessage() . "\n";
    }
}

echo "\n✅ $created von $count Jobs erfolgreich erstellt.\n";
echo "\nDer Worker wird diese Jobs beim nächsten Lauf (spätestens in 5 Minuten) verarbeiten.\n";
