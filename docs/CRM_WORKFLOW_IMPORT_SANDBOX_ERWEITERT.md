# CRM Workflow - Import Sandbox/Review-Konzept (Erweitert)

## Entscheidungen

1. ✅ **Separate Tabellen** (keine Flag-Lösung)
2. ✅ **Mapping:** Automatischer Vorschlag + manuelle Korrektur, flexibel für verschiedene Formate
3. ✅ **Validierung:** Geodaten, Vorwahlen, Duplikate, fehlende Angaben
4. ✅ **Personen-Import:** Separate Tabellen (wie empfohlen)
5. ✅ **Gründlichkeit > Geschwindigkeit**
6. ✅ **Kein Direkt-Import** (wird sonst immer genutzt)

---

## 1. Import Batch als First-Class Entity

### Datenmodell

```sql
-- Import Batch (First-Class Entity)
CREATE TABLE org_import_batch (
    batch_uuid CHAR(36) PRIMARY KEY,
    
    -- Quelle
    source_type VARCHAR(50) NOT NULL COMMENT 'excel | csv | api | manual',
    filename VARCHAR(255) COMMENT 'Dateiname (bei File-Import)',
    file_hash VARCHAR(64) COMMENT 'SHA-256 Hash der Datei (für Idempotenz)',
    
    -- Mapping
    mapping_template_id VARCHAR(100) COMMENT 'ID des verwendeten Mapping-Templates',
    mapping_config JSON COMMENT 'Tatsächliche Mapping-Konfiguration (kann von Template abweichen)',
    
    -- Status
    status VARCHAR(50) NOT NULL DEFAULT 'DRAFT' 
        COMMENT 'DRAFT | STAGED | IN_REVIEW | APPROVED | IMPORTED | CANCELED',
    
    -- Audit
    uploaded_by_user_id VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    staged_at DATETIME COMMENT 'Wann wurde in Staging importiert',
    reviewed_by_user_id VARCHAR(255) COMMENT 'Wer hat Review durchgeführt',
    reviewed_at DATETIME COMMENT 'Wann wurde Review abgeschlossen',
    approved_by_user_id VARCHAR(255) COMMENT 'Wer hat freigegeben (nur Sales Ops)',
    approved_at DATETIME COMMENT 'Wann wurde freigegeben',
    imported_by_user_id VARCHAR(255) COMMENT 'Wer hat final importiert',
    imported_at DATETIME COMMENT 'Wann wurde final importiert',
    
    -- Statistiken (JSON)
    stats_json JSON COMMENT '{
        "total_rows": 150,
        "valid": 140,
        "warnings": 8,
        "errors": 2,
        "duplicates": 12,
        "imported": 138,
        "skipped": 12
    }',
    
    -- Validierungsregeln
    validation_rule_set_version VARCHAR(50) COMMENT 'Version der Validierungsregeln',
    
    -- Notizen
    notes TEXT COMMENT 'Interne Notizen zum Import',
    
    -- Metadaten
    metadata_json JSON COMMENT 'Zusätzliche Metadaten (z.B. Excel-Sheets, etc.)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_import_batch_status ON org_import_batch(status);
CREATE INDEX idx_import_batch_user ON org_import_batch(uploaded_by_user_id);
CREATE INDEX idx_import_batch_file_hash ON org_import_batch(file_hash);
CREATE INDEX idx_import_batch_created ON org_import_batch(created_at);
```

**Warum First-Class Entity:**
- ✅ Import-Historie (wer hat was wann importiert)
- ✅ Saubere Permissions (nur Sales Ops darf APPROVE)
- ✅ Audit-Trail
- ✅ Rollback möglich
- ✅ Metrics/Reporting

---

## 2. Deterministische Row Fingerprints + Idempotenz

### Problem
"Wir importieren nochmal dieselbe Datei" → doppelte Daten

### Lösung: Row Fingerprints

