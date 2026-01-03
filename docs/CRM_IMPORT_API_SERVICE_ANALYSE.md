# Analyse: Vorgeschlagene API/Service-Struktur

## Zusammenfassung

**Ja, die APIs und Services helfen sehr!** Die Struktur ist sehr durchdacht und löst genau unsere Probleme:
1. ✅ Persistierung der UI-Entscheidungen
2. ✅ Server-driven Dropdowns (keine inkonsistenten Zustände)
3. ✅ Guards serverseitig
4. ✅ Saubere Trennung (DTOs, Repositories, Services)

---

## 1. API-Endpoints

### Endpoint 1: GET /api/import/staging/{staging_uuid}

**Zweck:** UI lädt eine Zeile inkl. `raw_data`, `mapped_data`, `industry_resolution`, Validierung/Review

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

**Vorschlag:**
```php
// In public/api/import.php
function handleGetStagingRow($importService, $stagingUuid) {
    $row = $importService->getStagingRow($stagingUuid);
    
    return [
        'staging_uuid' => $row['staging_uuid'],
        'batch_uuid' => $row['import_batch_uuid'],
        'row_number' => $row['row_number'],
        'raw_data' => json_decode($row['raw_data'], true),
        'mapped_data' => json_decode($row['mapped_data'], true),
        'industry_resolution' => json_decode($row['industry_resolution'], true),
        'validation_status' => $row['validation_status'],
        'validation_errors' => json_decode($row['validation_errors'], true),
        'review_status' => $row['disposition'],  // Mapping: disposition → review_status
        'review_notes' => $row['review_notes'],
        'duplicate_status' => $row['duplicate_status'] ?? 'unknown',
        'duplicate_summary' => json_decode($row['duplicate_summary'] ?? 'null', true)
    ];
}
```

---

### Endpoint 2: POST /api/import/staging/{staging_uuid}/industry-decision

**Zweck:** User bestätigt/ändert L1/L2/L3. Server validiert Konsistenz und gibt Dropdown-Optionen zurück

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

**Vorschlag:**
```php
// In public/api/import.php
function handleIndustryDecision($decisionService, $stagingUuid, $request, $userId) {
    $req = new IndustryDecisionRequest(
        level1Uuid: $request['level1_uuid'] ?? null,
        level2Uuid: $request['level2_uuid'] ?? null,
        level3Action: $request['level3_action'] ?? 'UNDECIDED',
        level3Uuid: $request['level3_uuid'] ?? null,
        level3NewName: $request['level3_new_name'] ?? null,
        confirmLevel1: $request['confirm_level1'] ?? false,
        confirmLevel2: $request['confirm_level2'] ?? false
    );
    
    try {
        $result = $decisionService->applyDecision($stagingUuid, $req, $userId);
        return $result;
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'INCONSISTENT_PARENT') {
            return [
                'error' => 'INCONSISTENT_PARENT',
                'message' => 'Die gewählte Branche (Level 2) gehört nicht zum gewählten Branchenbereich (Level 1).',
                'details' => [
                    'level1_uuid' => $req->level1Uuid,
                    'level2_uuid' => $req->level2Uuid,
                    'expected_level1_uuid' => $decisionService->getExpectedLevel1($req->level2Uuid)
                ]
            ], 409;
        }
        throw $e;
    }
}
```

---

### Endpoint 3: POST /api/import/batch/{batch_uuid}/commit

**Zweck:** Importiert alle `review_status=approved` Zeilen in Produktion

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

**Vorschlag:**
```php
// In public/api/import.php
function handleCommitBatch($commitService, $batchUuid, $request, $userId) {
    $mode = $request['mode'] ?? 'APPROVED_ONLY';
    $startWorkflows = $request['start_workflows'] ?? true;
    $dryRun = $request['dry_run'] ?? false;
    
    if ($dryRun) {
        // Nur Validierung, kein Commit
        $result = $commitService->validateCommit($batchUuid);
    } else {
        $result = $commitService->commitBatch($batchUuid, $userId, $startWorkflows);
    }
    
    return [
        'batch_uuid' => $batchUuid,
        'result' => $result
    ];
}
```

