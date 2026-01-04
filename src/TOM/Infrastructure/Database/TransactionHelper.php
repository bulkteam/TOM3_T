<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Database;

use PDO;

/**
 * TransactionHelper
 * 
 * Hilfsfunktionen für Datenbank-Transaktionen
 * Stellt sicher, dass Transaktionen korrekt behandelt werden (Commit/Rollback)
 */
class TransactionHelper
{
    /**
     * Führt eine Callback-Funktion in einer Transaktion aus
     * 
     * @param PDO $db Datenbankverbindung
     * @param callable $callback Callback-Funktion, die in der Transaktion ausgeführt wird
     * @return mixed Rückgabewert der Callback-Funktion
     * @throws \Exception Wenn ein Fehler auftritt (Transaktion wird zurückgerollt)
     */
    public static function executeInTransaction(PDO $db, callable $callback)
    {
        // Prüfe ob bereits eine Transaktion läuft
        $alreadyInTransaction = $db->inTransaction();
        
        if (!$alreadyInTransaction) {
            $db->beginTransaction();
        }
        
        try {
            $result = $callback($db);
            
            if (!$alreadyInTransaction) {
                $db->commit();
            }
            
            return $result;
        } catch (\Exception $e) {
            if (!$alreadyInTransaction) {
                $db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Führt mehrere Callback-Funktionen in einer Transaktion aus
     * 
     * @param PDO $db Datenbankverbindung
     * @param array $callbacks Array von Callback-Funktionen
     * @return array Array von Rückgabewerten
     * @throws \Exception Wenn ein Fehler auftritt (Transaktion wird zurückgerollt)
     */
    public static function executeMultipleInTransaction(PDO $db, array $callbacks): array
    {
        return self::executeInTransaction($db, function($db) use ($callbacks) {
            $results = [];
            foreach ($callbacks as $callback) {
                $results[] = $callback($db);
            }
            return $results;
        });
    }
    
    /**
     * Prüft ob eine Transaktion aktiv ist
     * 
     * @param PDO $db Datenbankverbindung
     * @return bool True wenn Transaktion aktiv ist
     */
    public static function isInTransaction(PDO $db): bool
    {
        return $db->inTransaction();
    }
}

