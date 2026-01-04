<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Auth;

/**
 * AuthHelper - Helper-Funktionen für Authentifizierung
 * 
 * Zentrale Funktionen zum Abrufen des aktuellen Users
 */
class AuthHelper
{
    private static ?AuthService $authService = null;
    
    /**
     * Gibt den AuthService zurück (Singleton)
     */
    private static function getAuthService(): AuthService
    {
        if (self::$authService === null) {
            // ActivityLogService optional - wenn nicht vorhanden, wird kein Activity-Log erstellt
            $activityLogService = new \TOM\Infrastructure\Activity\ActivityLogService();
            self::$authService = new AuthService(null, $activityLogService);
        }
        return self::$authService;
    }
    
    /**
     * Gibt den aktuell eingeloggten User zurück
     * 
     * @return array|null User-Daten oder null wenn nicht eingeloggt
     */
    public static function getCurrentUser(): ?array
    {
        return self::getAuthService()->getCurrentUser();
    }
    
    /**
     * Gibt die User-ID des aktuell eingeloggten Users zurück
     * 
     * @param bool $allowFallback Erlaubt 'default_user' Fallback nur in Dev-Mode
     * @return string User-ID
     * @throws \RuntimeException Wenn kein User eingeloggt und Fallback nicht erlaubt
     */
    public static function getCurrentUserId(bool $allowFallback = false): string
    {
        $user = self::getCurrentUser();
        if ($user) {
            return (string)$user['user_id'];
        }
        
        // Fallback nur in Dev-Mode erlauben
        if ($allowFallback && self::isDevMode()) {
            return 'default_user';
        }
        
        throw new \RuntimeException('Authentication required: No user logged in');
    }
    
    /**
     * Prüft ob wir in Dev-Mode sind
     * 
     * @return bool True wenn Dev-Mode
     */
    private static function isDevMode(): bool
    {
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
        return in_array($appEnv, ['local', 'dev', 'development']);
    }
    
    /**
     * Prüft, ob der aktuelle User eine bestimmte Rolle hat
     */
    public static function hasRole(string $roleCode): bool
    {
        return self::getAuthService()->hasRole($roleCode);
    }
    
    /**
     * Prüft, ob der aktuelle User eine der angegebenen Rollen hat
     */
    public static function hasAnyRole(array $roleCodes): bool
    {
        return self::getAuthService()->hasAnyRole($roleCodes);
    }
}





