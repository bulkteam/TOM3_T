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
     * @return string|null User-ID oder 'default_user' wenn nicht eingeloggt (für Kompatibilität)
     */
    public static function getCurrentUserId(): string
    {
        $user = self::getCurrentUser();
        if ($user) {
            return (string)$user['user_id'];
        }
        // Fallback für Kompatibilität mit bestehendem Code
        return 'default_user';
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