```sql
-- Erweiterung org_import_staging
ALTER TABLE org_import_staging 
ADD COLUMN row_fingerprint VARCHAR(64) COMMENT 'Hash über normalisierte Schlüsselfelder',
ADD COLUMN file_fingerprint VARCHAR(64) COMMENT 'Hash der Datei (für Batch-Idempotenz)';

CREATE INDEX idx_staging_fingerprint ON org_import_staging(row_fingerprint);
CREATE UNIQUE KEY unique_batch_row ON org_import_staging(import_batch_uuid, row_number);
```

### Fingerprint-Generierung

```php
/**
 * Generiert Row-Fingerprint aus normalisierten Schlüsselfeldern
 */
private function generateRowFingerprint(array $rowData): string
{
    // Normalisiere Schlüsselfelder
    $name = $this->normalizeString($rowData['name'] ?? '');
    $domain = $this->extractDomain($rowData['website'] ?? '');
    $country = strtoupper(trim($rowData['address_country'] ?? 'DE'));
    $postalCode = $this->normalizePostalCode($rowData['address_postal_code'] ?? '');
    
    // Kombiniere zu String
    $key = sprintf(
        "%s|%s|%s|%s",
        $name,
        $domain,
        $country,
        $postalCode
    );
    
    // Hash
    return hash('sha256', $key);
}

/**
 * Generiert File-Fingerprint (SHA-256 der Datei)
 */
private function generateFileFingerprint(string $filePath): string
{
    return hash_file('sha256', $filePath);
}
```

### Idempotenz-Check

```php
/**
 * Prüft, ob Batch bereits existiert (gleiche Datei)
 */
private function findExistingBatch(string $fileHash): ?string
{
    $stmt = $this->db->prepare("
        SELECT batch_uuid 
        FROM org_import_batch 
        WHERE file_hash = :file_hash 
        AND status IN ('DRAFT', 'STAGED', 'IN_REVIEW', 'APPROVED')
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    
    $stmt->execute(['file_hash' => $fileHash]);
    $result = $stmt->fetch();
    
    return $result ? $result['batch_uuid'] : null;
}

/**
 * Prüft, ob Row bereits existiert (gleicher Fingerprint)
 */
private function findExistingRow(string $rowFingerprint, string $batchUuid): ?string
{
    $stmt = $this->db->prepare("
        SELECT staging_uuid 
        FROM org_import_staging 
        WHERE row_fingerprint = :fingerprint 
        AND import_batch_uuid != :batch_uuid
        AND import_status = 'imported'
        LIMIT 1
    ");
    
    $stmt->execute([
        'fingerprint' => $rowFingerprint,
        'batch_uuid' => $batchUuid
    ]);
    
    $result = $stmt->fetch();
    return $result ? $result['staging_uuid'] : null;
}
```

**Effekt:**
- ✅ Wiederholtes Staging ist sauber erkennbar
- ✅ "Import starten" wird idempotent
- ✅ Duplikate werden erkannt (auch über Batches hinweg)

---

## 3. Dedupe-Kandidaten in separater Tabelle

### Problem
Duplikate sind nicht nur "Warning", sondern eigener Review-Workflow

### Lösung: Separate Tabelle

```sql
-- Dedupe-Kandidaten (separate Tabelle)
CREATE TABLE import_duplicate_candidates (
    candidate_uuid CHAR(36) PRIMARY KEY,
    staging_uuid CHAR(36) NOT NULL,
    candidate_org_uuid CHAR(36) NOT NULL COMMENT 'Bestehende Org in Produktion',
    
    -- Match-Informationen
    match_score DECIMAL(5,2) NOT NULL COMMENT '0.00 - 100.00',
    match_reason_json JSON COMMENT '{
        "name_match": 0.95,
        "domain_match": 0.90,
        "postal_code_match": 0.85,
        "phone_match": 0.80
    }',
    
    -- Entscheidung
    decision VARCHAR(50) COMMENT 'NEW | LINK_EXISTING | MERGE | SKIP',
    decided_by_user_id VARCHAR(255),
    decided_at DATETIME,
    decision_notes TEXT,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (staging_uuid) REFERENCES org_import_staging(staging_uuid) ON DELETE CASCADE,
    FOREIGN KEY (candidate_org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_duplicate_staging ON import_duplicate_candidates(staging_uuid);
CREATE INDEX idx_duplicate_org ON import_duplicate_candidates(candidate_org_uuid);
CREATE INDEX idx_duplicate_decision ON import_duplicate_candidates(decision);
```

