# Header-Row Detection & Template Matching

## Implementierte Features

### 1. Verbesserte Header-Row Detection

**Vorher:**
- Einfache Heuristik (Text vs. Zahlen, Keywords)
- Statische Keyword-Liste

**Jetzt:**
- Nutzt bekannte Header-Tokens aus:
  - `expected_headers_json` aller aktiven Templates
  - `import_header_alias.header_alias` (normalisiert)
- Berücksichtigt nächste Zeile (Daten-Likelihood)
- Robusterer Score-Algorithmus

**Code:**
- `ImportTemplateService::loadKnownHeaderTokens()` - lädt bekannte Tokens
- `OrgImportService::detectHeaderRow()` - verbesserte Heuristik
- `OrgImportService::scoreHeaderRow()` - bewertet Zeile
- `OrgImportService::dataLikelihood()` - schätzt Daten-Wahrscheinlichkeit

---

### 2. Template Matching

**Funktionalität:**
- Automatisches Matching von Excel-Headern gegen Templates
- Fit-Score-Berechnung (0.0-1.0)
- Required-Coverage-Prüfung (hart)
- Overlap-Score (weich)
- Bonus für starke Felder (org.name, org.website, etc.)

**Code:**
- `ImportTemplateService::chooseBestTemplate()` - wählt bestes Template
- `ImportTemplateService::computeTemplateFit()` - berechnet Fit-Score
- `ImportTemplateService::loadAliasesByTarget()` - lädt Aliases gruppiert

**Score-Klassifizierung:**
- `>= 0.85`: `AUTO_SUGGEST_STRONG` → 1-Klick-Bestätigung
- `>= 0.60`: `AUTO_SUGGEST_WEAK` → Mapping-UI mit Vorauswahl
- `< 0.60`: `NO_MATCH` → Mapping-UI normal

---

### 3. Integration in Workflow

**Ablauf:**
1. User lädt Excel hoch
2. System erkennt Header-Zeile automatisch (mit bekannten Tokens)
3. System sucht bestes Template
4. `analyzeExcel()` gibt zurück:
   - `header_row` (erkannt)
   - `template_match` (Template-Vorschlag mit Score)

**UI zeigt:**
- Template X (Score 0.92) vorgeschlagen → Button "Übernehmen"
- Button "Mapping anpassen" (öffnet Wizard mit Vorschlägen)
- Wenn NO_MATCH: automatisch in Mapping-Schritt springen

---

### 4. Batch-Metadaten

**Neue Felder in `org_import_batch`:**
- `detected_header_row` - automatisch erkannte Header-Zeile
- `detected_template_uuid` - UUID des erkannten Templates
- `detected_template_score` - Fit-Score (0.00-1.00)

**Vorteil:**
- Debugging: Warum wurde welches Template gewählt?
- Audit: Nachvollziehbarkeit
- Lernen: Welche Templates funktionieren gut?

---

## Beispiel-Workflow

### Szenario: Gleiches Excel-Format (2. Import)

**1. Import (erster):**
- Upload → Header erkannt (Zeile 1)
- Kein Template gefunden → Mapping manuell
- "Als Template speichern" → Template "Standard Excel" erstellt

**2. Import (zweiter, gleiches Format):**
- Upload → Header erkannt (Zeile 1, mit bekannten Tokens)
- Template "Standard Excel" gefunden (Score 0.92)
- UI zeigt: "Template 'Standard Excel' (92%) vorgeschlagen"
- User klickt "Übernehmen" → Mapping übernommen
- **Zeitersparnis: ~5 Minuten**

---

## Technische Details

### Header-Normalisierung

```php
function normalizeHeader(string $h): string {
    $h = trim(mb_strtolower($h));
    $h = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $h);
    $h = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return trim($h);
}
```

**Beispiele:**
- `"Firmenname"` → `"firmenname"`
- `"Firma-Name"` → `"firma name"`
- `"PLZ / Postleitzahl"` → `"plz postleitzahl"`

---

### Fit-Score-Berechnung

**Komponenten:**
1. **Required Coverage (hart):**
   - Prüft, ob alle `required_targets` abgedeckt sind
   - Wenn nicht → Score = 0.0

2. **Expected Overlap (weich):**
   - Wie viele `expected_headers` kommen in Excel vor?
   - `overlap = hits / total_expected`

3. **Bonus für starke Felder:**
   - `org.name`, `org.website`, `org.phone`, `org.address.postal_code`
   - Jeder Match: +0.05

**Formel:**
```
score = min(1.0, 0.15 + 0.85 * overlap + bonus)
```

---

## Migration

**Migration 052:**
- Fügt `detected_header_row`, `detected_template_uuid`, `detected_template_score` zu `org_import_batch` hinzu

**Ausführen:**
```bash
php scripts/run-migration-052.php
```

---

## Zusammenfassung

✅ **Verbesserte Header-Row Detection:**
- Nutzt bekannte Tokens aus Templates + Aliases
- Robusterer Algorithmus
- Berücksichtigt Daten-Likelihood

✅ **Template Matching:**
- Automatisches Matching beim Upload
- Fit-Score-Berechnung
- Klassifizierung für UI-Entscheidung

✅ **Integration:**
- `analyzeExcel()` gibt `template_match` zurück
- Batch-Metadaten für Debugging/Audit

✅ **Vorteile:**
- Deutlich weniger manuelle Arbeit bei wiederkehrenden Formaten
- System wird mit der Zeit "intelligenter" (durch Aliases)
- Nachvollziehbarkeit durch Metadaten
