# Analyse: State-Engine für Branchen-Prüfung

## Zustandsdiagramm (Vorschlag)

```
[*] --> SUGGESTED: staging created
SUGGESTED --> L1_CONFIRMED: user confirms Level1
SUGGESTED --> L1_CHANGED: user changes Level1
L1_CONFIRMED --> L2_READY: system loads Level2 options
L2_READY --> L2_CONFIRMED: user confirms Level2
L2_READY --> L2_CHANGED: user changes Level2
L2_CONFIRMED --> L3_READY: system loads Level3 options
L3_READY --> L3_SELECTED: user selects existing L3
L3_READY --> L3_CREATE: user enters new L3
L3_SELECTED --> APPROVED: row approved
L3_CREATE --> APPROVED: row approved
SUGGESTED --> NEEDS_FIX: validation error
NEEDS_FIX --> SUGGESTED: user fixes
```

## Aktueller Stand

### Frontend (import.js):
- ⚠️ Teilweise implementiert, aber **keine explizite State-Maschine**
- ✅ `confirmLevel1()`, `confirmLevel2()` existieren
- ✅ Reset-Logik bei Level-Änderungen vorhanden
- ❌ Keine zentrale State-Verwaltung
- ❌ Guards nur im Frontend, nicht serverseitig

### Backend:
- ❌ **Keine State-Engine**
- ❌ Keine Guards für Konsistenz
- ❌ Keine explizite State-Transition-Logik

---

## Was wir übernehmen sollten

### 1. State-Enum definieren

```php
// In ImportIndustryValidationService oder neuer Service
class IndustryDecisionState
{
    public const SUGGESTED = 'SUGGESTED';
    public const L1_CONFIRMED = 'L1_CONFIRMED';
    public const L1_CHANGED = 'L1_CHANGED';
    public const L2_READY = 'L2_READY';
    public const L2_CONFIRMED = 'L2_CONFIRMED';
    public const L2_CHANGED = 'L2_CHANGED';
    public const L3_READY = 'L3_READY';
    public const L3_SELECTED = 'L3_SELECTED';
    public const L3_CREATE = 'L3_CREATE';
    public const APPROVED = 'APPROVED';
    public const NEEDS_FIX = 'NEEDS_FIX';
}
```

### 2. Feld-Mutations-Regeln implementieren

#### Event: "Staging row created"
```php
// In buildIndustryResolution()
$resolution = [
    'suggestions' => [
        'level2_candidates' => [...],
        'derived_level1' => [...],
        'level3_candidates' => []
    ],
    'decision' => [
        'status' => 'PENDING',  // ✅ Entspricht SUGGESTED
        'level1_uuid' => $derivedLevel1['industry_uuid'] ?? null,
        'level2_uuid' => $bestCandidate['industry_uuid'] ?? null,
        'level1_confirmed' => false,
        'level2_confirmed' => false,
        'level3_action' => 'UNDECIDED',
        'level3_uuid' => null,
        'level3_new_name' => null
    ]
];
```

#### Aktion: User bestätigt Level 1
```php
// In updateIndustryDecision()
public function confirmLevel1(string $stagingUuid, string $level1Uuid, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Mutations-Regeln
    $resolution['decision']['level1_uuid'] = $level1Uuid;
    $resolution['decision']['level1_confirmed'] = true;
    // ✅ Level2 Dropdown wird aktiviert (Frontend)
    // ✅ NICHT automatisch level2_confirmed setzen
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    // Lade Level2 Optionen
    $level2Options = $this->getLevel2Options($level1Uuid);
    
    return [
        'industry_resolution' => $resolution,
        'dropdown_options' => ['level2' => $level2Options],
        'guards' => ['level2_enabled' => true]
    ];
}
```

#### Aktion: User ändert Level 1
```php
// In updateIndustryDecision()
public function changeLevel1(string $stagingUuid, string $newLevel1Uuid, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Reset-Regeln (zwingend!)
    $resolution['decision']['level1_uuid'] = $newLevel1Uuid;
    $resolution['decision']['level1_confirmed'] = false;  // Muss neu bestätigt werden
    $resolution['decision']['level2_uuid'] = null;
    $resolution['decision']['level2_confirmed'] = false;
    $resolution['decision']['level3_uuid'] = null;
    $resolution['decision']['level3_action'] = 'UNDECIDED';
    $resolution['decision']['level3_new_name'] = null;
    $resolution['suggestions']['level3_candidates'] = [];  // Reset
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    // Lade neue Level2 Optionen
    $level2Options = $this->getLevel2Options($newLevel1Uuid);
    
    return [
        'industry_resolution' => $resolution,
        'dropdown_options' => ['level2' => $level2Options],
        'guards' => ['level2_enabled' => false, 'level3_enabled' => false]
    ];
}
```

