# Umsetzungsstrategie: Gesamtchange für Import-System

## Problemstellung

Wir haben mehrere Analysen erstellt, die einen umfangreichen Umbau beschreiben:
- DB-Schema-Änderungen (3 Migrationen)
- Neue Services (5+ Services)
- API-Endpoints (3 neue Endpoints)
- Frontend-Änderungen (State-Engine, Fixes)
- Refactoring bestehender Services

**Risiko:** Einzelne Änderungen könnten nicht korrelieren, Redundanz entstehen, oder Fehler durch inkonsistente Implementierungen.

---

## Strategie: Phasenweise Umsetzung mit klaren Abhängigkeiten

### Prinzipien:
1. ✅ **Bottom-Up**: DB → Services → API → Frontend
2. ✅ **Inkrementell**: Jede Phase ist testbar
3. ✅ **Rückwärtskompatibel**: Bestehende Funktionalität bleibt erhalten
4. ✅ **Refactoring**: Schrittweise, nicht alles auf einmal
5. ✅ **Integration Points**: Klare Schnittstellen zwischen Phasen

---

## Phase 0: Analyse & Planung ✅ (ABGESCHLOSSEN)

### Aufgaben:
- ✅ Alle Konzepte analysiert
- ✅ DB-Struktur verglichen
- ✅ Service-Struktur geplant
- ✅ API-Endpoints definiert
- ✅ State-Engine verstanden

### Ergebnis:
- 6 Analyse-Dokumente erstellt
- Migrationen vorbereitet (048, 049, 050)
- Klare Vorstellung der finalen Struktur

---

## Phase 1: DB-Migrationen (Foundation)

### Abhängigkeiten:
- Keine (Basis für alles weitere)

### Aufgaben:
1. ✅ Migration 048: `industry_resolution` Feld
2. ✅ Migration 049: Optionale Felder (`duplicate_status`, `commit_log`)
3. ✅ Migration 050: `industry_alias` Tabelle

### Prüfpunkt:
- ✅ Alle Migrationen erfolgreich
- ✅ Tabellen existieren
- ✅ Indizes erstellt

### Nächste Phase:
- Phase 2 kann starten (Services benötigen DB-Felder)

---

## Phase 2: Core Services (Basis-Logik)

### Abhängigkeiten:
- ✅ Phase 1 (DB-Felder müssen existieren)

### Reihenfolge (wichtig!):

#### 2.1 IndustryNormalizer (unabhängig)
**Warum zuerst:** Wird von anderen Services verwendet
```php
// src/TOM/Service/Import/IndustryNormalizer.php
class IndustryNormalizer {
    public function normalize(string $s): string { ... }
}
```

#### 2.2 IndustryRepository (oder erweitere industries.php)
**Warum:** Wird von IndustryResolver benötigt
```php
// Option A: Neue Klasse
// src/TOM/Repository/IndustryRepository.php

// Option B: Erweitere public/api/industries.php
// (einfacher, weniger Breaking Changes)
```

#### 2.3 IndustryResolver (refactored aus ImportIndustryValidationService)
**Warum:** Wird von buildIndustryResolution() benötigt
```php
// src/TOM/Service/Import/IndustryResolver.php
class IndustryResolver {
    public function suggestLevel2(string $excelLabel): array { ... }
    public function deriveLevel1FromLevel2(string $level2Uuid): ?array { ... }
    public function suggestLevel3UnderLevel2(...): array { ... }
}
```

**Refactoring-Strategie:**
- ✅ Extrahiere Matching-Logik aus `ImportIndustryValidationService`
- ✅ Nutze `IndustryNormalizer`
- ✅ Nutze `IndustryRepository`
- ⚠️ **Wichtig:** `ImportIndustryValidationService` weiterhin nutzbar lassen (für bestehende Code-Pfade)

#### 2.4 IndustryDecisionService (State-Engine)
**Warum:** Wird von API-Endpoint benötigt
```php
// src/TOM/Service/Import/IndustryDecisionService.php
class IndustryDecisionService {
    public function applyDecision(...): array { ... }
    public function validateConsistency(...): void { ... }
    public function canApprove(...): bool { ... }
}
```

