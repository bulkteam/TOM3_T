# CRM Workflow - Import-Konzept für externe Datenquellen

## Problemstellung

Bei Importen größerer Mengen von Organisationen (z.B. aus Excel, CSV, externen APIs) stellt sich die Frage:

1. **Welcher initiale Status/Stage?** 
   - Direkt als `lead` (UNVERIFIED)?
   - Oder eine Vorstufe für Importe?

2. **Wie mit ungeprüften/unkompletten Daten umgehen?**
   - Importierte Daten sind oft unvollständig
   - Mögliche Duplikate
   - Qualität muss geprüft werden

3. **Workflow für Importe?**
   - Sollten importierte Orgs direkt in den Qualifizierungs-Workflow?
   - Oder erst Validierung/Prüfung?

---

## Lösung: Import-Flag + Validierungs-Task

### Kernidee

**Importierte Orgs bekommen:**
- `status = 'lead'` (wie manuelle Eingabe)
- `current_stage = 'UNVERIFIED'` (wie manuelle Eingabe)
- **ZUSÄTZLICH:** `is_imported = true` Flag
- **ZUSÄTZLICH:** Import-Metadaten (Quelle, Import-Datum, Import-Batch)

**Workflow-Anpassung:**
- Importierte Orgs starten `QUALIFY_COMPANY` Workflow
- **ABER:** Erste Task ist `IMPORT_VALIDATION` (vor OPS_DATA_CHECK)
- Diese Task muss erledigt sein, bevor Qualifizierung startet

### Vorteile

✅ **Einheitliche Stage-Maschine:** Keine neue Vorstufe nötig  
✅ **Flexibel:** Importierte Orgs können wie normale Leads behandelt werden  
✅ **Qualitätssicherung:** Validierungs-Task als Gatekeeper  
✅ **Nachvollziehbarkeit:** Import-Metadaten für Audit

---

## Datenmodell-Erweiterungen

### 1. Import-Flag + Metadaten

```sql
-- Erweiterung org Tabelle
ALTER TABLE org 
ADD COLUMN is_imported TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Wurde diese Org importiert?',
ADD COLUMN import_source VARCHAR(100) COMMENT 'Quelle: excel | csv | api | manual',
ADD COLUMN import_batch_uuid CHAR(36) COMMENT 'Verknüpfung zum Import-Batch',
ADD COLUMN imported_at DATETIME COMMENT 'Wann wurde importiert',
ADD COLUMN imported_by_user_id VARCHAR(255) COMMENT 'Wer hat importiert';

CREATE INDEX idx_org_imported ON org(is_imported);
CREATE INDEX idx_org_import_batch ON org(import_batch_uuid);
```

### 2. Import-Batch Tabelle

```sql
-- Import-Batch (für Gruppierung von Importen)
CREATE TABLE org_import_batch (
    batch_uuid CHAR(36) PRIMARY KEY,
    source_type VARCHAR(50) NOT NULL COMMENT 'excel | csv | api | other',
    source_file VARCHAR(255) COMMENT 'Dateiname (bei File-Import)',
    total_rows INT NOT NULL COMMENT 'Anzahl Zeilen im Import',
    imported_count INT NOT NULL DEFAULT 0 COMMENT 'Erfolgreich importiert',
    skipped_count INT NOT NULL DEFAULT 0 COMMENT 'Übersprungen (Duplikate, etc.)',
    error_count INT NOT NULL DEFAULT 0 COMMENT 'Fehlerhafte Zeilen',
    status VARCHAR(50) NOT NULL DEFAULT 'processing' COMMENT 'processing | completed | failed',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    imported_by_user_id VARCHAR(255) NOT NULL,
    notes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_import_batch_status ON org_import_batch(status);
CREATE INDEX idx_import_batch_user ON org_import_batch(imported_by_user_id);
```

### 3. Import-Validierungs-Task

Die erste Task im Workflow für importierte Orgs:

```yaml
# Workflow Template Anpassung
workflows:
  - key: QUALIFY_COMPANY
    tasks:
      # Nur für importierte Orgs
      - task_type: IMPORT_VALIDATION
        title: "Import validieren (Datenqualität prüfen)"
        assigned_queue: SALES_OPS
        assignee_role: ops
        due_in_days: 1
        priority: HIGH
        condition: org.is_imported == true  # Nur wenn importiert
        
      # Standard-Tasks (wie bisher)
      - task_type: OPS_DATA_CHECK
        title: "Basisdaten prüfen / Dedupe"
        # ...
```