---

## 2. Service-Struktur

### 2.1 DTOs (Data Transfer Objects)

**Vorschlag:**
- `IndustryCandidate` - Für Vorschläge mit Score
- `IndustryResolution` - Für industry_resolution Struktur
- `IndustryDecisionRequest` - Für API-Requests

**Aktueller Stand:**
- ❌ **Existieren nicht**
- ⚠️ Aktuell: Arrays überall (funktioniert, aber weniger typsicher)

**Empfehlung:**
- ✅ **Sollten erstellt werden** - Machen Code typsicherer und wartbarer
- ⚠️ Optional für MVP, können später hinzugefügt werden

---

### 2.2 Repository-Schicht

#### IndustryRepository

**Vorschlag:**
```php
public function getByUuid(string $uuid): ?array;
public function getChildren(string $parentUuid): array;
public function listLevel1(): array;
public function listLevel2ByLevel1(string $level1Uuid): array;
public function listLevel3ByLevel2(string $level2Uuid): array;
public function findLevel3ByNameUnderParent(string $level2Uuid, string $nameNormalized): ?array;
public function createLevel3(string $parentLevel2Uuid, string $name): string;
public function getParentUuid(string $uuid): ?string;
```

**Aktueller Stand:**
- ⚠️ **Teilweise vorhanden** in `public/api/industries.php`
- ❌ Keine zentrale Repository-Klasse
- ❌ Methoden sind direkt in API-Endpoint

**Empfehlung:**
- ✅ **Sollte erstellt werden** - Zentrale DB-Zugriffe, wiederverwendbar
- ✅ Macht Code testbarer

---

#### ImportStagingRepository

**Vorschlag:**
```php
public function getRow(string $stagingUuid): array;
public function updateIndustryResolution(string $stagingUuid, array $industryResolution): void;
public function updateReviewStatus(string $stagingUuid, string $status, ?string $userId, ?string $notes): void;
public function listApprovedRows(string $batchUuid): array;
public function markImported(string $stagingUuid, string $orgUuid, array $commitLog): void;
public function markFailed(string $stagingUuid, string $reason, array $details = []): void;
```

**Aktueller Stand:**
- ⚠️ **Teilweise vorhanden** in `OrgImportService`
- ❌ Keine separate Repository-Klasse
- ❌ Methoden sind direkt im Service

**Empfehlung:**
- ✅ **Sollte erstellt werden** - Saubere Trennung (Service-Logik vs. DB-Zugriffe)

---

#### ImportBatchRepository

**Vorschlag:**
```php
public function getBatch(string $batchUuid): array;
public function setStatus(string $batchUuid, string $status, array $stats = []): void;
```

**Aktueller Stand:**
- ⚠️ **Teilweise vorhanden** in `OrgImportService`
- ❌ Keine separate Repository-Klasse

**Empfehlung:**
- ✅ **Sollte erstellt werden** - Konsistenz mit anderen Repositories

---

### 2.3 Core Services

#### IndustryNormalizer

**Vorschlag:**
- Normalisiert Strings (lowercase, umlaut_fold, suffix_stripping)

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ⚠️ Normalisierung ist in `ImportIndustryValidationService` verstreut

**Empfehlung:**
- ✅ **Sollte erstellt werden** - Wiederverwendbar, testbar

---

#### IndustryResolver

**Vorschlag:**
- `suggestLevel2()` - Findet Level2 Kandidaten
- `deriveLevel1FromLevel2()` - Leitet Level1 ab
- `suggestLevel3UnderLevel2()` - Findet Level3 Kandidaten