**Warum separate Tabelle:**
- ✅ Dedupe ist eigener Workflow
- ✅ UI bleibt handhabbar
- ✅ Mehrere Kandidaten pro Staging-Row möglich
- ✅ Entscheidung wird explizit dokumentiert

---

## 4. Review-Status: Validation vs. Disposition

### Problem
`review_status` ist zu unspezifisch

### Lösung: Trennung

```sql
-- Erweiterung org_import_staging
ALTER TABLE org_import_staging 
ADD COLUMN validation_status VARCHAR(50) COMMENT 'valid | warning | error' 
    COMMENT 'System-Validierung',
ADD COLUMN disposition VARCHAR(50) DEFAULT 'pending' 
    COMMENT 'pending | approve_new | link_existing | skip | needs_fix'
    COMMENT 'Menschliche Entscheidung',
MODIFY COLUMN review_status VARCHAR(50) COMMENT 'DEPRECATED - verwende disposition';
```

### Workflow

```
System-Validierung:
    ↓
validation_status = valid | warning | error
    ↓
Sales Ops sieht Vorschau:
    ↓
Disposition setzen:
    - approve_new: Neue Org erstellen
    - link_existing: Mit bestehender Org verknüpfen
    - skip: Überspringen
    - needs_fix: Korrektur nötig
```

**Vorteile:**
- ✅ Klare Trennung: System vs. Mensch
- ✅ Bulk Actions möglich ("approve_new alle validen")
- ✅ Duplikate bleiben pending bis entschieden

---

## 5. Korrekturen als Patch (nicht Edit)

### Problem
Wenn Sales Ops korrigiert, soll Nachvollziehbarkeit bleiben

### Lösung: Patch-System

```sql
-- Erweiterung org_import_staging
ALTER TABLE org_import_staging 
ADD COLUMN corrections_json JSON COMMENT 'Manuelle Overrides (Patch)',
ADD COLUMN effective_data JSON COMMENT 'Computed: mapped_data + corrections_json';
```

### Code

```php
/**
 * Berechnet effective_data aus mapped_data + corrections
 */
private function computeEffectiveData(array $mappedData, ?array $corrections): array
{
    if (empty($corrections)) {
        return $mappedData;
    }
    
    // Merge: corrections überschreiben mapped_data
    return array_merge($mappedData, $corrections);
}

/**
 * Speichert Korrektur (Patch)
 */
public function applyCorrection(string $stagingUuid, array $corrections, string $userId): void
{
    // Hole mapped_data
    $staging = $this->getStagingRow($stagingUuid);
    $mappedData = json_decode($staging['mapped_data'], true);
    
    // Berechne effective_data
    $effectiveData = $this->computeEffectiveData($mappedData, $corrections);
    
    // Speichere
    $stmt = $this->db->prepare("
        UPDATE org_import_staging 
        SET corrections_json = :corrections,
            effective_data = :effective,
            disposition = 'needs_fix',
            updated_at = NOW()
        WHERE staging_uuid = :uuid
    ");
    
    $stmt->execute([
        'uuid' => $stagingUuid,
        'corrections' => json_encode($corrections),
        'effective' => json_encode($effectiveData)
    ]);
}
```

**Warum Patch:**
- ✅ Nachvollziehbarkeit: "was kam aus der Datei" vs. "was hat Sales Ops geändert"
- ✅ Reproduzierbar: mapped_data bleibt unverändert
- ✅ Audit: Alle Korrekturen werden dokumentiert

---

## 6. Validierungsregeln versioniert

### Problem
Regeln ändern sich, aber alte Importe sollen nicht "zerbrechen"

### Lösung: Versionierte Rule-Sets

