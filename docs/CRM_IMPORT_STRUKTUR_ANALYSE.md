# Analyse: mapping_config und industry_resolution Strukturen

## Aktueller Stand vs. Konzept-Vorschlag

### 1. mapping_config (Template) - Vergleich

#### Aktuell in TOM:
```php
// In OrgImportService::saveMapping()
$mappingConfig = [
    'header_row' => 1,
    'data_start_row' => 2,
    'columns' => [
        'name' => ['excel_column' => 'A', 'required' => true],
        'website' => ['excel_column' => 'B'],
        // ...
    ]
];
```

#### Konzept-Vorschlag:
```json
{
  "version": 1,
  "source": {
    "type": "excel",
    "sheet": "Tabelle1",
    "header_row": 1,
    "locale": "de-DE"
  },
  "column_mapping": {
    "org.name": { "excel_header": "Firmenname", "required": true },
    "org.website": { "excel_header": "Website", "transform": ["trim", "to_url"] },
    "industry.excel_level2_label": { "excel_header": "Oberkategorie" },
    "industry.excel_level3_label": { "excel_header": "Kategorie" }
  },
  "industry_mapping": {
    "enabled": true,
    "model": "industry_table_3_levels",
    "levels": {
      "level1": { "name": "Branchenbereich", "db_field": "industry_level1_uuid" },
      "level2": { "name": "Branche", "db_field": "industry_level2_uuid" },
      "level3": { "name": "Unterbranche", "db_field": "industry_level3_uuid" }
    },
    "match_strategy": {
      "match_level2_from_excel": "industry.excel_level2_label",
      "then_derive_level1_from_parent": true,
      "match_level3_from_excel": "industry.excel_level3_label",
      "normalization": {
        "lowercase": true,
        "strip_punctuation": true,
        "umlaut_fold": true,
        "remove_suffixes": ["industrie", "hersteller", "produktion", "fertigung", "handel"]
      },
      "thresholds": {
        "auto_suggest_level2_min": 0.75,
        "auto_suggest_level3_min": 0.70
      },
      "allow_create_level3_if_no_match": true,
      "create_level3_scope": "under_selected_level2_only"
    }
  }
}
```

#### Unterschiede:
1. ✅ **Aktuell**: Flache Struktur mit `columns`
2. ❌ **Fehlt**: `version`, `source` Metadaten
3. ❌ **Fehlt**: `industry_mapping` Sektion komplett
4. ❌ **Fehlt**: `match_strategy`, `normalization`, `thresholds`
5. ❌ **Fehlt**: Trennung zwischen `org.*` und `industry.*` Feldern

#### Empfehlung:
- **Migration**: Bestehende `mapping_config` Struktur erweitern (nicht brechen)
- **Rückwärtskompatibilität**: Alte Struktur weiterhin unterstützen
- **Neue Struktur**: Schrittweise einführen, wenn `version >= 2`

---

### 2. industry_resolution (pro Staging-Row) - Vergleich

#### Aktuell in TOM:
```php
// In OrgImportService::saveStagingRow()
$mapped_data = json_encode($rowData);  // Enthält industry_level2, industry_level3 als Excel-Werte
// ❌ Keine industry_resolution Struktur
```

