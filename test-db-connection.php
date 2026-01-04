<?php
/**
 * Test Datenbankverbindung
 */

require __DIR__ . '/vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== Datenbankverbindungstest ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    echo "✓ Datenbankverbindung erfolgreich\n\n";
    
    // Test Query
    $stmt = $db->query('SELECT 1 as test, DATABASE() as dbname, USER() as user');
    $result = $stmt->fetch();
    
    echo "Test-Ergebnis:\n";
    echo "  test: " . $result['test'] . "\n";
    echo "  Datenbank: " . ($result['dbname'] ?? 'nicht verbunden') . "\n";
    echo "  Benutzer: " . ($result['user'] ?? 'unbekannt') . "\n\n";
    
    // Prüfe Tabellen
    $stmt = $db->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Gefundene Tabellen: " . count($tables) . "\n";
    if (count($tables) > 0) {
        echo "  - " . implode("\n  - ", array_slice($tables, 0, 10)) . "\n";
        if (count($tables) > 10) {
            echo "  ... und " . (count($tables) - 10) . " weitere\n";
        }
    }
    
    echo "\n✓ Datenbank ist bereit!\n";
    
} catch (Exception $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    echo "\nMögliche Ursachen:\n";
    echo "  1. MySQL läuft nicht (starte in XAMPP Control Panel)\n";
    echo "  2. Datenbank 'tom' existiert nicht\n";
    echo "  3. Benutzer/Passwort falsch (prüfe config/database.php)\n";
    echo "  4. Port 3306 blockiert\n";
    exit(1);
}