```sql
-- Validierungsregeln (Versioniert)
CREATE TABLE validation_rule_set (
    rule_set_id VARCHAR(50) PRIMARY KEY COMMENT 'z.B. v1.0, v1.1',
    version VARCHAR(20) NOT NULL,
    rules_json JSON NOT NULL COMMENT 'Regeln als JSON',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beispiel rules_json:
-- {
--   "required_fields": ["name"],
--   "format_validations": {
--     "postal_code": {"type": "postal_code_de", "required": false},
--     "email": {"type": "email", "required": false},
--     "website": {"type": "url", "required": false}
--   },
--   "geodata_validation": {
--     "enabled": true,
--     "check_postal_code_city_match": true
--   },
--   "phone_validation": {
--     "enabled": true,
--     "check_area_code": true
--   }
-- }
```

### Validierung mit Version

```php
/**
 * Validiert Row mit versionierten Regeln
 */
private function validateRow(array $mappedData, string $ruleSetVersion): array
{
    // Lade Regeln
    $rules = $this->loadValidationRules($ruleSetVersion);
    
    $errors = [];
    $warnings = [];
    $info = [];
    
    // Pflichtfelder
    foreach ($rules['required_fields'] as $field) {
        if (empty($mappedData[$field])) {
            $errors[] = [
                'code' => 'MISSING_REQUIRED_FIELD',
                'severity' => 'ERROR',
                'field' => $field,
                'message' => "Pflichtfeld '$field' fehlt"
            ];
        }
    }
    
    // Format-Validierungen
    foreach ($rules['format_validations'] as $field => $config) {
        $value = $mappedData[$field] ?? null;
        if (empty($value) && !($config['required'] ?? false)) {
            continue; // Optionales Feld ist leer, OK
        }
        
        if (!empty($value)) {
            $validation = $this->validateFormat($value, $config['type']);
            if (!$validation['valid']) {
                $severity = $config['required'] ?? false ? 'ERROR' : 'WARNING';
                $errors[] = [
                    'code' => 'INVALID_FORMAT',
                    'severity' => $severity,
                    'field' => $field,
                    'message' => $validation['message']
                ];
            }
        }
    }
    
    // Geodaten-Validierung
    if ($rules['geodata_validation']['enabled'] ?? false) {
        $geoValidation = $this->validateGeodata($mappedData);
        if (!$geoValidation['valid']) {
            $warnings[] = [
                'code' => 'GEODATA_MISMATCH',
                'severity' => 'WARNING',
                'field' => 'postal_code',
                'message' => $geoValidation['message']
            ];
        }
    }
    
    // Telefon-Vorwahl-Validierung
    if ($rules['phone_validation']['enabled'] ?? false) {
        $phoneValidation = $this->validatePhoneAreaCode($mappedData);
        if (!$phoneValidation['valid']) {
            $warnings[] = [
                'code' => 'PHONE_AREA_CODE_MISMATCH',
                'severity' => 'WARNING',
                'field' => 'phone',
                'message' => $phoneValidation['message']
            ];
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'info' => $info
    ];
}
```

**Warum versioniert:**
- ✅ Alte Importe bleiben konsistent
- ✅ Regeln können sich weiterentwickeln
- ✅ Nachvollziehbarkeit: Welche Regeln wurden verwendet?

---

## 7. Transaktionen/Commit-Strategie beim finalen Import

### Problem
Ein kaputter Datensatz blockiert nicht den ganzen Batch

### Lösung: Zeilenweise Transaktionen

