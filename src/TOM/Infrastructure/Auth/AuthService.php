<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Auth;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;
use TOM\Infrastructure\Activity\ActivityLogService;

/**
 * AuthService - Session-basierte Authentifizierung
 * 
 * Dev-Auth: Einfache User-Auswahl für Entwicklung
 * Später austauschbar durch echte Auth (Passkeys, Passwort+MFA, SSO)
 */
class AuthService
{
    private PDO $db;
    private string $authMode;
    private string $appEnv;
    private ?ActivityLogService $activityLogService = null;
    
    public function __construct(?PDO $db = null, ?ActivityLogService $activityLogService = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
        $this->activityLogService = $activityLogService;
        $this->authMode = getenv('AUTH_MODE') ?: 'dev';
        
        // Verwende SecurityHelper für konsistente APP_ENV-Prüfung
        try {
            $this->appEnv = \TOM\Infrastructure\Security\SecurityHelper::requireAppEnv();
        } catch (\RuntimeException $e) {
            // In Production: Fail sofort
            throw new \RuntimeException('Security: APP_ENV must be set. ' . $e->getMessage());
        }
        
        // Sicherheits-Check: Dev-Auth darf nicht in Production laufen
        if ($this->appEnv === 'prod' && $this->authMode === 'dev') {
            throw new \RuntimeException('Misconfiguration: DEV auth must not run in production.');
        }
        
        $this->startSession();
    }
    
