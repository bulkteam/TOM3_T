<?php
/**
 * Prüft, ob die Staging-Tabellen leer sind
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Prüfung der Staging-Tabellen ===\n\n";

// 1. Prüfe org_import_staging
$stmt = $db->query("SELECT COUNT(*) as count FROM org_import_staging");
$stagingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "org_import_staging: {$stagingCount} Zeilen\n";

if ($stagingCount > 0) {
    // Zeige Details
    $stmt = $db->query("
        SELECT 
            disposition,
            import_status,
            COUNT(*) as count
        FROM org_import_staging
        GROUP BY disposition, import_status
        ORDER BY disposition, import_status
    ");
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  Details:\n";
    foreach ($details as $detail) {
        echo "    - disposition: {$detail['disposition']}, import_status: {$detail['import_status']}, count: {$detail['count']}\n";
    }
}

// 2. Prüfe org_import_batch
$stmt = $db->query("SELECT COUNT(*) as count FROM org_import_batch");
$batchCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "\norg_import_batch: {$batchCount} Batches\n";

if ($batchCount > 0) {
    // Zeige Details nach Status
    $stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM org_import_batch
        GROUP BY status
        ORDER BY status
    ");
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  Details nach Status:\n";
    foreach ($details as $detail) {
        echo "    - status: {$detail['status']}, count: {$detail['count']}\n";
    }
    
    // Zeige alle Batches mit Details
    $stmt = $db->query("
        SELECT 
            b.batch_uuid,
            b.filename,
            b.status,
            b.created_at,
            COUNT(DISTINCT s.staging_uuid) as staging_rows,
            SUM(CASE WHEN s.disposition = 'pending' THEN 1 ELSE 0 END) as pending_rows,
            SUM(CASE WHEN s.disposition = 'approved' THEN 1 ELSE 0 END) as approved_rows,
            SUM(CASE WHEN s.import_status = 'imported' THEN 1 ELSE 0 END) as imported_rows
        FROM org_import_batch b
        LEFT JOIN org_import_staging s ON b.batch_uuid = s.import_batch_uuid
        GROUP BY b.batch_uuid, b.filename, b.status, b.created_at
        ORDER BY b.created_at DESC
    ");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n  Alle Batches:\n";
    foreach ($batches as $batch) {
        echo "    - UUID: {$batch['batch_uuid']}\n";
        echo "      Dateiname: {$batch['filename']}\n";
        echo "      Status: {$batch['status']}\n";
        echo "      Erstellt: {$batch['created_at']}\n";
        echo "      Staging-Rows: {$batch['staging_rows']}\n";
        echo "      Pending: {$batch['pending_rows']}, Approved: {$batch['approved_rows']}, Imported: {$batch['imported_rows']}\n";
        echo "\n";
    }
}

// 3. Zusammenfassung
echo "\n=== Zusammenfassung ===\n";
if ($stagingCount == 0 && $batchCount == 0) {
    echo "✅ Alle Staging-Tabellen sind leer. Sie können einen neuen Test starten.\n";
} else {
    echo "⚠️  Es gibt noch Daten in den Staging-Tabellen:\n";
    echo "   - {$stagingCount} Staging-Rows\n";
    echo "   - {$batchCount} Batches\n";
    echo "\n";
    echo "Möchten Sie diese Daten löschen? (Dies kann nicht rückgängig gemacht werden!)\n";
    echo "Führen Sie folgende SQL-Befehle aus:\n";
    echo "  DELETE FROM org_import_staging;\n";
    echo "  DELETE FROM org_import_batch;\n";
}


