# Vergleich: Beispiel-Staging-Row vs. Aktueller Stand

## Vollständiges Beispiel (Ziel)

### 1) raw_data (Original aus Excel)
```json
{
  "row_number": 12,
  "Firmenname": "Musterfarben GmbH",
  "Website": "www.musterfarben.de",
  "Telefon": "+49 30 123456",
  "Oberkategorie": "Chemieindustrie",
  "Kategorie": "Farbenhersteller",
  "PLZ": "12345",
  "Stadt": "Berlin",
  "Land": "DE"
}
```

**Aktueller Stand:**
- ✅ `raw_data` wird gespeichert (Zeile 340 in `saveStagingRow()`)
- ⚠️ Aber: Aktuell wird `$rowData` gespeichert (gemappte Daten), nicht Original Excel
- ❌ **Muss angepasst werden**: Original Excel-Zeile speichern

---

### 2) mapped_data (nach Mapping + Transformation)
```json
{
  "org": {
    "name": "Musterfarben GmbH",
    "website": "https://www.musterfarben.de",
    "phone": "+49 30 123456",
    "address": {
      "postal_code": "12345",
      "city": "Berlin",
      "country": "DE"
    }
  },
  "industry": {
    "excel_level2_label": "Chemieindustrie",
    "excel_level3_label": "Farbenhersteller"
  }
}
```

**Aktueller Stand:**
```php
// In saveStagingRow() Zeile 341
'mapped_data' => json_encode($rowData),  // ❌ Flache Struktur
```

**Problem:**
- ❌ Aktuell: Flache Struktur (`name`, `website`, `phone`, `industry_level2`, ...)
- ❌ Keine Trennung zwischen `org.*` und `industry.*`
- ❌ Keine `address` Gruppierung

**Muss angepasst werden:**
```php
$mappedData = [
    'org' => [
        'name' => $rowData['name'] ?? null,
        'website' => $this->normalizeUrl($rowData['website'] ?? null),
        'phone' => $rowData['phone'] ?? null,
        'address' => [
            'postal_code' => $rowData['address_postal_code'] ?? null,
            'city' => $rowData['address_city'] ?? null,
            'country' => $rowData['address_country'] ?? 'DE'
        ]
    ],
    'industry' => [
        'excel_level2_label' => $rowData['industry_level2'] ?? null,
        'excel_level3_label' => $rowData['industry_level3'] ?? null
    ]
];
```

---

### 3) industry_resolution (Vorschläge + Entscheidung)

**Beispiel (Staging-Phase):**
```json
{
  "excel": {
    "level2_label": "Chemieindustrie",
    "level3_label": "Farbenhersteller"
  },
  "suggestions": {
    "level2_candidates": [
      {
        "industry_uuid": "77a88793e72311f0992a9647320be4be",
        "code": "C20",
        "name": "C20 - Herstellung von chemischen Erzeugnissen",
        "score": 0.91
      }
    ],
    "derived_level1": {
      "industry_uuid": "779bb588e72311f0992a9647320be4be",
      "code": "C",
      "name": "C - Verarbeitendes Gewerbe",
      "derived_from_level2_uuid": "77a88793e72311f0992a9647320be4be"
    },
    "level3_candidates": [],
    "level3_search_scope": {
      "under_level2_uuid": "77a88793e72311f0992a9647320be4be"
    }
  },
  "decision": {
    "status": "PENDING",
    "level1_uuid": "779bb588e72311f0992a9647320be4be",
    "level2_uuid": "77a88793e72311f0992a9647320be4be",
    "level3_uuid": null,
    "level1_confirmed": false,
    "level2_confirmed": false,
    "level3_action": "UNDECIDED",
    "level3_new_name": null
  }
}
```

**Aktueller Stand:**
- ❌ **Feld existiert nicht** in `org_import_staging`
- ❌ Wird nicht erstellt in `saveStagingRow()`
- ❌ `buildIndustryResolution()` fehlt

**Muss implementiert werden:**
- ✅ Migration: `industry_resolution` Feld hinzufügen
- ✅ `buildIndustryResolution()` Methode erstellen
- ✅ In `saveStagingRow()` aufrufen und speichern

