<?php
/**
 * Debug-Skript: Prüft Import-Batches
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/load-env.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Import-Batches Prüfung ===\n\n";

// 1. Prüfe, ob Tabelle existiert
try {
    $stmt = $db->query("SHOW TABLES LIKE 'org_import_batch'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        echo "❌ Tabelle 'org_import_batch' existiert nicht!\n";
        exit(1);
    }
    echo "✅ Tabelle 'org_import_batch' existiert\n\n";
} catch (Exception $e) {
    echo "❌ Fehler beim Prüfen der Tabelle: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Zeige alle Batches
try {
    $stmt = $db->query("
        SELECT 
            batch_uuid,
            filename,
            status,
            mapping_config,
            uploaded_by_user_id,
            created_at
        FROM org_import_batch
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($batches)) {
        echo "⚠️  Keine Batches gefunden!\n";
    } else {
        echo "📦 Gefundene Batches:\n";
        echo str_repeat("-", 100) . "\n";
        foreach ($batches as $batch) {
            echo "Batch UUID: " . $batch['batch_uuid'] . "\n";
            echo "  - Dateiname: " . ($batch['filename'] ?? 'N/A') . "\n";
            echo "  - Status: " . ($batch['status'] ?? 'N/A') . "\n";
            echo "  - Upload von User: " . ($batch['uploaded_by_user_id'] ?? 'N/A') . "\n";
            echo "  - Erstellt: " . ($batch['created_at'] ?? 'N/A') . "\n";
            
            // Prüfe Mapping-Config
            $mappingConfig = json_decode($batch['mapping_config'] ?? '{}', true);
            if ($mappingConfig) {
                echo "  - Mapping-Config vorhanden: ✅\n";
                echo "    - Header Row: " . ($mappingConfig['header_row'] ?? 'N/A') . "\n";
                echo "    - Data Start Row: " . ($mappingConfig['data_start_row'] ?? 'N/A') . "\n";
                echo "    - Anzahl gemappte Spalten: " . (isset($mappingConfig['columns']) ? count($mappingConfig['columns']) : 0) . "\n";
                if (isset($mappingConfig['columns'])) {
                    echo "    - Gemappte Felder: " . implode(', ', array_keys($mappingConfig['columns'])) . "\n";
                }
            } else {
                echo "  - Mapping-Config: ❌ Fehlt oder leer\n";
            }
            
            // Prüfe Staging-Rows für diesen Batch
            $stmt2 = $db->prepare("SELECT COUNT(*) as count FROM org_import_staging WHERE import_batch_uuid = ?");
            $stmt2->execute([$batch['batch_uuid']]);
            $stagingCount = $stmt2->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            echo "  - Staging-Rows: " . $stagingCount . "\n";
            
            echo "\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Fehler beim Abfragen der Batches: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Prüfung abgeschlossen ===\n";
