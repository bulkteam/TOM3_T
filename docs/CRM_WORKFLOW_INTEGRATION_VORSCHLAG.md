# CRM Workflow Integration - Vorschlag für TOM

**Stand:** 2026-01-01  
**Ziel:** Integration des Company Qualification Workflows in die bestehende TOM-Architektur

---

## 1. Analyse: Was passt bereits zu TOM?

### ✅ Gut passend / Direkt verwendbar

1. **Case-System (`case_item`)**
   - TOM hat bereits `case_item` mit `case_type`, `engine`, `phase`, `status`, `owner_role`, `org_uuid`
   - Passt perfekt für `QUALIFY_COMPANY` Cases
   - **Anpassung:** `case_type` erweitern um `QUALIFY_COMPANY`, `WORK_LEAD`, `OPPORTUNITY`

2. **Task-System (`task`)**
   - TOM hat bereits `task` mit `case_uuid`, `assignee_role`, `status`, `due_at`
   - Passt für Case-Tasks (OPS_DATA_CHECK, FIRST_OUTREACH, etc.)
   - **Anpassung:** `task_type` Feld hinzufügen (enum) + `assigned_queue` optional

3. **Engine-Modell**
   - TOM hat bereits Engines: `customer_inbound`, `ops`, `inside_sales`, `outside_sales`, `order_admin`
   - Passt perfekt: `QUALIFY_COMPANY` läuft in Engine `inside_sales` (oder `ops`)

4. **Rollenlogik**
   - TOM nutzt bereits `owner_role` und `assignee_role` (nicht Personen)
   - Passt zu Queue-basiertem Routing (`INSIDE_SALES`, `SALES_OPS`)

5. **Handover/Return-Mechanismus**
   - TOM hat bereits `case_handover` und `case_return` Tabellen
   - Passt für Übergabe Qualified Lead → Outside Sales

6. **Timeline/Notes (`case_note`)**
   - TOM hat bereits `case_note` mit `note_type`
   - **Ergänzung:** `activity` Tabelle für strukturierte Aktivitäten (Calls/Emails/Meetings)

---

## 2. Was muss anders gestaltet werden?

### 🔄 Anpassungen an TOM-Architektur

#### 2.1 Company Stage (State Machine)

**Problem:** TOM hat aktuell nur `org.status` (lead | prospect | customer | inactive) - zu einfach für CRM-Lifecycle.

**Lösung:** Neue Tabelle `org_stage` + State Machine

```sql
-- Neue Tabelle: org_stage (aktueller Stage pro Org)
ALTER TABLE org 
ADD COLUMN current_stage VARCHAR(50) DEFAULT 'UNVERIFIED' 
COMMENT 'UNVERIFIED | QUALIFYING | QUALIFIED_LEAD | SALES_ACCEPTED | CUSTOMER | DISQUALIFIED | DORMANT | ARCHIVED';

CREATE INDEX idx_org_stage ON org(current_stage);

-- Stage History (Audit-Trail)
CREATE TABLE org_stage_history (
    history_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    from_stage VARCHAR(50) NOT NULL,
    to_stage VARCHAR(50) NOT NULL,
    reason_code VARCHAR(50),
    reason_note TEXT,
    changed_by_user_id VARCHAR(255),
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    case_uuid CHAR(36) COMMENT 'Verknüpfung zum auslösenden Case',
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_stage_history_org ON org_stage_history(org_uuid);
CREATE INDEX idx_org_stage_history_stage ON org_stage_history(to_stage);
```

**Guard-Regeln (serverseitig):**
- `UNVERIFIED → QUALIFYING`: Nur wenn Case `QUALIFY_COMPANY` erstellt/aktiv
- `QUALIFYING → QUALIFIED_LEAD`: Nur wenn mind. 1 Activity (CALL/EMAIL/MEETING) + Next Step vorhanden
- `ANY → ARCHIVED`: Nur wenn kein offener Case existiert

#### 2.2 Activities (strukturierte Aktivitäten)

**Problem:** TOM hat `case_note` (Freitext), aber keine strukturierten Activities mit Outcome-Codes.