---

## Import-Workflow

### Phase 1: Import-Verarbeitung

```
Excel/CSV Upload
        │
        ▼
┌───────────────────┐
│ Import-Batch      │
│ erstellen         │
└───────────────────┘
        │
        ▼
┌───────────────────┐
│ Zeile für Zeile   │
│ verarbeiten        │
└───────────────────┘
        │
        ├─→ Duplikat? → Skip (in skipped_count)
        ├─→ Fehler? → Log (in error_count)
        └─→ OK → Org erstellen
                │
                ├─→ status = 'lead'
                ├─→ current_stage = 'UNVERIFIED'
                ├─→ is_imported = true
                ├─→ import_batch_uuid = ...
                └─→ QUALIFY_COMPANY Workflow starten
                        │
                        └─→ Erste Task: IMPORT_VALIDATION
```

### Phase 2: Validierung (Sales Ops)

```
Sales Ops sieht Task: "Import validieren"
        │
        ▼
┌───────────────────┐
│ Prüft:            │
│ - Datenvollständig?│
│ - Duplikate?       │
│ - Qualität OK?     │
└───────────────────┘
        │
        ├─→ OK → Task erledigt → Weiter mit OPS_DATA_CHECK
        └─→ Probleme → Notiz + ggf. Org korrigieren/löschen
```

---

## Implementierung

### 1. OrgImportService

```php
class OrgImportService {
    private PDO $db;
    private OrgService $orgService;
    private WorkflowTemplateService $workflowService;
    
    public function importFromExcel(string $filePath, string $userId): array
    {
        // 1. Import-Batch erstellen
        $batchUuid = $this->createImportBatch('excel', basename($filePath), $userId);
        
        // 2. Excel lesen
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // 3. Header-Zeile lesen (Mapping)
        $headers = $this->readHeaders($worksheet);
        
        // 4. Zeile für Zeile verarbeiten
        $stats = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0
        ];
        
        $highestRow = $worksheet->getHighestRow();
        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $rowData = $this->readRow($worksheet, $row, $headers);
                
                // Prüfe auf Duplikate
                if ($this->isDuplicate($rowData)) {
                    $stats['skipped']++;
                    continue;
                }
                
                // Erstelle Org
                $orgData = $this->mapRowToOrgData($rowData);
                $orgData['is_imported'] = true;
                $orgData['import_source'] = 'excel';
                $orgData['import_batch_uuid'] = $batchUuid;
                $orgData['imported_at'] = date('Y-m-d H:i:s');
                $orgData['imported_by_user_id'] = $userId;
                $orgData['status'] = 'lead'; // Immer lead für Importe
                
                $org = $this->orgService->createOrg($orgData, $userId);
                
                // Workflow starten (wird automatisch in createOrg gemacht)
                // Erste Task wird IMPORT_VALIDATION sein (wenn is_imported = true)
                
                $stats['imported']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->logImportError($batchUuid, $row, $e->getMessage());
            }
        }
        
        // 5. Batch abschließen
        $this->completeImportBatch($batchUuid, $stats);
        
        return [
            'batch_uuid' => $batchUuid,
            'stats' => $stats
        ];
    }
    
    private function createImportBatch(string $sourceType, string $sourceFile, string $userId): string
    {
        $batchUuid = UuidHelper::generate($this->db);
        
        $stmt = $this->db->prepare("
            INSERT INTO org_import_batch (
                batch_uuid, source_type, source_file, 
                total_rows, imported_by_user_id
            )
            VALUES (
                :batch_uuid, :source_type, :source_file,
                :total_rows, :imported_by_user_id
            )
        ");
        
        // TODO: total_rows aus Excel ermitteln
        $stmt->execute([
            'batch_uuid' => $batchUuid,
            'source_type' => $sourceType,
            'source_file' => $sourceFile,
            'total_rows' => 0, // Wird später aktualisiert
            'imported_by_user_id' => $userId
        ]);
        
        return $batchUuid;
    }
}
```

### 2. Workflow Template Anpassung

