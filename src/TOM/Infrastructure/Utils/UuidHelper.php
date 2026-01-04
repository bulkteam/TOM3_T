<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Utils;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * Zentrale UUID-Helper-Klasse für TOM3
 * 
 * Stellt sicher, dass UUIDs konsistent für MariaDB und Neo4j generiert werden.
 * MariaDB verwendet MySQL UUID() Funktion, die RFC 4122 konforme UUIDs erzeugt.
 */
class UuidHelper
{
    private static ?PDO $db = null;
    
    /**
     * Generiert eine neue UUID
     * 
     * @param PDO|null $db Optional: PDO-Instanz (für Tests)
     * @return string UUID im Format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
     */
    public static function generate(?PDO $db = null): string
    {
        $database = $db ?? self::getDatabase();
        
        $stmt = $database->query("SELECT UUID() as uuid");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || empty($result['uuid'])) {
            throw new \RuntimeException("Failed to generate UUID");
        }
        
        return $result['uuid'];
    }
    
    /**
     * Validiert eine UUID
     * 
     * @param string $uuid Die zu validierende UUID
     * @return bool True wenn gültig
     */
    public static function isValid(string $uuid): bool
    {
        // RFC 4122 Format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }
    
    /**
     * Normalisiert eine UUID (entfernt Leerzeichen, konvertiert zu Lowercase)
     * 
     * @param string $uuid Die zu normalisierende UUID
     * @return string Normalisierte UUID
     */
    public static function normalize(string $uuid): string
    {
        return strtolower(trim($uuid));
    }
    
    private static function getDatabase(): PDO
    {
        if (self::$db === null) {
            self::$db = DatabaseConnection::getInstance();
        }
        return self::$db;
    }
    
    /**
     * Setzt die Datenbank-Instanz (für Tests)
     * 
     * @param PDO|null $db
     */
    public static function setDatabase(?PDO $db): void
    {
        self::$db = $db;
    }
}