**Lösung:** Neue Tabelle `activity` (parallel zu `case_note`, nicht ersetzend)

```sql
CREATE TABLE activity (
    activity_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    person_uuid CHAR(36) COMMENT 'Optional: Ansprechpartner',
    case_uuid CHAR(36) COMMENT 'Optional: Verknüpfung zum Case',
    activity_type VARCHAR(50) NOT NULL COMMENT 'CALL | EMAIL | MEETING | NOTE | RESEARCH',
    occurred_at DATETIME NOT NULL,
    outcome_code VARCHAR(50) COMMENT 'REACHED_DECISION_MAKER | NO_ANSWER | LEFT_VOICEMAIL | etc.',
    notes TEXT,
    follow_up_at DATETIME COMMENT 'Wiedervorlage-Datum',
    follow_up_task_uuid CHAR(36) COMMENT 'Referenz zur erzeugten CALL_BACK Task',
    created_by_user_id VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE SET NULL,
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE SET NULL,
    FOREIGN KEY (follow_up_task_uuid) REFERENCES task(task_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_activity_org ON activity(org_uuid);
CREATE INDEX idx_activity_case ON activity(case_uuid);
CREATE INDEX idx_activity_occurred ON activity(occurred_at);
CREATE INDEX idx_activity_type ON activity(activity_type);
```

**Vorteil:** `case_note` bleibt für allgemeine Notizen, `activity` für strukturierte Interaktionen.

#### 2.3 Task Type + Queue

**Problem:** TOM `task` hat nur `title` (Freitext), kein `task_type` Enum.

**Lösung:** Erweitern

```sql
ALTER TABLE task 
ADD COLUMN task_type VARCHAR(50) COMMENT 'OPS_DATA_CHECK | FIRST_OUTREACH | IDENTIFY_CONTACT | FIT_ASSESSMENT | CALL_BACK | etc.',
ADD COLUMN assigned_queue VARCHAR(50) COMMENT 'INSIDE_SALES | SALES_OPS | OUTSIDE_SALES';

CREATE INDEX idx_task_type ON task(task_type);
CREATE INDEX idx_task_queue ON task(assigned_queue);
```

#### 2.4 Case Status vs. Case Outcome

**Problem:** TOM `case_item.status` ist systemisch (neu | in_bearbeitung | abgeschlossen).  
CRM braucht zusätzlich `outcome_code` (QUALIFIED | DISQUALIFIED | DORMANT).

**Lösung:** Erweitern

```sql
ALTER TABLE case_item 
ADD COLUMN outcome_code VARCHAR(50) COMMENT 'QUALIFIED | DISQUALIFIED | DORMANT | etc.',
ADD COLUMN outcome_note TEXT,
ADD COLUMN owner_queue VARCHAR(50) COMMENT 'INSIDE_SALES | SALES_OPS | OUTSIDE_SALES';
```

**Hinweis:** `status` bleibt berechnet (systemisch), `outcome_code` ist fachlich (bei Abschluss).

---

## 3. Was fehlt / Neu hinzufügen?

### ➕ Neue Komponenten

#### 3.1 Workflow Template System

**Ziel:** Workflows als YAML konfigurierbar (nicht hart verdrahtet).

**Lösung:** 
- YAML-Dateien in `config/workflows/` (z.B. `qualify_company.yaml`)
- `WorkflowTemplateService` liest YAML und erstellt Cases + Tasks
- Event: `org.created` → Trigger `QUALIFY_COMPANY` Workflow

**Struktur:**
```
config/
  workflows/
    qualify_company.yaml
    work_lead.yaml (später)
```

**Service:**
```php
class WorkflowTemplateService {
    public function startWorkflow(string $workflowKey, string $orgUuid): void {
        // 1. Lade YAML Template
        // 2. Erstelle Case
        // 3. Erstelle Tasks aus Template
        // 4. Stage Transition (wenn definiert)
    }
}
```

#### 3.2 Activity → Task Automation

**Ziel:** Wenn Activity `follow_up_at` gesetzt → automatisch `CALL_BACK` Task erstellen.

**Lösung:** Event Listener oder Service-Methode