```yaml
workflows:
  - key: QUALIFY_COMPANY
    tasks:
      # Bedingte Task: Nur für Importe
      - task_type: IMPORT_VALIDATION
        title: "Import validieren (Datenqualität prüfen)"
        assigned_queue: SALES_OPS
        assignee_role: ops
        due_in_days: 1
        priority: HIGH
        condition: |
          # Nur wenn Org importiert wurde
          org.is_imported == true
        blocking: true  # Muss erledigt sein, bevor andere Tasks starten
        
      # Standard-Tasks (für alle)
      - task_type: OPS_DATA_CHECK
        title: "Basisdaten prüfen / Dedupe"
        assigned_queue: SALES_OPS
        assignee_role: ops
        due_in_days: 1
        priority: HIGH
        # Keine Bedingung = für alle Orgs
```

### 3. OrgService Anpassung

```php
// In OrgService::createOrg()
if (!empty($data['is_imported']) && $data['is_imported']) {
    // Importierte Org: Workflow startet, aber erste Task ist IMPORT_VALIDATION
    // (wird im WorkflowTemplateService gehandhabt)
}
```

---

## UI: Import-Interface

### Import-Dialog

```html
<div class="import-dialog">
    <h2>Organisationen importieren</h2>
    
    <form id="import-form">
        <div>
            <label>Datei auswählen:</label>
            <input type="file" accept=".xlsx,.xls,.csv" id="import-file">
        </div>
        
        <div>
            <label>Import-Optionen:</label>
            <label>
                <input type="checkbox" name="skip_duplicates" checked>
                Duplikate überspringen
            </label>
            <label>
                <input type="checkbox" name="auto_validate" checked>
                Automatische Validierung aktivieren
            </label>
        </div>
        
        <button type="submit">Import starten</button>
    </form>
    
    <div id="import-progress" style="display: none;">
        <p>Import läuft...</p>
        <progress id="import-progress-bar" value="0" max="100"></progress>
        <p id="import-status">Vorbereitung...</p>
    </div>
</div>
```

### Import-Ergebnis

```html
<div class="import-result">
    <h3>Import abgeschlossen</h3>
    <table>
        <tr>
            <td>Erfolgreich importiert:</td>
            <td><strong>150</strong></td>
        </tr>
        <tr>
            <td>Übersprungen (Duplikate):</td>
            <td>12</td>
        </tr>
        <tr>
            <td>Fehler:</td>
            <td>3</td>
        </tr>
    </table>
    
    <p>
        <a href="/imports/{batch_uuid}">Import-Details anzeigen</a>
    </p>
</div>
```

---

## Alternative: Vorstufe "IMPORTED"?

**Frage:** Brauchen wir eine eigene Stage `IMPORTED` vor `UNVERIFIED`?

**Antwort:** **Nein, nicht nötig.**

**Begründung:**
- `UNVERIFIED` beschreibt bereits "ungeprüft"
- Importierte Orgs sind auch "ungeprüft"
- `is_imported` Flag + `IMPORT_VALIDATION` Task reichen aus
- Einheitliche Stage-Maschine bleibt einfach

**Aber:** Wenn gewünscht, könnte man `IMPORTED` als Stage einführen:

```
IMPORTED → UNVERIFIED → QUALIFYING → ...
```

**Empfehlung:** Bei `is_imported = true` bleibt `current_stage = UNVERIFIED`, aber erste Task ist `IMPORT_VALIDATION`.

---

## Zusammenfassung

### Lösung: Import-Flag + Validierungs-Task

1. **Importierte Orgs:**
   - `status = 'lead'`
   - `current_stage = 'UNVERIFIED'`
   - `is_imported = true`
   - Import-Metadaten (Batch, Quelle, Datum)

2. **Workflow:**
   - `QUALIFY_COMPANY` startet (wie bei manuellen Leads)
   - **ABER:** Erste Task ist `IMPORT_VALIDATION` (nur für Importe)
   - Diese Task muss erledigt sein, bevor Qualifizierung startet

3. **Vorteile:**
   - ✅ Keine neue Stage nötig
   - ✅ Einheitliche Workflow-Maschine
   - ✅ Qualitätssicherung durch Validierungs-Task
   - ✅ Nachvollziehbarkeit durch Import-Batch

### Keine Vorstufe nötig

- `UNVERIFIED` beschreibt bereits "ungeprüft"
- Importierte Orgs sind auch "ungeprüft"
- `is_imported` Flag + `IMPORT_VALIDATION` Task reichen aus
