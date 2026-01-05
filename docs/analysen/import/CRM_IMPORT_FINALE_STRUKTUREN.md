# Finale JSON-Strukturen (Copy/Paste-tauglich)

## A) mapping_config (Template) - Batch-weit

### Exakte Struktur (wie im Konzept):

```json
{
  "version": 1,
  "column_mapping": {
    "org.name": { "excel_header": "Firmenname", "required": true },
    "org.website": { "excel_header": "Website", "transform": ["trim", "to_url"] },
    "org.phone": { "excel_header": "Telefon", "transform": ["trim"] },
    "industry.excel_level2_label": { "excel_header": "Oberkategorie" },
    "industry.excel_level3_label": { "excel_header": "Kategorie" }
  },
  "industry_mapping": {
    "enabled": true,
    "db": {
      "table": "industry",
      "pk": "industry_uuid",
      "name_field": "name",
      "code_field": "code",
      "parent_field": "parent_industry_uuid"
    },
    "levels": {
      "level1": { "label": "Branchenbereich", "filter": { "parent_is_null": true } },
      "level2": { "label": "Branche", "filter": { "parent_code_len": 1 } },
      "level3": { "label": "Unterbranche", "filter": { "parent_is_level2": true } }
    },
    "strategy": {
      "level2_from_excel_field": "industry.excel_level2_label",
      "derive_level1_from_level2_parent": true,
      "level3_from_excel_field": "industry.excel_level3_label",
      "level3_search_scope": "UNDER_SELECTED_LEVEL2",
      "thresholds": {
        "suggest_level2_min": 0.75,
        "suggest_level3_min": 0.70
      },
      "normalization": {
        "lowercase": true,
        "trim": true,
        "umlaut_fold": true,
        "remove_punctuation": true,
        "remove_suffixes": ["industrie", "hersteller", "produktion", "fertigung", "handel"]
      },
      "policy": {
        "allow_user_create_level3": true,
        "allow_user_create_level1": false,
        "allow_user_create_level2": false
      }
    }
  }
}
```

### Vergleich mit aktueller Struktur:

**Aktuell:**
```php
$mappingConfig = [
    'header_row' => 1,
    'data_start_row' => 2,
    'columns' => [
        'name' => ['excel_column' => 'A', 'required' => true],
        // ...
    ]
];
```

**Neue Struktur:**
- ✅ `version` Feld hinzufügen
- ✅ `column_mapping` statt `columns` (mit `org.*` und `industry.*` Präfixen)
- ✅ `industry_mapping` Sektion komplett neu
- ⚠️ Rückwärtskompatibilität: Alte Struktur weiterhin unterstützen

---

## B) staging.industry_resolution (pro Zeile)

### B1) Vor Review (Staging-Analyse):

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
        "name": "Herstellung von chemischen Erzeugnissen",
        "score": 0.91
      },
      {
        "industry_uuid": "7771f87ae72311f0992a9647320be4be",
        "code": "C20",
        "name": "Chemie",
        "score": 0.78
      }
    ],
    "derived_level1": {
      "industry_uuid": "779bb588e72311f0992a9647320be4be",
      "code": "C",
      "name": "C - Verarbeitendes Gewerbe",
      "derived_from_level2_uuid": "77a88793e72311f0992a9647320be4be"
    },
    "level3_candidates": [
      {
        "industry_uuid": null,
        "name": "Farbenhersteller",
        "score": 0.00
      }
    ]
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

### B2) Nach Bestätigung (CREATE_NEW):

```json
{
  "decision": {
    "status": "APPROVED",
    "level1_uuid": "779bb588e72311f0992a9647320be4be",
    "level2_uuid": "77a88793e72311f0992a9647320be4be",
    "level3_uuid": null,
    "level1_confirmed": true,
    "level2_confirmed": true,
    "level3_action": "CREATE_NEW",
    "level3_new_name": "Farbenhersteller"
  }
}
```

### B3) Nach Bestätigung (SELECT_EXISTING):

