<?php
/**
 * TOM3 - Main Entry Point
 * 
 * Prüft MySQL-Verfügbarkeit, Authentifizierung und lädt die App nur wenn eingeloggt
 */

if (!defined('TOM3_AUTOLOADED')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    define('TOM3_AUTOLOADED', true);
}

use TOM\Infrastructure\Auth\AuthService;

/**
 * Prüft ob MySQL auf Port 3306 antwortet
 */
function isMySQLRunning(): bool {
    $connection = @fsockopen('localhost', 3306, $errno, $errstr, 2);
    if ($connection) {
        fclose($connection);
        return true;
    }
    return false;
}

/**
 * Zeigt eine benutzerfreundliche Fehlerseite an
 */
function showErrorPage(string $title, string $message, array $suggestions = []): void {
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - TOM3</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                margin: 0;
                padding: 20px;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }
            .error-container {
                background: white;
                border-radius: 8px;
                padding: 2rem;
                max-width: 600px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            h1 {
                color: #dc2626;
                margin-top: 0;
            }
            .message {
                color: #374151;
                margin: 1rem 0;
                line-height: 1.6;
            }
            .suggestions {
                margin-top: 1.5rem;
                padding-top: 1.5rem;
                border-top: 1px solid #e5e7eb;
            }
            .suggestions h2 {
                font-size: 1rem;
                color: #6b7280;
                margin-bottom: 0.5rem;
            }
            .suggestions ul {
                margin: 0;
                padding-left: 1.5rem;
                color: #4b5563;
            }
            .suggestions li {
                margin: 0.5rem 0;
            }
            .retry-button {
                margin-top: 1.5rem;
                padding: 0.75rem 1.5rem;
                background: #3b82f6;
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 1rem;
            }
            .retry-button:hover {
                background: #2563eb;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="message"><?= nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) ?></div>
            <?php if (!empty($suggestions)): ?>
            <div class="suggestions">
                <h2>Mögliche Lösungen:</h2>
                <ul>
                    <?php foreach ($suggestions as $suggestion): ?>
                    <li><?= htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <button class="retry-button" onclick="window.location.reload()">Seite neu laden</button>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 1. Prüfe MySQL-Verfügbarkeit
if (!isMySQLRunning()) {
    // Versuche MySQL automatisch zu starten (nur auf Windows)
    if (PHP_OS_FAMILY === 'Windows') {
        $recoveryScript = __DIR__ . '/../scripts/ensure-mysql-running.bat';
        if (file_exists($recoveryScript)) {
            // Führe Script im Hintergrund aus (non-blocking)
            $command = 'start /B "' . $recoveryScript . '"';
            @exec($command);
            
            // Warte kurz und prüfe erneut
            sleep(3);
            if (isMySQLRunning()) {
                // MySQL läuft jetzt, weiter mit Auth-Prüfung
            } else {
                // MySQL konnte nicht gestartet werden
                showErrorPage(
                    'MySQL nicht verfügbar',
                    'Die MySQL-Datenbank ist nicht erreichbar. Die Anwendung kann nicht gestartet werden.',
                    [
                        'Starte MySQL über das XAMPP Control Panel',
                        'Führe das Recovery-Script aus: scripts\\ensure-mysql-running.bat',
                        'Prüfe ob Port 3306 blockiert ist',
                        'Prüfe die MySQL-Logs: C:\\xampp\\mysql\\data\\mysql_error.log'
                    ]
                );
            }
        } else {
            // Script nicht gefunden
            showErrorPage(
                'MySQL nicht verfügbar',
                'Die MySQL-Datenbank ist nicht erreichbar. Die Anwendung kann nicht gestartet werden.',
                [
                    'Starte MySQL über das XAMPP Control Panel',
                    'Prüfe ob Port 3306 blockiert ist',
                    'Prüfe die MySQL-Logs: C:\\xampp\\mysql\\data\\mysql_error.log'
                ]
            );
        }
    } else {
        // Nicht Windows - zeige Fehlermeldung
        showErrorPage(
            'MySQL nicht verfügbar',
            'Die MySQL-Datenbank ist nicht erreichbar. Die Anwendung kann nicht gestartet werden.',
            [
                'Stelle sicher, dass MySQL läuft',
                'Prüfe ob Port 3306 erreichbar ist',
                'Prüfe die MySQL-Konfiguration'
            ]
        );
    }
}

// 2. Prüfe Authentifizierung
try {
    $auth = new AuthService();
    $currentUser = $auth->getCurrentUser();
    
    // Wenn nicht eingeloggt -> weiterleiten zu Login
    if (!$currentUser) {
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }
        header('Location: ' . $basePath . '/login.php');
        exit;
    }
    
    // Wenn eingeloggt -> HTML-Datei einbinden
    $htmlFile = __DIR__ . '/index.html';
    if (file_exists($htmlFile)) {
        readfile($htmlFile);
    } else {
        http_response_code(500);
        die('index.html not found');
    }
} catch (PDOException $e) {
    // Datenbank-Verbindungsfehler
    if (strpos($e->getMessage(), '2002') !== false || strpos($e->getMessage(), 'connection') !== false) {
        showErrorPage(
            'Datenbank-Verbindungsfehler',
            'Es konnte keine Verbindung zur MySQL-Datenbank hergestellt werden.',
            [
                'Stelle sicher, dass MySQL läuft',
                'Prüfe die Datenbank-Konfiguration in config/database.php',
                'Prüfe ob die Datenbank "tom" existiert',
                'Führe das Recovery-Script aus: scripts\\ensure-mysql-running.bat'
            ]
        );
    } else {
        http_response_code(500);
        die('Database Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
