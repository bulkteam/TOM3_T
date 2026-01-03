# Offene ToDos - Import-System

## Status: Alle kritischen ToDos abgeschlossen ✅

**Kritische ToDos:** 18/18 abgeschlossen (100%)  
**Optionale ToDos:** 26 offen (können später implementiert werden)

---

## ✅ Abgeschlossen

### Phase 0-6: Core Import-System
- ✅ Phase 0: Analyse
- ✅ Phase 1: DB-Migrationen (048, 049, 050)
- ✅ Phase 2: Core Services (IndustryNormalizer, IndustryResolver, IndustryDecisionService)
- ✅ Phase 3: Staging-Service
- ✅ Phase 4: API-Endpoints
- ✅ Phase 5: Frontend-Fixes
- ✅ Phase 6: Commit-Service

### Template-System
- ✅ Migrationen (051, 052)
- ✅ ImportTemplateService
- ✅ API-Endpunkte
- ✅ UI-Integration
- ✅ Header-Detection
- ✅ Template-Matching

### Frontend-Kritische Funktionen
- ✅ `saveMapping()` - Mapping sammeln und speichern
- ✅ `renderReviewStep()` - Staging-Rows laden und Review-UI rendern
- ✅ `commitBatch()` - Commit-API aufrufen

### Bugfixes
- ✅ Analysis-Daten beim Mapping-Step laden
- ✅ `suggestions` und `decision` in `renderIndustryCombination()` initialisiert
- ✅ Veraltetes TODO in `import.js::loadStagingRows()` entfernt (Endpoint existiert bereits)

---

## ⚠️ Offen (1 Haupt-Todo + 7 optionale Code-ToDos)

### 1. Phase 7: Testing & Integration (Haupt-Todo)
**Status:** pending  
**Priorität:** Hoch

**Aufgaben:**
- End-to-End Test durchführen
- Guards prüfen (Industry-Decision Guards)
- State-Transitions testen
- Edge Cases testen

---

## 📝 Kleine ToDos im Code

### Backend (nicht kritisch)

#### 1. `ImportMappingService::findColumnByHeader()` (Zeile 271)
**Datei:** `src/TOM/Service/Import/ImportMappingService.php`  
**Status:** TODO (nicht implementiert)  
**Priorität:** Niedrig (wird aktuell nicht verwendet, da `excel_column` verwendet wird)

**Beschreibung:**
```php
private function findColumnByHeader(string $header, array $mappingConfig): ?string
{
    // TODO: Implementierung
    return null;
}
```

**Notwendigkeit:** Nur wenn Header-Name statt Spalte verwendet wird.

---

#### 2. `ImportCommitService::commitRow()` - Workflow (Zeile 291)
**Datei:** `src/TOM/Service/Import/ImportCommitService.php`  
**Status:** TODO  
**Priorität:** Mittel

**Beschreibung:**
```php
// TODO: Workflow-Service erweitern für QUALIFY_COMPANY
```

**Notwendigkeit:** Workflow-Service muss noch erweitert werden, um automatisch `QUALIFY_COMPANY` Cases zu erstellen.

---

#### 3. `ImportValidationService::validatePhone()` - Vorwahl (Zeile 216)
**Datei:** `src/TOM/Service/Import/ImportValidationService.php`  
**Status:** TODO  
**Priorität:** Niedrig

**Beschreibung:**
```php
// TODO: Vorwahl-Validierung implementieren
```

**Notwendigkeit:** Optional - kann später implementiert werden.

---

#### 4. `OrgImportService::importToStaging()` - raw_data (Zeile 340)
**Datei:** `src/TOM/Service/Import/OrgImportService.php`  
**Status:** TODO  
**Priorität:** Niedrig

**Beschreibung:**
```php
'raw_data' => json_encode($rowData), // TODO: Original Excel-Zeile
```

**Notwendigkeit:** Sollte die originale Excel-Zeile speichern, nicht die bereits gemappten Daten.

---

#### 5. `public/api/import.php` - importType (Zeile 192)
**Datei:** `public/api/import.php`  
**Status:** TODO  
**Priorität:** Niedrig

**Beschreibung:**
```php
$importType = 'ORG_ONLY'; // TODO: Aus Request oder Config
```

**Notwendigkeit:** Sollte aus Request-Parameter oder Config kommen, aktuell hardcodiert.

---

#### 6. `public/api/import.php` - file_path (Zeile 251)
**Datei:** `public/api/import.php`  
**Status:** TODO  
**Priorität:** Niedrig

**Beschreibung:**
```php
// TODO: Hole Datei-Pfad aus DocumentService
```