### Prüfpunkt:
- ✅ Alle Services erstellt
- ✅ Unit-Tests für Normalizer, Resolver
- ✅ Guards funktionieren
- ✅ Keine Breaking Changes in bestehenden Services

### Nächste Phase:
- Phase 3 kann starten (Staging-Service nutzt neue Services)

---

## Phase 3: Staging-Service erweitern

### Abhängigkeiten:
- ✅ Phase 2 (IndustryResolver, IndustryNormalizer müssen existieren)

### Aufgaben:

#### 3.1 buildIndustryResolution() implementieren
```php
// In OrgImportService oder neuer ImportStagingService
private function buildIndustryResolution(
    array $mappedData,
    array $mappingConfig
): array {
    // Nutzt IndustryResolver
    // Erstellt industry_resolution Struktur (exakt wie im Beispiel)
}
```

#### 3.2 mapped_data strukturieren
```php
// In saveStagingRow()
private function structureMappedData(array $rowData): array {
    return [
        'org' => [...],
        'industry' => [
            'excel_level2_label' => ...,
            'excel_level3_label' => ...
        ]
    ];
}
```

#### 3.3 raw_data Original speichern
```php
// In importToStaging()
// Lese Original Excel-Zeile (vor Mapping)
$rawRowData = $this->readRawExcelRow($worksheet, $row, $headerRow);
// Speichere als raw_data
```

#### 3.4 effective_data Merge-Logik
```php
// In saveStagingRow()
$effectiveData = $this->mergeRecursive($mappedData, $corrections);
```

#### 3.5 saveStagingRow() erweitern
```php
// Füge industry_resolution hinzu
$industryResolution = $this->buildIndustryResolution($structuredMappedData, $mappingConfig);
'industry_resolution' => json_encode($industryResolution)
```

### Refactoring-Strategie:
- ⚠️ **Wichtig:** Bestehende `importToStaging()` weiterhin funktionsfähig
- ✅ Schrittweise erweitern, nicht alles auf einmal
- ✅ Alte Struktur parallel unterstützen (Rückwärtskompatibilität)

### Prüfpunkt:
- ✅ `buildIndustryResolution()` erstellt korrekte Struktur
- ✅ `mapped_data` ist strukturiert (org.* + industry.*)
- ✅ `raw_data` enthält Original Excel
- ✅ `effective_data` = merge(mapped, corrections)
- ✅ Bestehende Imports funktionieren weiterhin

### Nächste Phase:
- Phase 4 kann starten (API-Endpoints nutzen neue Services)

---

## Phase 4: API-Endpoints

### Abhängigkeiten:
- ✅ Phase 2 (IndustryDecisionService)
- ✅ Phase 3 (buildIndustryResolution)

### Reihenfolge:

#### 4.1 GET /api/import/staging/{staging_uuid}
**Warum zuerst:** Einfachster Endpoint, nur Lesen
```php
// In public/api/import.php
function handleGetStagingRow($importService, $stagingUuid) {
    $row = $importService->getStagingRow($stagingUuid);
    // Formatiere Response (exakt wie im Beispiel)
}
```

#### 4.2 POST /api/import/staging/{staging_uuid}/industry-decision
**Warum:** Kern-Endpoint für UI-Interaktion
```php
// In public/api/import.php
function handleIndustryDecision($decisionService, $stagingUuid, $request, $userId) {
    // Nutzt IndustryDecisionService
    // Validiert Guards
    // Liefert Dropdown-Optionen zurück
}
```

#### 4.3 POST /api/import/batch/{batch_uuid}/commit
**Warum:** Finaler Import (kann später kommen)
```php
// In public/api/import.php
function handleCommitBatch($commitService, $batchUuid, $request, $userId) {
    // Nutzt ImportCommitService (Phase 6)
}
```

### Prüfpunkt:
- ✅ Alle Endpoints funktionieren
- ✅ Response-Struktur entspricht Beispiel
- ✅ Guards funktionieren
- ✅ Error-Handling korrekt

