<?php
/**
 * Debug-Skript: Führt Staging-Import manuell aus und zeigt Fehler
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Service\Import\ImportStagingService;
use TOM\Service\Document\BlobService;
use TOM\Service\Import\OrgImportService;

$db = DatabaseConnection::getInstance();

// Batch UUID als Argument
$batchUuid = $argv[1] ?? null;

if (!$batchUuid) {
    echo "Usage: php debug-staging-import.php <batch_uuid>\n";
    echo "\nVerfügbare Batches:\n";
    $stmt = $db->query("SELECT batch_uuid, filename, status FROM org_import_batch ORDER BY created_at DESC LIMIT 5");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($batches as $batch) {
        echo "  - {$batch['batch_uuid']} ({$batch['filename']}) - Status: {$batch['status']}\n";
    }
    exit(1);
}

echo "=== Debug Staging Import ===\n\n";
echo "Batch UUID: $batchUuid\n\n";

try {
    // 1. Prüfe Batch
    $importService = new OrgImportService();
    $batch = $importService->getBatch($batchUuid);
    
    if (!$batch) {
        echo "❌ Batch nicht gefunden!\n";
        exit(1);
    }
    
    echo "✅ Batch gefunden:\n";
    echo "  - Dateiname: " . ($batch['filename'] ?? 'N/A') . "\n";
    echo "  - Status: " . ($batch['status'] ?? 'N/A') . "\n";
    echo "  - Mapping-Config: " . (empty($batch['mapping_config']) ? '❌ Fehlt' : '✅ Vorhanden') . "\n";
    
    $mappingConfig = json_decode($batch['mapping_config'] ?? '{}', true);
    if ($mappingConfig) {
        echo "  - Header Row: " . ($mappingConfig['header_row'] ?? 'N/A') . "\n";
        echo "  - Data Start Row: " . ($mappingConfig['data_start_row'] ?? 'N/A') . "\n";
        echo "  - Gemappte Felder: " . (isset($mappingConfig['columns']) ? count($mappingConfig['columns']) : 0) . "\n";
    }
    echo "\n";
    
    // 2. Prüfe Document/Blob
    $stmt = $db->prepare("
        SELECT d.current_blob_uuid as blob_uuid
        FROM document_attachments da
        JOIN documents d ON da.document_uuid = d.document_uuid
        WHERE da.entity_type = 'import_batch'
        AND da.entity_uuid = :batch_uuid
        ORDER BY da.created_at DESC
        LIMIT 1
    ");
    $stmt->execute(['batch_uuid' => $batchUuid]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc || !$doc['blob_uuid']) {
        echo "❌ Kein Document/Blob für Batch gefunden!\n";
        exit(1);
    }
    
    echo "✅ Blob gefunden: " . $doc['blob_uuid'] . "\n";
    
    // 3. Hole Datei-Pfad
    $blobService = new BlobService();
    $filePath = $blobService->getBlobFilePath($doc['blob_uuid']);
    
    if (!$filePath) {
        echo "❌ Datei-Pfad konnte nicht ermittelt werden!\n";
        exit(1);
    }
    
    if (!file_exists($filePath)) {
        echo "❌ Datei existiert nicht auf Festplatte: $filePath\n";
        exit(1);
    }
    
    echo "✅ Datei gefunden: $filePath\n";
    echo "  - Dateigröße: " . filesize($filePath) . " Bytes\n";
    echo "\n";
    
    // 4. Führe Import aus
    echo "🔄 Starte Import...\n\n";
    
    $stagingService = new ImportStagingService();
    $stats = $stagingService->stageBatch($batchUuid, $filePath);
    
    echo "✅ Import abgeschlossen:\n";
    echo "  - Gesamt Zeilen: " . ($stats['total_rows'] ?? 0) . "\n";
    echo "  - Importiert: " . ($stats['imported'] ?? 0) . "\n";
    echo "  - Fehler: " . ($stats['errors'] ?? 0) . "\n";
    
    if (!empty($stats['errors_detail'])) {
        echo "\n⚠️  Fehler-Details:\n";
        foreach (array_slice($stats['errors_detail'], 0, 5) as $error) {
            echo "  - Zeile {$error['row']}: {$error['error']}\n";
        }
        if (count($stats['errors_detail']) > 5) {
            echo "  ... und " . (count($stats['errors_detail']) - 5) . " weitere Fehler\n";
        }
    }
    
    // 5. Prüfe Staging-Rows
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM org_import_staging WHERE import_batch_uuid = ?");
    $stmt->execute([$batchUuid]);
    $stagingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
    
    echo "\n📊 Staging-Rows in DB: $stagingCount\n";
    
    if ($stagingCount > 0) {
        echo "✅ Daten wurden erfolgreich gespeichert!\n";
    } else {
        echo "❌ Keine Daten in DB gespeichert!\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ FEHLER:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Debug abgeschlossen ===\n";

