<?php
/**
 * Testet ClamAV-Verfügbarkeit
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Document\ClamAvService;

$clamAv = new ClamAvService();

echo "=== ClamAV Test ===\n";
echo "Verfügbar: " . ($clamAv->isAvailable() ? 'JA' : 'NEIN') . "\n";

if ($clamAv->isAvailable()) {
    try {
        $version = $clamAv->getVersion();
        echo "Version: " . ($version ?: 'N/A') . "\n";
    } catch (\Exception $e) {
        echo "Fehler beim Abrufen der Version: " . $e->getMessage() . "\n";
    }
} else {
    echo "ClamAV ist nicht verfügbar. Bitte prüfen:\n";
    echo "  - Läuft der Docker-Container?\n";
    echo "  - Ist ClamAV lokal installiert?\n";
}