### Nächste Phase:
- Phase 5 kann starten (Frontend nutzt neue APIs)

---

## Phase 5: Frontend-Fixes

### Abhängigkeiten:
- ✅ Phase 4 (API-Endpoints müssen existieren)

### Reihenfolge:

#### 5.1 Doppelte Funktionen entfernen
**Warum zuerst:** Verhindert Verwirrung
- Entferne doppelte `loadMainIndustries()`, `onLevel1Selected()`, `loadLevel2Options()`
- Behalte nur die beste Version

#### 5.2 loadLevel2Options() Fix
**Warum:** Kritischer Bug
```javascript
// Sichere currentValue vor Reload
const currentValue = select.value;
// ... lade Optionen ...
// Setze currentValue wieder
if (currentValue) select.value = currentValue;
```

#### 5.3 API-Integration
**Warum:** UI-Entscheidungen müssen serverseitig gespeichert werden
```javascript
// Statt: this.industryDecisions[excelValue] = {...}
// Nutze: POST /api/import/staging/{uuid}/industry-decision
async saveIndustryDecision(stagingUuid, decision) {
    const response = await fetch(`/api/import/staging/${stagingUuid}/industry-decision`, {
        method: 'POST',
        body: JSON.stringify(decision)
    });
    // Update UI mit Response (dropdown_options, guards)
}
```

#### 5.4 State-Engine Integration
**Warum:** Guards serverseitig, Dropdowns server-driven
```javascript
// Nutze Guards vom Server
if (response.guards.level2_enabled) {
    // Aktiviere Level 2
}
// Nutze Dropdown-Optionen vom Server
response.dropdown_options.level2.forEach(...)
```

### Prüfpunkt:
- ✅ Keine doppelten Funktionen
- ✅ loadLevel2Options behält Vorbelegung
- ✅ UI-Entscheidungen werden serverseitig gespeichert
- ✅ Dropdowns sind server-driven
- ✅ Guards funktionieren

### Nächste Phase:
- Phase 6 kann starten (Commit-Service)

---

## Phase 6: Commit-Service

### Abhängigkeiten:
- ✅ Phase 3 (Staging-Service mit industry_resolution)
- ✅ Phase 2 (IndustryRepository für Level 3 Erstellung)

### Aufgaben:

#### 6.1 ImportCommitService erstellen
```php
// src/TOM/Service/Import/ImportCommitService.php
class ImportCommitService {
    public function commitBatch(string $batchUuid, string $userId): array {
        // Für jede approved row:
        // 1. Level 3 erstellen wenn CREATE_NEW
        // 2. Org erstellen mit Industry-UUIDs
        // 3. Workflow starten
        // 4. Staging aktualisieren
    }
}
```

#### 6.2 API-Endpoint erweitern
```php
// In public/api/import.php
// POST /api/import/batch/{batch_uuid}/commit
// (bereits in Phase 4 vorbereitet)
```

### Prüfpunkt:
- ✅ Level 3 Industries werden erstellt
- ✅ Orgs werden mit korrekten UUIDs erstellt
- ✅ Workflows werden gestartet
- ✅ Staging wird aktualisiert (import_status, commit_log)
- ✅ Alias-Learning funktioniert

---

## Phase 7: Testing & Integration

### Abhängigkeiten:
- ✅ Alle vorherigen Phasen

### Aufgaben:
1. ✅ End-to-End Test (Upload → Mapping → Staging → Decision → Commit)
2. ✅ Guards testen (Konsistenz, Create-Guard, Code-Guard)
3. ✅ State-Transitions testen
4. ✅ Edge-Cases testen (C28 Duplikate, etc.)
5. ✅ Performance testen (große Imports)

---

## Kritische Integrationspunkte

### 1. IndustryResolver vs. ImportIndustryValidationService

**Problem:** Beide machen ähnliches (Matching)

**Lösung:**
- ✅ `IndustryResolver` = Neue, saubere Implementierung
- ✅ `ImportIndustryValidationService` = Weiterhin für bestehende Code-Pfade
- ✅ Schrittweise Migration: Neue Code-Pfade nutzen `IndustryResolver`
- ⚠️ Später: `ImportIndustryValidationService` refactoren, nutzt `IndustryResolver` intern

