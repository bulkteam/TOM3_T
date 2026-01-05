# Detaillierte Problem-Analyse und Fixes

## Problem 1: Doppelte Funktionen in import.js ⚠️ KRITISCH

### Symptom
- UI-Verhalten ist "komisch"
- Level 2 wird nach Bestätigung von Level 1 nicht korrekt vorausgewählt
- Confirm-Button bleibt disabled

### Ursache
**Doppelte Funktionsdefinitionen:**
- `loadMainIndustries()`: Zeile 652 und 983
- `onLevel1Selected()`: Zeile 682 und 1013  
- `loadLevel2Options()`: Zeile 771 und 1094

Die **spätere Version überschreibt die frühere**, aber die spätere Version hat einen Bug:
- `loadLevel2Options()` setzt Optionen neu, aber **übernimmt den vorbelegten Wert nicht**

### Fix
1. **Entferne doppelte Funktionen** (behalte nur eine Version)
2. **Fix in `loadLevel2Options()`**: Vor dem Reload den aktuellen Wert sichern und danach wieder setzen

```javascript
async loadLevel2Options(comboId, level1Uuid) {
    const select = document.querySelector(`select.industry-level2-select[data-combo-id="${comboId}"]`);
    if (!select) return;
    
    // WICHTIG: Aktuellen Wert sichern
    const currentValue = select.value;
    
    // Lade Optionen
    const options = await Utils.loadIndustryLevel2(level1Uuid);
    
    // Setze Optionen
    select.innerHTML = '<option value="">-- Bitte wählen --</option>';
    options.forEach(opt => {
        const option = document.createElement('option');
        option.value = opt.industry_uuid;
        option.textContent = opt.name;
        select.appendChild(option);
    });
    
    // WICHTIG: Vorbelegten Wert wieder setzen (falls vorhanden)
    if (currentValue) {
        select.value = currentValue;
        // Enable confirm button wenn Wert vorhanden
        const confirmBtn = document.querySelector(`button.confirm-level2-btn[data-combo-id="${comboId}"]`);
        if (confirmBtn) confirmBtn.disabled = false;
    }
}
```

---

## Problem 2: UI-State wird nicht gespeichert ⚠️ KRITISCH

### Symptom
- UI kann schön auswählen
- Aber beim Staging-Import landen die Branchen leer / nicht wie erwartet

### Ursache
**In `saveMapping()` (Frontend):**
```javascript
// Zeile 1360+
async saveMapping() {
    // ...
    body: JSON.stringify({ mapping_config: mappingConfig })
    // ❌ industryDecisions wird NICHT übertragen!
}
```

**Im Backend (`OrgImportService::importToStaging`):**
- Keine Logik, die `industryDecisions` auswertet
- `industry_resolution` wird nicht erstellt
- Branchen-UUIDs werden nicht in `mapped_data` gesetzt

### Fix

#### Schritt 1: Frontend - Speichere industryDecisions
```javascript
async saveMapping() {
    const mappingConfig = {
        // ... existing mapping ...
        industry_decisions: this.industryDecisions  // ✅ Neu hinzufügen
    };
    
    const response = await fetch(`/tom3/public/api/import/batch/${this.batchUuid}/mapping`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            mapping_config: mappingConfig,
            industry_decisions: this.industryDecisions  // ✅ Separates Feld
        })
    });
}
```

#### Schritt 2: Backend - Speichere industry_decisions im Batch
```php
// In OrgImportService::saveMapping()
public function saveMapping(string $batchUuid, array $mappingConfig, ?array $industryDecisions = null, ?string $userId = null): void
{
    $stmt = $this->db->prepare("
        UPDATE org_import_batch
        SET mapping_config = :mapping_config,
            industry_decisions = :industry_decisions
        WHERE batch_uuid = :batch_uuid
    ");
    
    $stmt->execute([
        'batch_uuid' => $batchUuid,
        'mapping_config' => json_encode($mappingConfig),
        'industry_decisions' => $industryDecisions ? json_encode($industryDecisions) : null
    ]);
}
```

#### Schritt 3: Migration - Füge industry_decisions Feld hinzu
```sql
ALTER TABLE org_import_batch 
ADD COLUMN industry_decisions JSON NULL 
COMMENT 'Branchen-Entscheidungen pro Excel-Wert (temporär, wird in industry_resolution überführt)';
```

#### Schritt 4: Backend - Erstelle industry_resolution beim Staging
```php
// In OrgImportService::importToStaging()
private function buildIndustryResolution(array $rowData, array $mappingConfig, array $industryDecisions): array
{
    $excelLevel2 = $rowData['industry_level2'] ?? $rowData['industry_main'] ?? null;
    $excelLevel3 = $rowData['industry_level3'] ?? $rowData['industry_sub'] ?? null;
    
    // Suche nach passender Entscheidung
    $decision = null;
    if ($excelLevel2 && isset($industryDecisions[$excelLevel2])) {
        $decision = $industryDecisions[$excelLevel2];
    }
    
    // Erstelle Resolution-Struktur
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
    
    // Wenn Entscheidung vorhanden, übernehme sie
    if ($decision) {
        $resolution['decision']['level2_uuid'] = $decision['industry_uuid'] ?? null;
        // ... weitere Logik ...
    }
    
    return $resolution;
}
```

