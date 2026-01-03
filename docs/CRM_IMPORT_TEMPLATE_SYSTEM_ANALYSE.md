# Analyse: Template-System für Import-Mappings

## Frage
**Helfen die Codebeispiele? Sollen wir Template-Verwaltung, Header-Aliases und automatische Erkennung implementieren?**

---

## Aktueller Stand

### ✅ Was wir haben:
1. **Mapping pro Batch:**
   - `org_import_batch.mapping_config` (JSON) - wird pro Batch gespeichert
   - `org_import_batch.mapping_template_id` (VARCHAR) - Feld existiert, wird aber **nicht genutzt**

2. **Mapping-Funktionalität:**
   - `ImportMappingService::suggestMapping()` - automatische Vorschläge basierend auf Keywords
   - `ImportMappingService::readRow()` - wendet Mapping an
   - UI: Sales Ops kann Mapping manuell anpassen

3. **Workflow:**
   - Upload → Analyse → Mapping (manuell) → Staging → Review → Commit

### ❌ Was fehlt:
1. **Template-Verwaltung:**
   - Keine Tabelle für wiederverwendbare Templates
   - Jedes Mapping muss neu erstellt werden (auch bei ähnlichen Excel-Formaten)

2. **Automatische Erkennung:**
   - Keine Header-Fingerprints
   - Keine Template-Matching-Logik
   - Keine Fit-Score-Berechnung

3. **Lernfähigkeit:**
   - Keine Header-Aliases
   - System "lernt" nicht aus vorherigen Mappings

---

## Was die Codebeispiele bringen

### 1. Template-Verwaltung (`import_mapping_template`)
**Vorteile:**
- ✅ Mappings sind wiederverwendbar
- ✅ Bei ähnlichen Excel-Formaten: Template auswählen statt neu mappen
- ✅ Versionierung möglich
- ✅ Header-Fingerprints für schnelle Erkennung

**Nachteile:**
- ⚠️ Zusätzliche Komplexität
- ⚠️ Migration nötig (wenn bestehende Mappings als Templates gespeichert werden sollen)

### 2. Header-Aliases (`import_header_alias`)
**Vorteile:**
- ✅ System "lernt" aus manuellen Mappings
- ✅ Bei "Unternehmen" → `org.name` wird automatisch Alias gespeichert
- ✅ Nächster Import erkennt "Unternehmen" sofort

**Nachteile:**
- ⚠️ Zusätzliche Tabelle
- ⚠️ Alias-Verwaltung (wann speichern? wann löschen?)

### 3. Automatische Template-Erkennung
**Vorteile:**
- ✅ Upload → System erkennt Format → Vorschlag mit Score
- ✅ Bei Score >= 85%: "1-Klick-Bestätigung"
- ✅ Bei Score 60-85%: Mapping-UI mit Vorauswahl
- ✅ Bei Score < 60%: Mapping-UI normal

**Nachteile:**
- ⚠️ Header-Normalisierung nötig
- ⚠️ Fit-Score-Algorithmus muss getestet werden
- ⚠️ UI muss Score + Template-Auswahl anzeigen

---

## Vergleich: Aktuell vs. Mit Templates

### Szenario: Gleiches Excel-Format (3x importiert)

**Aktuell (ohne Templates):**
1. Upload → Analyse → Mapping manuell (5 Min)
2. Upload → Analyse → Mapping manuell (5 Min)
3. Upload → Analyse → Mapping manuell (5 Min)
**Gesamt: 15 Minuten**

**Mit Templates:**
1. Upload → Analyse → Mapping manuell → **Als Template speichern** (5 Min)
2. Upload → **Template erkannt (92%)** → Bestätigen (10 Sek)
3. Upload → **Template erkannt (92%)** → Bestätigen (10 Sek)
**Gesamt: ~6 Minuten**

**Ersparnis: ~9 Minuten pro 3 Imports**

---

## Empfehlung

### ✅ **JA, die Codebeispiele helfen!**

**Aber:** Nicht kritisch für MVP, sehr nützlich für Produktion.

### Implementierungs-Strategie