```php
class ActivityService {
    public function createActivity(array $data): string {
        // 1. Activity speichern
        // 2. Wenn follow_up_at gesetzt:
        //    - Erstelle Task(type=CALL_BACK, due_at=follow_up_at)
        //    - Verknüpfe activity.follow_up_task_uuid
    }
}
```

#### 3.3 Stage Guard Service

**Ziel:** Stage-Transitions nur erlauben, wenn Guards erfüllt.

**Lösung:** `OrgStageService` mit Guard-Prüfung

```php
class OrgStageService {
    public function transitionStage(
        string $orgUuid, 
        string $toStage, 
        ?string $reasonCode = null
    ): void {
        // 1. Prüfe Guards (z.B. QUALIFYING → QUALIFIED_LEAD)
        // 2. Wenn OK: Stage ändern + History schreiben
        // 3. Sonst: Exception
    }
    
    private function checkGuard(string $fromStage, string $toStage, string $orgUuid): bool {
        // Implementiere Guard-Regeln
    }
}
```

#### 3.4 Queue-basierte Task-Views

**Ziel:** "Meine Aufgaben" nach Queue filtern.

**Lösung:** Erweitere Task-Queries

```php
class TaskService {
    public function getTasksByQueue(string $queue, ?string $userId = null): array {
        // Filter: assigned_queue = $queue AND (assignee_user_id = $userId OR assignee_user_id IS NULL)
        // Sort: overdue first, then due_at ASC
    }
}
```

---

## 4. Integrations-Strategie

### 4.1 Mapping: CRM-Konzept → TOM

| CRM-Konzept | TOM-Entsprechung | Status |
|------------|------------------|--------|
| `cases` | `case_item` | ✅ Vorhanden |
| `case_tasks` | `task` | ✅ Vorhanden (erweitern) |
| `activities` | `activity` (neu) | ➕ Neu |
| `company_stage` | `org.current_stage` (neu) | ➕ Neu |
| `company_stage_history` | `org_stage_history` (neu) | ➕ Neu |
| `owner_queue` | `case_item.owner_queue` (neu) | ➕ Neu |
| `task_type` | `task.task_type` (neu) | ➕ Neu |
| `outcome_code` | `case_item.outcome_code` (neu) | ➕ Neu |

### 4.2 Engine-Mapping

| CRM-Workflow | TOM Engine | Begründung |
|-------------|------------|------------|
| `QUALIFY_COMPANY` | `inside_sales` | Qualifizierung = Inside Sales Aufgabe |
| `WORK_LEAD` (später) | `outside_sales` | Konkreter Lead = Outside Sales |
| Sales Ops Tasks | `ops` | Datenqualität = OPS |

**Hinweis:** Ein Case kann Tasks in verschiedenen Queues haben (z.B. `QUALIFY_COMPANY` Case hat Tasks für `SALES_OPS` und `INSIDE_SALES`).

### 4.3 Case Type Erweiterung

```sql
-- Enum-Werte für case_type erweitern:
-- Bestehend: (aus TOM Core)
-- Neu: QUALIFY_COMPANY, WORK_LEAD, OPPORTUNITY
```

**Empfehlung:** Enum-Tabelle oder Constraint-Liste in Code dokumentieren.

---

## 5. Implementierungsreihenfolge (MVP)

### Phase 1: Datenmodell (T1-T2)
1. ✅ Migration: `org_stage`, `org_stage_history`
2. ✅ Migration: `activity` Tabelle
3. ✅ Migration: `task.task_type`, `task.assigned_queue`
4. ✅ Migration: `case_item.outcome_code`, `case_item.owner_queue`
5. ✅ Enum-Katalog dokumentieren

### Phase 2: Services (T3-T5)
6. ✅ `WorkflowTemplateService` (YAML-Loader + Case/Task-Erstellung)
7. ✅ `ActivityService` (mit follow_up → Task Automation)
8. ✅ `OrgStageService` (mit Guards)
9. ✅ Event: `org.created` → `QUALIFY_COMPANY` Workflow starten

