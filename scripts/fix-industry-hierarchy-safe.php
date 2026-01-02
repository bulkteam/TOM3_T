<?php
/**
 * Korrigiert die Branchen-Hierarchie (sicher mit Prüfungen):
 * Unterklassen, die fälschlicherweise als Hauptklassen gespeichert sind,
 * werden den richtigen Hauptklassen zugeordnet.
 * 
 * Usage: php fix-industry-hierarchy-safe.php [--dry-run] [--execute]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

$dryRun = in_array('--dry-run', $argv) || !in_array('--execute', $argv);

echo "=== Branchen-Hierarchie korrigieren ===\n";
echo ($dryRun ? "🔍 DRY-RUN Modus (keine Änderungen)\n" : "⚙️  EXECUTE Modus (Änderungen werden durchgeführt)\n");
echo "\n";

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

echo "Gefundene Hauptklassen: " . count($mainClasses) . "\n";

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

echo "Gefundene Unterklassen (falsch als Hauptklasse): " . count($subClassesAsMain) . "\n\n";

// 3. Prüfe, ob diese Branchen bereits verwendet werden (nur org Tabelle)
try {
    $stmt = $db->query("
        SELECT 
            i.industry_uuid,
            i.name,
            i.code,
            COUNT(DISTINCT o.org_uuid) as org_count
        FROM industry i
        LEFT JOIN org o ON o.industry_main_uuid = i.industry_uuid OR o.industry_sub_uuid = i.industry_uuid
        WHERE i.parent_industry_uuid IS NULL 
        AND LENGTH(i.code) > 1
        AND i.code REGEXP '^[A-Z][0-9]+'
        GROUP BY i.industry_uuid, i.name, i.code
        ORDER BY i.code, i.name
    ");
    
    $usageCheck = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasUsage = false;
    foreach ($usageCheck as $check) {
        if ($check['org_count'] > 0) {
            $hasUsage = true;
            echo sprintf("⚠️  %s (%s) wird bereits verwendet: %d Organisationen\n", 
                $check['name'], 
                $check['code'],
                $check['org_count']
            );
        }
    }
    
    if ($hasUsage) {
        echo "\n⚠️  WARNUNG: Einige Branchen werden bereits verwendet!\n";
        echo "Die Zuordnung wird trotzdem durchgeführt, da nur parent_industry_uuid gesetzt wird.\n";
        echo "Die Verwendung in org.industry_main_uuid / industry_sub_uuid bleibt erhalten.\n\n";
    }
} catch (Exception $e) {
    // Tabelle existiert möglicherweise nicht, ignoriere
    echo "Hinweis: Verwendungsprüfung übersprungen.\n\n";
}

// 4. Ordne Unterklassen den Hauptklassen zu
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

// Gruppiere nach Code für bessere Übersicht
$grouped = [];
foreach ($updates as $update) {
    $key = $update['sub_code'] . '|' . $update['sub_name'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'sub_code' => $update['sub_code'],
            'sub_name' => $update['sub_name'],
            'main' => $update,
            'count' => 0
        ];
    }
    $grouped[$key]['count']++;
}

echo "=== Zuordnungen (gruppiert):\n";
foreach ($grouped as $group) {
    echo sprintf("  %s (%s) → %s (%s) [%d Einträge]\n", 
        $group['sub_name'], 
        $group['sub_code'],
        $group['main']['main_name'],
        $group['main']['main_code'],
        $group['count']
    );
}

if (!empty($errors)) {
    echo "\n\n=== Fehler:\n";
    foreach ($errors as $error) {
        echo "  ⚠️  $error\n";
    }
}

// 5. Führe Updates durch (wenn nicht dry-run)
if ($dryRun) {
    echo "\n\n=== DRY-RUN: Keine Änderungen durchgeführt ===\n";
    echo "Um die Änderungen durchzuführen, verwenden Sie: php fix-industry-hierarchy-safe.php --execute\n";
    exit(0);
}

echo "\n\n=== Führe Updates durch...\n";

$db->beginTransaction();

try {
    $updateStmt = $db->prepare("
        UPDATE industry 
        SET parent_industry_uuid = :parent_uuid 
        WHERE industry_uuid = :uuid
    ");
    
    $updated = 0;
    $skipped = 0;
    
    foreach ($updates as $update) {
        // Prüfe, ob bereits korrekt zugeordnet
        $checkStmt = $db->prepare("
            SELECT parent_industry_uuid 
            FROM industry 
            WHERE industry_uuid = :uuid
        ");
        $checkStmt->execute(['uuid' => $update['sub_uuid']]);
        $current = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current && $current['parent_industry_uuid'] === $update['main_uuid']) {
            $skipped++;
            continue; // Bereits korrekt
        }
        
        $updateStmt->execute([
            'parent_uuid' => $update['main_uuid'],
            'uuid' => $update['sub_uuid']
        ]);
        
        if ($updateStmt->rowCount() > 0) {
            $updated++;
        }
    }
    
    $db->commit();
    
    echo "\n=== Erfolgreich abgeschlossen ===\n";
    echo "Aktualisiert: $updated Branchen\n";
    if ($skipped > 0) {
        echo "Übersprungen (bereits korrekt): $skipped Branchen\n";
    }
    
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
