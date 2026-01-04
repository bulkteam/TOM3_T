# Automatische Generierung von Template-Metadaten

## Problem
Beim Speichern eines Templates müssen `required_targets_json` und `expected_headers_json` nicht manuell gepflegt werden - sie können automatisch aus `mapping_config` abgeleitet werden.

---

## Lösung: Automatische Generierung

### 1. Was wird automatisch generiert?

#### A) `required_targets_json`
Liste aller Ziel-Felder, die im Template als `required: true` markiert sind.

**Beispiel:**
```json
["org.name", "industry.excel_level2_label"]
```

#### B) `expected_headers_json`
Liste der normalisierten erwarteten Header (inkl. `excel_header` und `excel_headers[]`), die bei der Template-Erkennung zum Overlap-Score genutzt werden.

**Beispiel:**
```json
["firmenname","website","telefon","oberkategorie","kategorie","plz","stadt","land"]
```

#### C) `header_fingerprint`
SHA-256 Hash über normalisierte Header (für schnelle Erkennung).

---

### 2. Algorithmus beim "Template speichern"

**Eingabe:**
- `mapping_config` (JSON)
- `import_type` (z.B. `ORG_ONLY`)

**Ausgabe:**
- `required_targets_json`
- `expected_headers_json`
- `header_fingerprint`

**Regeln:**
1. Für jedes `targetKey` in `mapping_config.column_mapping`:
   - Sammle `excel_header` und `excel_headers[]` (falls vorhanden)
   - Normalisiere jeden Header (lowercase, trim, umlaute, punctuation raus)
   - Füge in `expected_headers` ein
   - Wenn `required=true` → `targetKey` in `required_targets` aufnehmen

2. Entferne Dubletten, sortiere, speichere.

---

### 3. Header-Normalisierung

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
- `"Oberkategorie"` → `"oberkategorie"`
- `"PLZ / Postleitzahl"` → `"plz postleitzahl"`

---

### 4. Beispiel: Konkrete Generierung

**Input (`mapping_config`):**
```json
{
  "column_mapping": {
    "org.name": { "excel_header": "Firmenname", "required": true },
    "org.website": { "excel_header": "Website" },
    "industry.excel_level2_label": { "excel_header": "Oberkategorie", "required": false },
    "industry.excel_level3_label": { "excel_header": "Kategorie" }
  }
}
```

**Output (automatisch generiert):**
```json
{
  "required_targets_json": ["org.name"],
  "expected_headers_json": ["firmenname","kategorie","oberkategorie","website"],
  "header_fingerprint": "sha256(firmenname|kategorie|oberkategorie|website)"
}
```

---

### 5. Integration in `ImportTemplateService`

**Beim Erstellen:**
```php
$meta = $this->buildTemplateMatchMeta($mappingConfig);

INSERT INTO import_mapping_template (
    mapping_config, required_targets_json, expected_headers_json,
    header_fingerprint, header_fingerprint_v
) VALUES (
    :mapping_config, :required_targets_json, :expected_headers_json,
    :header_fingerprint, 1
);
```

**Beim Aktualisieren:**
- Wenn `mapping_config` geändert wird → Metadaten automatisch neu generieren
- Wenn nur `name` oder `is_default` geändert wird → Metadaten bleiben unverändert

---

### 6. Optional: Alias-Integration

**Konzept:**
- `expected_headers_json` = nur Template-Header (statisch)
- `import_header_alias` = lernender Layer (dynamisch)

**Beim Fit-Score:**
1. Lade `expected_headers_json` aus Template
2. Lade zusätzlich alle `import_header_alias.header_alias` für die Targets des Templates
3. Kombiniere beide für Overlap-Score

**Vorteil:**
- Template bleibt "sauber" (nur statische Header)
- Aliases sind "lernender Layer" (wird mit der Zeit besser)

---

### 7. Automatische Required-Regeln (Optional)

**Hard Required (automatisch):**
- `org.name` immer required (kann automatisch gesetzt werden)

**Soft Required (Warning):**
- Bei `ORG_IMPORT`: mindestens eine Kontaktmöglichkeit (`website` oder `phone`)
- Technisch: `soft_required=true` → nur Score-Malus / Warning, nicht disqualifizierend

**Implementierung:**
```php
// Automatisch required setzen
if ($targetKey === 'org.name') {
    $spec['required'] = true;
}

// Soft required prüfen
if ($importType === 'ORG_ONLY') {
    $hasContact = isset($columnMapping['org.website']) || isset($columnMapping['org.phone']);
    if (!$hasContact) {
        // Warning, aber nicht disqualifizierend
    }
}
```

---

## Zusammenfassung

✅ **Automatische Generierung:**
- `required_targets_json` aus `required: true` Feldern
- `expected_headers_json` aus `excel_header` + `excel_headers[]` (normalisiert)
- `header_fingerprint` aus normalisierten Headern

✅ **Vorteile:**
- Sales Ops muss nichts extra pflegen
- Metadaten sind immer konsistent mit `mapping_config`
- Bei Änderung von `mapping_config` werden Metadaten automatisch aktualisiert

✅ **Implementierung:**
- `ImportTemplateService::buildTemplateMatchMeta()` generiert Metadaten
- Wird automatisch beim `createTemplate()` und `updateTemplate()` aufgerufen


