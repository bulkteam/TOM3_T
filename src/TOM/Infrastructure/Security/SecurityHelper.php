<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Security;

use TOM\Infrastructure\Auth\AuthHelper;

/**
 * SecurityHelper
 * 
 * Zentrale Sicherheits-Funktionen:
 * - APP_ENV Validierung
 * - Auth-Zwang
 * - CSRF-Schutz
 */
class SecurityHelper
{
    /**
     * Prüft APP_ENV und wirft Exception in Production wenn nicht gesetzt
     * 
     * @return string APP_ENV Wert
     * @throws \RuntimeException Wenn APP_ENV in Production fehlt
     */
    public static function requireAppEnv(): string
    {
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        
        // In Production: APP_ENV MUSS gesetzt sein (fail-closed)
        if (empty($appEnv)) {
            // Prüfe ob wir in Production sind (z.B. über DOCUMENT_ROOT oder andere Indikatoren)
            $isProduction = self::isProductionEnvironment();
            
            if ($isProduction) {
                throw new \RuntimeException(
                    'Security: APP_ENV must be explicitly set in production. ' .
                    'Set APP_ENV=production in your environment configuration.'
                );
            }
            
            // In Dev: Default auf 'local'
            $appEnv = 'local';
            $_ENV['APP_ENV'] = $appEnv;
        }
        
        return $appEnv;
    }
    
    /**
     * Prüft ob wir in einer Production-Umgebung sind
     * 
     * @return bool True wenn Production
     */
    private static function isProductionEnvironment(): bool
    {
        // Prüfe verschiedene Indikatoren für Production
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        
        // Heuristik: Wenn nicht localhost/127.0.0.1 und kein .local/.dev TLD, dann wahrscheinlich Production
        $isLocalhost = in_array($serverName, ['localhost', '127.0.0.1', '::1']);
        $isDevDomain = preg_match('/\.(local|dev|test)$/', $serverName);
        
        return !$isLocalhost && !$isDevDomain;
    }
    
    /**
     * Prüft ob APP_ENV ein Development-Mode ist
     * 
     * @return bool True wenn Dev-Mode
     */
    public static function isDevMode(): bool
    {
        $appEnv = self::requireAppEnv();
        return in_array($appEnv, ['local', 'dev', 'development']);
    }
    
    /**
     * Prüft ob APP_ENV Production ist
     * 
     * @return bool True wenn Production
     */
    public static function isProduction(): bool
    {
        $appEnv = self::requireAppEnv();
        return in_array($appEnv, ['prod', 'production']);
    }
}