---

### 4) validation_status + validation_errors

**Beispiel:**
```json
{
  "validation_status": "warning",
  "validation_errors": [
    {
      "severity": "WARNING",
      "code": "IND_L3_NO_MATCH",
      "field": "industry.level3",
      "message": "Keine passende Unterbranche unter der vorgeschlagenen Branche gefunden."
    }
  ]
}
```

**Aktueller Stand:**
```php
// In saveStagingRow() Zeile 345-349
'validation_status' => $validationStatus,  // ✅ Existiert
'validation_errors' => json_encode([
    'errors' => $validation['errors'] ?? [],
    'warnings' => $validation['warnings'] ?? []
])  // ⚠️ Struktur anders
```

**Vergleich:**
- ✅ `validation_status` existiert
- ⚠️ `validation_errors` Struktur ist anders (aktuell: `{errors: [], warnings: []}`)
- ⚠️ Beispiel: Array von Objekten mit `severity`, `code`, `field`, `message`

**Muss angepasst werden:**
- ⚠️ Optional: Struktur vereinheitlichen (kann später kommen)

---

### 5) review_status + Review-Metadaten

**Beispiel (vor Review):**
```json
{
  "review_status": "pending",
  "review_notes": null
}
```

**Beispiel (nach Review):**
```json
{
  "review_status": "approved",
  "reviewed_by_user_id": "user-123",
  "reviewed_at": "2026-01-03T10:45:00Z",
  "review_notes": "Chemieindustrie = C20 bestätigt; Unterbranche neu angelegt."
}
```

**Aktueller Stand:**
```sql
-- In Migration 042
disposition VARCHAR(50) DEFAULT 'pending',  -- ✅ Entspricht review_status
reviewed_by_user_id VARCHAR(255),          -- ✅ Existiert
reviewed_at DATETIME,                      -- ✅ Existiert
review_notes TEXT                          -- ✅ Existiert
```

**Vergleich:**
- ✅ Alle Felder existieren
- ⚠️ `disposition` statt `review_status` (kann umbenannt werden oder Mapping)

**Passt!** ✅

---

### 6) import_status + commit_log

**Beispiel:**
```json
{
  "import_status": "imported",
  "imported_org_uuid": "ORG-UUID-NEU",
  "imported_at": "2026-01-03T10:50:00Z",
  "commit_log": [
    {
      "action": "CREATE_INDUSTRY_LEVEL3",
      "new_industry_uuid": "NEW-L3-UUID",
      "parent_level2_uuid": "77a88793e72311f0992a9647320be4be",
      "name": "Farbenhersteller"
    },
    {
      "action": "CREATE_ORG",
      "org_uuid": "ORG-UUID-NEU"
    }
  ]
}
```

**Aktueller Stand:**
```sql
-- In Migration 042
import_status VARCHAR(50) DEFAULT 'pending',  -- ✅ Existiert
imported_org_uuid CHAR(36),                   -- ✅ Existiert
imported_at DATETIME                          -- ✅ Existiert
-- ❌ commit_log fehlt
```

**Muss hinzugefügt werden:**
- ❌ `commit_log` JSON Feld fehlt
- ⚠️ Optional: Kann später hinzugefügt werden (nicht kritisch für MVP)

---

### 7) corrections_json + effective_data

**Beispiel:**
```json
{
  "corrections_json": {
    "org": { "website": "https://musterfarben.de" }
  }
}
// effective_data = merge(mapped_data, corrections_json)
```

**Aktueller Stand:**
```sql
-- In Migration 042
corrections_json JSON,      -- ✅ Existiert
effective_data JSON         -- ✅ Existiert
```

**Aktueller Code:**
```php
// In saveStagingRow() Zeile 317
$effectiveData = $rowData;  // ⚠️ Noch kein Merge mit corrections
```

**Muss angepasst werden:**
- ✅ Felder existieren
- ⚠️ `effective_data` sollte `merge(mapped_data, corrections_json)` sein
- ⚠️ Aktuell wird `effective_data = mapped_data` gesetzt

---

