<?php
/**
 * TOM3 - List Industries
 * Zeigt alle Industries in der Datenbank an
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

echo "=== TOM3: Industry-Übersicht ===\n\n";

try {
    $db = DatabaseConnection::getInstance();
    
    // Hauptklassen (ohne parent)
    echo "=== HAUPTKLASSEN (parent_industry_uuid IS NULL) ===\n";
    $stmt = $db->query("
        SELECT industry_uuid, name, code 
        FROM industry 
        WHERE parent_industry_uuid IS NULL
        ORDER BY code, name
    ");
    $mainClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($mainClasses)) {
        echo "Keine Hauptklassen gefunden.\n\n";
    } else {
        foreach ($mainClasses as $ind) {
            echo sprintf("  %-5s | %s\n", $ind['code'] ?? 'NULL', $ind['name']);
        }
        echo "\nGesamt: " . count($mainClasses) . " Hauptklassen\n\n";
    }
    
    // Unterklassen (mit parent)
    echo "=== UNTERKLASSEN (mit parent_industry_uuid) ===\n";
    $stmt = $db->query("
        SELECT 
            i.industry_uuid, 
            i.name, 
            i.code,
            i.parent_industry_uuid,
            p.name as parent_name,
            p.code as parent_code
        FROM industry i
        LEFT JOIN industry p ON i.parent_industry_uuid = p.industry_uuid
        WHERE i.parent_industry_uuid IS NOT NULL
        ORDER BY p.code, i.code, i.name
    ");
    $subClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($subClasses)) {
        echo "Keine Unterklassen gefunden.\n\n";
    } else {
        $currentParent = null;
        foreach ($subClasses as $ind) {
            if ($currentParent !== $ind['parent_code']) {
                if ($currentParent !== null) {
                    echo "\n";
                }
                echo "  Unter {$ind['parent_code']} - {$ind['parent_name']}:\n";
                $currentParent = $ind['parent_code'];
            }
            echo sprintf("    %-5s | %s\n", $ind['code'] ?? 'NULL', $ind['name']);
        }
        echo "\nGesamt: " . count($subClasses) . " Unterklassen\n\n";
    }
    
    // Statistiken
    echo "=== STATISTIKEN ===\n";
    $stmt = $db->query("SELECT COUNT(*) as total FROM industry");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    echo "Gesamt Industries: $total\n";
    echo "Hauptklassen: " . count($mainClasses) . "\n";
    echo "Unterklassen: " . count($subClasses) . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



