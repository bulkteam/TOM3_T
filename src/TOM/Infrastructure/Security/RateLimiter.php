<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Security;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * RateLimiter
 * 
 * Einfacher Rate-Limiter für API-Endpunkte
 * Verwendet In-Memory-Array (für Staging ausreichend)
 * Für Production: Redis oder Memcached verwenden
 */
class RateLimiter
{
    private PDO $db;
    private array $limits = [];
    private static array $memoryStore = [];
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Prüft ob Request erlaubt ist
     * 
     * @param string $key Eindeutiger Key (z.B. "login:127.0.0.1" oder "calls:user123")
     * @param int $maxRequests Maximale Anzahl Requests
     * @param int $windowSeconds Zeitfenster in Sekunden
     * @return bool True wenn erlaubt
     */
    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        // Hole Einträge aus Memory-Store
        if (!isset(self::$memoryStore[$key])) {
            self::$memoryStore[$key] = [];
        }
        
        // Entferne alte Einträge
        self::$memoryStore[$key] = array_filter(
            self::$memoryStore[$key],
            fn($timestamp) => $timestamp > $windowStart
        );
        
        // Prüfe Limit
        $count = count(self::$memoryStore[$key]);
        if ($count >= $maxRequests) {
            return false;
        }
        
        // Füge aktuellen Request hinzu
        self::$memoryStore[$key][] = $now;
        
        return true;
    }
    
    /**
     * Prüft Rate-Limit für IP-Adresse
     */
    public function checkIpLimit(string $endpoint, int $maxRequests = 10, int $windowSeconds = 60): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "{$endpoint}:ip:{$ip}";
        return $this->isAllowed($key, $maxRequests, $windowSeconds);
    }
    
    /**
     * Prüft Rate-Limit für User
     */
    public function checkUserLimit(string $endpoint, string $userId, int $maxRequests = 20, int $windowSeconds = 60): bool
    {
        $key = "{$endpoint}:user:{$userId}";
        return $this->isAllowed($key, $maxRequests, $windowSeconds);
    }
    
    /**
     * Gibt verbleibende Requests zurück
     */
    public function getRemaining(string $key, int $maxRequests, int $windowSeconds): int
    {
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        if (!isset(self::$memoryStore[$key])) {
            return $maxRequests;
        }
        
        self::$memoryStore[$key] = array_filter(
            self::$memoryStore[$key],
            fn($timestamp) => $timestamp > $windowStart
        );
        
        $count = count(self::$memoryStore[$key]);
        return max(0, $maxRequests - $count);
    }
}


