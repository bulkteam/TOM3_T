<?php
/**
 * TOM3 - Simple .env Loader
 * 
 * Lädt Umgebungsvariablen aus .env Datei (falls vorhanden)
 * Wird automatisch von config/database.php verwendet
 */

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $envPath): void
    {
        if (!file_exists($envPath)) {
            return; // .env Datei existiert nicht
        }
        
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Ignoriere Kommentare
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE
            if (strpos($line, '=') === false) {
                continue;
            }
            
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Entferne Anführungszeichen
            if ((strpos($value, '"') === 0 && substr($value, -1) === '"') ||
                (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Setze nur wenn noch nicht als System-ENV gesetzt
            if (!isset($_ENV[$key]) && empty(getenv($key))) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Lade .env Datei aus Projektroot
$projectRoot = dirname(__DIR__);
$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';

loadEnvFile($envFile);


