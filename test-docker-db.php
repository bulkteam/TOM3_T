<?php
/**
 * Test Docker MySQL Verbindung
 */

echo "=== Docker MySQL Verbindungstest ===\n\n";

$host = '127.0.0.1';
$port = 3307;
$dbname = 'tom';
$user = 'tomcat';
$password = 'tim@2025!';

echo "Verbindungsparameter:\n";
echo "  Host: $host\n";
echo "  Port: $port\n";
echo "  Datenbank: $dbname\n";
echo "  User: $user\n";
echo "  Passwort: " . str_repeat('*', strlen($password)) . "\n\n";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    echo "DSN: $dsn\n\n";
    
    $pdo = new PDO(
        $dsn,
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
    
    echo "✓ Verbindung erfolgreich!\n\n";
    
    // Test Query
    $stmt = $pdo->query('SELECT 1 as test, DATABASE() as dbname, USER() as user, VERSION() as version');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Datenbank-Informationen:\n";
    echo "  Test: " . $result['test'] . "\n";
    echo "  Datenbank: " . ($result['dbname'] ?? 'nicht verbunden') . "\n";
    echo "  Benutzer: " . ($result['user'] ?? 'unbekannt') . "\n";
    echo "  Version: " . ($result['version'] ?? 'unbekannt') . "\n\n";
    
    // Prüfe Tabellen
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Gefundene Tabellen: " . count($tables) . "\n";
    if (count($tables) > 0) {
        echo "  - " . implode("\n  - ", array_slice($tables, 0, 10)) . "\n";
        if (count($tables) > 10) {
            echo "  ... und " . (count($tables) - 10) . " weitere\n";
        }
    }
    
    echo "\n✓ Datenbank ist bereit!\n";
    
} catch (PDOException $e) {
    echo "✗ FEHLER: " . $e->getMessage() . "\n";
    echo "  Code: " . $e->getCode() . "\n\n";
    
    if ($e->getCode() == 1045) {
        echo "Zugriffsfehler - mögliche Ursachen:\n";
        echo "  1. Benutzer 'tomcat' existiert nicht in Docker MySQL\n";
        echo "  2. Passwort ist falsch\n";
        echo "  3. Benutzer hat keine Berechtigung für Host '172.19.0.1' oder '%'\n";
        echo "\nLösung: Erstelle den Benutzer in Docker MySQL:\n";
        echo "  CREATE USER 'tomcat'@'%' IDENTIFIED BY 'tim@2025!';\n";
        echo "  GRANT ALL PRIVILEGES ON tom.* TO 'tomcat'@'%';\n";
        echo "  FLUSH PRIVILEGES;\n";
    } elseif ($e->getCode() == 2002) {
        echo "Verbindungsfehler - mögliche Ursachen:\n";
        echo "  1. Docker MySQL läuft nicht\n";
        echo "  2. Port 3307 ist nicht korrekt gemappt\n";
        echo "  3. Firewall blockiert Port 3307\n";
    }
    
    exit(1);
}