#### Phase 1: Basis-Templates (MVP)
1. ✅ Migration: `import_mapping_template` Tabelle
2. ✅ Migration: `import_header_alias` Tabelle
3. ✅ Service: `ImportTemplateService` (CRUD für Templates)
4. ✅ UI: "Als Template speichern" Button nach Mapping
5. ✅ UI: Template-Auswahl beim Upload (Dropdown)

**Aufwand:** ~2-3 Stunden
**Nutzen:** Mappings sind wiederverwendbar

#### Phase 2: Automatische Erkennung (Nice-to-Have)
1. ✅ Header-Normalisierung (Funktionen aus Codebeispielen)
2. ✅ Header-Fingerprint-Berechnung
3. ✅ Fit-Score-Algorithmus
4. ✅ Template-Matching beim Upload
5. ✅ UI: Template-Vorschlag mit Score

**Aufwand:** ~4-6 Stunden
**Nutzen:** Deutlich weniger manuelle Arbeit bei wiederkehrenden Formaten

#### Phase 3: Lernfähigkeit (Optional)
1. ✅ Automatisches Alias-Speichern beim Mapping
2. ✅ Alias-Integration in Suggestions
3. ✅ UI: Alias-Verwaltung (optional)

**Aufwand:** ~2-3 Stunden
**Nutzen:** System wird mit der Zeit "intelligenter"

---

## Konkrete Umsetzung

### 1. DB-Migrationen

```sql
-- Migration 051: Template-System
CREATE TABLE import_mapping_template (
  template_uuid CHAR(36) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  import_type VARCHAR(30) NOT NULL DEFAULT 'ORG_ONLY',
  version INT NOT NULL DEFAULT 1,
  mapping_config JSON NOT NULL,
  header_fingerprint CHAR(64) NULL,
  header_fingerprint_v INT NOT NULL DEFAULT 1,
  required_targets_json JSON NULL,
  expected_headers_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_header_alias (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  import_type VARCHAR(30) NOT NULL DEFAULT 'ORG_ONLY',
  target_key VARCHAR(120) NOT NULL,
  header_alias VARCHAR(255) NOT NULL,
  created_by_user_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alias (import_type, target_key, header_alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Service: `ImportTemplateService`

```php
class ImportTemplateService {
    // CRUD für Templates
    public function createTemplate(...)
    public function getTemplate(string $uuid)
    public function listTemplates(string $importType)
    public function updateTemplate(...)
    public function deleteTemplate(...)
    
    // Matching
    public function normalizeHeader(string $header): string
    public function headerSetFingerprint(array $headers): string
    public function computeTemplateFitScore(...): float
    public function chooseBestTemplate(...): array
}
```

### 3. Integration in Workflow

**Upload:**
1. Excel hochladen
2. Header lesen
3. Template-Matching (wenn Templates vorhanden)
4. Wenn Match gefunden → Vorschlag anzeigen
5. Wenn kein Match → Mapping-UI normal

**Nach Mapping:**
1. "Als Template speichern" Button
2. Template-Name eingeben
3. Header-Fingerprint berechnen
4. Template speichern

---

## Fazit

### ✅ **Empfehlung: Implementieren (in Phasen)**

**Phase 1 (MVP):** Basis-Templates
- Templates speichern/laden
- Template-Auswahl beim Upload
- **Aufwand:** ~2-3 Stunden
- **Nutzen:** Mappings wiederverwendbar

**Phase 2 (später):** Automatische Erkennung
- Header-Fingerprints
- Fit-Score
- Template-Matching
- **Aufwand:** ~4-6 Stunden
- **Nutzen:** Deutlich weniger manuelle Arbeit

**Phase 3 (optional):** Lernfähigkeit
- Header-Aliases
- Automatisches Lernen
- **Aufwand:** ~2-3 Stunden
- **Nutzen:** System wird "intelligenter"

---

## Entscheidung

**Soll ich Phase 1 (Basis-Templates) jetzt implementieren?**

Das würde bedeuten:
- ✅ Migration 051: Template-Tabellen
- ✅ Service: `ImportTemplateService`
- ✅ UI: "Als Template speichern" Button
- ✅ UI: Template-Auswahl beim Upload

**Oder erst später, wenn Bedarf besteht?**