```json
{
  "decision": {
    "status": "APPROVED",
    "level1_uuid": "779bb588e72311f0992a9647320be4be",
    "level2_uuid": "77a88793e72311f0992a9647320be4be",
    "level3_uuid": "UUID_DER_UNTERBRANCHE",
    "level1_confirmed": true,
    "level2_confirmed": true,
    "level3_action": "SELECT_EXISTING",
    "level3_new_name": null
  }
}
```

---

## C) UI-Guards (Kaskadierende Dropdowns)

### Regeln:

1. **Level1 Dropdown:**
   - ✅ Immer aktiv
   - ✅ Vorbelegt wenn `derived_level1` existiert

2. **Level2 Dropdown:**
   - ✅ Wird aktiv wenn:
     - Level1 bestätigt wurde (`level1_confirmed == true`) ODER
     - Level2 bereits vorbelegt ist (`level2_uuid != null`)

3. **Level1 geändert:**
   - ✅ Reset Level2: `level2_uuid = null`, `level2_confirmed = false`
   - ✅ Reset Level3: `level3_uuid = null`, `level3_action = UNDECIDED`

4. **Level2 geändert:**
   - ✅ Reset Level3: `level3_uuid = null`, `level3_action = UNDECIDED`

5. **Create-New (Level3):**
   - ✅ Nur erlaubt wenn:
     - `level2_confirmed == true`
     - Kein Level3 Treffer mit `score >= threshold` vorhanden (oder User will trotzdem "neu")

### Aktueller Stand in import.js:

- ⚠️ Teilweise implementiert, aber nicht vollständig
- ⚠️ Guards fehlen serverseitig
- ✅ Frontend-Logik für Reset vorhanden (in `onLevel1Selected`, `onLevel2Selected`)

---

## D) Commit-Logik (Import in Produktion)

### Exakte Logik:

```php
// Für jede Zeile mit review_status = approved:
foreach ($approvedRows as $row) {
    $resolution = json_decode($row['industry_resolution'], true);
    $decision = $resolution['decision'] ?? [];
    
    // 1. Level 3 erstellen wenn CREATE_NEW
    $level3Uuid = null;
    if ($decision['level3_action'] === 'CREATE_NEW') {
        // Insert industry
        $level3Uuid = $this->createIndustry([
            'industry_uuid' => UuidHelper::generate($this->db),
            'name' => $decision['level3_new_name'],
            'code' => null,
            'parent_industry_uuid' => $decision['level2_uuid']
        ]);
        
        // Optional: Zurückschreiben in staging
        $decision['level3_uuid'] = $level3Uuid;
        $this->updateIndustryResolution($row['staging_uuid'], $resolution);
    } else {
        $level3Uuid = $decision['level3_uuid'];
    }
    
    // 2. Org erstellen mit Industry-UUIDs
    $orgUuid = $this->orgService->createOrg([
        'name' => $mapped['org']['name'],
        'industry_level1_uuid' => $decision['level1_uuid'],
        'industry_level2_uuid' => $decision['level2_uuid'],
        'industry_level3_uuid' => $level3Uuid  // existing oder neu
    ]);
}
```

### Aktueller Stand:

- ❌ **Komplett fehlend**: Kein `ImportCommitService`
- ❌ Keine Logik für Level 3 Erstellung
- ❌ Keine Logik für Org-Erstellung mit Industry-UUIDs

---

## E) Zwei Endpoints (MVP)

### Endpoint 1: POST /import/batch/{batchUuid}/stage

**Zweck:** Erzeugt staging rows + industry_resolution suggestions

**Request:**
```
POST /api/import/batch/{batchUuid}/stage
Body: { "file_path": "..." }
```

**Response:**
```json
{
  "batch_uuid": "...",
  "stats": {
    "total_rows": 150,
    "imported": 148,
    "errors": 2
  },
  "staging_rows": [
    {
      "staging_uuid": "...",
      "row_number": 1,
      "industry_resolution": { /* B1 Struktur */ }
    }
  ]
}
```

