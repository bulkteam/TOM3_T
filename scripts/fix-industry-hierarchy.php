<?php
/**
 * Korrigiert die Branchen-Hierarchie:
 * Unterklassen, die fälschlicherweise als Hauptklassen gespeichert sind,
 * werden den richtigen Hauptklassen zugeordnet.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "=== Branchen-Hierarchie korrigieren ===\n\n";

// 1. Finde alle Hauptklassen (Code: A, B, C, etc.) - eindeutig
$stmt = $db->query("
    SELECT industry_uuid, name, code
    FROM industry 
    WHERE parent_industry_uuid IS NULL 
    AND LENGTH(code) = 1 
    AND code REGEXP '^[A-Z]$'
    GROUP BY code, name
    ORDER BY code
");

$mainClasses = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $code = $row['code'];
    // Bei Duplikaten: Nimm die erste (kann später bereinigt werden)
    if (!isset($mainClasses[$code])) {
        $mainClasses[$code] = $row;
    }
}

echo "Gefundene Hauptklassen:\n";
foreach ($mainClasses as $code => $main) {
    echo sprintf("  %s → %s (UUID: %s)\n", $code, $main['name'], substr($main['industry_uuid'], 0, 8));
}

// 2. Finde alle Unterklassen, die als Hauptklassen gespeichert sind
$stmt = $db->query("
    SELECT industry_uuid, name, code
    FROM industry 
    WHERE parent_industry_uuid IS NULL 
    AND LENGTH(code) > 1
    AND code REGEXP '^[A-Z][0-9]+'
    ORDER BY code, name
");

$subClassesAsMain = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n\nGefundene Unterklassen (falsch als Hauptklasse):\n";
foreach ($subClassesAsMain as $sub) {
    echo sprintf("  %-5s | %s (UUID: %s)\n", $sub['code'], $sub['name'], substr($sub['industry_uuid'], 0, 8));
}

// 3. Ordne Unterklassen den Hauptklassen zu
$updates = [];
$errors = [];

foreach ($subClassesAsMain as $sub) {
    $subCode = $sub['code'];
    $mainCode = substr($subCode, 0, 1); // Erster Buchstabe (C10 → C)
    
    if (!isset($mainClasses[$mainCode])) {
        $errors[] = sprintf("Keine Hauptklasse gefunden für %s (Code: %s)", $sub['name'], $subCode);
        continue;
    }
    
    $mainClass = $mainClasses[$mainCode];
    
    $updates[] = [
        'sub_uuid' => $sub['industry_uuid'],
        'sub_name' => $sub['name'],
        'sub_code' => $subCode,
        'main_uuid' => $mainClass['industry_uuid'],
        'main_name' => $mainClass['name'],
        'main_code' => $mainCode
    ];
}

echo "\n\n=== Zuordnungen:\n";
foreach ($updates as $update) {
    echo sprintf("  %s (%s) → %s (%s)\n", 
        $update['sub_name'], 
        $update['sub_code'],
        $update['main_name'],
        $update['main_code']
    );
}

if (!empty($errors)) {
    echo "\n\n=== Fehler:\n";
    foreach ($errors as $error) {
        echo "  ⚠️  $error\n";
    }
}

// 4. Bestätigung
echo "\n\n=== Bereit zum Update ===\n";
echo "Anzahl Updates: " . count($updates) . "\n";
echo "\nMöchten Sie die Updates durchführen? (j/n): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'j' && strtolower($line) !== 'y' && strtolower($line) !== 'ja' && strtolower($line) !== 'yes') {
    echo "Abgebrochen.\n";
    exit(0);
}

// 5. Führe Updates durch
echo "\n=== Führe Updates durch...\n";

$db->beginTransaction();

try {
    $updateStmt = $db->prepare("
        UPDATE industry 
        SET parent_industry_uuid = :parent_uuid 
        WHERE industry_uuid = :uuid
    ");
    
    $updated = 0;
    foreach ($updates as $update) {
        $updateStmt->execute([
            'parent_uuid' => $update['main_uuid'],
            'uuid' => $update['sub_uuid']
        ]);
        
        if ($updateStmt->rowCount() > 0) {
            $updated++;
            echo sprintf("  ✓ %s → %s\n", $update['sub_name'], $update['main_name']);
        }
    }
    
    $db->commit();
    
    echo "\n=== Erfolgreich abgeschlossen ===\n";
    echo "Aktualisiert: $updated von " . count($updates) . " Branchen\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Fehler: " . $e->getMessage() . "\n";
    echo "Rollback durchgeführt.\n";
    exit(1);
}

// 6. Verifiziere Ergebnis
echo "\n=== Verifikation ===\n";

$stmt = $db->query("
    SELECT COUNT(*) as count
    FROM industry 
    WHERE parent_industry_uuid IS NULL 
    AND LENGTH(code) > 1
    AND code REGEXP '^[A-Z][0-9]+'
");

$remaining = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

if ($remaining == 0) {
    echo "✓ Alle Unterklassen wurden korrekt zugeordnet!\n";
} else {
    echo "⚠️  Es verbleiben noch $remaining falsch gespeicherte Unterklassen.\n";
}
