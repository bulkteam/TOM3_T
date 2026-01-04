<?php
require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Document Status Check ===\n\n";

// Status-Übersicht
$stmt = $db->query("
    SELECT status, COUNT(*) as count 
    FROM documents 
    GROUP BY status
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Document Status:\n";
foreach ($results as $row) {
    echo "  " . ($row['status'] ?: 'NULL') . ": " . $row['count'] . "\n";
}

echo "\n";

// Detaillierte Liste (nur deleted)
$stmt = $db->query("
    SELECT document_uuid, title, status, created_at, extraction_status
    FROM documents 
    WHERE status = 'deleted'
    ORDER BY created_at DESC
    LIMIT 20
");
$deleted = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($deleted) > 0) {
    echo "Gelöschte Dokumente (erste 20):\n";
    foreach ($deleted as $doc) {
        echo "  - {$doc['title']} ({$doc['document_uuid']}) - Erstellt: {$doc['created_at']}\n";
    }
    echo "\n";
    echo "Hinweis: Diese Dokumente sind 'soft deleted' (status = 'deleted').\n";
    echo "Sie werden in der GUI nicht angezeigt, existieren aber noch in der DB.\n";
} else {
    echo "Keine gelöschten Dokumente gefunden.\n";
}


