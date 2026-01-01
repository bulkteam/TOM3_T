# Duplikaten-Housekeeper Job - TOM3

## Konzept

Ein automatischer Wartungsjob, der regelmäßig (täglich) nach potenziellen Duplikaten sucht und diese im Monitoring anzeigt. Dies ist ein **proaktiver Ansatz**, der Duplikate früh erkennt, ohne die Erstellung neuer Einträge zu blockieren.

## Vorteile

✅ **Proaktiv**: Findet Duplikate, bevor sie zu Problemen führen  
✅ **Nicht-blockierend**: Erlaubt flexible Geschäftslogik (z.B. gleicher Name bei unterschiedlichem `org_kind`)  
✅ **Transparent**: Zeigt potenzielle Duplikate im Monitoring  
✅ **Erweiterbar**: Kann auf alle Entitäten ausgeweitet werden  
✅ **Flexibel**: Verschiedene Kriterien kombinierbar (Name + PLZ, E-Mail, Telefon, etc.)

## Duplikaten-Kriterien

### Organisationen (Org)

#### Kriterium 1: Name + PLZ (Hauptadresse)
```sql
SELECT 
    o1.org_uuid as org_uuid_1,
    o1.name as name_1,
    o2.org_uuid as org_uuid_2,
    o2.name as name_2,
    a1.postal_code as postal_code,
    COUNT(*) as match_count
FROM org o1
JOIN org_address a1 ON a1.org_uuid = o1.org_uuid AND a1.is_primary = 1
JOIN org o2 ON o2.org_uuid != o1.org_uuid
JOIN org_address a2 ON a2.org_uuid = o2.org_uuid AND a2.is_primary = 1
WHERE LOWER(TRIM(o1.name)) = LOWER(TRIM(o2.name))
  AND a1.postal_code = a2.postal_code
  AND a1.postal_code IS NOT NULL
  AND a1.postal_code != ''
  AND o1.is_active = 1
  AND o2.is_active = 1
GROUP BY o1.org_uuid, o2.org_uuid
HAVING match_count > 0
```

#### Kriterium 2: E-Mail (Kommunikationskanal)
```sql
SELECT 
    o1.org_uuid as org_uuid_1,
    o1.name as name_1,
    o2.org_uuid as org_uuid_2,
    o2.name as name_2,
    c1.value as email
FROM org o1
JOIN org_communication_channel c1 ON c1.org_uuid = o1.org_uuid AND c1.channel_type = 'email'
JOIN org o2 ON o2.org_uuid != o1.org_uuid
JOIN org_communication_channel c2 ON c2.org_uuid = o2.org_uuid AND c2.channel_type = 'email'
WHERE LOWER(TRIM(c1.value)) = LOWER(TRIM(c2.value))
  AND c1.value IS NOT NULL
  AND c1.value != ''
  AND o1.is_active = 1
  AND o2.is_active = 1
```

#### Kriterium 3: Telefonnummer (Kommunikationskanal)
```sql
SELECT 
    o1.org_uuid as org_uuid_1,
    o1.name as name_1,
    o2.org_uuid as org_uuid_2,
    o2.name as name_2,
    c1.value as phone
FROM org o1
JOIN org_communication_channel c1 ON c1.org_uuid = o1.org_uuid AND c1.channel_type = 'phone'
JOIN org o2 ON o2.org_uuid != o1.org_uuid
JOIN org_communication_channel c2 ON c2.org_uuid = o2.org_uuid AND c2.channel_type = 'phone'
WHERE LOWER(TRIM(c1.value)) = LOWER(TRIM(c2.value))
  AND c1.value IS NOT NULL
  AND c1.value != ''
  AND o1.is_active = 1
  AND o2.is_active = 1
```

