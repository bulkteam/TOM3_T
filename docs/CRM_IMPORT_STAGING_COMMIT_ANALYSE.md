# Analyse: Staging/Commit-Phase und zusätzliche Hinweise

## 1. Staging/Commit-Phase Umsetzung

### Aktueller Stand

#### Staging-Phase:
```php
// In OrgImportService::saveStagingRow()
$mapped_data = json_encode($rowData);  // Enthält Excel-Werte
// ❌ industry_resolution wird NICHT erstellt
// ❌ Keine Trennung zwischen mapped_data und industry_resolution
```

#### Commit-Phase:
- ❌ **Existiert noch nicht** - Kein Service für finalen Import
- ❌ Keine Logik für Level 3 Erstellung
- ❌ Keine Logik für Org-Erstellung mit Industry-UUIDs
- ❌ Kein Alias-Learning

### Konzept-Vorschlag

#### Staging-Phase:
```php
// In saveStagingRow()
$mapped_data = [
    'org' => [
        'name' => 'Musterfarben GmbH',
        'website' => 'https://...',
        // ... andere Org-Felder
    ],
    'industry' => [
        'excel_level2_label' => 'Chemieindustrie',  // Excel-Wert, keine UUID
        'excel_level3_label' => 'Farbenhersteller'
    ]
];

$industry_resolution = [
    'excel' => [...],
    'suggestions' => [...],
    'decision' => [
        'level1_uuid' => '...',
        'level2_uuid' => '...',
        'level3_uuid' => null,  // ✅ Noch nicht gesetzt, weil ggf. neu
        'level3_action' => 'CREATE_NEW',
        'level3_new_name' => 'Farbenhersteller'
    ]
];
```

#### Commit-Phase:
```php
// Neuer Service: ImportCommitService
public function commitBatch(string $batchUuid, string $userId): array
{
    $rows = $this->stagingRepo->listApprovedRows($batchUuid);
    
    foreach ($rows as $row) {
        $resolution = json_decode($row['industry_resolution'], true);
        $decision = $resolution['decision'] ?? [];
        
        // 1. Level 3 erstellen wenn CREATE_NEW
        $level3Uuid = null;
        if ($decision['level3_action'] === 'CREATE_NEW') {
            $level3Uuid = $this->createLevel3Industry(
                $decision['level2_uuid'],
                $decision['level3_new_name']
            );
        } else {
            $level3Uuid = $decision['level3_uuid'];
        }
        
        // 2. Org erstellen mit Industry-UUIDs
        $orgUuid = $this->orgService->createOrg([
            'name' => $mapped['org']['name'],
            'industry_level1_uuid' => $decision['level1_uuid'],
            'industry_level2_uuid' => $decision['level2_uuid'],
            'industry_level3_uuid' => $level3Uuid
        ]);
        
        // 3. Alias-Learning
        if ($resolution['excel']['level2_label']) {
            $this->saveAlias(
                $resolution['excel']['level2_label'],
                $decision['level2_uuid'],
                2,  // Level 2
                $userId
            );
        }
    }
}
```

---

## 2. Code-Uniqueness Warnung (C28 Duplikate)

### Problem
- Mehrere Industries mit Code `C28`:
  - "Maschinenbau" (C28)
  - "Anlagenbau" (C28)
  - "C28 - Maschinenbau" (C28)
- Matching und Dropdowns können inkonsistent werden

### Aktueller Code-Check
```php
// In ImportIndustryValidationService
// ❌ Wird Code verwendet für Matching? Prüfen!
```

### Lösung
**Regel:**
- Level 1: Code = A, B, C... (eindeutig)
- Level 2: Code = C20, C21... (eindeutig)
- Level 3: Meist ohne Code oder eigener Untercode (eindeutig unter Parent)

**Implementierung:**
- ✅ **UI/Matching immer `(uuid + parent_uuid)` verwenden, nie nur `code`**
- ✅ Dropdowns: Value = `industry_uuid`, nicht `code`
- ✅ Matching: Prüfe `uuid` + `parent_industry_uuid`, nicht nur `code`

### Prüfungen nötig:
1. `ImportIndustryValidationService`: Verwendet Code für Matching?
2. `public/api/industries.php`: Dropdowns liefern `uuid` als Value?
3. Frontend: Verwendet `uuid` oder `code`?

---