```php
/**
 * Importiert Staging-Daten in Produktion (zeilenweise)
 */
public function importToProduction(string $batchUuid, string $userId): array
{
    // Hole alle freigegebenen Rows
    $stmt = $this->db->prepare("
        SELECT staging_uuid, effective_data, disposition, candidate_org_uuid
        FROM org_import_staging
        WHERE import_batch_uuid = :batch_uuid
        AND disposition IN ('approve_new', 'link_existing')
        AND import_status = 'pending'
        ORDER BY row_number
    ");
    
    $stmt->execute(['batch_uuid' => $batchUuid]);
    $rows = $stmt->fetchAll();
    
    $stats = [
        'imported' => 0,
        'failed' => 0,
        'errors' => []
    ];
    
    foreach ($rows as $row) {
        try {
            // Starte Transaktion pro Zeile
            $this->db->beginTransaction();
            
            $effectiveData = json_decode($row['effective_data'], true);
            
            if ($row['disposition'] === 'approve_new') {
                // Neue Org erstellen
                $org = $this->orgService->createOrg($effectiveData, $userId);
                $orgUuid = $org['org_uuid'];
                
            } elseif ($row['disposition'] === 'link_existing') {
                // Mit bestehender Org verknüpfen
                $orgUuid = $row['candidate_org_uuid'];
                
                // Aktualisiere bestehende Org (optional)
                $this->orgService->updateOrg($orgUuid, $effectiveData, $userId);
                
                // Activity: "Imported data attached"
                $this->activityService->createActivity([
                    'org_uuid' => $orgUuid,
                    'activity_type' => 'NOTE',
                    'notes' => 'Import-Daten angehängt (Batch: ' . $batchUuid . ')',
                    'occurred_at' => date('Y-m-d H:i:s')
                ], $userId);
            }
            
            // Update Staging-Row
            $updateStmt = $this->db->prepare("
                UPDATE org_import_staging
                SET import_status = 'imported',
                    imported_org_uuid = :org_uuid,
                    imported_at = NOW()
                WHERE staging_uuid = :uuid
            ");
            
            $updateStmt->execute([
                'uuid' => $row['staging_uuid'],
                'org_uuid' => $orgUuid
            ]);
            
            // Commit pro Zeile
            $this->db->commit();
            
            $stats['imported']++;
            
        } catch (\Exception $e) {
            // Rollback pro Zeile
            $this->db->rollBack();
            
            // Markiere als fehlgeschlagen
            $failStmt = $this->db->prepare("
                UPDATE org_import_staging
                SET import_status = 'failed',
                    failure_reason = :reason
                WHERE staging_uuid = :uuid
            ");
            
            $failStmt->execute([
                'uuid' => $row['staging_uuid'],
                'reason' => $e->getMessage()
            ]);
            
            $stats['failed']++;
            $stats['errors'][] = [
                'row' => $row['staging_uuid'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Update Batch-Status
    $this->updateBatchStatus($batchUuid, 'IMPORTED', $stats, $userId);
    
    return $stats;
}
```

**Warum zeilenweise:**
- ✅ Ein kaputter Datensatz blockiert nicht den ganzen Batch
- ✅ Teilweise erfolgreiche Imports möglich
- ✅ Fehler werden dokumentiert (failure_reason)

---

## 8. Nach Import: Automatisch Qualify-Workflow starten

### Integration mit CRM-Workflow

```php
// In importToProduction(), nach Org-Erstellung
if ($row['disposition'] === 'approve_new') {
    $org = $this->orgService->createOrg($effectiveData, $userId);
    $orgUuid = $org['org_uuid'];
    
    // Automatisch Qualify-Workflow starten
    // (wird bereits in OrgService::createOrg() gemacht, wenn status='lead')
    // Aber: Importierte Orgs haben is_imported=true → IMPORT_VALIDATION Task
    
} elseif ($row['disposition'] === 'link_existing') {
    $orgUuid = $row['candidate_org_uuid'];
    
    // Kein neuer Qualify-Case, sondern:
    // Activity: "Imported data attached"
    $this->activityService->createActivity([
        'org_uuid' => $orgUuid,
        'activity_type' => 'NOTE',
        'notes' => 'Import-Daten angehängt (Batch: ' . $batchUuid . ')',
        'occurred_at' => date('Y-m-d H:i:s')
    ], $userId);
    
    // Optional: Task "Review imported info"
    $this->taskService->createTask([
        'case_uuid' => $this->getActiveQualifyCase($orgUuid),
        'task_type' => 'REVIEW_IMPORTED_INFO',
        'title' => 'Importierte Daten prüfen',
        'assigned_queue' => 'SALES_OPS',
        'due_in_days' => 1
    ]);
}
```

**Warum:**
- ✅ Brücke zu CRM-Workflow-Konzept
- ✅ Importierte Orgs gehen automatisch in Qualifizierung
- ✅ Bestehende Orgs bekommen Review-Task

---

## 9. Personen-Import: Separate Tabellen + Join-Klammer

### Datenmodell

