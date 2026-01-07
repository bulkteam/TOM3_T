<?php
/**
 * Prüft und korrigiert bulk.tech Case für Wiedervorlage
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();

echo "========================================\n";
echo "  Prüfe bulk.tech Case\n";
echo "========================================\n\n";

// Finde bulk.tech Org
$stmt = $db->prepare("
    SELECT org_uuid, name, status
    FROM org
    WHERE name LIKE '%bulk.tech%'
    LIMIT 1
");
$stmt->execute();
$org = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$org) {
    echo "❌ bulk.tech Organisation nicht gefunden\n";
    exit(1);
}

echo "Organisation: {$org['name']} (UUID: {$org['org_uuid']})\n\n";

// Finde Case
$stmt = $db->prepare("
    SELECT case_uuid, stage, next_action_at, next_action_type, created_at
    FROM case_item
    WHERE org_uuid = :org_uuid
      AND case_type = 'LEAD'
      AND engine = 'inside_sales'
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute(['org_uuid' => $org['org_uuid']]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    echo "❌ Kein Case gefunden\n";
    exit(1);
}

echo "Case gefunden:\n";
echo "  UUID: {$case['case_uuid']}\n";
echo "  Stage: {$case['stage']}\n";
echo "  next_action_at: " . ($case['next_action_at'] ?? 'NULL') . "\n";
echo "  next_action_type: " . ($case['next_action_type'] ?? 'NULL') . "\n";
echo "  Erstellt: {$case['created_at']}\n\n";

// Prüfe Timeline
$stmt = $db->prepare("
    SELECT timeline_id, activity_type, next_action_at, next_action_type, occurred_at, notes
    FROM work_item_timeline
    WHERE work_item_uuid = :case_uuid
      AND next_action_at IS NOT NULL
    ORDER BY occurred_at DESC
    LIMIT 5
");
$stmt->execute(['case_uuid' => $case['case_uuid']]);
$timelineItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Timeline-Einträge mit next_action_at:\n";
if (empty($timelineItems)) {
    echo "  Keine gefunden\n";
} else {
    foreach ($timelineItems as $item) {
        echo "  - {$item['activity_type']}: next_action_at = {$item['next_action_at']}, occurred_at = {$item['occurred_at']}\n";
    }
}

// Prüfe, ob next_action_at in der Zukunft liegt
if ($case['next_action_at']) {
    $nextAction = new \DateTime($case['next_action_at']);
    $now = new \DateTime();
    $isFuture = $nextAction > $now;
    
    echo "\nPrüfung:\n";
    echo "  next_action_at: {$case['next_action_at']}\n";
    echo "  Jetzt: " . $now->format('Y-m-d H:i:s') . "\n";
    echo "  In der Zukunft: " . ($isFuture ? 'JA' : 'NEIN') . "\n";
    echo "  Aktueller Stage: {$case['stage']}\n";
    
    if ($isFuture && $case['stage'] !== 'SNOOZED') {
        echo "\n⚠️  PROBLEM: next_action_at ist in der Zukunft, aber stage ist nicht SNOOZED!\n";
        echo "  → Stage sollte 'SNOOZED' sein\n";
        
        // Korrigiere
        $updateStmt = $db->prepare("
            UPDATE case_item
            SET stage = 'SNOOZED',
                updated_at = NOW()
            WHERE case_uuid = :case_uuid
        ");
        $updateStmt->execute(['case_uuid' => $case['case_uuid']]);
        
        echo "  ✅ Stage auf 'SNOOZED' gesetzt\n";
    } elseif (!$isFuture && $case['stage'] === 'NEW') {
        echo "\n⚠️  PROBLEM: next_action_at ist heute/in der Vergangenheit, aber stage ist noch NEW!\n";
        echo "  → Stage sollte 'IN_PROGRESS' sein\n";
        
        // Korrigiere
        $updateStmt = $db->prepare("
            UPDATE case_item
            SET stage = 'IN_PROGRESS',
                updated_at = NOW()
            WHERE case_uuid = :case_uuid
        ");
        $updateStmt->execute(['case_uuid' => $case['case_uuid']]);
        
        echo "  ✅ Stage auf 'IN_PROGRESS' gesetzt\n";
    } else {
        echo "\n✅ Stage ist korrekt\n";
    }
} else {
    echo "\n⚠️  next_action_at ist nicht gesetzt im case_item\n";
    if (!empty($timelineItems)) {
        $latest = $timelineItems[0];
        echo "  Aber in Timeline gefunden: {$latest['next_action_at']}\n";
        echo "  → Sollte in case_item kopiert werden\n";
        
        $nextAction = new \DateTime($latest['next_action_at']);
        $now = new \DateTime();
        $isFuture = $nextAction > $now;
        $newStage = $isFuture ? 'SNOOZED' : ($case['stage'] === 'NEW' ? 'IN_PROGRESS' : $case['stage']);
        
        $updateStmt = $db->prepare("
            UPDATE case_item
            SET next_action_at = :next_action_at,
                next_action_type = :next_action_type,
                stage = :stage,
                updated_at = NOW()
            WHERE case_uuid = :case_uuid
        ");
        $updateStmt->execute([
            'case_uuid' => $case['case_uuid'],
            'next_action_at' => $latest['next_action_at'],
            'next_action_type' => $latest['next_action_type'],
            'stage' => $newStage
        ]);
        
        echo "  ✅ case_item aktualisiert: next_action_at = {$latest['next_action_at']}, stage = {$newStage}\n";
    }
}

echo "\n✅ Prüfung abgeschlossen\n";


