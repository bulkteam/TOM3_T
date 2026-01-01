<?php
/**
 * TOM3 - ClamAV Definitionen Update
 * 
 * Aktualisiert die ClamAV Virendefinitionen manuell
 * 
 * Usage:
 *   php scripts/update-clamav-definitions.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

echo "=== TOM3 ClamAV Definitionen Update ===\n\n";

$containerName = getenv('CLAMAV_CONTAINER') ?: 'tom3-clamav';

// Prüfe ob Container läuft
$checkCommand = sprintf('docker ps --filter "name=%s" --format "{{.Names}}"', escapeshellarg($containerName));
exec($checkCommand, $checkOutput, $checkReturnCode);

if ($checkReturnCode !== 0 || empty($checkOutput)) {
    echo "✗ FEHLER: ClamAV Container '$containerName' läuft nicht oder wurde nicht gefunden\n";
    echo "   Bitte starte den Container mit: docker start $containerName\n";
    exit(1);
}

echo "✓ Container '$containerName' läuft\n\n";

// Führe freshclam aus
echo "Starte FreshClam Update...\n";
$updateCommand = sprintf('docker exec %s freshclam 2>&1', escapeshellarg($containerName));
exec($updateCommand, $updateOutput, $updateReturnCode);

if ($updateReturnCode === 0) {
    echo "✓ Update erfolgreich!\n\n";
    echo "Output:\n";
    echo implode("\n", $updateOutput) . "\n";
} else {
    echo "⚠ Update mit Warnungen/Fehlern beendet (Return Code: $updateReturnCode)\n\n";
    echo "Output:\n";
    echo implode("\n", $updateOutput) . "\n";
}

// Prüfe neuen Status
echo "\n=== Neuer Status ===\n";
$statusCommand = sprintf('docker exec %s stat -c %%Y /var/lib/clamav/main.cvd 2>&1', escapeshellarg($containerName));
exec($statusCommand, $statusOutput, $statusReturnCode);

if ($statusReturnCode === 0 && !empty($statusOutput)) {
    $timestamp = (int)trim($statusOutput[0]);
    if ($timestamp > 0) {
        $ageHours = (time() - $timestamp) / 3600;
        $ageDays = round($ageHours / 24, 1);
        
        echo "Letztes Update: " . date('Y-m-d H:i:s', $timestamp) . "\n";
        echo "Alter: " . round($ageHours, 1) . " Stunden (" . $ageDays . " Tage)\n";
        
        if ($ageHours < 24) {
            echo "✓ Definitionen sind aktuell!\n";
        } elseif ($ageHours < 48) {
            echo "⚠ Definitionen sind etwas alt, aber noch akzeptabel\n";
        } else {
            echo "✗ Definitionen sind veraltet (>48h)\n";
        }
    }
}

echo "\n";