**Aktueller Stand:**
- ⚠️ **Teilweise vorhanden** in `ImportIndustryValidationService`
- ❌ Keine zentrale Klasse
- ❌ Logik ist verstreut

**Empfehlung:**
- ✅ **Sollte erstellt werden** - Zentrale Matching-Logik

---

#### IndustryDecisionService

**Vorschlag:**
- `applyDecision()` - Verarbeitet UI-Entscheidungen
- Guards prüfen
- Dropdown-Optionen zurückgeben

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

**Empfehlung:**
- ✅ **MUSS erstellt werden** - Kern der State-Engine

---

#### ImportStagingService

**Vorschlag:**
- `stageBatch()` - Erstellt staging rows + industry_resolution
- `buildIndustryResolution()` - Erstellt Resolution-Struktur

**Aktueller Stand:**
- ⚠️ **Teilweise vorhanden** in `OrgImportService::importToStaging()`
- ❌ `buildIndustryResolution()` fehlt
- ❌ Keine separate Klasse

**Empfehlung:**
- ✅ **Sollte erstellt werden** - Oder `OrgImportService` erweitern

---

#### ImportCommitService

**Vorschlag:**
- `commitBatch()` - Importiert approved rows in Produktion
- `commitRow()` - Verarbeitet einzelne Zeile

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

**Empfehlung:**
- ✅ **MUSS erstellt werden** - Für finalen Import

---

## Vergleich: Aktuell vs. Vorschlag

### Aktuell:
```
OrgImportService (groß, macht alles)
├── importToStaging() - Erstellt staging rows
├── saveStagingRow() - Speichert Zeile
└── (keine separate Services)
```

### Vorschlag:
```
IndustryNormalizer (klein, wiederverwendbar)
IndustryResolver (Matching-Logik)
IndustryDecisionService (State-Management)
ImportStagingService (Staging-Erstellung)
ImportCommitService (Finaler Import)
├── IndustryRepository (DB-Zugriffe)
├── ImportStagingRepository (DB-Zugriffe)
└── ImportBatchRepository (DB-Zugriffe)
```

**Vorteile des Vorschlags:**
- ✅ Single Responsibility (jeder Service hat eine Aufgabe)
- ✅ Testbar (Services isoliert testbar)
- ✅ Wiederverwendbar (z.B. IndustryNormalizer)
- ✅ Wartbar (klare Struktur)

---

## Empfehlung: Schrittweise Umsetzung

### Phase 1 (MVP - kritisch):
1. ✅ **IndustryDecisionService** - Kern der State-Engine
2. ✅ **API-Endpoint** `/industry-decision` - Für UI-Interaktion
3. ✅ **IndustryRepository** - Zentrale DB-Zugriffe (oder erweitere `industries.php`)

### Phase 2 (Verbesserung):
4. ✅ **IndustryNormalizer** - Wiederverwendbar
5. ✅ **IndustryResolver** - Zentrale Matching-Logik
6. ✅ **ImportCommitService** - Finaler Import

### Phase 3 (Optimierung):
7. ✅ **DTOs** - Typsicherheit (optional)
8. ✅ **Repositories** - Saubere Trennung (optional)

---

## Fazit

**Ja, die APIs und Services helfen sehr!**

**Was wir übernehmen sollten:**
1. ✅ **IndustryDecisionService** - MUSS erstellt werden (State-Engine)
2. ✅ **API-Endpoints** - MÜSSEN erstellt werden (GET staging, POST decision, POST commit)
3. ✅ **IndustryNormalizer** - Sollte erstellt werden (wiederverwendbar)
4. ✅ **IndustryResolver** - Sollte erstellt werden (zentrale Matching-Logik)
5. ✅ **ImportCommitService** - MUSS erstellt werden (finaler Import)
6. ⚠️ **Repositories** - Können später kommen (optional für MVP)

**Empfehlung:** Starte mit Phase 1 (IndustryDecisionService + API-Endpoints). Das löst die kritischsten Probleme.
