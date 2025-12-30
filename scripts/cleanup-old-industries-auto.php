<?php
/**
 * TOM3 - Cleanup Old Industry Test Data (Automatic)
 * Entfernt die alten Testdaten aus Migration 005
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Bereinige alte Industry-Testdaten ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    // Finde alle Industries, die keine parent_industry_uuid haben
    // aber nicht mit einem Buchstaben + " - " beginnen (WZ 2008 Format)
    $stmt = $db->query("
        SELECT industry_uuid, name, code 
        FROM industry 
        WHERE parent_industry_uuid IS NULL
        AND name NOT LIKE '%- %'
        ORDER BY name
    ");
    $oldIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($oldIndustries)) {
        echo "Keine alten Testdaten gefunden.\n";
        exit(0);
    }
    
    echo "Gefundene alte Testdaten:\n";
    foreach ($oldIndustries as $ind) {
        echo "  - {$ind['name']} (Code: {$ind['code']})\n";
    }
    
    // Lösche die alten Testdaten
    $deleted = 0;
    foreach ($oldIndustries as $ind) {
        try {
            $db->prepare("DELETE FROM industry WHERE industry_uuid = :uuid")
               ->execute(['uuid' => $ind['industry_uuid']]);
            echo "✓ Gelöscht: {$ind['name']}\n";
            $deleted++;
        } catch (Exception $e) {
            echo "✗ Fehler beim Löschen von {$ind['name']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== Fertig: $deleted Einträge gelöscht ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