#### Konzept-Vorschlag:
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
        "score": 0.91,
        "match_reason": ["alias_or_suffix_match", "token_similarity_high"]
      }
    ],
    "derived_level1": {
      "industry_uuid": "779bb588e72311f0992a9647320be4be",
      "code": "C",
      "name": "Verarbeitendes Gewerbe",
      "derived_from_level2_uuid": "77a88793e72311f0992a9647320be4be"
    },
    "level3_candidates": []
  },
  "decision": {
    "level1_uuid": "779bb588e72311f0992a9647320be4be",
    "level2_uuid": "77a88793e72311f0992a9647320be4be",
    "level3_uuid": null,
    "level1_confirmed": false,
    "level2_confirmed": false,
    "level3_action": "UNDECIDED",
    "level3_new_name": null,
    "status": "PENDING_REVIEW"
  }
}
```

#### Unterschiede:
1. ❌ **Fehlt komplett**: `industry_resolution` Feld existiert nicht in `org_import_staging`
2. ❌ **Fehlt**: `suggestions` Struktur (Kandidaten, Scores, Match-Reasons)
3. ❌ **Fehlt**: `derived_level1` (abgeleitet aus Level 2)
4. ❌ **Fehlt**: `decision` Struktur (bestätigte UUIDs, Flags, Status)

#### Aktueller Workflow:
- `ImportIndustryValidationService::validateIndustries()` erstellt `combinations`
- Aber: Diese werden nur im Frontend angezeigt, nicht in DB gespeichert
- `industryDecisions` existiert nur im Browser (`this.industryDecisions = {}`)

#### Empfehlung:
- **Migration**: `industry_resolution` Feld zu `org_import_staging` hinzufügen
- **Backend**: `buildIndustryResolution()` in `importToStaging()` einbauen
- **Frontend**: Entscheidungen per API speichern (nicht nur im Browser)

---

## Konkrete Umsetzung

### Schritt 1: Migration für industry_resolution

```sql
-- Migration 048: industry_resolution zu staging
ALTER TABLE org_import_staging 
ADD COLUMN industry_resolution JSON NULL 
COMMENT 'Vorschläge + bestätigte Branchen-Entscheidung pro Zeile';
```

### Schritt 2: Migration für industry_decisions (temporär)

```sql
-- Migration 049: industry_decisions zu batch (temporär, bis industry_resolution pro Zeile)
ALTER TABLE org_import_batch 
ADD COLUMN industry_decisions JSON NULL 
COMMENT 'Branchen-Entscheidungen pro Excel-Wert (temporär, wird in industry_resolution überführt)';
```

### Schritt 3: Backend - buildIndustryResolution()

```php
// In OrgImportService::importToStaging()
private function buildIndustryResolution(
    array $rowData, 
    array $mappingConfig, 
    ?array $industryDecisions = null
): array {
    // Extrahiere Excel-Werte
    $excelLevel2 = $rowData['industry_level2'] ?? $rowData['industry_main'] ?? null;
    $excelLevel3 = $rowData['industry_level3'] ?? $rowData['industry_sub'] ?? null;
    
    // Initialisiere Resolution
    $resolution = [
        'excel' => [
            'level2_label' => $excelLevel2,
            'level3_label' => $excelLevel3
        ],
        'suggestions' => [
            'level2_candidates' => [],
            'derived_level1' => null,
            'level3_candidates' => []
        ],
        'decision' => [
            'status' => 'PENDING',
            'level1_uuid' => null,
            'level2_uuid' => null,
            'level3_uuid' => null,
            'level1_confirmed' => false,
            'level2_confirmed' => false,
            'level3_action' => 'UNDECIDED',
            'level3_new_name' => null
        ]
    ];
    
    // Wenn industryDecisions vorhanden (aus Mapping-Phase)
    if ($industryDecisions && $excelLevel2 && isset($industryDecisions[$excelLevel2])) {
        $decision = $industryDecisions[$excelLevel2];
        $resolution['decision']['level2_uuid'] = $decision['industry_uuid'] ?? null;
        // ... weitere Logik ...
    }
    
    // Wenn Excel-Level2 vorhanden: Suche Kandidaten
    if ($excelLevel2) {
        $candidates = $this->findLevel2Candidates($excelLevel2);
        $resolution['suggestions']['level2_candidates'] = $candidates;
        
        // Beste Kandidat → ableite Level 1
        if (!empty($candidates)) {
            $best = $candidates[0];
            $resolution['decision']['level2_uuid'] = $best['industry_uuid'];
            
            $level1 = $this->deriveLevel1FromLevel2($best['industry_uuid']);
            if ($level1) {
                $resolution['suggestions']['derived_level1'] = $level1;
                $resolution['decision']['level1_uuid'] = $level1['industry_uuid'];
            }
            
            // Level 3 Kandidaten suchen
            if ($excelLevel3) {
                $level3Candidates = $this->findLevel3Candidates(
                    $best['industry_uuid'], 
                    $excelLevel3
                );
                $resolution['suggestions']['level3_candidates'] = $level3Candidates;
            }
        }
    }
    
    return $resolution;
}
```

### Schritt 4: mapping_config Struktur erweitern

```php
// In OrgImportService::saveMapping()
// Erweitere mapping_config um industry_mapping Sektion
$mappingConfig = [
    'version' => 2,  // Neue Version
    'source' => [
        'type' => 'excel',
        'sheet' => 'Tabelle1',  // TODO: Aus Analysis
        'header_row' => 1,
        'locale' => 'de-DE'
    ],
    'column_mapping' => [
        // Bestehende Struktur beibehalten für Rückwärtskompatibilität
        'columns' => $existingColumns,
        // Neue Struktur
        'org.name' => ['excel_header' => 'Firmenname', 'required' => true],
        'industry.excel_level2_label' => ['excel_header' => 'Oberkategorie'],
        'industry.excel_level3_label' => ['excel_header' => 'Kategorie']
    ],
    'industry_mapping' => [
        'enabled' => true,
        'model' => 'industry_table_3_levels',
        'levels' => [
            'level1' => ['name' => 'Branchenbereich', 'db_field' => 'industry_level1_uuid'],
            'level2' => ['name' => 'Branche', 'db_field' => 'industry_level2_uuid'],
            'level3' => ['name' => 'Unterbranche', 'db_field' => 'industry_level3_uuid']
        ],
        'match_strategy' => [
            'match_level2_from_excel' => 'industry.excel_level2_label',
            'then_derive_level1_from_parent' => true,
            'match_level3_from_excel' => 'industry.excel_level3_label',
            'normalization' => [
                'lowercase' => true,
                'strip_punctuation' => true,
                'umlaut_fold' => true,
                'remove_suffixes' => ['industrie', 'hersteller', 'produktion', 'fertigung', 'handel']
            ],
            'thresholds' => [
                'auto_suggest_level2_min' => 0.75,
                'auto_suggest_level3_min' => 0.70
            ],
            'allow_create_level3_if_no_match' => true,
            'create_level3_scope' => 'under_selected_level2_only'
        ]
    ]
];
```

---

## Zusammenfassung: Was fehlt

### mapping_config:
- ✅ Basis-Struktur vorhanden (`columns`)
- ❌ `version` Feld fehlt
- ❌ `source` Metadaten fehlen
- ❌ `industry_mapping` Sektion fehlt komplett
- ❌ `match_strategy`, `normalization`, `thresholds` fehlen

### industry_resolution:
- ❌ Feld existiert nicht in `org_import_staging`
- ❌ `suggestions` Struktur wird nicht erstellt
- ❌ `decision` Struktur wird nicht gespeichert
- ❌ UI-Entscheidungen werden nicht persistiert

### Nächste Schritte:
1. Migrationen erstellen (industry_resolution, industry_decisions)
2. `buildIndustryResolution()` implementieren
3. `mapping_config` Struktur erweitern
4. API-Endpoint für Industry-Entscheidungen
5. Frontend: Entscheidungen per API speichern