**Notwendigkeit:** `handleAnalyze()` sollte Datei-Pfad aus DocumentService holen, nicht aus Request.

---

#### 7. `public/api/import.php` - dry-run (Zeile 522)
**Datei:** `public/api/import.php`  
**Status:** TODO  
**Priorität:** Niedrig

**Beschreibung:**
```php
// TODO: Validierung ohne Commit
```

**Notwendigkeit:** Dry-Run für Commit (Validierung ohne tatsächlichen Import).

---

### Frontend (abgeschlossen)

#### 7. `import.js::saveMapping()` ✅
**Datei:** `public/js/modules/import.js`  
**Status:** ✅ Implementiert  
**Priorität:** Hoch (war kritisch)

**Implementierung:**
- Sammelt Mapping aus Radio-Buttons
- Erstellt `mapping_config` JSON
- Validiert Mapping
- Sendet an `POST /api/import/mapping/{batch_uuid}`

---

#### 8. `import.js::renderReviewStep()` ✅
**Datei:** `public/js/modules/import.js`  
**Status:** ✅ Implementiert  
**Priorität:** Hoch (war kritisch)

**Implementierung:**
- Lädt Batch-Details
- Ruft `POST /api/import/staging/{batch_uuid}` auf (Import in Staging)
- Lädt Staging-Rows via `GET /api/import/batch/{batch_uuid}/staging-rows`
- Rendert Review-UI mit Statistiken und Commit-Button

---

#### 9. `import.js::commitBatch()` ✅
**Datei:** `public/js/modules/import.js`  
**Status:** ✅ Implementiert  
**Priorität:** Hoch (war kritisch)

**Implementierung:**
- Bestätigungs-Dialog
- Ruft `POST /api/import/batch/{batch_uuid}/commit` auf
- Zeigt Erfolgsmeldungen
- Setzt UI zurück

---

## 📋 Optionale / Spätere Verbesserungen

### UI-Verbesserungen

#### 1. Diff-Ansicht (Raw → Mapped → Corrected)
**Priorität:** Mittel  
**Beschreibung:** Zeige visuell die Transformation von Excel-Rohdaten über gemappte Daten zu korrigierten Daten.

**Vorteile:**
- Bessere Nachvollziehbarkeit
- Einfacheres Debugging
- Transparenz für Sales Ops

---

#### 2. Bulk Actions mit Guardrails
**Priorität:** Mittel  
**Beschreibung:** 
- "Approve all VALID (no duplicates)" Button
- "Approve WARNINGS" nur mit Checkbox "I understand..."
- Bulk-Korrekturen für mehrere Zeilen gleichzeitig

**Vorteile:**
- Schnellere Bearbeitung großer Imports
- Sicherheits-Guards verhindern Fehler

---

#### 3. Queue "Needs Fix"
**Priorität:** Niedrig  
**Beschreibung:** Separate Ansicht für Zeilen mit Errors/Warnings/Duplicates, damit Sales Ops fokussiert arbeiten kann.

---

#### 4. Vollständige Staging-Vorschau (Firmen + Personen getrennt)
**Priorität:** Mittel  
**Beschreibung:** Zeige Firmen und Personen in separaten Tabs/Ansichten während des Reviews.

**Notwendigkeit:** Aktuell werden Personen noch nicht vollständig unterstützt.

---

### Backend-Services (optional)

#### 5. ImportReviewService
**Priorität:** Mittel  
**Beschreibung:** Dedizierter Service für Review-Entscheidungen, Bulk-Actions, und Review-Status-Management.

**Vorteile:**
- Saubere Trennung der Logik
- Bessere Testbarkeit

---

#### 6. ImportCorrectionService
**Priorität:** Niedrig  
**Beschreibung:** Service für das Patch-System (corrections_json), um Korrekturen sauber zu verwalten.

**Vorteile:**
- Nachvollziehbarkeit (was kam aus Excel vs. was wurde korrigiert)
- Audit-Trail

---

#### 7. ImportProductionService
**Priorität:** Niedrig (wird aktuell von ImportCommitService abgedeckt)  
**Beschreibung:** Dedizierter Service für den finalen Import mit zeilenweisen Transaktionen.

**Notwendigkeit:** 
- Aktuell in `ImportCommitService` integriert
- Könnte später ausgelagert werden für bessere Trennung der Verantwortlichkeiten

**Quelle:** `docs/CRM_IMPORT_IMPLEMENTATION_ZUSAMMENFASSUNG.md`

---

### Workflow-Integration

#### 8. Automatische QUALIFY_COMPANY Cases
**Priorität:** Hoch (für Produktivbetrieb wichtig)  
**Beschreibung:** Nach erfolgreichem Commit automatisch `QUALIFY_COMPANY` Cases für neue Organisationen erstellen.