### 2. mapped_data Struktur-Änderung

**Problem:** Bestehender Code erwartet flache Struktur

**Lösung:**
- ✅ Neue Struktur: `{org: {...}, industry: {...}}`
- ✅ Helper-Methoden für Rückwärtskompatibilität:
```php
private function getOrgField(array $mappedData, string $field): ?string {
    // Unterstützt beide Strukturen
    return $mappedData['org'][$field] ?? $mappedData[$field] ?? null;
}
```

### 3. industry_resolution vs. industryDecisions (Frontend)

**Problem:** Frontend nutzt `this.industryDecisions = {}`, Backend nutzt `industry_resolution`

**Lösung:**
- ✅ Frontend speichert per API in `industry_resolution`
- ✅ Frontend lädt `industry_resolution` vom Server
- ✅ `industryDecisions` wird nur noch für temporäre UI-State verwendet

---

## Redundanz vermeiden

### Strategie:

#### 1. Zentrale Services
- ✅ `IndustryNormalizer` - Einmal implementiert, überall genutzt
- ✅ `IndustryResolver` - Zentrale Matching-Logik
- ✅ `IndustryDecisionService` - Zentrale State-Engine

#### 2. Repository-Pattern (optional)
- ⚠️ Später: DB-Zugriffe in Repositories bündeln
- ✅ Für MVP: Services können direkt DB nutzen (weniger Breaking Changes)

#### 3. Code-Duplikation vermeiden
- ✅ Helper-Methoden für gemeinsame Logik
- ✅ Traits für wiederverwendbare Funktionalität
- ✅ Klare Verantwortlichkeiten (Single Responsibility)

---

## Rollback-Strategie

### Jede Phase ist rückgängig machbar:

1. **Phase 1 (Migrationen):**
   - ✅ Migrationen können zurückgerollt werden
   - ✅ Neue Felder sind NULL (keine Breaking Changes)

2. **Phase 2-6 (Services/API):**
   - ✅ Neue Services parallel zu bestehenden
   - ✅ Alte Code-Pfade bleiben funktionsfähig
   - ✅ Schrittweise Migration möglich

3. **Phase 5 (Frontend):**
   - ✅ Feature-Flags für neue UI
   - ✅ Fallback auf alte Implementierung

---

## Checkliste vor jedem Commit

### Code-Qualität:
- [ ] Keine doppelten Funktionen
- [ ] Keine Code-Duplikation
- [ ] Guards serverseitig implementiert
- [ ] Error-Handling korrekt
- [ ] Rückwärtskompatibilität gewährleistet

### Integration:
- [ ] Services nutzen neue Services (nicht alte Logik duplizieren)
- [ ] API-Endpoints nutzen neue Services
- [ ] Frontend nutzt neue APIs
- [ ] DB-Struktur konsistent

### Testing:
- [ ] Unit-Tests für neue Services
- [ ] Integration-Tests für API-Endpoints
- [ ] End-to-End Test funktioniert

---

## Empfohlene Umsetzungsreihenfolge

### Woche 1: Foundation
1. ✅ Phase 1: Migrationen ausführen
2. ✅ Phase 2.1-2.3: IndustryNormalizer, IndustryRepository, IndustryResolver

### Woche 2: Core-Logik
3. ✅ Phase 2.4: IndustryDecisionService
4. ✅ Phase 3: Staging-Service erweitern

### Woche 3: Integration
5. ✅ Phase 4: API-Endpoints
6. ✅ Phase 5: Frontend-Fixes

### Woche 4: Finalisierung
7. ✅ Phase 6: Commit-Service
8. ✅ Phase 7: Testing & Integration

---

## Nächster Schritt

**Soll ich mit Phase 1 starten?** Das würde beinhalten:
1. Migrationen 048, 049, 050 ausführen
2. Prüfen, dass alles funktioniert
3. Dann Phase 2 starten

Oder bevorzugst du eine andere Reihenfolge?