    /**
     * Startet die PHP-Session mit sicheren Cookie-Einstellungen
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Session läuft bereits, prüfe nur Timeout
            $sessionTimeout = (int)(getenv('SESSION_TIMEOUT') ?: 28800);
            $this->checkSessionTimeout($sessionTimeout);
            return;
        }
        
        // Prüfe ob Header bereits gesendet wurden (z.B. durch API-Response)
        if (headers_sent()) {
            // Header bereits gesendet - Session kann nicht gestartet werden
            // Dies passiert z.B. bei API-Aufrufen, wo JSON-Header bereits gesendet wurden
            // In diesem Fall können wir keine Session verwenden, aber das ist OK für API-Calls
            return;
        }
        
        // Cookie-Settings (lokal Secure=false, später bei HTTPS true)
        $secure = ($this->appEnv === 'prod' && isset($_SERVER['HTTPS']));
        
        // SameSite: Strict für bessere CSRF-Schutz (in Staging/Prod)
        // Lax für lokale Entwicklung (um Cross-Origin-Tests zu ermöglichen)
        $sameSite = ($this->appEnv === 'prod' || $this->appEnv === 'staging') ? 'Strict' : 'Lax';
        
        session_set_cookie_params([
            'lifetime' => 0, // Session-Cookie (bis Browser geschlossen)
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        
        // Session-Timeout: 8 Stunden Inaktivität (kann konfiguriert werden)
        $sessionTimeout = (int)(getenv('SESSION_TIMEOUT') ?: 28800); // 8 Stunden = 28800 Sekunden
        ini_set('session.gc_maxlifetime', $sessionTimeout);
        
        session_start();
        
        // Prüfe Session-Timeout bei jedem Request
        $this->checkSessionTimeout($sessionTimeout);
    }
    
    /**
     * Prüft, ob die Session abgelaufen ist (Inaktivitäts-Timeout)
     */
    private function checkSessionTimeout(int $timeoutSeconds): void
    {
        if (isset($_SESSION['last_activity'])) {
            $lastActivity = (int)$_SESSION['last_activity'];
            $now = time();
            
            // Wenn Session zu alt ist, löschen
            if (($now - $lastActivity) > $timeoutSeconds) {
                $this->logout();
                return;
            }
        }
        
        // Update last_activity bei jedem Request
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Gibt den aktuell eingeloggten User zurück
     * 
     * @return array|null User-Daten mit user_id, email, name, roles oder null wenn nicht eingeloggt
     */
    public function getCurrentUser(): ?array
    {
        // Wenn Session nicht aktiv ist (z.B. Header bereits gesendet), gibt es keinen eingeloggten User
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        
        $userId = (int)$_SESSION['user_id'];
        
        // Lade User mit Rollen
        $stmt = $this->db->prepare("
            SELECT 
                u.user_id,
                u.email,
                u.name,
                u.is_active,
                u.last_login_at
            FROM users u
            WHERE u.user_id = :user_id AND u.is_active = 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // User existiert nicht mehr oder ist inaktiv
            // Session sofort invalidiert - User wurde deaktiviert
            $this->logout();
            return null;
        }
        
        // Zusätzliche Prüfung: Wenn User während der Session deaktiviert wurde,
        // wird die Session hier invalidiert (getCurrentUser wird bei jedem Request aufgerufen)
        
        // Lade Rollen des Users
        $stmt = $this->db->prepare("
            SELECT r.role_code, r.role_name, r.description
            FROM user_role ur
            JOIN role r ON ur.role_id = r.role_id
            WHERE ur.user_id = :user_id
            ORDER BY r.role_code
        ");
        $stmt->execute(['user_id' => $userId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $user['roles'] = array_column($roles, 'role_code');
        $user['roles_detail'] = $roles;
        
        return $user;
    }
    
    /**
     * Prüft, ob der aktuelle User eine bestimmte Rolle hat
     */
    public function hasRole(string $roleCode): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        
        return in_array($roleCode, $user['roles'] ?? [], true);
    }
    
    /**
     * Prüft, ob der aktuelle User eine der angegebenen Rollen hat
     */
    public function hasAnyRole(array $roleCodes): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        
        $userRoles = $user['roles'] ?? [];
        return !empty(array_intersect($roleCodes, $userRoles));
    }
    
    /**
     * Meldet einen User an (Dev-Auth: User-ID auswählen)
     * 
     * @param int $userId User-ID
     * @return bool Erfolg
     */
    public function login(int $userId): bool
    {
        // Prüfe ob User existiert und aktiv ist
        $stmt = $this->db->prepare("
            SELECT user_id FROM users 
            WHERE user_id = :user_id AND is_active = 1
        ");
        $stmt->execute(['user_id' => $userId]);
        
        if (!$stmt->fetch()) {
            return false;
        }
        
        // Session-Fixation vermeiden: Neue Session-ID generieren
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time(); // Für Timeout-Prüfung
        
        // Update last_login_at
        $stmt = $this->db->prepare("
            UPDATE users 
            SET last_login_at = NOW() 
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        
        // Logge Login in Activity-Log
        if ($this->activityLogService) {
            $this->activityLogService->logLogin((string)$userId);
        }
        
        return true;
    }
    
    /**
     * Meldet den aktuellen User ab
     */
    public function logout(): void
    {
        // Logge Logout in Activity-Log (vor Session-Löschung)
        if ($this->activityLogService && isset($_SESSION['user_id'])) {
            $this->activityLogService->logLogout((string)$_SESSION['user_id']);
        }
        
        $_SESSION = [];
        
        // Cookie löschen
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        
        session_destroy();
    }
    
    /**
     * Erfordert, dass ein User eingeloggt ist
     * 
     * @return array User-Daten
     * @throws \Exception Wenn nicht eingeloggt
     */
    public function requireAuth(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            throw new \Exception('Authentication required');
        }
        return $user;
    }
    
    /**
     * Gibt zurück, ob Dev-Auth aktiv ist
     */
    public function isDevMode(): bool
    {
        return $this->authMode === 'dev';
    }
    
    /**
     * Gibt alle aktiven User zurück (für Login-Dropdown)
     */
    public function getActiveUsers(): array
    {
        $stmt = $this->db->query("
            SELECT 
                u.user_id,
                u.email,
                u.name,
                GROUP_CONCAT(r.role_code ORDER BY r.role_code SEPARATOR ', ') as roles
            FROM users u
            LEFT JOIN user_role ur ON u.user_id = ur.user_id
            LEFT JOIN role r ON ur.role_id = r.role_id
            WHERE u.is_active = 1
            GROUP BY u.user_id, u.email, u.name
            ORDER BY u.name
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