```sql
-- Personen-Staging
CREATE TABLE person_import_staging (
    staging_uuid CHAR(36) PRIMARY KEY,
    import_batch_uuid CHAR(36) NOT NULL,
    row_number INT NOT NULL,
    
    -- Rohdaten
    raw_data JSON,
    
    -- Gemappte Daten
    mapped_data JSON,
    corrections_json JSON,
    effective_data JSON,
    
    -- Validierung
    validation_status VARCHAR(50),
    validation_errors JSON,
    
    -- Disposition
    disposition VARCHAR(50) DEFAULT 'pending',
    
    -- Import-Status
    import_status VARCHAR(50) DEFAULT 'pending',
    imported_person_uuid CHAR(36),
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (import_batch_uuid) REFERENCES org_import_batch(batch_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employment-Staging (Join-Klammer)
CREATE TABLE employment_import_staging (
    staging_uuid CHAR(36) PRIMARY KEY,
    import_batch_uuid CHAR(36) NOT NULL,
    
    -- Verknüpfungen
    org_staging_uuid CHAR(36) COMMENT 'Verknüpfung zur Org-Staging-Row',
    person_staging_uuid CHAR(36) COMMENT 'Verknüpfung zur Person-Staging-Row',
    
    -- Oder: Finale UUIDs (wenn bereits importiert)
    org_uuid CHAR(36) COMMENT 'Verknüpfung zur finalen Org',
    person_uuid CHAR(36) COMMENT 'Verknüpfung zur finalen Person',
    
    -- Employment-Daten
    job_title VARCHAR(255),
    job_function VARCHAR(100),
    since_date DATE,
    until_date DATE,
    
    -- Disposition
    disposition VARCHAR(50) DEFAULT 'pending',
    
    -- Import-Status
    import_status VARCHAR(50) DEFAULT 'pending',
    imported_employment_uuid CHAR(36),
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (import_batch_uuid) REFERENCES org_import_batch(batch_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_staging_uuid) REFERENCES org_import_staging(staging_uuid) ON DELETE CASCADE,
    FOREIGN KEY (person_staging_uuid) REFERENCES person_import_staging(staging_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Warum separate Tabellen:**
- ✅ Personen können importiert werden, auch wenn Org "link_existing" ist
- ✅ Klare Trennung: Org, Person, Employment
- ✅ Flexibel: Personen können später zugeordnet werden

---

## 10. UI: Diff-Ansicht, Bulk Actions, Queue

### Diff-Ansicht

```html
<div class="import-diff-view">
    <h3>Zeile 1: Musterfirma GmbH</h3>
    
    <table class="diff-table">
        <thead>
            <tr>
                <th>Feld</th>
                <th>Raw (Excel)</th>
                <th>Mapped</th>
                <th>Corrected</th>
                <th>Effective</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Name</td>
                <td>Musterfirma GmbH</td>
                <td>Musterfirma GmbH</td>
                <td>-</td>
                <td>Musterfirma GmbH</td>
            </tr>
            <tr class="diff-changed">
                <td>PLZ</td>
                <td>12345</td>
                <td>12345</td>
                <td>12346</td>
                <td>12346</td>
            </tr>
        </tbody>
    </table>
</div>
```

### Bulk Actions mit Guardrails

```html
<div class="bulk-actions">
    <h3>Bulk Actions</h3>
    
    <!-- Safe Action -->
    <button class="btn-safe" onclick="approveAllValid()">
        ✅ Alle VALIDEN freigeben (keine Duplikate)
    </button>
    
    <!-- Guarded Action -->
    <label>
        <input type="checkbox" id="confirm-warnings">
        Ich verstehe, dass Warnings vorhanden sind
    </label>
    <button class="btn-warning" onclick="approveWithWarnings()" disabled>
        ⚠️ Alle mit WARNINGS freigeben
    </button>
    
    <!-- Dangerous Action -->
    <label>
        <input type="checkbox" id="confirm-errors">
        Ich verstehe, dass Errors vorhanden sind
    </label>
    <button class="btn-danger" onclick="approveWithErrors()" disabled>
        ❌ Alle mit ERRORS freigeben (nicht empfohlen)
    </button>
