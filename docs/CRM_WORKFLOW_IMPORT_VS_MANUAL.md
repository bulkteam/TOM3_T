# CRM Workflow - Import vs. Manuelle Eingabe

## Vergleich: Manuelle Eingabe vs. Import

### Manuelle Eingabe (Webformular)

```
┌─────────────────────────────────────┐
│  Benutzer gibt Org manuell ein      │
│  Input: status = 'lead'            │
└─────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│  OrgService::createOrg()            │
│  - status = 'lead'                  │
│  - current_stage = 'UNVERIFIED'     │
│  - is_imported = false              │
└─────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│  QUALIFY_COMPANY Workflow startet   │
│                                     │
│  Tasks:                             │
│  1. OPS_DATA_CHECK                  │
│  2. ADD_CONTACT_POINTS              │
│  3. FIRST_OUTREACH                  │
│  ...                                │
└─────────────────────────────────────┘
```

### Import (Excel/CSV)

```
┌─────────────────────────────────────┐
│  Benutzer lädt Excel/CSV hoch       │
│  Input: Datei mit vielen Orgs       │
└─────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│  OrgImportService::importFromExcel()│
│  - Erstellt Import-Batch            │
│  - Verarbeitet Zeile für Zeile      │
└─────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│  Für jede Zeile:                    │
│  OrgService::createOrg()            │
│  - status = 'lead'                  │
│  - current_stage = 'UNVERIFIED'     │
│  - is_imported = true  ⭐           │
│  - import_batch_uuid = ...         │
│  - import_source = 'excel'          │
└─────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────┐
│  QUALIFY_COMPANY Workflow startet   │
│                                     │
│  Tasks:                             │
│  1. IMPORT_VALIDATION ⭐            │
│     (nur wenn is_imported = true)   │
│  2. OPS_DATA_CHECK                  │
│  3. ADD_CONTACT_POINTS              │
│  4. FIRST_OUTREACH                  │
│  ...                                │
└─────────────────────────────────────┘
```

---

## Unterschiede im Detail

| Aspekt | Manuelle Eingabe | Import |
|--------|------------------|--------|
| **Status** | `status = 'lead'` (oder user-set) | `status = 'lead'` (immer) |
| **Stage** | `current_stage = 'UNVERIFIED'` | `current_stage = 'UNVERIFIED'` |
| **is_imported** | `false` | `true` |
| **Erste Task** | `OPS_DATA_CHECK` | `IMPORT_VALIDATION` |
| **Validierung** | Optional (durch OPS_DATA_CHECK) | **Pflicht** (durch IMPORT_VALIDATION) |
| **Batch** | Kein Batch | Import-Batch für Gruppierung |
| **Metadaten** | Keine | Quelle, Datum, User |

---

## Workflow-Flow: Importierte Org

```
Import-Batch erstellt
        │
        ▼
Org erstellt (is_imported = true)
        │
        ▼
QUALIFY_COMPANY Workflow startet
        │
        ▼
┌───────────────────────┐
│ Task 1:               │
│ IMPORT_VALIDATION     │
│ (Sales Ops)           │
│                       │
│ Prüft:                │
│ - Datenvollständig?   │
│ - Duplikate?          │
│ - Qualität OK?        │
└───────────────────────┘
        │
        ├─→ ❌ Probleme → Notiz + ggf. Org korrigieren/löschen
        │
        └─→ ✅ OK → Task erledigt
                │
                ▼
┌───────────────────────┐
│ Task 2:               │
│ OPS_DATA_CHECK        │
│ (Sales Ops)           │
│                       │
│ Prüft:                │
│ - Basisdaten          │
│ - Dedupe              │
└───────────────────────┘
        │
        ▼
┌───────────────────────┐
│ Task 3:               │
│ ADD_CONTACT_POINTS    │
│ (Sales Ops)           │
└───────────────────────┘
        │
        ▼
┌───────────────────────┐
│ Task 4:               │
│ FIRST_OUTREACH        │
│ (Inside Sales)        │
└───────────────────────┘
        │
        ▼
... (Rest wie normal)
```

---

## Entscheidungsbaum: Import vs. Manual

```
┌─────────────────────────────────────┐
│  Org wird erstellt                  │
└─────────────────────────────────────┘
                │
        ┌───────┴───────┐
        │               │
        ▼               ▼
┌───────────────┐  ┌───────────────┐
│ Manuell       │  │ Import        │
│ (Formular)    │  │ (Excel/CSV)   │
└───────────────┘  └───────────────┘
        │               │
        ▼               ▼
┌───────────────┐  ┌───────────────┐
│ is_imported   │  │ is_imported   │
│ = false       │  │ = true        │
└───────────────┘  └───────────────┘
        │               │
        ▼               ▼
┌───────────────┐  ┌───────────────┐
│ Workflow:     │  │ Workflow:     │
│               │  │               │
│ 1. OPS_DATA_  │  │ 1. IMPORT_     │
│    CHECK      │  │    VALIDATION │
│ 2. ADD_       │  │ 2. OPS_DATA_   │
│    CONTACT_   │  │    CHECK       │
│    POINTS     │  │ 3. ADD_       │
│ 3. FIRST_     │  │    CONTACT_   │
│    OUTREACH   │  │    POINTS     │
│ ...           │  │ 4. FIRST_     │
│               │  │    OUTREACH   │
└───────────────┘  └───────────────┘
```

---

## Code-Beispiel: Workflow Template

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
        condition: org.is_imported == true
        blocking: true  # Muss erledigt sein, bevor andere Tasks starten
        
      # Standard-Tasks (für alle)
      - task_type: OPS_DATA_CHECK
        title: "Basisdaten prüfen / Dedupe"
        assigned_queue: SALES_OPS
        assignee_role: ops
        due_in_days: 1
        priority: HIGH
        # Keine Bedingung = für alle Orgs
        
      - task_type: ADD_CONTACT_POINTS
        title: "Telefon/Webseite/Domain ergänzen"
        assigned_queue: SALES_OPS
        assignee_role: ops
        due_in_days: 2
        priority: NORMAL
        
      # ... weitere Tasks ...
```

---

## Zusammenfassung

### ✅ Lösung: Import-Flag + Validierungs-Task

**Importierte Orgs:**
- Gleicher initialer Stage (`UNVERIFIED`) wie manuelle Leads
- **ABER:** `is_imported = true` Flag
- **ABER:** Erste Task ist `IMPORT_VALIDATION` (nur für Importe)
- **ABER:** Import-Metadaten (Batch, Quelle, Datum)

**Vorteile:**
- ✅ Keine neue Stage nötig
- ✅ Einheitliche Workflow-Maschine
- ✅ Qualitätssicherung durch Validierungs-Task
- ✅ Nachvollziehbarkeit durch Import-Batch

**Keine Vorstufe nötig:**
- `UNVERIFIED` beschreibt bereits "ungeprüft"
- Importierte Orgs sind auch "ungeprüft"
- `is_imported` Flag + `IMPORT_VALIDATION` Task reichen aus
