<?php
/**
 * Testet den Scan für ein spezifisches Blob
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Document\BlobService;
use TOM\Infrastructure\Document\ClamAvService;

$db = DatabaseConnection::getInstance();
$blobService = new BlobService($db);
$clamAv = new ClamAvService();

$blobUuid = '5a0c8f68-eb41-11f0-93c7-de3a7939e1f4';

echo "=== Test Scan für Blob ===\n";
echo "Blob UUID: {$blobUuid}\n\n";

// Hole Blob-Informationen
$blob = $blobService->getBlob($blobUuid);
if (!$blob) {
    echo "Blob nicht gefunden.\n";
    exit(1);
}

echo "Blob-Informationen:\n";
echo "  Storage Key: {$blob['storage_key']}\n";
echo "  Scan Status: {$blob['scan_status']}\n\n";

// Hole Datei-Pfad
try {
    $filePath = $blobService->getBlobFilePath($blobUuid);
    echo "Datei-Pfad: {$filePath}\n";
    echo "Datei existiert: " . (file_exists($filePath) ? 'JA' : 'NEIN') . "\n";
    
    if (!file_exists($filePath)) {
        echo "⚠️  Datei existiert nicht!\n";
        exit(1);
    }
    
    echo "\n";
    
    // Teste ClamAV-Verfügbarkeit
    echo "ClamAV verfügbar: " . ($clamAv->isAvailable() ? 'JA' : 'NEIN') . "\n";
    
    if (!$clamAv->isAvailable()) {
        echo "⚠️  ClamAV ist nicht verfügbar!\n";
        exit(1);
    }
    
    echo "\n";
    
    // Versuche Scan
    echo "Starte Scan...\n";
    try {
        $scanResult = $clamAv->scan($filePath);
        echo "Scan erfolgreich!\n";
        echo "Status: {$scanResult['status']}\n";
        echo "Message: {$scanResult['message']}\n";
        if (isset($scanResult['threats'])) {
            echo "Threats: " . json_encode($scanResult['threats']) . "\n";
        }
    } catch (\Exception $e) {
        echo "⚠️  Scan fehlgeschlagen: " . $e->getMessage() . "\n";
        echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    }
    
} catch (\Exception $e) {
    echo "⚠️  Fehler beim Abrufen des Datei-Pfads: " . $e->getMessage() . "\n";
    exit(1);
}

