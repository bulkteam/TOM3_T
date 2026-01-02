# CRM Workflow - Stage Decision Tree

## Entscheidungsbaum: Was passiert bei Org-Erstellung?

```
┌─────────────────────────────────────────────────────────┐
│  Organisation wird angelegt (org.created)                │
│  Input: org.status (lead | prospect | customer | inactive)│
└─────────────────────────────────────────────────────────┘
                        │
                        ▼
        ┌───────────────┴───────────────┐
        │                               │
        ▼                               ▼
┌───────────────┐              ┌───────────────┐
│ status = lead │              │ status ≠ lead  │
│ (oder null)   │              │ (prospect/     │
│               │              │  customer/      │
│               │              │  inactive)     │
└───────────────┘              └───────────────┘
        │                               │
        ▼                               ▼
┌───────────────┐              ┌──────────────────────┐
│ current_stage │              │ current_stage =      │
│ = UNVERIFIED  │              │ MAPPED_STAGE         │
└───────────────┘              │                      │
        │                      │ prospect → QUALIFIED_LEAD│
        ▼                      │ customer → CUSTOMER     │
┌───────────────┐              │ inactive → DORMANT      │
│ ✅ Workflow   │              └──────────────────────┘
│   starten     │                      │
│               │                      ▼
│ QUALIFY_      │              ┌───────────────┐
│ COMPANY       │              │ ❌ KEIN       │
│               │              │ Workflow      │
└───────────────┘              └───────────────┘
        │
        ▼
┌───────────────┐
│ Case erstellt │
│ Tasks erstellt│
└───────────────┘
        │
        ▼
┌───────────────┐
│ Stage Transition│
│ UNVERIFIED →   │
│ QUALIFYING     │
└───────────────┘
```

---

## Szenarien im Detail

### Szenario A: Neuer Lead (Standard-Workflow)

```
Eingabe: status = 'lead' (oder nicht gesetzt)
         ↓
current_stage = 'UNVERIFIED'
         ↓
Stage History: null → UNVERIFIED (ORG_CREATED)
         ↓
✅ QUALIFY_COMPANY Workflow startet
         ↓
Case erstellt (type=QUALIFY_COMPANY, engine=inside_sales)
         ↓
Tasks erstellt (OPS_DATA_CHECK, FIRST_OUTREACH, etc.)
         ↓
Stage Transition: UNVERIFIED → QUALIFYING (CASE_OPENED)
         ↓
Org durchläuft Qualifizierungs-Pipeline
```

### Szenario B: Bereits Kunde (Bypass)

```
Eingabe: status = 'customer'
         ↓
current_stage = 'CUSTOMER'
         ↓
Stage History: null → CUSTOMER (ORG_CREATED)
         ↓
❌ KEIN Workflow (Bypass)
         ↓
Org ist direkt im finalen Stage
         ↓
Optional: Account Owner sollte gesetzt sein
```

### Szenario C: Bereits qualifiziert (Bypass)

```
Eingabe: status = 'prospect'
         ↓
current_stage = 'QUALIFIED_LEAD'
         ↓
Stage History: null → QUALIFIED_LEAD (ORG_CREATED)
         ↓
❌ KEIN Workflow (Bypass)
         ↓
Org kann direkt an Outside Sales übergeben werden
         ↓
Optional: WORK_LEAD Case manuell erstellen
```

### Szenario D: Inaktiv (Bypass)

```
Eingabe: status = 'inactive'
         ↓
current_stage = 'DORMANT'
         ↓
Stage History: null → DORMANT (ORG_CREATED)
         ↓
❌ KEIN Workflow (Bypass)
         ↓
Org ist ruhend
         ↓
Kann später reaktiviert werden (DORMANT → QUALIFYING)
```

---

## Mapping-Tabelle

| Eingabe (org.status) | Initial Stage (current_stage) | Workflow? | Begründung |
|---------------------|-------------------------------|-----------|------------|
| `lead` (oder null) | `UNVERIFIED` | ✅ Ja | Muss qualifiziert werden |
| `prospect` | `QUALIFIED_LEAD` | ❌ Nein | Bereits qualifiziert |
| `customer` | `CUSTOMER` | ❌ Nein | Bereits Kunde |
| `inactive` | `DORMANT` | ❌ Nein | Ruhend |

---

## Code-Flow

```php
OrgService::createOrg($data)
    │
    ├─→ mapStatusToInitialStage($status)
    │   └─→ return 'UNVERIFIED' | 'QUALIFIED_LEAD' | 'CUSTOMER' | 'DORMANT'
    │
    ├─→ INSERT INTO org (..., status, current_stage)
    │
    ├─→ writeStageHistory($orgUuid, null, $initialStage, 'ORG_CREATED')
    │
    └─→ if ($initialStage === 'UNVERIFIED')
            └─→ triggerQualifyWorkflow($orgUuid)
                └─→ WorkflowTemplateService::startWorkflow('QUALIFY_COMPANY', $orgUuid)
                    ├─→ Erstelle Case
                    ├─→ Erstelle Tasks
                    └─→ Stage Transition: UNVERIFIED → QUALIFYING
```

---

## UI-Flow

```
┌─────────────────────────────────────┐
│  Org-Erstellungsformular            │
│                                     │
│  Name: [____________]               │
│  Status: [Dropdown ▼]              │
│    • Lead (wird qualifiziert)       │
│    • Prospect (bereits qualifiziert)│
│    • Customer (bereits Kunde)       │
│    • Inactive (ruhend)              │
│                                     │
│  [Hinweis]                          │
│  "Lead" durchläuft automatisch      │
│  den Qualifizierungs-Workflow.     │
│                                     │
│  [Speichern]                        │
└─────────────────────────────────────┘
                │
                ▼
        ┌───────────────┐
        │ Backend       │
        │ Processing    │
        └───────────────┘
                │
        ┌───────┴───────┐
        │               │
        ▼               ▼
┌───────────┐   ┌───────────┐
│ Lead?     │   │ Andere?    │
│           │   │            │
│ ✅ Workflow│   │ ❌ Bypass  │
│ startet   │   │            │
└───────────┘   └───────────┘
```

---

## Zusammenfassung

**Kernprinzip:**
- ✅ **Flexible Eingabe:** Benutzer kann Status setzen
- ✅ **Intelligente Initialisierung:** Stage wird basierend auf Status gemappt
- ✅ **Workflow nur bei Bedarf:** Nur `UNVERIFIED` → Workflow
- ✅ **Bypass für bekannte Orgs:** `customer`, `prospect` → direkt passender Stage

**Vorteile:**
- Keine unnötigen Workflows für bereits qualifizierte Orgs
- Vollständige Historie (Stage History)
- Klare Trennung: `status` (Eingabe) vs. `current_stage` (Workflow-State)
