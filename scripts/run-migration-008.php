<?php
/**
 * TOM3 - Run Migration 008: Industry Hierarchy
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3 Migration 008: Industry Hierarchy ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    $sqlFile = __DIR__ . '/../database/migrations/008_org_industry_hierarchy_mysql.sql';
    if (!file_exists($sqlFile)) {
        die("ERROR: Migration file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Entferne Kommentare und teile in einzelne Statements
    $statements = [];
    $currentStatement = '';
    $inComment = false;
    
    foreach (explode("\n", $sql) as $line) {
        $line = trim($line);
        
        // Überspringe leere Zeilen und Kommentare
        if (empty($line) || preg_match('/^--/', $line)) {
            continue;
        }
        
        // Überspringe Block-Kommentare
        if (preg_match('/^\/\*/', $line)) {
            $inComment = true;
            continue;
        }
        if (preg_match('/\*\/$/', $line)) {
            $inComment = false;
            continue;
        }
        if ($inComment) {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Wenn die Zeile mit ; endet, ist das Statement vollständig
        if (substr(rtrim($line), -1) === ';') {
            $stmt = trim($currentStatement);
            if (!empty($stmt)) {
                $statements[] = $stmt;
            }
            $currentStatement = '';
        }
    }
    
    // Führe alle Statements aus
    foreach ($statements as $index => $stmt) {
        try {
            echo "Executing statement " . ($index + 1) . "...\n";
            $db->exec($stmt);
            echo "✓ OK\n\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "⚠ Already exists, skipping...\n\n";
            } else {
                echo "✗ ERROR: " . $e->getMessage() . "\n\n";
                throw $e;
            }
        }
    }
    
    echo "=== Migration erfolgreich abgeschlossen ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



