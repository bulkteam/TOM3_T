<?php
/**
 * Scannt alle Blobs mit "pending" Status manuell
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Document\ClamAvService;

$db = DatabaseConnection::getInstance();
$blobService = new BlobService($db);
$clamAv = new ClamAvService();

if (!$clamAv->isAvailable()) {
    echo "FEHLER: ClamAV nicht verfügbar!\n";
    exit(1);
}

echo "=== Manueller Scan für pending Blobs ===\n\n";

// Hole alle Blobs mit "pending" Status
$stmt = $db->query("
    SELECT blob_uuid, storage_key, original_filename, size_bytes
    FROM blobs
    WHERE scan_status = 'pending'
    ORDER BY created_at DESC
    LIMIT 20
");

$blobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($blobs)) {
    echo "Keine pending Blobs gefunden.\n";
    exit(0);
}

echo "Gefunden: " . count($blobs) . " Blob(s) mit Status 'pending'\n\n";

$scanned = 0;
$errors = 0;

foreach ($blobs as $blob) {
    $blobUuid = $blob['blob_uuid'];
    echo "Scanne Blob: " . substr($blobUuid, 0, 8) . "... ({$blob['original_filename']})\n";
    
    try {
        // Hole Datei-Pfad
        $filePath = $blobService->getBlobFilePath($blobUuid);
        
        if (!file_exists($filePath)) {
            echo "  FEHLER: Datei nicht gefunden: {$filePath}\n";
            // Markiere als error
            $db->prepare("UPDATE blobs SET scan_status = 'error', scan_result = :result WHERE blob_uuid = :uuid")
               ->execute([
                   'uuid' => $blobUuid,
                   'result' => json_encode(['error' => 'File not found'])
               ]);
            $errors++;
            continue;
        }
        
        // Scan durchführen
        echo "  Datei: {$filePath}\n";
        $scanResult = $clamAv->scan($filePath);
        
        // Status aktualisieren
        $stmt = $db->prepare("
            UPDATE blobs
            SET scan_status = :status,
                scan_engine = 'clamav',
                scan_at = NOW(),
                scan_result = :result
            WHERE blob_uuid = :blob_uuid
        ");
        
        $stmt->execute([
            'blob_uuid' => $blobUuid,
            'status' => $scanResult['status'],
            'result' => json_encode($scanResult)
        ]);
        
        echo "  ✓ Scan abgeschlossen: Status = {$scanResult['status']}\n";
        
        // Wenn infected: Blockiere Documents
        if ($scanResult['status'] === 'infected') {
            $db->prepare("
                UPDATE documents
                SET status = 'blocked'
                WHERE current_blob_uuid = :blob_uuid
                  AND status = 'active'
            ")->execute(['blob_uuid' => $blobUuid]);
            echo "  ⚠ Dokumente blockiert\n";
        }
        
        $scanned++;
        
    } catch (Exception $e) {
        echo "  FEHLER: " . $e->getMessage() . "\n";
        // Markiere als error
        $db->prepare("UPDATE blobs SET scan_status = 'error', scan_result = :result WHERE blob_uuid = :uuid")
           ->execute([
               'uuid' => $blobUuid,
               'result' => json_encode(['error' => $e->getMessage()])
           ]);
        $errors++;
    }
    
    echo "\n";
}

echo "=== Zusammenfassung ===\n";
echo "Gescannt: {$scanned}\n";
echo "Fehler: {$errors}\n";


