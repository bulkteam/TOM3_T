<?php
/**
 * Fix für Blobs mit pending scan_status, aber verarbeiteten Jobs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Document\ClamAvService;

$db = DatabaseConnection::getInstance();
$blobService = new BlobService($db);
$clamAv = new ClamAvService();

echo "=== Fix für pending Blob-Status ===\n\n";

// Finde Blobs mit pending scan_status, aber verarbeiteten Jobs
$stmt = $db->query("
    SELECT DISTINCT b.blob_uuid, b.scan_status, b.scan_at
    FROM blobs b
    INNER JOIN outbox_event o ON o.aggregate_uuid = b.blob_uuid
    WHERE b.scan_status = 'pending'
      AND o.aggregate_type = 'blob'
      AND o.event_type = 'BlobScanRequested'
      AND o.processed_at IS NOT NULL
    ORDER BY b.created_at DESC
    LIMIT 10
");

$blobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($blobs)) {
    echo "Keine Blobs mit pending Status gefunden.\n";
    exit(0);
}

echo "Gefunden: " . count($blobs) . " Blob(s)\n\n";

foreach ($blobs as $blob) {
    $blobUuid = $blob['blob_uuid'];
    echo "Blob: {$blobUuid}\n";
    
    // Prüfe, ob Datei existiert
    $filePath = $blobService->getBlobFilePath($blobUuid);
    if (!$filePath || !file_exists($filePath)) {
        echo "  ⚠️  Datei nicht gefunden, überspringe\n\n";
        continue;
    }
    
    // Prüfe, ob ClamAV verfügbar ist
    if (!$clamAv->isAvailable()) {
        echo "  ⚠️  ClamAV nicht verfügbar, überspringe\n\n";
        continue;
    }
    
    // Führe Scan durch
    try {
        echo "  Starte Scan...\n";
        $scanResult = $clamAv->scan($filePath);
        echo "  Scan erfolgreich: {$scanResult['status']}\n";
        
        // Update Blob-Status
        $updateStmt = $db->prepare("
            UPDATE blobs
            SET scan_status = :status,
                scan_engine = 'clamav',
                scan_at = NOW(),
                scan_result = :result
            WHERE blob_uuid = :blob_uuid
        ");
        
        $updateStmt->execute([
            'blob_uuid' => $blobUuid,
            'status' => $scanResult['status'],
            'result' => json_encode($scanResult)
        ]);
        
        echo "  ✓ Blob-Status aktualisiert: {$scanResult['status']}\n";
        
    } catch (\Exception $e) {
        echo "  ⚠️  Fehler beim Scan: " . $e->getMessage() . "\n";
        
        // Markiere als Error
        $errorStmt = $db->prepare("
            UPDATE blobs
            SET scan_status = 'error',
                scan_engine = 'clamav',
                scan_at = NOW(),
                scan_result = :result
            WHERE blob_uuid = :blob_uuid
        ");
        
        $errorStmt->execute([
            'blob_uuid' => $blobUuid,
            'result' => json_encode(['error' => $e->getMessage()])
        ]);
        
        echo "  ✓ Blob-Status auf 'error' gesetzt\n";
    }
    
    echo "\n";
}

echo "=== Fix abgeschlossen ===\n";