**Aktueller Status:** TODO in `ImportCommitService::commitRow()` (Zeile 291)

**Notwendigkeit:** 
- Workflow-Service muss erweitert werden
- Integration mit CRM-Workflow-System

---

### Validierungen & Qualität

#### 9. Vorwahl-Validierung für Telefonnummern
**Priorität:** Niedrig  
**Beschreibung:** Prüfe, ob Vorwahl zu Land/PLZ passt.

**Aktueller Status:** TODO in `ImportValidationService::validatePhone()` (Zeile 216)

---

#### 10. Erweiterte Geodaten-Validierung
**Priorität:** Niedrig  
**Beschreibung:** 
- PLZ zu Stadt/PLZ zu Bundesland Validierung
- Koordinaten-Konsistenz prüfen

---

### Performance & Skalierung

#### 11. Batch-Processing für große Imports
**Priorität:** Niedrig  
**Beschreibung:** 
- Chunking für sehr große Excel-Dateien (>10.000 Zeilen)
- Background-Jobs für Staging-Import
- Progress-Tracking

---

#### 12. Caching von Industry-Matches
**Priorität:** Niedrig  
**Beschreibung:** Cache häufig verwendete Industry-Matches, um Performance zu verbessern.

---

### Personen-Import

#### 13. Vollständiger Personen-Import
**Priorität:** Mittel  
**Beschreibung:** 
- `person_import_staging` und `employment_import_staging` Tabellen sind vorhanden (Migration 044)
- UI und Services für Personen-Import noch nicht vollständig implementiert

**Notwendigkeit:**
- Personen-Mapping in UI
- Personen-Review in Staging
- Personen-Commit in Produktion

---

### Template-System (Erweiterungen)

#### 14. Template-Versionierung
**Priorität:** Niedrig  
**Beschreibung:** Templates versionieren, um Änderungen nachvollziehbar zu machen.

---

#### 15. Template-Sharing zwischen Benutzern
**Priorität:** Niedrig  
**Beschreibung:** Templates können zwischen Sales Ops geteilt werden.

---

#### 16. Header-Aliases (Template-System Phase 3)
**Priorität:** Niedrig  
**Beschreibung:** System lernt automatisch alternative Header-Namen (z.B. "Firma" = "Firmenname" = "Name").

**Vorteile:**
- Bessere Template-Erkennung
- Weniger manuelle Mapping-Anpassungen

**Quelle:** `docs/CRM_IMPORT_TEMPLATE_SYSTEM_ANALYSE.md` (Phase 3)

---

#### 17. Automatische Required-Regeln (Template-System)
**Priorität:** Niedrig  
**Beschreibung:** Templates können automatisch erkennen, welche Felder als "required" markiert werden sollten.

**Quelle:** `docs/CRM_IMPORT_TEMPLATE_AUTO_META.md`

---

### Alias-Learning (Erweiterungen)

#### 18. Automatisches Alias-Learning
**Priorität:** Niedrig  
**Beschreibung:** System lernt automatisch aus bestätigten Industry-Entscheidungen (aktuell manuell).

**Aktueller Status:** `industry_alias` Tabelle existiert (Migration 050), aber automatisches Lernen noch nicht implementiert.

---

### API-Erweiterungen

#### 17. `POST /api/import/review` - Review-Entscheidung
**Priorität:** Niedrig  
**Beschreibung:** Dedizierter Endpoint für Review-Entscheidungen pro Zeile.

**Notwendigkeit:** 
- Aktuell wird Review über Commit-Endpoint abgewickelt
- Könnte später ausgelagert werden für bessere Trennung

**Quelle:** `docs/CRM_IMPORT_IMPLEMENTATION_ZUSAMMENFASSUNG.md`

---

#### 18. `POST /api/import/approve` - Freigeben
**Priorität:** Niedrig  
**Beschreibung:** Separater Endpoint für Freigabe von Staging-Rows.

**Quelle:** `docs/CRM_IMPORT_IMPLEMENTATION_ZUSAMMENFASSUNG.md`

---

#### 19. `POST /api/import/production` - Finaler Import
**Priorität:** Niedrig (wird aktuell von `/commit` abgedeckt)  
**Beschreibung:** Dedizierter Endpoint für finalen Import in Produktion.

**Quelle:** `docs/CRM_IMPORT_IMPLEMENTATION_ZUSAMMENFASSUNG.md`

---

#### 20. Header-Aliases (Template-System Phase 3)
**Priorität:** Niedrig  
**Beschreibung:** System lernt automatisch alternative Header-Namen (z.B. "Firma" = "Firmenname" = "Name").

