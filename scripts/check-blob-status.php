<?php
/**
 * Prüft den Status eines spezifischen Blobs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$blobUuid = '5a0c8f68-eb41-11f0-93c7-de3a7939e1f4';

$stmt = $db->prepare("
    SELECT 
        blob_uuid, 
        scan_status, 
        scan_at, 
        scan_result, 
        scan_engine,
        created_at
    FROM blobs
    WHERE blob_uuid = ?
");

$stmt->execute([$blobUuid]);
$blob = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$blob) {
    echo "Blob nicht gefunden.\n";
    exit(1);
}

echo "=== Blob-Status ===\n";
echo "Blob UUID: {$blob['blob_uuid']}\n";
echo "Scan Status: {$blob['scan_status']}\n";
echo "Scan At: " . ($blob['scan_at'] ?: 'NULL') . "\n";
echo "Scan Engine: " . ($blob['scan_engine'] ?: 'NULL') . "\n";
echo "Created At: {$blob['created_at']}\n";
echo "Scan Result: " . ($blob['scan_result'] ?: 'NULL') . "\n";

if ($blob['scan_result']) {
    $result = json_decode($blob['scan_result'], true);
    if ($result) {
        echo "\nScan Result Details:\n";
        print_r($result);
    }
}