</div>
```

### Queue "Needs Fix"

```html
<div class="queue-needs-fix">
    <h3>Queue: Needs Fix</h3>
    <p>Nur Errors und Warnings+Duplicates</p>
    
    <table>
        <thead>
            <tr>
                <th>Zeile</th>
                <th>Name</th>
                <th>Status</th>
                <th>Fehler</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
            <tr class="error">
                <td>5</td>
                <td>Test GmbH</td>
                <td>❌ Error</td>
                <td>PLZ fehlt</td>
                <td>[Korrigieren]</td>
            </tr>
            <tr class="warning duplicate">
                <td>12</td>
                <td>Muster AG</td>
                <td>⚠️ Warning + Duplikat</td>
                <td>Mögliches Duplikat (Score: 0.95)</td>
                <td>[Entscheiden]</td>
            </tr>
        </tbody>
    </table>
</div>
```

---

## Vollständiges Datenmodell (Zusammenfassung)

```sql
-- 1. Import Batch (First-Class)
CREATE TABLE org_import_batch (...);

-- 2. Org Staging
CREATE TABLE org_import_staging (
    staging_uuid, import_batch_uuid, row_number,
    raw_data JSON, mapped_data JSON, corrections_json JSON, effective_data JSON,
    row_fingerprint VARCHAR(64), file_fingerprint VARCHAR(64),
    validation_status VARCHAR(50), validation_errors JSON,
    disposition VARCHAR(50), review_status VARCHAR(50),
    import_status VARCHAR(50), imported_org_uuid, failure_reason
);

-- 3. Duplicate Candidates
CREATE TABLE import_duplicate_candidates (
    candidate_uuid, staging_uuid, candidate_org_uuid,
    match_score, match_reason_json,
    decision, decided_by, decided_at
);

-- 4. Person Staging
CREATE TABLE person_import_staging (...);

-- 5. Employment Staging
CREATE TABLE employment_import_staging (
    org_staging_uuid, person_staging_uuid,
    org_uuid, person_uuid,
    job_title, job_function, ...
);

-- 6. Validation Rules (Versioniert)
CREATE TABLE validation_rule_set (
    rule_set_id, version, rules_json, is_active
);
```

---

## Workflow: Kompletter Prozess

```
1. Excel hochladen
   ↓
2. File-Fingerprint generieren
   ↓
3. Prüfe: Batch existiert bereits? → Idempotenz
   ↓
4. Mapping-Vorschlag generieren (automatisch)
   ↓
5. Sales Ops bestätigt/anpasst Mapping
   ↓
6. Staging-Import:
   - Zeile für Zeile verarbeiten
   - Row-Fingerprint generieren
   - Mapping anwenden
   - Validierung (versionierte Regeln)
   - Duplikate erkennen
   - In org_import_staging speichern
   ↓
7. Vorschau:
   - Statistiken
   - Firmen + Personen getrennt
   - Diff-Ansicht
   ↓
8. Review:
   - Sales Ops prüft
   - Korrekturen (Patch)
   - Disposition setzen
   ↓
9. Freigabe:
   - Bulk Actions (mit Guardrails)
   - Zeilenweise Import (Transaktionen)
   - Qualify-Workflow starten
   ↓
10. Nach Import:
    - Batch-Status = IMPORTED
    - Stats aktualisieren
    - Historie bleibt erhalten
```

---

## Zusammenfassung der Erweiterungen

1. ✅ **Import Batch als First-Class Entity** - Historie, Audit, Permissions
2. ✅ **Row Fingerprints + Idempotenz** - Keine doppelten Imports
3. ✅ **Dedupe-Kandidaten separate Tabelle** - Eigener Workflow
4. ✅ **Validation vs. Disposition** - Klare Trennung
5. ✅ **Korrekturen als Patch** - Nachvollziehbarkeit
6. ✅ **Versionierte Validierungsregeln** - Konsistenz
7. ✅ **Zeilenweise Transaktionen** - Robustheit
8. ✅ **Qualify-Workflow nach Import** - CRM-Integration
9. ✅ **Personen-Import separate Tabellen** - Flexibilität
10. ✅ **UI: Diff, Bulk Actions, Queue** - Benutzerfreundlichkeit
