# Analyse: Konzept für Branchen-Import vs. aktuelle TOM-Implementierung

## Zusammenfassung

Das bereitgestellte Konzept ist sehr durchdacht und löst genau die Probleme, die wir aktuell haben. Die wichtigsten Verbesserungen:

1. **Trennung von Template und Entscheidung**: `mapping_config` (Template) vs. `industry_resolution` (pro Zeile)
2. **Persistierung der UI-Entscheidungen**: Branchen-Entscheidungen werden in DB gespeichert
3. **Alias-Learning**: System lernt aus Bestätigungen
4. **Saubere Guards**: Konsistenz-Prüfungen verhindern Fehler

## Was bereits in TOM vorhanden ist ✅

- ✅ `org_import_batch` Tabelle (041)
- ✅ `org_import_staging` Tabelle (042) mit `mapped_data`, `corrections_json`, `effective_data`
- ✅ `import_duplicate_candidates` Tabelle (043)
- ✅ Fingerprints für Idempotenz
- ✅ Validation-Status und Disposition
- ✅ Import-Status Tracking

## Was fehlt / sollte übernommen werden

### 1. **industry_resolution Feld in org_import_staging** ⚠️ KRITISCH

**Problem aktuell:**
- UI-Entscheidungen (Level 1/2/3 Bestätigungen) werden nur im Browser gespeichert
- Beim Staging-Import werden Branchen nicht korrekt übernommen
- Keine Persistierung der User-Entscheidungen

**Lösung aus Konzept:**
```sql
ALTER TABLE org_import_staging 
ADD COLUMN industry_resolution JSON NULL 
COMMENT 'Vorschläge + bestätigte Branchen-Entscheidung pro Zeile';
```

**JSON-Struktur (wie im Konzept):**
```json
{
  "excel": {
    "level2_label": "Chemieindustrie",
    "level3_label": "Farbenhersteller"
  },
  "suggestions": {
    "level2_candidates": [...],
    "derived_level1": {...},
    "level3_candidates": [...]
  },
  "decision": {
    "status": "PENDING|APPROVED",
    "level1_uuid": "...",
    "level2_uuid": "...",
    "level3_uuid": null,
    "level1_confirmed": false,
    "level2_confirmed": false,
    "level3_action": "UNDECIDED|SELECT_EXISTING|CREATE_NEW",
    "level3_new_name": null
  }
}
```

### 2. **Alias-Learning Tabelle** 💡 SEHR SINNVOLL

**Vorteil:**
- System lernt aus Bestätigungen
- "Chemieindustrie" → C20 wird nach 1x Bestätigung sofort erkannt
- Reduziert Fuzzy-Matching-Overhead

**Migration:**
```sql
CREATE TABLE industry_alias (
  alias_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  alias VARCHAR(255) NOT NULL,
  industry_uuid CHAR(36) NOT NULL,
  level TINYINT NOT NULL,  -- 1|2|3
  source VARCHAR(30) NOT NULL DEFAULT 'user',  -- user|import|system
  created_by_user_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY uq_alias_level (alias, level),
  FOREIGN KEY (industry_uuid) REFERENCES industry(industry_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_alias_industry ON industry_alias(industry_uuid);
```

### 3. **Trennung: mapping_config vs. industry_resolution**

**Aktuell:**
- `mapping_config` enthält Spalten-Mapping
- Branchen-Entscheidungen werden nicht gespeichert

**Konzept:**
- `mapping_config` = Template (wiederverwendbar, keine UUIDs)
- `industry_resolution` = pro Zeile (Vorschläge + Entscheidung)

**Anpassung:**
- `mapping_config` erweitern um `industry_mapping` Sektion (wie im Konzept)
- `industry_resolution` als separates Feld in `org_import_staging`

### 4. **API-Endpoints für Industry-Entscheidungen**

