<?php
declare(strict_types=1);

namespace TOM\Infrastructure\Neo4j;

use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;

/**
 * Neo4jService - Verbindung zu Neo4j
 */
class Neo4jService
{
    private ClientInterface $client;
    private array $config;
    
    public function __construct(?array $config = null)
    {
        // Unterdrücke Deprecation-Warnungen von laudis/neo4j-php-client (PHP 8.1+ Kompatibilität)
        // Dies verhindert Fatal Errors bei PHP 8.1+ wegen veralteter Return-Type-Deklarationen
        $oldErrorReporting = error_reporting();
        error_reporting($oldErrorReporting & ~E_DEPRECATED);
        
        try {
            if ($config === null) {
                // Versuche verschiedene Pfade für database.php
                // Von src/TOM/Infrastructure/Neo4j/ -> config/ (4 Ebenen hoch)
                $possiblePaths = [
                    __DIR__ . '/../../../../config/database.php',  // Von Neo4j/ -> config/
                    dirname(__DIR__, 4) . '/config/database.php',  // Alternative
                    getcwd() . '/config/database.php',  // Vom aktuellen Arbeitsverzeichnis
                ];
                
                // Füge Document Root Pfade hinzu (nur wenn gesetzt)
                if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
                    $docRoot = $_SERVER['DOCUMENT_ROOT'];
                    $possiblePaths[] = $docRoot . '/TOM3/config/database.php';
                    $possiblePaths[] = $docRoot . '/tom3/config/database.php';
                    // Prüfe auch ohne TOM3 (falls direkt im htdocs)
                    $possiblePaths[] = $docRoot . '/config/database.php';
                }
                
                // Fallback: 3 Ebenen (von Infrastructure/)
                $possiblePaths[] = dirname(__DIR__, 3) . '/config/database.php';
                
                $dbConfig = null;
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $dbConfig = require $path;
                        break;
                    }
                }
                
                if (!$dbConfig) {
                    throw new \RuntimeException('Neo4j configuration not found. Tried: ' . implode(', ', $possiblePaths));
                }
                
                $config = $dbConfig['neo4j'] ?? null;
                
                if (!$config) {
                    throw new \RuntimeException('Neo4j configuration not found in database.php');
                }
            }
            
            $this->config = $config;
            $this->client = $this->createClient();
        } finally {
            // Stelle error_reporting wieder her
            error_reporting($oldErrorReporting);
        }
    }
    
    private function createClient(): ClientInterface
    {
        $uri = $this->config['uri'];
        
        // Erstelle vollständige URI mit Credentials für Remote-Verbindungen
        if (strpos($uri, '@') === false) {
            preg_match('/^([a-z+]+:\/\/)/', $uri, $matches);
            $schema = $matches[1] ?? 'neo4j+s://';
            $host = str_replace($schema, '', $uri);
            $uri = $schema . $this->config['user'] . ':' . $this->config['password'] . '@' . $host;
        }
        
        // Bestimme Driver-Typ basierend auf URI-Schema
        $driverType = 'default';
        if (strpos($uri, 'bolt://') === 0 || strpos($uri, 'bolt+s://') === 0) {
            $driverType = 'bolt';
        } elseif (strpos($uri, 'neo4j://') === 0 || strpos($uri, 'neo4j+s://') === 0) {
            $driverType = 'neo4j';
        }
        
        return ClientBuilder::create()
            ->withDriver($driverType, $uri)
            ->build();
    }
    
    public function getClient(): ClientInterface
    {
        return $this->client;
    }
    
    /**
     * Führt eine Cypher-Query aus
     */
    public function run(string $query, array $parameters = []): array
    {
        // Unterdrücke Deprecation-Warnungen von laudis/neo4j-php-client (PHP 8.1+ Kompatibilität)
        $oldErrorReporting = error_reporting();
        error_reporting($oldErrorReporting & ~E_DEPRECATED);
        
        try {
            $result = $this->client->run($query, $parameters);
            
            $records = [];
            foreach ($result as $record) {
                $records[] = $record->toArray();
            }
            
            return $records;
        } finally {
            error_reporting($oldErrorReporting);
        }
    }
    
    /**
     * Führt eine Cypher-Query aus und gibt nur den ersten Wert zurück
     */
    public function runSingle(string $query, array $parameters = []): mixed
    {
        // Unterdrücke Deprecation-Warnungen von laudis/neo4j-php-client (PHP 8.1+ Kompatibilität)
        $oldErrorReporting = error_reporting();
        error_reporting($oldErrorReporting & ~E_DEPRECATED);
        
        try {
            $result = $this->client->run($query, $parameters);
            $first = $result->first();
            
            if (!$first) {
                return null;
            }
            
            $values = $first->values();
            return $values[0] ?? null;
        } finally {
            error_reporting($oldErrorReporting);
        }
    }
    
    /**
     * Testet die Verbindung zu Neo4j
     */
    public function testConnection(): bool
    {
        try {
            // Unterdrücke Deprecation-Warnungen von laudis/neo4j-php-client (PHP 8.1+ Kompatibilität)
            $oldErrorReporting = error_reporting();
            error_reporting($oldErrorReporting & ~E_DEPRECATED);
            
            try {
                $this->run('RETURN 1 as test');
                $result = true;
            } finally {
                error_reporting($oldErrorReporting);
            }
            
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }
}