**Vorteile:**
- Bessere Template-Erkennung
- Weniger manuelle Mapping-Anpassungen

**Quelle:** `docs/CRM_IMPORT_TEMPLATE_SYSTEM_ANALYSE.md` (Phase 3)

---

#### 21. Automatische Required-Regeln (Template-System)
**Priorität:** Niedrig  
**Beschreibung:** Templates können automatisch erkennen, welche Felder als "required" markiert werden sollten.

**Quelle:** `docs/CRM_IMPORT_TEMPLATE_AUTO_META.md`

---

#### 22. `importType` aus Request/Config
**Priorität:** Niedrig  
**Beschreibung:** `importType` sollte aus Request-Parameter oder Config kommen, nicht hardcodiert.

**Aktueller Status:** TODO in `public/api/import.php` (Zeile 192)

---

#### 23. Dry-Run für Commit
**Priorität:** Niedrig  
**Beschreibung:** Validierung ohne tatsächlichen Import (Test-Modus).

**Aktueller Status:** TODO in `public/api/import.php` (Zeile 522)

---

#### 24. `file_path` aus DocumentService
**Priorität:** Niedrig  
**Beschreibung:** `handleAnalyze()` sollte Datei-Pfad aus DocumentService holen, nicht aus Request.

**Aktueller Status:** TODO in `public/api/import.php` (Zeile 251) - **Hinweis:** Wurde teilweise bereits in `handleImportToStaging()` implementiert.

---

### Architektur-Verbesserungen (optional)

#### 25. Repository-Pattern
**Priorität:** Niedrig  
**Beschreibung:** DB-Zugriffe in Repositories bündeln für saubere Trennung von Business-Logik und Datenzugriff.

**Vorteile:**
- Bessere Testbarkeit (Mocking)
- Saubere Trennung der Verantwortlichkeiten
- Wiederverwendbarkeit

**Aktueller Status:** Services nutzen direkt DB (funktioniert für MVP)

**Quelle:** `docs/CRM_IMPORT_UMSETZUNGSSTRATEGIE.md`, `docs/CRM_IMPORT_API_SERVICE_ANALYSE.md`

---

#### 26. DTOs (Data Transfer Objects)
**Priorität:** Niedrig  
**Beschreibung:** Typsichere DTOs für API-Requests/Responses und Service-Interfaces.

**Vorteile:**
- Typsicherheit
- Bessere Dokumentation
- Validierung auf Objektebene

**Quelle:** `docs/CRM_IMPORT_API_SERVICE_ANALYSE.md` (Phase 3)

---

## Zusammenfassung

### Kritisch (müssen implementiert werden):
1. ⚠️ **Phase 7: Testing** - End-to-End Tests, Guards prüfen, State-Transitions testen

### Abgeschlossen (✅):
- ✅ `saveMapping()` - Mapping speichern (Frontend)
- ✅ `renderReviewStep()` - Review-UI (Frontend)
- ✅ `commitBatch()` - Commit-Funktion (Frontend)

### Optional / Später (können implementiert werden):
- **UI-Verbesserungen:** Diff-Ansicht, Bulk Actions, Queue "Needs Fix", Staging-Vorschau
- **Backend-Services:** ImportReviewService, ImportCorrectionService, ImportProductionService
- **Workflow-Integration:** Automatische QUALIFY_COMPANY Cases
- **Personen-Import:** Vollständige Implementierung (UI + Services)
- **Validierungen:** Vorwahl-Validierung, erweiterte Geodaten-Validierung
- **Performance:** Batch-Processing für große Imports, Caching von Industry-Matches
- **Template-System:** Versionierung, Sharing, Header-Aliases, automatische Required-Regeln
- **Alias-Learning:** Automatisches Lernen aus Bestätigungen
- **API-Erweiterungen:** importType aus Request/Config, dry-run, file_path, Review/Approve/Production-Endpoints
- **Architektur:** Repository-Pattern, DTOs (Typsicherheit)

---

## Empfehlung

**Nächste Schritte:**
1. **Phase 7: Testing** - End-to-End Test durchführen
   - Guards prüfen (Industry-Decision Guards)
   - State-Transitions testen
   - Edge Cases testen

**Danach (optional, nach Priorität):**
1. **Workflow-Integration** - QUALIFY_COMPANY Cases automatisch erstellen (für Produktivbetrieb wichtig)
2. **UI-Verbesserungen** - Diff-Ansicht, Bulk Actions
3. **Personen-Import** - Vollständige Implementierung
4. **Weitere Validierungen** - Vorwahl, Geodaten
5. **Performance** - Batch-Processing für große Imports
