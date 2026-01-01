<?php
/**
 * Archiviert alte Activity-Log-Einträge
 * 
 * Verwendung:
 * php scripts/archive-activity-log.php [--months=24] [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Activity\ActivityLogArchiveService;

$options = getopt('', ['months:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Verwendung: php scripts/archive-activity-log.php [--months=24] [--dry-run]\n";
    echo "\n";
    echo "Optionen:\n";
    echo "  --months=N    Anzahl Monate für Retention (Standard: 24)\n";
    echo "  --dry-run     Zeigt nur an, was archiviert würde, ohne zu archivieren\n";
    echo "  --help        Zeigt diese Hilfe\n";
    exit(0);
}

$months = isset($options['months']) ? (int)$options['months'] : 24;
$dryRun = isset($options['dry-run']);

try {
    $archiveService = new ActivityLogArchiveService(null, $months);
    
    echo "Activity-Log Archivierung\n";
    echo "==========================\n\n";
    
    $stats = $archiveService->getStatistics();
    echo "Aktuelle Statistiken:\n";
    echo "  - Aktive Einträge: " . number_format($stats['active_count'], 0, ',', '.') . "\n";
    echo "  - Archivierte Einträge: " . number_format($stats['archive_count'], 0, ',', '.') . "\n";
    echo "  - Retention: {$months} Monate\n";
    echo "  - Ältester aktiver Eintrag: " . ($stats['oldest_active'] ?? 'Keine') . "\n";
    echo "\n";
    
    if ($dryRun) {
        echo "DRY-RUN Modus: Es wird nichts archiviert.\n";
        echo "Einträge älter als " . date('Y-m-d', strtotime("-{$months} months")) . " würden archiviert werden.\n";
    } else {
        echo "Starte Archivierung...\n";
        $archivedCount = $archiveService->archiveOldEntries($months);
        echo "✓ {$archivedCount} Einträge wurden archiviert.\n";
        
        $statsAfter = $archiveService->getStatistics();
        echo "\nNeue Statistiken:\n";
        echo "  - Aktive Einträge: " . number_format($statsAfter['active_count'], 0, ',', '.') . "\n";
        echo "  - Archivierte Einträge: " . number_format($statsAfter['archive_count'], 0, ',', '.') . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