#### Kriterium 4: Website (exakte Übereinstimmung)
```sql
SELECT 
    o1.org_uuid as org_uuid_1,
    o1.name as name_1,
    o2.org_uuid as org_uuid_2,
    o2.name as name_2,
    o1.website as website
FROM org o1
JOIN org o2 ON o2.org_uuid != o1.org_uuid
WHERE LOWER(TRIM(o1.website)) = LOWER(TRIM(o2.website))
  AND o1.website IS NOT NULL
  AND o1.website != ''
  AND o1.is_active = 1
  AND o2.is_active = 1
```

### Personen (Person)

#### Kriterium 1: E-Mail (exakte Übereinstimmung)
```sql
SELECT 
    p1.person_uuid as person_uuid_1,
    CONCAT(p1.first_name, ' ', p1.last_name) as name_1,
    p2.person_uuid as person_uuid_2,
    CONCAT(p2.first_name, ' ', p2.last_name) as name_2,
    p1.email as email
FROM person p1
JOIN person p2 ON p2.person_uuid != p1.person_uuid
WHERE LOWER(TRIM(p1.email)) = LOWER(TRIM(p2.email))
  AND p1.email IS NOT NULL
  AND p1.email != ''
  AND p1.is_active = 1
  AND p2.is_active = 1
```

#### Kriterium 2: Name + E-Mail (fuzzy)
```sql
SELECT 
    p1.person_uuid as person_uuid_1,
    CONCAT(p1.first_name, ' ', p1.last_name) as name_1,
    p2.person_uuid as person_uuid_2,
    CONCAT(p2.first_name, ' ', p2.last_name) as name_2,
    p1.email as email
FROM person p1
JOIN person p2 ON p2.person_uuid != p1.person_uuid
WHERE LOWER(TRIM(p1.first_name)) = LOWER(TRIM(p2.first_name))
  AND LOWER(TRIM(p1.last_name)) = LOWER(TRIM(p2.last_name))
  AND LOWER(TRIM(p1.email)) = LOWER(TRIM(p2.email))
  AND p1.email IS NOT NULL
  AND p1.is_active = 1
  AND p2.is_active = 1
```

#### Kriterium 3: Telefonnummer (exakte Übereinstimmung)
```sql
SELECT 
    p1.person_uuid as person_uuid_1,
    CONCAT(p1.first_name, ' ', p1.last_name) as name_1,
    p2.person_uuid as person_uuid_2,
    CONCAT(p2.first_name, ' ', p2.last_name) as name_2,
    p1.phone as phone
FROM person p1
JOIN person p2 ON p2.person_uuid != p1.person_uuid
WHERE LOWER(TRIM(p1.phone)) = LOWER(TRIM(p2.phone))
  AND p1.phone IS NOT NULL
  AND p1.phone != ''
  AND p1.is_active = 1
  AND p2.is_active = 1
```

## Implementierung

### 1. PHP-Script: `scripts/check-duplicates.php`

