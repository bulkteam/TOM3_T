#!/usr/bin/env php
<?php
/**
 * TOM3 - Datenbank-Passwort aktualisieren
 * 
 * Usage: php scripts/update-database-password.php [passwort]
 */

$password = $argv[1] ?? null;

if (!$password) {
    echo "Usage: php scripts/update-database-password.php [passwort]\n";
    echo "\n";
    echo "Beispiel:\n";
    echo "  php scripts/update-database-password.php mein_passwort\n";
    echo "\n";
    echo "Oder interaktiv:\n";
    echo "  php scripts/test-database-connection.php\n";
    exit(1);
}

$configFile = __DIR__ . '/../config/database.php';
if (!file_exists($configFile)) {
    echo "[FEHLER] config/database.php nicht gefunden!\n";
    exit(1);
}

$config = require $configFile;
$dbConfig = $config['postgresql'] ?? [];

echo "Teste Verbindung mit neuem Passwort...\n";

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? 5432,
        'postgres' // Verwende 'postgres' DB für Verbindungstest
    );
    
    $pdo = new PDO(
        $dsn,
        $dbConfig['user'] ?? 'postgres',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
    
    echo "[OK] Verbindung erfolgreich!\n";
    
    // Aktualisiere Config
    $config['postgresql']['password'] = $password;
    $configContent = "<?php\n";
    $configContent .= "/**\n";
    $configContent .= " * TOM3 - Database Configuration\n";
    $configContent .= " * \n";
    $configContent .= " * Passe die Werte entsprechend deiner Datenbank-Konfiguration an.\n";
    $configContent .= " */\n\n";
    $configContent .= "return " . var_export($config, true) . ";\n";
    
    file_put_contents($configFile, $configContent);
    echo "[OK] config/database.php aktualisiert.\n";
    echo "\n";
    echo "Teste jetzt: http://localhost/TOM3/public/api/test.php\n";
    
} catch (PDOException $e) {
    echo "[FEHLER] Verbindung fehlgeschlagen: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Bitte pruefe:\n";
    echo "  1. Ist das Passwort korrekt?\n";
    echo "  2. Laeuft PostgreSQL?\n";
    echo "  3. Sind Host/Port korrekt?\n";
    exit(1);
}

