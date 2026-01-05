<?php
declare(strict_types=1);

namespace TOM\Service\Org\Core;

use PDO;
use TOM\Infrastructure\Database\DatabaseConnection;

/**
 * OrgCustomerNumberService
 * 
 * Handles customer number generation for organizations:
 * - Generate next customer number
 * - Get next customer number (without assigning)
 */
class OrgCustomerNumberService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Gibt die nächste verfügbare Kundennummer zurück (ohne sie zu vergeben)
     * 
     * @return string Numerische Kundennummer
     */
    public function getNextCustomerNumber(): string
    {
        return $this->generateCustomerNumber();
    }

    /**
     * Generiert eine neue Kundennummer basierend auf der höchsten vorhandenen Nummer
     * 
     * @return string Numerische Kundennummer
     */
    public function generateCustomerNumber(): string
    {
        // Lade Konfiguration
        $configFile = dirname(__DIR__, 3) . '/config/customer_number.php';
        if (!file_exists($configFile)) {
            // Fallback: Standard-Startnummer
            $startNumber = 100;
        } else {
            $config = require $configFile;
            $startNumber = $config['start_number'] ?? 100;
        }
        
        // Finde die höchste numerische Kundennummer
        // Nur Werte, die rein numerisch sind (ohne Präfix, Suffix, etc.)
        $stmt = $this->db->query("
            SELECT external_ref 
            FROM org 
            WHERE external_ref IS NOT NULL 
              AND external_ref REGEXP '^[0-9]+$'
            ORDER BY CAST(external_ref AS UNSIGNED) DESC 
            LIMIT 1
        ");
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['external_ref'])) {
            // Erhöhe die höchste Nummer um 1
            $nextNumber = (int)$result['external_ref'] + 1;
        } else {
            // Keine vorhandene Nummer, verwende Startnummer
            $nextNumber = $startNumber;
        }
        
        // Stelle sicher, dass die Nummer nicht kleiner als die Startnummer ist
        if ($nextNumber < $startNumber) {
            $nextNumber = $startNumber;
        }
        
        // Rückgabe als String (rein numerisch)
        return (string)$nextNumber;
    }
}