## 3. API-Endpoints (MVP)

### Aktueller Stand

#### POST /import/batch/{batchId}/stage
- ✅ **Existiert bereits**: `POST /api/import/staging/{batchUuid}`
- ❌ Aber: Erstellt keine `industry_resolution` suggestions
- ❌ Muss erweitert werden

#### POST /import/staging/{stagingUuid}/industry-decision
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

### Konzept-Vorschlag

#### Endpoint 1: POST /api/import/batch/{batchUuid}/stage
```php
// In public/api/import.php
function handleImportToStaging($importService, $batchUuid, $filePath) {
    // 1. Importiere in Staging
    $stats = $importService->importToStaging($batchUuid, $filePath);
    
    // 2. Für jede Staging-Row: Erstelle industry_resolution suggestions
    $stagingRows = $importService->getStagingRows($batchUuid);
    foreach ($stagingRows as $row) {
        $resolution = $importService->buildIndustryResolution(
            json_decode($row['mapped_data'], true),
            $mappingConfig
        );
        $importService->updateIndustryResolution($row['staging_uuid'], $resolution);
    }
    
    return $stats;
}
```

#### Endpoint 2: POST /api/import/staging/{stagingUuid}/industry-decision
```php
// In public/api/import.php
function handleIndustryDecision($importService, $stagingUuid, $request) {
    $decision = [
        'level1_uuid' => $request['level1_uuid'],
        'level2_uuid' => $request['level2_uuid'],
        'level3_uuid' => $request['level3_uuid'] ?? null,
        'level1_confirmed' => $request['confirm_level1'] ?? false,
        'level2_confirmed' => $request['confirm_level2'] ?? false,
        'level3_action' => $request['level3_action'] ?? 'UNDECIDED',
        'level3_new_name' => $request['level3_new_name'] ?? null
    ];
    
    // Validiere Konsistenz (Guards)
    $importService->validateIndustryDecision($stagingUuid, $decision);
    
    // Speichere Entscheidung
    $importService->updateIndustryDecision($stagingUuid, $decision);
    
    // Lade Dropdown-Optionen für kaskadierende UI
    $dropdowns = $importService->getIndustryDropdowns($decision);
    
    return [
        'staging_uuid' => $stagingUuid,
        'industry_resolution' => $updatedResolution,
        'dropdown_options' => $dropdowns,
        'guards' => $guards
    ];
}
```

---

## Zusammenfassung: Was fehlt

### Staging-Phase:
- ✅ `mapped_data` wird erstellt (aber ohne Trennung `org.*` vs `industry.*`)
- ❌ `industry_resolution` wird **nicht** erstellt
- ❌ `buildIndustryResolution()` fehlt

### Commit-Phase:
- ❌ **Komplett fehlend**: Kein `ImportCommitService`
- ❌ Keine Logik für Level 3 Erstellung
- ❌ Keine Logik für Org-Erstellung mit Industry-UUIDs
- ❌ Kein Alias-Learning

### Code-Uniqueness:
- ⚠️ **Prüfung nötig**: Wird Code für Matching verwendet?
- ⚠️ **Prüfung nötig**: Dropdowns verwenden `uuid` oder `code`?

### API-Endpoints:
- ✅ `/api/import/staging/{batchUuid}` existiert (muss erweitert werden)
- ❌ `/api/import/staging/{stagingUuid}/industry-decision` fehlt komplett

---

## Nächste Schritte (erweitert)

### Phase 1: Staging-Phase (kritisch)
1. ✅ `buildIndustryResolution()` implementieren
2. ✅ `saveStagingRow()` erweitern: `industry_resolution` speichern
3. ✅ API-Endpoint `/stage` erweitern: `industry_resolution` suggestions erstellen

### Phase 2: API-Endpoints
4. ✅ API-Endpoint `/industry-decision` erstellen
5. ✅ Guards für Konsistenz-Validierung

### Phase 3: Commit-Phase
6. ✅ `ImportCommitService` erstellen
7. ✅ Level 3 Erstellung implementieren
8. ✅ Org-Erstellung mit Industry-UUIDs
9. ✅ Alias-Learning implementieren

### Phase 4: Code-Uniqueness
10. ✅ Prüfung: Code-Verwendung in Matching
11. ✅ Sicherstellen: UI verwendet immer `uuid`, nie `code`