#### Aktion: User bestätigt Level 2
```php
public function confirmLevel2(string $stagingUuid, string $level2Uuid, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Guard: Konsistenz prüfen
    $expectedLevel1 = $this->getParentIndustryUuid($level2Uuid);
    if ($resolution['decision']['level1_uuid'] !== $expectedLevel1) {
        // Auto-Korrektur oder Fehler
        $resolution['decision']['level1_uuid'] = $expectedLevel1;
    }
    
    // Mutations-Regeln
    $resolution['decision']['level2_uuid'] = $level2Uuid;
    $resolution['decision']['level2_confirmed'] = true;
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    // Lade Level3 Optionen
    $level3Options = $this->getLevel3Options($level2Uuid);
    
    return [
        'industry_resolution' => $resolution,
        'dropdown_options' => ['level3' => $level3Options],
        'guards' => ['level3_enabled' => true]
    ];
}
```

#### Aktion: User ändert Level 2
```php
public function changeLevel2(string $stagingUuid, string $newLevel2Uuid, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Reset-Regeln
    $resolution['decision']['level2_uuid'] = $newLevel2Uuid;
    $resolution['decision']['level2_confirmed'] = true;  // Sobald gewählt = confirmed
    $resolution['decision']['level3_uuid'] = null;
    $resolution['decision']['level3_action'] = 'UNDECIDED';
    $resolution['decision']['level3_new_name'] = null;
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    // Lade neue Level3 Optionen
    $level3Options = $this->getLevel3Options($newLevel2Uuid);
    
    return [
        'industry_resolution' => $resolution,
        'dropdown_options' => ['level3' => $level3Options],
        'guards' => ['level3_enabled' => true]
    ];
}
```

#### Aktion: User wählt Level 3 (SELECT_EXISTING)
```php
public function selectLevel3(string $stagingUuid, string $level3Uuid, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Mutations-Regeln
    $resolution['decision']['level3_action'] = 'SELECT_EXISTING';
    $resolution['decision']['level3_uuid'] = $level3Uuid;
    $resolution['decision']['level3_new_name'] = null;
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    return [
        'industry_resolution' => $resolution,
        'guards' => ['approve_enabled' => $this->canApprove($resolution)]
    ];
}
```

#### Aktion: User legt Level 3 neu an (CREATE_NEW)
```php
public function createLevel3(string $stagingUuid, string $level3Name, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Guard: CREATE_NEW nur wenn level2_confirmed == true
    if (!$resolution['decision']['level2_confirmed']) {
        throw new \RuntimeException('L2_CONFIRM_REQUIRED');
    }
    
    // Mutations-Regeln
    $resolution['decision']['level3_action'] = 'CREATE_NEW';
    $resolution['decision']['level3_new_name'] = $level3Name;
    $resolution['decision']['level3_uuid'] = null;  // Wird beim Commit erzeugt
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    return [
        'industry_resolution' => $resolution,
        'guards' => ['approve_enabled' => $this->canApprove($resolution)]
    ];
}
```

#### Aktion: Row Approve
```php
public function approveRow(string $stagingUuid, string $userId): array
{
    $resolution = $this->getIndustryResolution($stagingUuid);
    
    // Guards prüfen
    if (!$this->canApprove($resolution)) {
        throw new \RuntimeException('APPROVE_GUARDS_FAILED');
    }
    
    // Mutations-Regeln
    $resolution['decision']['status'] = 'APPROVED';
    
    // Update review_status in staging
    $this->updateReviewStatus($stagingUuid, 'approved', $userId);
    
    $this->saveIndustryResolution($stagingUuid, $resolution);
    
    return [
        'industry_resolution' => $resolution,
        'review_status' => 'approved'
    ];
}

private function canApprove(array $resolution): bool
{
    $decision = $resolution['decision'] ?? [];
    
    return !empty($decision['level1_uuid'])
        && !empty($decision['level2_uuid'])
        && $decision['level2_confirmed'] === true
        && (
            ($decision['level3_action'] === 'SELECT_EXISTING' && !empty($decision['level3_uuid']))
            || ($decision['level3_action'] === 'CREATE_NEW' && !empty($decision['level3_new_name']))
        );
}
```

---

## Backend-Guards implementieren

### 1. Consistency Guard
```php
// In updateIndustryDecision()
private function validateConsistency(array $decision): void
{
    if (!empty($decision['level2_uuid'])) {
        $expectedLevel1 = $this->getParentIndustryUuid($decision['level2_uuid']);
        
        if (!empty($decision['level1_uuid']) && $decision['level1_uuid'] !== $expectedLevel1) {
            // Option A: Auto-Korrektur
            $decision['level1_uuid'] = $expectedLevel1;
            
            // Option B: Fehler werfen
            // throw new \RuntimeException('INCONSISTENT_PARENT');
        }
    }
}
```

### 2. Create Guard
```php
// In createLevel3()
private function validateCreateLevel3(array $decision): void
{
    if (!$decision['level2_confirmed']) {
        throw new \RuntimeException('L3_CREATE_REQUIRES_CONFIRMED_L2');
    }
    
    if (empty($decision['level2_uuid'])) {
        throw new \RuntimeException('L3_CREATE_REQUIRES_L2_UUID');
    }
}
```