**Fehlt aktuell:**
- Endpoint zum Speichern der Industry-Entscheidung
- Endpoint zum Laden der Dropdown-Optionen (kaskadierend)

**Vorschlag aus Konzept:**
- `POST /api/import/staging/{stagingUuid}/industry-decision`
- Response enthält: updated resolution + dropdown options + guards

### 5. **Commit-Logik mit Level 3 Erstellung**

**Aktuell:**
- `importToStaging` erstellt nur Staging-Rows
- Keine Logik für finalen Import in Produktion

**Konzept:**
- Commit-Service erstellt Level 3 Industries wenn `CREATE_NEW`
- Setzt `industry_level1_uuid`, `industry_level2_uuid`, `industry_level3_uuid` in Org
- Startet QUALIFY_COMPANY Workflow

## Konkrete Umsetzungsschritte

### Schritt 1: Schema erweitern

1. `industry_resolution` Feld zu `org_import_staging` hinzufügen
2. `industry_alias` Tabelle erstellen
3. `mapping_config` Struktur erweitern (optional, kann später kommen)

### Schritt 2: Backend-Services anpassen

1. `ImportIndustryValidationService` erweitern:
   - Erstellt `industry_resolution` JSON mit suggestions + decision
   - Nutzt Alias-Tabelle für besseres Matching

2. Neuer Service `IndustryDecisionService`:
   - Speichert UI-Entscheidungen in `industry_resolution.decision`
   - Validiert Konsistenz (Guards)
   - Liefert Dropdown-Optionen zurück

3. `importToStaging` anpassen:
   - Erstellt `industry_resolution` für jede Zeile
   - Speichert in `org_import_staging.industry_resolution`

### Schritt 3: API-Endpoints

1. `POST /api/import/staging/{uuid}/industry-decision`
   - Speichert Entscheidung
   - Liefert Dropdown-Optionen zurück

2. `GET /api/import/staging/{uuid}`
   - Liefert Staging-Row inkl. `industry_resolution`

### Schritt 4: Frontend anpassen

1. `industryDecisions` nicht nur im Browser, sondern per API speichern
2. Dropdown-Optionen vom Server laden (kaskadierend)
3. Guards vom Server prüfen lassen

### Schritt 5: Commit-Service

1. Neuer Service `ImportCommitService`:
   - Liest `industry_resolution.decision`
   - Erstellt Level 3 wenn `CREATE_NEW`
   - Erstellt Org mit korrekten Industry-UUIDs
   - Startet Workflow

## Empfehlung: Schrittweise Umsetzung

**Phase 1 (MVP - schnell umsetzbar):**
1. ✅ `industry_resolution` Feld hinzufügen
2. ✅ `importToStaging` erweitern: Erstellt `industry_resolution` mit suggestions
3. ✅ API-Endpoint zum Speichern der Entscheidung
4. ✅ Frontend speichert Entscheidungen per API

**Phase 2 (Verbesserung):**
1. ✅ Alias-Tabelle + Learning
2. ✅ Commit-Service für finalen Import
3. ✅ Guards für Konsistenz

**Phase 3 (Optimierung):**
1. ✅ Normalisierung mit Suffix-Stripping
2. ✅ Caching von Dropdown-Optionen
3. ✅ Bulk-Operations

## Wichtigste Erkenntnisse aus dem Konzept

1. **Trennung ist entscheidend**: Template (mapping_config) vs. Entscheidung (industry_resolution)
2. **Persistierung ist kritisch**: UI-Entscheidungen müssen in DB gespeichert werden
3. **Guards verhindern Bugs**: Konsistenz-Prüfungen serverseitig
4. **Alias-Learning macht es besser**: System lernt aus Bestätigungen

## Nächste Schritte

Soll ich mit **Phase 1** starten?
1. Migration für `industry_resolution` Feld
2. Anpassung von `importToStaging` 
3. API-Endpoint für Industry-Entscheidungen
4. Frontend-Anpassung zum Speichern

