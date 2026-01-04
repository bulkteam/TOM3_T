<?php
/**
 * Erstellt neue Partitionen für activity_log Tabelle
 * 
 * Sollte monatlich ausgeführt werden, um neue Partitionen für den kommenden Monat zu erstellen
 * 
 * Verwendung:
 * php scripts/partition-activity-log.php [--months-ahead=3] [--dry-run]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$options = getopt('', ['months-ahead:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Verwendung: php scripts/partition-activity-log.php [--months-ahead=3] [--dry-run]\n";
    echo "\n";
    echo "Optionen:\n";
    echo "  --months-ahead=N    Erstellt Partitionen für die nächsten N Monate (Standard: 3)\n";
    echo "  --dry-run           Zeigt nur an, welche Partitionen erstellt würden\n";
    echo "  --help              Zeigt diese Hilfe\n";
    exit(0);
}

$monthsAhead = isset($options['months-ahead']) ? (int)$options['months-ahead'] : 3;
$dryRun = isset($options['dry-run']);

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Activity-Log Partitionierung\n";
    echo "============================\n\n";
    
    // Prüfe ob Tabelle partitioniert ist
    $checkStmt = $db->query("
        SELECT 
            PARTITION_NAME,
            PARTITION_DESCRIPTION,
            TABLE_ROWS
        FROM INFORMATION_SCHEMA.PARTITIONS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'activity_log'
        AND PARTITION_NAME IS NOT NULL
        ORDER BY PARTITION_ORDINAL_POSITION
    ");
    $existingPartitions = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($existingPartitions)) {
        echo "⚠ Tabelle 'activity_log' ist noch nicht partitioniert.\n";
        echo "  Möchten Sie die Partitionierung jetzt aktivieren? (j/n): ";
        
        if (!$dryRun) {
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            fclose($handle);
            
            if (trim(strtolower($line)) !== 'j' && trim(strtolower($line)) !== 'y') {
                echo "Abgebrochen.\n";
                exit(0);
            }
            
            // Erstelle initiale Partitionen
            echo "\nAktiviere Partitionierung...\n";
            $thisMonth = date('Ym');
            $nextMonth = date('Ym', strtotime('+1 month'));
            
            // Erstelle Partitionen für die letzten 3 Monate und nächsten 3 Monate
            $partitions = [];
            for ($i = -3; $i <= $monthsAhead; $i++) {
                $month = date('Ym', strtotime("{$i} months"));
                $nextMonthValue = date('Ym', strtotime("+1 month", strtotime("{$i} months")));
                $partitions[] = "PARTITION p{$month} VALUES LESS THAN ({$nextMonthValue})";
            }
            
            $sql = "
                ALTER TABLE activity_log 
                PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
                    " . implode(",\n                    ", $partitions) . "
                )
            ";
            
            if ($dryRun) {
                echo "DRY-RUN: Würde folgendes ausführen:\n";
                echo $sql . "\n";
            } else {
                $db->exec($sql);
                echo "✓ Partitionierung aktiviert.\n";
            }
        } else {
            echo "DRY-RUN: Partitionierung würde aktiviert werden.\n";
        }
    } else {
        echo "Bestehende Partitionen:\n";
        foreach ($existingPartitions as $partition) {
            echo "  - {$partition['PARTITION_NAME']}: {$partition['PARTITION_DESCRIPTION']} ({$partition['TABLE_ROWS']} Zeilen)\n";
        }
        echo "\n";
        
        // Finde die letzte Partition
        $lastPartition = end($existingPartitions);
        $lastPartitionValue = (int)$lastPartition['PARTITION_DESCRIPTION'];
        $lastPartitionDate = \DateTime::createFromFormat('Ym', (string)$lastPartitionValue);
        
        // Erstelle neue Partitionen für die nächsten Monate
        $newPartitions = [];
        $currentDate = new \DateTime();
        $targetDate = clone $currentDate;
        $targetDate->modify("+{$monthsAhead} months");
        
        while ($lastPartitionDate < $targetDate) {
            $lastPartitionDate->modify('+1 month');
            $month = $lastPartitionDate->format('Ym');
            $nextMonthValue = (int)$lastPartitionDate->format('Ym') + 1;
            
            $newPartitions[] = [
                'name' => "p{$month}",
                'value' => $nextMonthValue
            ];
        }
        
        if (empty($newPartitions)) {
            echo "✓ Alle benötigten Partitionen existieren bereits.\n";
        } else {
            echo "Zu erstellende Partitionen:\n";
            foreach ($newPartitions as $partition) {
                echo "  - {$partition['name']}: VALUES LESS THAN ({$partition['value']})\n";
            }
            echo "\n";
            
            if ($dryRun) {
                echo "DRY-RUN: Würde folgende Partitionen erstellen:\n";
                foreach ($newPartitions as $partition) {
                    echo "  ALTER TABLE activity_log ADD PARTITION ({$partition['name']} VALUES LESS THAN ({$partition['value']}));\n";
                }
            } else {
                foreach ($newPartitions as $partition) {
                    try {
                        $db->exec("
                            ALTER TABLE activity_log 
                            ADD PARTITION (
                                PARTITION {$partition['name']} VALUES LESS THAN ({$partition['value']})
                            )
                        ");
                        echo "✓ Partition {$partition['name']} erstellt.\n";
                    } catch (\Exception $e) {
                        echo "✗ Fehler beim Erstellen von {$partition['name']}: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
    }
    
} catch (\Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}


