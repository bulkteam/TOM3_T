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
        if ($config === null) {
            $dbConfig = require __DIR__ . '/../../../config/database.php';
            $config = $dbConfig['neo4j'] ?? null;
            
            if (!$config) {
                throw new \RuntimeException('Neo4j configuration not found');
            }
        }
        
        $this->config = $config;
        $this->client = $this->createClient();
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
        $result = $this->client->run($query, $parameters);
        
        $records = [];
        foreach ($result as $record) {
            $records[] = $record->toArray();
        }
        
        return $records;
    }
    
    /**
     * Führt eine Cypher-Query aus und gibt nur den ersten Wert zurück
     */
    public function runSingle(string $query, array $parameters = []): mixed
    {
        $result = $this->client->run($query, $parameters);
        $first = $result->first();
        
        if (!$first) {
            return null;
        }
        
        $values = $first->values();
        return $values[0] ?? null;
    }
    
    /**
     * Testet die Verbindung zu Neo4j
     */
    public function testConnection(): bool
    {
        try {
            $this->run('RETURN 1 as test');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}



