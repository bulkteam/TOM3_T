<?php
/**
 * TOM3 - Fix Migration 007: Create missing indices
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Erstelle fehlende Indizes für Communication Channels ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    $indices = [
        'idx_org_channel_org' => 'CREATE INDEX idx_org_channel_org ON org_communication_channel(org_uuid)',
        'idx_org_channel_type' => 'CREATE INDEX idx_org_channel_type ON org_communication_channel(channel_type)',
        'idx_org_channel_primary' => 'CREATE INDEX idx_org_channel_primary ON org_communication_channel(org_uuid, channel_type, is_primary)',
        'idx_org_channel_email' => 'CREATE INDEX idx_org_channel_email ON org_communication_channel(email_address)'
    ];
    
    // Prüfe welche Indizes bereits existieren
    $stmt = $db->query("SHOW INDEX FROM org_communication_channel");
    $existingIndices = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingIndices[] = $row['Key_name'];
    }
    
    foreach ($indices as $indexName => $createSql) {
        if (in_array($indexName, $existingIndices)) {
            echo "✓ Index '$indexName' existiert bereits\n";
        } else {
            try {
                $db->exec($createSql);
                echo "✓ Index '$indexName' erstellt\n";
            } catch (PDOException $e) {
                echo "✗ Fehler beim Erstellen von '$indexName': " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n=== Fertig ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}





