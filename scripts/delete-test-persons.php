<?php
/**
 * Lösche alle Test-Personen
 */

require __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== Lösche Test-Personen ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    // Liste der Test-E-Mail-Adressen
    $testEmails = [
        'max.mustermann@acme-corp.com',
        'anna.schmidt@acme-corp.com',
        'michael.bauer@acme-corp.com',
        'thomas.weber@techcorp.de',
        'lisa.mueller@techcorp.de',
        'julia.fischer@techcorp.de',
        'peter.hoffmann@global-solutions.de',
        'sabine.wagner@global-solutions.de',
        'alexander.neumann@innovate-systems.com',
        'robert.schneider@mega-manufacturing.de',
        'daniel.richter@mega-manufacturing.de',
        'maria.becker@startup-dynamics.de',
        'klaus.zimmermann@premium-components.com',
        'nicole.schulz@quality-parts.de',
        'sarah.klein@eigene-firma.de',
        'thomas.meier@eigene-firma.de',
        'jennifer.lange@eigene-firma.de'
    ];
    
    $stmt = $db->prepare("DELETE FROM person WHERE email IN (" . implode(',', array_fill(0, count($testEmails), '?')) . ")");
    $stmt->execute($testEmails);
    
    $deleted = $stmt->rowCount();
    echo "✓ $deleted Test-Personen gelöscht\n";
    
} catch (Exception $e) {
    echo "❌ FEHLER: " . $e->getMessage() . "\n";
    exit(1);
}
