# CRM Workflow Integration - Zusammenfassung

## Kurzfassung

Das vorgeschlagene CRM-Workflow-Konzept passt **sehr gut** zu TOM. Die meisten Komponenten existieren bereits, müssen aber erweitert werden.

---

## ✅ Was kann direkt verwendet werden?

1. **Case-System** (`case_item`) - bereits vorhanden
2. **Task-System** (`task`) - bereits vorhanden  
3. **Engine-Modell** (inside_sales, ops, etc.) - bereits vorhanden
4. **Rollenlogik** (owner_role, assignee_role) - bereits vorhanden
5. **Handover/Return** - bereits vorhanden

---

## 🔄 Was muss erweitert werden?

### 1. Company Stage (State Machine)
- **Neu:** `org.current_stage` Feld
- **Neu:** `org_stage_history` Tabelle
- **Neu:** `OrgStageService` mit Guard-Prüfung

### 2. Activities (strukturierte Aktivitäten)
- **Neu:** `activity` Tabelle (parallel zu `case_note`)
- **Neu:** `ActivityService` mit follow_up → Task Automation

### 3. Task Erweiterungen
- **Neu:** `task.task_type` (Enum)
- **Neu:** `task.assigned_queue` (Queue-basiert)

### 4. Case Erweiterungen
- **Neu:** `case_item.outcome_code` (QUALIFIED, DISQUALIFIED, etc.)
- **Neu:** `case_item.owner_queue` (INSIDE_SALES, SALES_OPS, etc.)

---

## ➕ Was ist komplett neu?

1. **Workflow Template System** (YAML-basiert)
   - `config/workflows/qualify_company.yaml`
   - `WorkflowTemplateService` zum Laden und Ausführen

2. **Stage Guard Service**
   - Prüft Regeln vor Stage-Transitions
   - Verhindert ungültige Übergänge

3. **Activity → Task Automation**
   - Automatische `CALL_BACK` Task bei `follow_up_at`

---

## 📋 Implementierungsreihenfolge

### Phase 1: Datenmodell
- Migrationen für Stage, Activity, Task/Case Erweiterungen

### Phase 2: Services
- WorkflowTemplateService
- ActivityService  
- OrgStageService

### Phase 3: API
- Activity CRUD
- Timeline View
- Queue-basierte Task-Views
- Stage-Transitions (guarded)

### Phase 4: UI
- Company Detail (Stage + Case + Tasks + Timeline)
- Activity Logging Modal
- Queue Views

### Phase 5: Actions
- Qualify Lead
- Disqualify
- Dormant

---

## 🎯 Kernentscheidungen

1. **Stage vs. Status:** Beide parallel (`org.status` = einfach, `org.current_stage` = detailliert)
2. **Queue vs. Role:** `owner_queue` ergänzt `owner_role` (Queue = CRM, Role = TOM Engine)
3. **Activity vs. Note:** `activity` = strukturiert, `case_note` = allgemein
4. **Template Format:** YAML (wie im Konzept)

---

## 📊 Mapping: CRM → TOM

| CRM | TOM | Status |
|-----|-----|--------|
| `cases` | `case_item` | ✅ Vorhanden |
| `case_tasks` | `task` | ✅ Vorhanden |
| `activities` | `activity` | ➕ Neu |
| `company_stage` | `org.current_stage` | ➕ Neu |
| `company_stage_history` | `org_stage_history` | ➕ Neu |

---

**Fazit:** Gute Passung. Hauptaufgabe = Integration in bestehende Strukturen + Ergänzung fehlender Komponenten.
