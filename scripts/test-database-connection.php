#!/usr/bin/env php
<?php
/**
 * TOM3 - Datenbank-Verbindung testen und Passwort finden
 * 
 * Dieses Script hilft dir, das richtige PostgreSQL-Passwort zu finden.
 */

echo "========================================\n";
echo "   TOM3 - Datenbank-Verbindung testen\n";
echo "========================================\n\n";

// Lade Config
$configFile = __DIR__ . '/../config/database.php';
if (!file_exists($configFile)) {
    echo "[FEHLER] config/database.php nicht gefunden!\n";
    exit(1);
}

$config = require $configFile;
$dbConfig = $config['postgresql'] ?? [];

echo "Aktuelle Konfiguration:\n";
echo "  Host: " . ($dbConfig['host'] ?? 'nicht gesetzt') . "\n";
echo "  Port: " . ($dbConfig['port'] ?? 'nicht gesetzt') . "\n";
echo "  Datenbank: " . ($dbConfig['dbname'] ?? 'nicht gesetzt') . "\n";
echo "  Benutzer: " . ($dbConfig['user'] ?? 'nicht gesetzt') . "\n";
echo "  Passwort: " . (isset($dbConfig['password']) ? str_repeat('*', strlen($dbConfig['password'])) : 'nicht gesetzt') . "\n";
echo "\n";

// Teste Verbindung mit aktuellem Passwort
echo "Teste Verbindung mit aktuellem Passwort...\n";
try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? 5432,
        $dbConfig['dbname'] ?? 'postgres' // Verwende 'postgres' DB für Verbindungstest
    );
    
    $pdo = new PDO(
        $dsn,
        $dbConfig['user'] ?? 'postgres',
        $dbConfig['password'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
    
    echo "[OK] Verbindung erfolgreich!\n";
    echo "\n";
    echo "Das aktuelle Passwort ist korrekt.\n";
    echo "\n";
    echo "Moechtest du trotzdem Testnutzer erstellen? (j/n): ";
    $answer = trim(fgets(STDIN));
    
    if (strtolower($answer) === 'j') {
        echo "\nErstelle Testnutzer...\n";
        
        // Erstelle Datenbank falls nicht vorhanden
        $testDb = $dbConfig['dbname'] ?? 'tom3';
        try {
            $pdo->exec("CREATE DATABASE $testDb");
            echo "[OK] Datenbank '$testDb' erstellt.\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "[INFO] Datenbank '$testDb' existiert bereits.\n";
            } else {
                echo "[FEHLER] " . $e->getMessage() . "\n";
            }
        }
        
        // Erstelle Testnutzer
        $testUser = 'tom3_test';
        $testPassword = 'tom3_test';
        
        try {
            $pdo->exec("CREATE USER $testUser WITH PASSWORD '$testPassword'");
            echo "[OK] Benutzer '$testUser' erstellt.\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "[INFO] Benutzer '$testUser' existiert bereits.\n";
            } else {
                echo "[FEHLER] " . $e->getMessage() . "\n";
            }
        }
        
        // Setze Berechtigungen
        try {
            $pdo->exec("GRANT ALL PRIVILEGES ON DATABASE $testDb TO $testUser");
            $pdo->exec("ALTER DATABASE $testDb OWNER TO $testUser");
            echo "[OK] Berechtigungen gesetzt.\n";
        } catch (PDOException $e) {
            echo "[WARN] " . $e->getMessage() . "\n";
        }
        
        // Aktualisiere Config
        $newConfig = $config;
        $newConfig['postgresql']['user'] = $testUser;
        $newConfig['postgresql']['password'] = $testPassword;
        $newConfig['postgresql']['dbname'] = $testDb;
        
        $configContent = "<?php\n";
        $configContent .= "/**\n";
        $configContent .= " * TOM3 - Database Configuration\n";
        $configContent .= " * \n";
        $configContent .= " * Standard-Testkonfiguration (automatisch erstellt)\n";
        $configContent .= " */\n\n";
        $configContent .= "return " . var_export($newConfig, true) . ";\n";
        
        file_put_contents($configFile, $configContent);
        echo "[OK] config/database.php aktualisiert.\n";
    }
    
} catch (PDOException $e) {
    echo "[FEHLER] Verbindung fehlgeschlagen!\n";
    echo "  Fehler: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Moegliche Ursachen:\n";
    echo "  1. Das Passwort ist falsch\n";
    echo "  2. PostgreSQL laeuft nicht\n";
    echo "  3. Host/Port sind falsch\n";
    echo "\n";
    echo "Moechtest du ein neues Passwort eingeben? (j/n): ";
    $answer = trim(fgets(STDIN));
    
    if (strtolower($answer) === 'j') {
        echo "\nBitte gib das Passwort fuer Benutzer '" . ($dbConfig['user'] ?? 'postgres') . "' ein: ";
        $password = trim(fgets(STDIN));
        
        // Teste mit neuem Passwort
        try {
            $pdo = new PDO(
                $dsn,
                $dbConfig['user'] ?? 'postgres',
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5
                ]
            );
            
            echo "[OK] Verbindung mit neuem Passwort erfolgreich!\n";
            
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
            
        } catch (PDOException $e2) {
            echo "[FEHLER] Auch mit neuem Passwort fehlgeschlagen: " . $e2->getMessage() . "\n";
            echo "\n";
            echo "Bitte pruefe:\n";
            echo "  1. Ist PostgreSQL installiert und laeuft?\n";
            echo "  2. Ist der Host/Port korrekt?\n";
            echo "  3. Ist der Benutzername korrekt?\n";
        }
    }
}

echo "\n========================================\n";
echo "   Fertig\n";
echo "========================================\n";