```php
<?php
/**
 * TOM3 - Duplikaten-Housekeeper
 * Prüft auf potenzielle Duplikate in Organisationen und Personen
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TOM\Infrastructure\Database\DatabaseConnection;

$db = DatabaseConnection::getInstance();
$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'org_duplicates' => [],
    'person_duplicates' => [],
    'summary' => [
        'org_count' => 0,
        'person_count' => 0,
        'total_pairs' => 0
    ]
];

// Organisationen: Name + PLZ
$stmt = $db->query("
    SELECT 
        o1.org_uuid as org_uuid_1,
        o1.name as name_1,
        o2.org_uuid as org_uuid_2,
        o2.name as name_2,
        a1.postal_code as postal_code,
        'name_plz' as match_type
    FROM org o1
    JOIN org_address a1 ON a1.org_uuid = o1.org_uuid AND a1.is_primary = 1
    JOIN org o2 ON o2.org_uuid != o1.org_uuid
    JOIN org_address a2 ON a2.org_uuid = o2.org_uuid AND a2.is_primary = 1
    WHERE LOWER(TRIM(o1.name)) = LOWER(TRIM(o2.name))
      AND a1.postal_code = a2.postal_code
      AND a1.postal_code IS NOT NULL
      AND a1.postal_code != ''
      AND o1.is_active = 1
      AND o2.is_active = 1
      AND o1.org_uuid < o2.org_uuid  -- Verhindert doppelte Paare
");
$orgNamePlz = $stmt->fetchAll(PDO::FETCH_ASSOC);
$results['org_duplicates'] = array_merge($results['org_duplicates'], $orgNamePlz);

// Organisationen: E-Mail
$stmt = $db->query("
    SELECT DISTINCT
        o1.org_uuid as org_uuid_1,
        o1.name as name_1,
        o2.org_uuid as org_uuid_2,
        o2.name as name_2,
        c1.value as match_value,
        'email' as match_type
    FROM org o1
    JOIN org_communication_channel c1 ON c1.org_uuid = o1.org_uuid AND c1.channel_type = 'email'
    JOIN org o2 ON o2.org_uuid != o1.org_uuid
    JOIN org_communication_channel c2 ON c2.org_uuid = o2.org_uuid AND c2.channel_type = 'email'
    WHERE LOWER(TRIM(c1.value)) = LOWER(TRIM(c2.value))
      AND c1.value IS NOT NULL
      AND c1.value != ''
      AND o1.is_active = 1
      AND o2.is_active = 1
      AND o1.org_uuid < o2.org_uuid
");
$orgEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
$results['org_duplicates'] = array_merge($results['org_duplicates'], $orgEmail);

// Personen: E-Mail
$stmt = $db->query("
    SELECT 
        p1.person_uuid as person_uuid_1,
        CONCAT(p1.first_name, ' ', p1.last_name) as name_1,
        p2.person_uuid as person_uuid_2,
        CONCAT(p2.first_name, ' ', p2.last_name) as name_2,
        p1.email as match_value,
        'email' as match_type
    FROM person p1
    JOIN person p2 ON p2.person_uuid != p1.person_uuid
    WHERE LOWER(TRIM(p1.email)) = LOWER(TRIM(p2.email))
      AND p1.email IS NOT NULL
      AND p1.email != ''
      AND p1.is_active = 1
      AND p2.is_active = 1
      AND p1.person_uuid < p2.person_uuid
");
$personEmail = $stmt->fetchAll(PDO::FETCH_ASSOC);
$results['person_duplicates'] = array_merge($results['person_duplicates'], $personEmail);

// Zusammenfassung
$results['summary']['org_count'] = count($results['org_duplicates']);
$results['summary']['person_count'] = count($results['person_duplicates']);
$results['summary']['total_pairs'] = $results['summary']['org_count'] + $results['summary']['person_count'];

// Speichere Ergebnisse in Tabelle für Monitoring
$stmt = $db->prepare("
    INSERT INTO duplicate_check_results (
        check_date, org_duplicates, person_duplicates, total_pairs, results_json
    ) VALUES (
        NOW(), :org_count, :person_count, :total_pairs, :results_json
    )
");
$stmt->execute([
    'org_count' => $results['summary']['org_count'],
    'person_count' => $results['summary']['person_count'],
    'total_pairs' => $results['summary']['total_pairs'],
    'results_json' => json_encode($results)
]);

// Ausgabe für Logging
echo json_encode($results, JSON_PRETTY_PRINT);
```

### 2. Datenbank-Tabelle für Ergebnisse

