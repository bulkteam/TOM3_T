<?php
/**
 * Migration 056: CRM Aktivitäten, Links, Threads und Org Stage History
 * 
 * Führt die Migration 056_crm_activity_and_links_mysql.sql aus
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

try {
    $db = DatabaseConnection::getInstance();
    
    echo "Führe Migration 056 aus: CRM Aktivitäten, Links, Threads und Org Stage History...\n";
    
    $migrationFile = __DIR__ . '/../database/migrations/056_crm_activity_and_links_mysql.sql';
    
    if (!file_exists($migrationFile)) {
        throw new \RuntimeException("Migration-Datei nicht gefunden: $migrationFile");
    }
    
    $statement = file_get_contents($migrationFile);
    
    // Entferne problematische Kommentare
    $statement = preg_replace('/--.*$/m', '', $statement);
    
    // Splitte in einzelne Statements (einfacher Split by Semicolon; Achtung bei Prozeduren/Triggern)
    $statements = array_filter(array_map('trim', explode(';', $statement)));
    $executed = 0;
    
    foreach ($statements as $sql) {
        if ($sql === '') continue;
        $db->exec($sql);
        $executed++;
    }
    
    echo "✓ Migration 056 erfolgreich ausgeführt.\n";
    echo "  - $executed SQL-Statements ausgeführt\n";
    
    // Prüf-Output
    $tables = [
        'crm_activity',
        'activity_link',
        'activity_participant',
        'timeline_thread',
        'thread_activity',
        'org_stage_history'
    ];
    echo "\nTabellen-Check:\n";
    foreach ($tables as $t) {
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $stmt->execute(['t' => $t]);
        $exists = ($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
        echo "  - $t: " . ($exists ? "OK" : "FEHLT") . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Fehler bei Migration 056: " . $e->getMessage() . "\n";
    echo "  Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

