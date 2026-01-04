<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Security;

/**
 * CsrfTokenService
 * 
 * Handles CSRF token generation and validation
 */
class CsrfTokenService
{
    /**
     * Generiert einen CSRF-Token und speichert ihn in der Session
     * 
     * @return string CSRF-Token
     */
    public static function generateToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Holt den CSRF-Token aus der Session
     * 
     * @return string|null CSRF-Token oder null
     */
    public static function getToken(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        
        return $_SESSION['csrf_token'] ?? null;
    }
    
    /**
     * Validiert einen CSRF-Token
     * 
     * @param string $providedToken Token vom Client
     * @return bool True wenn Token gültig
     */
    public static function validateToken(string $providedToken): bool
    {
        $sessionToken = self::getToken();
        
        if (empty($sessionToken)) {
            return false;
        }
        
        // Timing-safe Vergleich
        return hash_equals($sessionToken, $providedToken);
    }
    
    /**
     * Prüft CSRF-Token für state-changing Requests
     * 
     * @param string $method HTTP-Methode
     * @param string|null $providedToken Token vom Client (aus Header oder POST)
     * @return bool True wenn gültig oder nicht erforderlich
     * @throws \RuntimeException Wenn Token ungültig
     */
    public static function requireValidToken(string $method, ?string $providedToken = null): bool
    {
        // Nur für state-changing Requests prüfen
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return true;
        }
        
        // In Dev-Mode: CSRF optional (für einfacheres Testen)
        if (SecurityHelper::isDevMode()) {
            if ($providedToken && !self::validateToken($providedToken)) {
                throw new \RuntimeException('Invalid CSRF token');
            }
            return true;
        }
        
        // In Production: Strikte CSRF-Prüfung
        if (empty($providedToken)) {
            throw new \RuntimeException('CSRF token required');
        }
        
        if (!self::validateToken($providedToken)) {
            throw new \RuntimeException('Invalid CSRF token');
        }
        
        return true;
    }
}