```sql
-- Migration: 032_create_duplicate_check_results_mysql.sql
CREATE TABLE IF NOT EXISTS duplicate_check_results (
    check_id INT AUTO_INCREMENT PRIMARY KEY,
    check_date DATETIME NOT NULL,
    org_duplicates INT NOT NULL DEFAULT 0,
    person_duplicates INT NOT NULL DEFAULT 0,
    total_pairs INT NOT NULL DEFAULT 0,
    results_json JSON COMMENT 'Vollständige Ergebnisse als JSON',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_check_date (check_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. Monitoring-Integration

#### API-Endpoint: `public/api/monitoring.php` erweitern

```php
// Neue Route: GET /api/monitoring/duplicates
if ($resource === 'monitoring' && $id === 'duplicates') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
    
    $stmt = $db->prepare("
        SELECT 
            check_id,
            check_date,
            org_duplicates,
            person_duplicates,
            total_pairs,
            results_json
        FROM duplicate_check_results
        ORDER BY check_date DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Hole auch die aktuellsten Duplikate
    $latestCheck = $checks[0] ?? null;
    $currentDuplicates = [];
    if ($latestCheck && $latestCheck['results_json']) {
        $currentDuplicates = json_decode($latestCheck['results_json'], true);
    }
    
    echo json_encode([
        'checks' => $checks,
        'current_duplicates' => $currentDuplicates
    ]);
    exit;
}
```

#### Frontend: `public/monitoring.html` erweitern

```html
<!-- Neuer Abschnitt: Duplikaten-Prüfung -->
<div class="monitoring-section">
    <h3>Duplikaten-Prüfung</h3>
    <div id="duplicates-status">
        <div class="status-item">
            <span class="status-label">Letzte Prüfung:</span>
            <span class="status-value" id="last-check-date">-</span>
        </div>
        <div class="status-item">
            <span class="status-label">Org-Duplikate:</span>
            <span class="status-value" id="org-duplicates-count">0</span>
        </div>
        <div class="status-item">
            <span class="status-label">Person-Duplikate:</span>
            <span class="status-value" id="person-duplicates-count">0</span>
        </div>
    </div>
    <div id="duplicates-list"></div>
</div>
```

### 4. Windows Task Scheduler Job

#### PowerShell-Script: `scripts/setup-duplicate-check-job.ps1`

```powershell
# Erstellt einen täglichen Windows Task Scheduler Job für Duplikaten-Prüfung
$action = New-ScheduledTaskAction -Execute "php.exe" -Argument "C:\xampp\htdocs\TOM3\scripts\check-duplicates.php" -WorkingDirectory "C:\xampp\htdocs\TOM3"
$trigger = New-ScheduledTaskTrigger -Daily -At "02:00"  # Täglich um 2 Uhr
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive

Register-ScheduledTask -TaskName "TOM3-DuplicateCheck" -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "TOM3 Duplikaten-Housekeeper - Prüft täglich auf potenzielle Duplikate"
```

#### Batch-Wrapper: `scripts/check-duplicates.bat`

```batch
@echo off
cd /d C:\xampp\htdocs\TOM3
php scripts\check-duplicates.php >> logs\duplicate-check.log 2>&1
```

## Erweiterte Optionen

### Fuzzy-Matching (optional)

Für ähnliche Namen (Levenshtein-Distanz):
```sql
-- Benötigt MySQL 8.0+ oder Custom-Funktion
SELECT 
    o1.org_uuid, o1.name,
    o2.org_uuid, o2.name,
    LEVENSHTEIN(LOWER(o1.name), LOWER(o2.name)) as distance
FROM org o1, org o2
WHERE o1.org_uuid < o2.org_uuid
  AND LEVENSHTEIN(LOWER(o1.name), LOWER(o2.name)) < 3  -- Max. 3 Zeichen Unterschied
```

### Konfigurierbare Schwellenwerte

```php
// config/duplicate_check.php
return [
    'org' => [
        'name_plz_match' => true,
        'email_match' => true,
        'phone_match' => true,
        'website_match' => true,
    ],
    'person' => [
        'email_match' => true,
        'name_email_match' => true,
        'phone_match' => true,
    ],
    'fuzzy_matching' => [
        'enabled' => false,
        'max_distance' => 3,
    ]
];
```

## Zusammenfassung

✅ **Sehr sinnvoll**: Proaktive Duplikatenerkennung ohne Blockierung  
✅ **Flexibel**: Verschiedene Kriterien kombinierbar  
✅ **Transparent**: Im Monitoring sichtbar  
✅ **Erweiterbar**: Kann auf alle Entitäten ausgeweitet werden  
✅ **Wartbar**: Täglicher automatischer Check

**Empfehlung**: Implementieren! Es ist ein bewährtes Pattern für Datenqualität.
