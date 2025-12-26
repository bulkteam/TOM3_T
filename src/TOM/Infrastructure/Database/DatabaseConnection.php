<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Database;

use PDO;

class DatabaseConnection
{
    private static ?PDO $instance = null;
    
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $configFile = __DIR__ . '/../../../../config/database.php';
            if (!file_exists($configFile)) {
                throw new \RuntimeException("Database config not found: $configFile");
            }
            
            $config = require $configFile;
            $dbConfig = $config['mysql'] ?? [];
            
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'] ?? 'localhost',
                $dbConfig['port'] ?? 3306,
                $dbConfig['dbname'] ?? 'tom',
                $dbConfig['charset'] ?? 'utf8mb4'
            );
            
            self::$instance = new PDO(
                $dsn,
                $dbConfig['user'] ?? 'tom',
                $dbConfig['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        }
        
        return self::$instance;
    }
}