**Aktueller Stand:**
- ✅ Endpoint existiert: `POST /api/import/staging/{batchUuid}`
- ❌ Erstellt keine `industry_resolution` suggestions
- ⚠️ Muss erweitert werden

---

### Endpoint 2: POST /import/staging/{stagingUuid}/industry-decision

**Zweck:** Speichert decision.* (Confirm/Change/Create-Level3)

**Request:**
```json
{
  "level1_uuid": "...",
  "level2_uuid": "...",
  "level3_action": "CREATE_NEW",
  "level3_uuid": null,
  "level3_new_name": "Farbenhersteller",
  "confirm_level1": true,
  "confirm_level2": true
}
```

**Response:**
```json
{
  "staging_uuid": "...",
  "industry_resolution": {
    "decision": { /* Aktualisierte Entscheidung */ }
  },
  "dropdown_options": {
    "level1": [...],
    "level2": [...],
    "level3": [...],
    "level3_create_allowed": true
  },
  "guards": {
    "level2_enabled": true,
    "level3_enabled": true,
    "approve_enabled": true,
    "messages": []
  }
}
```

**Aktueller Stand:**
- ❌ **Existiert nicht**
- ❌ Muss neu erstellt werden

---

## F) Code-Uniqueness (C28 Duplikate)

### Problem:
- Mehrere Industries mit Code `C28`:
  - "Maschinenbau" (C28)
  - "Anlagenbau" (C28)
  - "C28 - Maschinenbau" (C28)

### Lösung:
- ✅ **UI/Backend immer `industry_uuid` als Value verwenden, nie `code`**
- ✅ Matching: Prüfe `uuid + parent_uuid`, nie nur `code`

### Prüfungen nötig:

1. **Frontend (import.js, utils.js):**
   - ✅ Dropdowns verwenden `industry_uuid` als Value? → Prüfen
   - ✅ Keine Code-basierte Identifikation? → Prüfen

2. **Backend (ImportIndustryValidationService):**
   - ⚠️ Verwendet Code für Matching (Zeile 232, 347, 452, 562)
   - ⚠️ Muss auf `uuid + parent_uuid` umgestellt werden

3. **API (industries.php):**
   - ✅ Liefert `industry_uuid` in Response? → Prüfen

---

## Zusammenfassung: Was zu implementieren ist

### mapping_config:
- ✅ Struktur erweitern: `version`, `column_mapping`, `industry_mapping`
- ✅ Rückwärtskompatibilität beibehalten

### industry_resolution:
- ✅ Migration: Feld zu `org_import_staging` hinzufügen
- ✅ `buildIndustryResolution()` implementieren (B1 Struktur)
- ✅ `updateIndustryDecision()` implementieren (B2/B3 Strukturen)

### UI-Guards:
- ✅ Frontend: Guards bereits teilweise implementiert
- ⚠️ Backend: Guards für Konsistenz-Validierung fehlen

### Commit-Logik:
- ❌ `ImportCommitService` komplett neu erstellen
- ❌ Level 3 Erstellung implementieren
- ❌ Org-Erstellung mit Industry-UUIDs

### API-Endpoints:
- ⚠️ `/stage` erweitern: `industry_resolution` suggestions
- ❌ `/industry-decision` neu erstellen

### Code-Uniqueness:
- ⚠️ Backend: Matching auf `uuid + parent_uuid` umstellen
- ✅ Frontend: Prüfen, dass `uuid` verwendet wird

---

## Nächste Schritte

Diese exakten Strukturen sollten 1:1 übernommen werden, da sie:
1. ✅ Copy/Paste-tauglich sind
2. ✅ Keine Interpretationsspielräume lassen
3. ✅ Gut in bestehende Logik passen
4. ✅ Alle Edge-Cases abdecken

**Empfehlung:** Starte mit Phase 1 (kritische Fixes) und verwende diese exakten Strukturen als Vorlage.