### 3. Code Guard
```php
// In allen Methoden: Immer UUID verwenden, nie Code
// Beispiel:
public function getLevel2Options(string $level1Uuid): array
{
    // ✅ Korrekt: Filter nach parent_industry_uuid
    $stmt = $this->db->prepare("
        SELECT industry_uuid, code, name, parent_industry_uuid
        FROM industry
        WHERE parent_industry_uuid = :level1_uuid
        ORDER BY code, name
    ");
    
    // ❌ FALSCH: Filter nach code (wegen C28 Duplikaten)
    // WHERE code LIKE 'C%'
}
```

---

## Commit-Ablauf (Batch Import)

```php
// Neuer Service: ImportCommitService
public function commitBatch(string $batchUuid, string $userId): array
{
    $rows = $this->getApprovedRows($batchUuid);
    
    $stats = [
        'total' => count($rows),
        'imported' => 0,
        'failed' => 0,
        'created_level3' => 0,
        'started_workflows' => 0
    ];
    
    foreach ($rows as $row) {
        try {
            $this->db->beginTransaction();
            
            $resolution = json_decode($row['industry_resolution'], true);
            $decision = $resolution['decision'] ?? [];
            $mapped = json_decode($row['mapped_data'], true);
            
            // 1. Duplikat-Entscheidung prüfen
            if ($row['disposition'] === 'link_existing') {
                // Update bestehende Org
                $this->updateExistingOrg($row['imported_org_uuid'], $mapped);
                $stats['imported']++;
                $this->db->commit();
                continue;
            }
            
            // 2. Level 3 erstellen wenn CREATE_NEW
            $level3Uuid = null;
            if ($decision['level3_action'] === 'CREATE_NEW') {
                $level3Uuid = $this->createLevel3Industry(
                    $decision['level2_uuid'],
                    $decision['level3_new_name']
                );
                $stats['created_level3']++;
                
                // Optional: Zurückschreiben in staging
                $resolution['decision']['level3_uuid'] = $level3Uuid;
                $this->updateIndustryResolution($row['staging_uuid'], $resolution);
            } else {
                $level3Uuid = $decision['level3_uuid'];
            }
            
            // 3. Org erstellen
            $orgUuid = $this->orgService->createOrg([
                'name' => $mapped['org']['name'],
                'website' => $mapped['org']['website'] ?? null,
                'phone' => $mapped['org']['phone'] ?? null,
                'industry_level1_uuid' => $decision['level1_uuid'],
                'industry_level2_uuid' => $decision['level2_uuid'],
                'industry_level3_uuid' => $level3Uuid
            ]);
            
            // 4. Workflow starten
            $caseUuid = $this->workflowService->startQualifyCompanyCase($orgUuid, $userId);
            $stats['started_workflows']++;
            
            // 5. Staging aktualisieren
            $commitLog = [
                ['action' => 'CREATE_INDUSTRY_LEVEL3', 'new_industry_uuid' => $level3Uuid],
                ['action' => 'CREATE_ORG', 'org_uuid' => $orgUuid],
                ['action' => 'START_WORKFLOW', 'case_uuid' => $caseUuid]
            ];
            
            $this->updateStagingImported(
                $row['staging_uuid'],
                $orgUuid,
                $commitLog
            );
            
            $this->db->commit();
            $stats['imported']++;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            $stats['failed']++;
            $this->markStagingFailed($row['staging_uuid'], $e->getMessage());
        }
    }
    
    return $stats;
}
```

---

## Empfehlung: Neuer Service

### IndustryDecisionService

**Zweck:** Zentrale Verwaltung von State-Transitions und Guards

**Methoden:**
- `confirmLevel1()`
- `changeLevel1()`
- `confirmLevel2()`
- `changeLevel2()`
- `selectLevel3()`
- `createLevel3()`
- `approveRow()`
- `canApprove()` (Guard-Prüfung)
- `validateConsistency()` (Guard)

**Vorteile:**
- ✅ Zentrale State-Logik
- ✅ Guards serverseitig
- ✅ Konsistenz garantiert
- ✅ Testbar

---

## Zusammenfassung

**Ja, die State-Engine ist sehr sinnvoll!**

**Was wir übernehmen sollten:**
1. ✅ **Feld-Mutations-Regeln** - Sehr detailliert, verhindert Bugs
2. ✅ **Backend-Guards** - Konsistenz, Create-Guard, Code-Guard
3. ✅ **State-Transitions** - Explizite Zustandsübergänge
4. ✅ **Commit-Ablauf** - Klare Logik für finalen Import

**Empfehlung:**
- Neuer Service `IndustryDecisionService` für State-Management
- Guards serverseitig implementieren
- Frontend nutzt API-Endpoints (keine direkte DB-Zugriffe)
