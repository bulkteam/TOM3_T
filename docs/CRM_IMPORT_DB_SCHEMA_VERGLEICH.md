# Vergleich: Vorgeschlagene DB-Struktur vs. Aktueller Stand

## Zusammenfassung

**Die vorgeschlagene Struktur ist gut, aber unsere aktuelle Struktur ist bereits besser** (mehr Felder, flexibler).

**Wir müssen nur ergänzen:**
- ✅ `industry_resolution` (kritisch!)
- ✅ `industry_alias` Tabelle (sehr sinnvoll)
- ⚠️ Optional: `duplicate_status`, `duplicate_summary`, `commit_log`

**Keine Breaking Changes nötig** - nur Ergänzungen!

---

## 1. org_import_batch

### Vergleich:
- ✅ **Aktuelle Struktur ist besser** - mehr Felder (`reviewed_by_user_id`, `imported_by_user_id`, `validation_rule_set_version`, `metadata_json`)
- ⚠️ `file_hash` vs. `file_fingerprint` - Semantisch gleich, Name unterschiedlich (kann bleiben)

**Empfehlung:** ✅ Keine Änderung nötig

---

## 2. org_import_staging

### Vergleich:

#### ✅ Bereits vorhanden (besser als Vorschlag):
- `effective_data` - Sehr sinnvoll für Patch-Korrekturen
- `row_fingerprint`, `file_fingerprint` - Wichtig für Idempotenz
- `failure_reason` - Hilfreich für Debugging
- `updated_at` - Standard für Audit

#### ❌ Fehlt (MUSS hinzugefügt werden):
- `industry_resolution` - **KRITISCH!** Für Branchen-Mapping

#### ⚠️ Fehlt (Optional, aber hilfreich):
- `duplicate_status` - Für schnelle Filterung
- `duplicate_summary` - Kurze Zusammenfassung
- `commit_log` - Für Audit-Trail

#### ⚠️ Name-Unterschiede (nicht kritisch):
- `disposition` vs. `review_status` - Semantisch gleich
- `import_batch_uuid` vs. `batch_uuid` - Semantisch gleich

**Empfehlung:** 
- ✅ Migration 048: `industry_resolution` hinzufügen (kritisch!)
- ✅ Migration 049: Optionale Felder hinzufügen (hilfreich)
- ⚠️ Namen können später angepasst werden (nicht kritisch)

---

## 3. import_duplicate_candidates

### Vergleich:
- ✅ **Identisch!** Keine Änderung nötig.

**Hinweis:** Aktuelle Struktur verwendet `candidate_uuid` als PK statt `id`, aber das ist auch in Ordnung.

---

## 4. industry_alias

### Vergleich:
- ❌ **Existiert nicht** - Muss neu erstellt werden

**Empfehlung:** ✅ Migration 050: Tabelle erstellen (wie vorgeschlagen)

---

## Erstellte Migrationen

### Migration 048: industry_resolution (KRITISCH)
```sql
ALTER TABLE org_import_staging 
ADD COLUMN industry_resolution JSON NULL 
COMMENT 'Vorschläge + bestätigte Branchen-Entscheidung pro Zeile'
AFTER mapped_data;
```

### Migration 049: Optionale Felder
```sql
ALTER TABLE org_import_staging 
ADD COLUMN duplicate_status VARCHAR(20) NOT NULL DEFAULT 'unknown' 
    COMMENT 'unknown|none|possible|confirmed' 
    AFTER validation_errors,
ADD COLUMN duplicate_summary JSON NULL 
    COMMENT 'Kurze Zusammenfassung der Duplikate' 
    AFTER duplicate_status,
ADD COLUMN commit_log JSON NULL 
    COMMENT 'Log der Commit-Aktionen' 
    AFTER imported_at;

CREATE INDEX idx_staging_duplicate_status ON org_import_staging(duplicate_status);
```

### Migration 050: industry_alias Tabelle
```sql
CREATE TABLE industry_alias (
  alias_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  alias VARCHAR(255) NOT NULL,
  industry_uuid CHAR(36) NOT NULL,
  level TINYINT NOT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'user',
  created_by_user_id VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY uq_alias_level (alias, level),
  FOREIGN KEY (industry_uuid) REFERENCES industry(industry_uuid) ON DELETE CASCADE,
  INDEX idx_alias_industry (industry_uuid)
);
```

---

## Fazit

**Ja, ich würde die DB-Struktur so aufsetzen** - aber mit unseren Ergänzungen:

1. ✅ **industry_resolution** - MUSS hinzugefügt werden (kritisch!)
2. ✅ **industry_alias** - MUSS erstellt werden (sehr sinnvoll!)
3. ✅ **duplicate_status**, **duplicate_summary**, **commit_log** - Sollten hinzugefügt werden (hilfreich)
4. ✅ **Beibehalten**: `effective_data`, `row_fingerprint`, `file_fingerprint`, `failure_reason`, `updated_at` (sind besser als Vorschlag)

**Die Migrationen 048, 049, 050 sind erstellt und bereit zur Ausführung!**