#### Schritt 5: Migration - Füge industry_resolution zu staging hinzu
```sql
ALTER TABLE org_import_staging 
ADD COLUMN industry_resolution JSON NULL 
COMMENT 'Vorschläge + bestätigte Branchen-Entscheidung pro Zeile';
```

---

## Problem 3: Ambiguität in ImportMappingService

### Symptom
- "Oberkategorie" kann sowohl `industry_level2` als auch `industry_main` matchen
- Ties führen zu inkonsistenten Mappings

### Ursache
```php
// Zeile 24
'industry_level2' => ['oberkategorie', 'branche', ...],
// Zeile 27
'industry_main' => ['oberkategorie', 'hauptbranche', ...],  // ❌ Überschneidung!
```

### Fix
**Option A: Legacy-Felder entfernen** (empfohlen, da wir auf 3-Level umgestellt haben)
```php
// Entferne industry_main und industry_sub komplett
// Nur noch industry_level1, industry_level2, industry_level3
```

**Option B: Legacy-Felder ohne Überschneidung** (falls Rückwärtskompatibilität nötig)
```php
'industry_level2' => ['oberkategorie', 'branche', ...],
'industry_main' => ['hauptbranche', 'sektor'],  // ✅ Keine Überschneidung mehr
```

**Empfehlung:** Option A, da wir bereits auf 3-Level umgestellt haben.

---

## Problem 4: Fuzzy-Matching für deutsche Komposita

### Symptom
- "Chemieindustrie" findet keine guten Matches
- Keyword-Match greift nicht (ein Token)
- Levenshtein kann daneben liegen

### Ursache
- Keine Normalisierung (Suffix-Stripping)
- Keine Alias-Tabelle für Learning

### Fix

#### Schritt 1: IndustryNormalizer Service
```php
class IndustryNormalizer
{
    public function normalize(string $s): string
    {
        $s = trim(mb_strtolower($s));
        
        // Umlaute normalisieren
        $s = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $s);
        
        // Interpunktion entfernen
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        
        // Suffix-Stripping (MVP)
        $suffixes = ['industrie', 'hersteller', 'produktion', 'fertigung', 'handel'];
        foreach ($suffixes as $suffix) {
            $s = preg_replace('/\b' . preg_quote($suffix, '/') . '\b/u', '', $s);
        }
        
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
```

#### Schritt 2: Alias-Tabelle (Migration)
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

#### Schritt 3: Alias-Check vor Fuzzy-Matching
```php
// In ImportIndustryValidationService
private function findSimilarIndustry(string $excelLabel, int $level): ?array
{
    // 1. Zuerst Alias-Check (exakter Match)
    $alias = $this->checkAlias($excelLabel, $level);
    if ($alias) {
        return $this->getIndustryByUuid($alias['industry_uuid']);
    }
    
    // 2. Dann Normalisierung + Fuzzy-Matching
    $normalized = $this->normalizer->normalize($excelLabel);
    // ... existing fuzzy logic ...
}
```

#### Schritt 4: Alias speichern bei Bestätigung
```php
// In IndustryDecisionService (neu)
public function saveAlias(string $excelLabel, string $industryUuid, int $level, string $userId): void
{
    $normalized = mb_strtolower(trim($excelLabel));
    
    $stmt = $this->db->prepare("
        INSERT INTO industry_alias (alias, industry_uuid, level, source, created_by_user_id)
        VALUES (:alias, :industry_uuid, :level, 'user', :user_id)
        ON DUPLICATE KEY UPDATE 
            industry_uuid = VALUES(industry_uuid),
            created_by_user_id = VALUES(created_by_user_id)
    ");
    
    $stmt->execute([
        'alias' => $normalized,
        'industry_uuid' => $industryUuid,
        'level' => $level,
        'user_id' => $userId
    ]);
}
```

---

## Umsetzungsreihenfolge (Priorität)

### Phase 1: Kritische Fixes (sofort)
1. ✅ **Doppelte Funktionen entfernen** (import.js)
2. ✅ **loadLevel2Options Fix** (Vorbelegung erhalten)
3. ✅ **industry_resolution Feld** (Migration)
4. ✅ **saveMapping erweitern** (industryDecisions speichern)

### Phase 2: Backend-Logik (dann)
5. ✅ **buildIndustryResolution** in importToStaging
6. ✅ **API-Endpoint** für Industry-Entscheidungen
7. ✅ **Ambiguität entfernen** (Legacy-Felder)

### Phase 3: Verbesserungen (später)
8. ✅ **IndustryNormalizer** Service
9. ✅ **Alias-Tabelle** + Learning
10. ✅ **Commit-Service** für finalen Import

---

## Nächste Schritte

Soll ich mit **Phase 1** starten? Das würde die kritischsten Bugs sofort beheben.

