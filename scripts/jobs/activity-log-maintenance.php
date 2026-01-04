<?php
/**
 * Activity-Log Wartungs-Job
 * 
 * Führt automatisch folgende Wartungsaufgaben aus:
 * 1. Archivierung alter Einträge
 * 2. Erstellung neuer Partitionen
 * 3. Löschung sehr alter archivierter Einträge
 * 
 * Sollte monatlich als Cron-Job ausgeführt werden:
 * 0 2 1 * * cd /path/to/TOM3 && php scripts/jobs/activity-log-maintenance.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use TOM\Infrastructure\Activity\ActivityLogArchiveService;
use TOM\Infrastructure\Database\DatabaseConnection;

// Konfiguration
$config = [
    'retention_months' => 24,  // Aktiv: 24 Monate
    'archive_delete_years' => 7,  // Archiv löschen nach 7 Jahren
    'partition_months_ahead' => 3,  // Partitionen für nächste 3 Monate
    'dry_run' => false  // Setze auf true für Tests
];

// Prüfe ob als Cron-Job ausgeführt
$isCron = !isset($_SERVER['REQUEST_METHOD']);

try {
    $db = DatabaseConnection::getInstance();
    $archiveService = new ActivityLogArchiveService($db, $config['retention_months']);
    
    $output = [];
    $output[] = "==========================================";
    $output[] = "Activity-Log Wartungs-Job";
    $output[] = "Ausgeführt: " . date('Y-m-d H:i:s');
    $output[] = "==========================================";
    $output[] = "";
    
    // 1. Statistiken vor Wartung
    $statsBefore = $archiveService->getStatistics();
    $output[] = "Statistiken vor Wartung:";
    $output[] = "  - Aktive Einträge: " . number_format($statsBefore['active_count'], 0, ',', '.');
    $output[] = "  - Archivierte Einträge: " . number_format($statsBefore['archive_count'], 0, ',', '.');
    $output[] = "";
    
    // 2. Archivierung
    $output[] = "1. Archivierung alter Einträge...";
    if ($config['dry_run']) {
        $output[] = "   DRY-RUN: Würde Einträge älter als {$config['retention_months']} Monate archivieren.";
    } else {
        $archivedCount = $archiveService->archiveOldEntries($config['retention_months']);
        $output[] = "   ✓ {$archivedCount} Einträge archiviert.";
    }
    $output[] = "";
    
    // 3. Partitionierung
    $output[] = "2. Partitionierung...";
    try {
        // Prüfe ob Partitionierung aktiviert ist
        $partitionCheck = $db->query("
            SELECT COUNT(*) as count
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'activity_log'
            AND PARTITION_NAME IS NOT NULL
        ");
        $hasPartitions = (int)$partitionCheck->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        
        if ($hasPartitions) {
            // Erstelle neue Partitionen
            $currentDate = new \DateTime();
            $targetDate = clone $currentDate;
            $targetDate->modify("+{$config['partition_months_ahead']} months");
            
            // Finde letzte Partition
            $lastPartitionStmt = $db->query("
                SELECT PARTITION_DESCRIPTION
                FROM INFORMATION_SCHEMA.PARTITIONS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'activity_log'
                AND PARTITION_NAME IS NOT NULL
                ORDER BY PARTITION_ORDINAL_POSITION DESC
                LIMIT 1
            ");
            $lastPartition = $lastPartitionStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastPartition) {
                $lastPartitionValue = (int)$lastPartition['PARTITION_DESCRIPTION'];
                $lastPartitionDate = \DateTime::createFromFormat('Ym', (string)$lastPartitionValue);
                
                $newPartitions = [];
                while ($lastPartitionDate < $targetDate) {
                    $lastPartitionDate->modify('+1 month');
                    $month = $lastPartitionDate->format('Ym');
                    $nextMonthValue = (int)$lastPartitionDate->format('Ym') + 1;
                    
                    $newPartitions[] = [
                        'name' => "p{$month}",
                        'value' => $nextMonthValue
                    ];
                }
                
                if (!empty($newPartitions)) {
                    foreach ($newPartitions as $partition) {
                        if ($config['dry_run']) {
                            $output[] = "   DRY-RUN: Würde Partition {$partition['name']} erstellen.";
                        } else {
                            try {
                                $db->exec("
                                    ALTER TABLE activity_log 
                                    ADD PARTITION (
                                        PARTITION {$partition['name']} VALUES LESS THAN ({$partition['value']})
                                    )
                                ");
                                $output[] = "   ✓ Partition {$partition['name']} erstellt.";
                            } catch (\Exception $e) {
                                $output[] = "   ⚠ Fehler bei Partition {$partition['name']}: " . $e->getMessage();
                            }
                        }
                    }
                } else {
                    $output[] = "   ✓ Alle benötigten Partitionen existieren bereits.";
                }
            }
        } else {
            $output[] = "   ⚠ Partitionierung ist nicht aktiviert. Verwenden Sie scripts/partition-activity-log.php";
        }
    } catch (\Exception $e) {
        $output[] = "   ✗ Fehler bei Partitionierung: " . $e->getMessage();
    }
    $output[] = "";
    
    // 4. Löschung alter Archiv-Einträge
    $output[] = "3. Löschung alter Archiv-Einträge...";
    if ($config['dry_run']) {
        $output[] = "   DRY-RUN: Würde archivierte Einträge älter als {$config['archive_delete_years']} Jahre löschen.";
    } else {
        $deletedCount = $archiveService->deleteOldArchivedEntries($config['archive_delete_years']);
        $output[] = "   ✓ {$deletedCount} sehr alte archivierte Einträge gelöscht.";
    }
    $output[] = "";
    
    // 5. Statistiken nach Wartung
    $statsAfter = $archiveService->getStatistics();
    $output[] = "Statistiken nach Wartung:";
    $output[] = "  - Aktive Einträge: " . number_format($statsAfter['active_count'], 0, ',', '.');
    $output[] = "  - Archivierte Einträge: " . number_format($statsAfter['archive_count'], 0, ',', '.');
    $output[] = "";
    $output[] = "==========================================";
    $output[] = "Wartung abgeschlossen.";
    $output[] = "==========================================";
    
    // Ausgabe
    $outputString = implode("\n", $output);
    
    if ($isCron) {
        // Bei Cron-Job: Log in Datei schreiben
        $logFile = __DIR__ . '/../../logs/activity-log-maintenance.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, $outputString . "\n\n", FILE_APPEND);
        
        // Optional: E-Mail bei Fehlern
        // mail('admin@example.com', 'Activity-Log Wartung', $outputString);
    } else {
        // Bei manueller Ausführung: Ausgabe
        echo $outputString . "\n";
    }
    
} catch (\Exception $e) {
    $error = "✗ Fehler: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    
    if ($isCron) {
        $logFile = __DIR__ . '/../../logs/activity-log-maintenance.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, $error . "\n\n", FILE_APPEND);
    } else {
        echo $error . "\n";
    }
    
    exit(1);
}


