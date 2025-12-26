<?php
/**
 * TOM3 - Database Configuration
 * 
 * Passe die Werte entsprechend deiner Datenbank-Konfiguration an.
 */

return [
    'mysql' => [
        'host' => $_ENV['MYSQL_HOST'] ?? 'localhost',
        'port' => (int)($_ENV['MYSQL_PORT'] ?? 3306),
        'dbname' => $_ENV['MYSQL_DBNAME'] ?? 'tom',
        'user' => $_ENV['MYSQL_USER'] ?? 'tomcat',
        'password' => $_ENV['MYSQL_PASSWORD'] ?? 'tim@2025!',
        'charset' => 'utf8mb4'
    ],
    'neo4j' => [
        'uri' => 'neo4j+s://e9aaec11.databases.neo4j.io',
        'user' => 'neo4j',
        'password' => 'QYryAhc8LdXx_Sz0G0dWNqXTDfstbEW9_vk21A_7NVk'
    ]
];