### Phase 3: API (T4)
10. ✅ `POST /api/orgs/{id}/activities`
11. ✅ `GET /api/orgs/{id}/timeline` (Activities + Notes)
12. ✅ `GET /api/my/tasks` (Queue-basiert)
13. ✅ `POST /api/cases/{id}/close` (mit outcome_code)
14. ✅ `POST /api/orgs/{id}/stage` (guarded)

### Phase 4: UI (T6-T7)
15. ✅ Company Detail: Stage Badge + Active Case + Tasks + Timeline
16. ✅ "Log Activity" Modal (mit follow_up)
17. ✅ "My Tasks / My Cases" Queue View
18. ✅ "Qualify Lead" Button (mit Guard-Prüfung)
19. ✅ "Disqualify" Dialog

### Phase 5: Actions (T8-T10)
20. ✅ Qualify Lead Action (Guard + Stage + Case Close)
21. ✅ Disqualify Action
22. ✅ Dormant Action

---

## 6. Offene Fragen / Entscheidungen

### 6.1 Stage vs. Status

**Frage:** Soll `org.status` (lead/prospect/customer) durch `org.current_stage` ersetzt werden?

**Empfehlung:** 
- `org.status` = einfache Klassifikation (für Reporting/Filter)
- `org.current_stage` = detaillierter Lifecycle (für Workflow)
- Beide können parallel existieren (z.B. `status='lead'` + `current_stage='QUALIFYING'`)

### 6.2 Queue vs. Role

**Frage:** Wie unterscheiden sich `owner_queue` und `owner_role`?

**Empfehlung:**
- `owner_role` = TOM Engine-Rolle (`inside_sales`, `ops`, etc.)
- `owner_queue` = CRM-Queue (`INSIDE_SALES`, `SALES_OPS`, etc.)
- Mapping: `INSIDE_SALES` Queue → `inside_sales` Role
- Für MVP: `owner_queue` optional, `owner_role` bleibt führend

### 6.3 Activity vs. Case Note

**Frage:** Wann `activity`, wann `case_note`?

**Empfehlung:**
- `activity` = strukturierte Interaktion (Call/Email/Meeting) mit Outcome
- `case_note` = allgemeine Notiz/Kommentar/System-Event
- Beide können in Timeline kombiniert werden

### 6.4 Workflow Template Format

**Frage:** YAML oder JSON oder DB-Tabelle?

**Empfehlung:** YAML (wie im Konzept) - lesbar, versionierbar, kein Code-Change nötig.

---

## 7. Nächste Schritte

1. ✅ **Review dieses Dokuments** - Feedback einarbeiten
2. ✅ **Datenmodell finalisieren** - Migration-Skripte erstellen
3. ✅ **Workflow Template YAML** - `qualify_company.yaml` anpassen an TOM-Schema
4. ✅ **Service-Layer** - `WorkflowTemplateService`, `ActivityService`, `OrgStageService`
5. ✅ **API-Endpoints** - REST-API für Activities, Tasks, Stage-Transitions
6. ✅ **UI-Komponenten** - Company Detail, Activity Logging, Queue Views

---

## 8. Abweichungen vom Original-Konzept

### Was wir anders machen (TOM-spezifisch):

1. **Keine separate `cases` Tabelle** → Nutzen `case_item` (bereits vorhanden)
2. **Keine separate `case_tasks` Tabelle** → Nutzen `task` (bereits vorhanden)
3. **Engine + Phase** → TOM hat bereits Engine/Phase-Modell, nutzen wir
4. **Handover-Mechanismus** → TOM hat bereits `case_handover`, nutzen wir
5. **Timeline** → Kombinieren `activity` + `case_note` in einer View

### Was wir beibehalten (vom Konzept):

1. ✅ **Stage State Machine** - wie im Konzept
2. ✅ **Activities mit Outcome-Codes** - wie im Konzept
3. ✅ **Workflow Templates (YAML)** - wie im Konzept
4. ✅ **Guard-Regeln** - wie im Konzept
5. ✅ **Queue-basiertes Routing** - wie im Konzept

---

**Fazit:** Das CRM-Konzept passt sehr gut zu TOM. Hauptaufgabe ist Integration in bestehende Strukturen (`case_item`, `task`) + Ergänzung um fehlende Komponenten (`activity`, `org_stage`, Workflow Templates).