## Zusammenfassung: Was passt, was muss angepasst werden

### ✅ Passt bereits:
1. `validation_status`, `validation_errors` (Struktur leicht anders, aber funktional)
2. `review_status` (als `disposition`), `reviewed_by_user_id`, `reviewed_at`, `review_notes`
3. `import_status`, `imported_org_uuid`, `imported_at`
4. `corrections_json`, `effective_data` (Felder existieren)

### ⚠️ Muss angepasst werden:

1. **raw_data:**
   - ❌ Aktuell: Gemappte Daten werden gespeichert
   - ✅ Muss: Original Excel-Zeile speichern

2. **mapped_data:**
   - ❌ Aktuell: Flache Struktur
   - ✅ Muss: Strukturiert mit `org.*` und `industry.*` Gruppierung

3. **industry_resolution:**
   - ❌ Feld existiert nicht
   - ❌ Wird nicht erstellt
   - ✅ Muss: Migration + `buildIndustryResolution()` implementieren

4. **effective_data:**
   - ⚠️ Aktuell: `= mapped_data`
   - ✅ Muss: `merge(mapped_data, corrections_json)`

5. **commit_log:**
   - ❌ Feld fehlt
   - ⚠️ Optional für MVP, kann später hinzugefügt werden

---

## Anpassungen für unsere Changes

### 1. raw_data speichern (Original Excel)

```php
// In importToStaging()
for ($row = $dataStartRow; $row <= $highestRow; $row++) {
    // Lese Original Excel-Zeile (vor Mapping)
    $rawRowData = $this->readRawExcelRow($worksheet, $row, $headerRow);
    
    // Lese gemappte Zeile
    $rowData = $this->mappingService->readRow($worksheet, $row, $mappingConfig);
    
    // Speichere mit raw_data
    $stagingUuid = $this->saveStagingRow(
        $batchUuid,
        $row - $dataStartRow + 1,
        $rawRowData,  // ✅ Original
        $rowData,     // ✅ Gemappt
        // ...
    );
}
```

### 2. mapped_data strukturieren

```php
// In saveStagingRow()
private function structureMappedData(array $rowData): array
{
    return [
        'org' => [
            'name' => $rowData['name'] ?? null,
            'website' => $this->normalizeUrl($rowData['website'] ?? null),
            'phone' => $rowData['phone'] ?? null,
            'address' => [
                'postal_code' => $rowData['address_postal_code'] ?? null,
                'city' => $rowData['address_city'] ?? null,
                'country' => $rowData['address_country'] ?? 'DE'
            ]
        ],
        'industry' => [
            'excel_level2_label' => $rowData['industry_level2'] ?? $rowData['industry_main'] ?? null,
            'excel_level3_label' => $rowData['industry_level3'] ?? $rowData['industry_sub'] ?? null
        ]
    ];
}
```

### 3. industry_resolution erstellen

```php
// In saveStagingRow()
$industryResolution = $this->buildIndustryResolution(
    $structuredMappedData,
    $mappingConfig
);

// Speichere
'industry_resolution' => json_encode($industryResolution)
```

### 4. effective_data berechnen

```php
// In saveStagingRow()
$mappedData = $this->structureMappedData($rowData);
$corrections = json_decode($row['corrections_json'] ?? 'null', true) ?? [];
$effectiveData = $this->mergeRecursive($mappedData, $corrections);
```

---

## Fazit

**Unsere geplanten Changes passen zum Beispiel, ABER:**

1. ✅ **industry_resolution**: Wird durch unsere Changes implementiert
2. ⚠️ **mapped_data Struktur**: Muss angepasst werden (org.* + industry.*)
3. ⚠️ **raw_data**: Muss Original Excel speichern, nicht gemappte Daten
4. ⚠️ **effective_data**: Muss Merge-Logik implementieren
5. ⚠️ **commit_log**: Optional, kann später hinzugefügt werden

**Empfehlung:** 
- Phase 1: `industry_resolution` + `mapped_data` Strukturierung
- Phase 2: `raw_data` Original speichern + `effective_data` Merge
- Phase 3: `commit_log` (optional)
