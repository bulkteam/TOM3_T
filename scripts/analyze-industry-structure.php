<?php
/**
 * Analysiert die Struktur der Branchen: Hauptklassen vs. Unterklassen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Analyse: Hauptbranchen-Struktur ===\n\n";

// Alle Hauptbranchen (parent_industry_uuid IS NULL)
$stmt = $db->query("
    SELECT industry_uuid, name, code
    FROM industry 
    WHERE parent_industry_uuid IS NULL 
    ORDER BY code, name
");

$mainIndustries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mainClasses = [];      // WZ 2008 Hauptklassen (Code: A, B, C, etc.)
$subClassesAsMain = []; // Unterklassen, die fälschlicherweise als Hauptklassen gespeichert sind

foreach ($mainIndustries as $industry) {
    $code = $industry['code'] ?? '';
    
    // WZ 2008 Hauptklassen haben 1-stellige Codes (A, B, C, etc.)
    // Unterklassen haben mehrstellige Codes (C10, C20, C28, etc.)
    if (strlen($code) == 1 && preg_match('/^[A-Z]$/', $code)) {
        $mainClasses[] = $industry;
    } else if (strlen($code) > 1) {
        $subClassesAsMain[] = $industry;
    }
}

echo "=== WZ 2008 HAUPTKLASSEN (mit Buchstaben im Namen) ===\n";
echo "Diese sind die offiziellen Hauptklassen der WZ 2008 Klassifikation.\n";
echo "Code-Format: Einzelbuchstabe (A, B, C, etc.)\n\n";

foreach ($mainClasses as $industry) {
    echo sprintf("  %-5s | %s\n", $industry['code'], $industry['name']);
}

echo "\n\n=== UNTERKLASSEN (ohne Buchstaben im Namen, aber als Hauptklasse gespeichert) ===\n";
echo "Diese sind eigentlich Unterklassen der WZ 2008, wurden aber als Hauptklassen angelegt.\n";
echo "Code-Format: Mehrstellig (C10, C20, C28, H49, etc.)\n\n";

foreach ($subClassesAsMain as $industry) {
    echo sprintf("  %-5s | %s\n", $industry['code'], $industry['name']);
}

echo "\n\n=== Zusammenfassung ===\n";
echo "WZ 2008 Hauptklassen: " . count($mainClasses) . "\n";
echo "Unterklassen (falsch als Hauptklasse): " . count($subClassesAsMain) . "\n";

// Prüfe, ob diese Unterklassen tatsächlich eine Parent-Hauptklasse haben sollten
echo "\n=== Empfehlung ===\n";
echo "Die Unterklassen sollten eigentlich einer Hauptklasse zugeordnet sein:\n";
echo "  - C10, C20, C21, C28 → sollten unter 'C - Verarbeitendes Gewerbe' sein\n";
echo "  - H49 → sollte unter 'H - Verkehr und Lagerei' sein\n";
echo "\nDiese sollten als Subbranchen (parent_industry_uuid gesetzt) gespeichert werden.\n";
