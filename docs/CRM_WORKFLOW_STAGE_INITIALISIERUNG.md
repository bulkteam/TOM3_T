# CRM Workflow - Stage Initialisierung bei manueller Eingabe

## Problemstellung

Wenn Organisationen manuell angelegt werden (z.B. über Webformular), kann der Benutzer bereits einen `status` setzen:
- `lead` - Neuer Lead
- `prospect` - Interessent
- `customer` - Bereits Kunde
- `inactive` - Inaktiv

**Frage:** Soll eine Org, die als `customer` angelegt wird, auch durch die Qualifizierungs-Pipeline (`UNVERIFIED → QUALIFYING → QUALIFIED_LEAD`) laufen?

**Antwort:** Nein, das macht keinen Sinn. Bereits qualifizierte/kundige Organisationen sollten direkt den passenden `current_stage` erhalten.

---

## Lösung: Initial Stage Mapping + Bypass-Mechanismus

### 1. Mapping: `org.status` → `org.current_stage`

Beim Anlegen einer Org wird der `current_stage` basierend auf dem `status` initialisiert:

| org.status (Eingabe) | org.current_stage (initial) | Workflow-Trigger? |
|---------------------|----------------------------|-------------------|
| `lead` | `UNVERIFIED` | ✅ Ja → `QUALIFY_COMPANY` Workflow starten |
| `prospect` | `QUALIFIED_LEAD` | ❌ Nein (bereits qualifiziert) |
| `customer` | `CUSTOMER` | ❌ Nein (bereits Kunde) |
| `inactive` | `DORMANT` oder `ARCHIVED` | ❌ Nein |

### 2. Workflow-Trigger nur für bestimmte Stages

Der `QUALIFY_COMPANY` Workflow wird **nur** gestartet, wenn:
- `current_stage = UNVERIFIED` (oder nicht gesetzt)
- **UND** `status = lead` (oder nicht gesetzt)

**Ausnahmen (Bypass):**
- Wenn `status = customer` → `current_stage = CUSTOMER`, kein Workflow
- Wenn `status = prospect` → `current_stage = QUALIFIED_LEAD`, kein Workflow
- Wenn `status = inactive` → `current_stage = DORMANT`, kein Workflow

---

## Implementierung

### 1. Erweiterte `OrgService::createOrg()` Logik

```php
public function createOrg(array $data, ?string $userId = null): array
{
    // ... bestehender Code ...
    
    // Status (bestehend)
    $status = $data['status'] ?? 'lead';
    
    // Initial Stage basierend auf Status
    $initialStage = $this->mapStatusToInitialStage($status);
    
    $stmt = $this->db->prepare("
        INSERT INTO org (
            org_uuid, name, org_kind, external_ref, 
            industry, industry_main_uuid, industry_sub_uuid,
            revenue_range, employee_count, website, notes, 
            status, current_stage,  -- NEU: current_stage
            account_owner_user_id, account_owner_since
        )
        VALUES (
            :org_uuid, :name, :org_kind, :external_ref,
            :industry, :industry_main_uuid, :industry_sub_uuid,
            :revenue_range, :employee_count, :website, :notes,
            :status, :current_stage,  -- NEU
            :account_owner_user_id, :account_owner_since
        )
    ");
    
    $stmt->execute([
        // ... bestehende Felder ...
        'status' => $status,
        'current_stage' => $initialStage,  // NEU
        // ...
    ]);
    
    $org = $this->getOrg($uuid);
    
    // Stage History schreiben
    $this->writeStageHistory($uuid, null, $initialStage, 'ORG_CREATED', null, $userId);
    
    // Workflow-Trigger (nur wenn Stage = UNVERIFIED)
    if ($initialStage === 'UNVERIFIED') {
        $this->triggerQualifyWorkflow($uuid, $userId);
    }
    
    // Event-Publishing
    if ($org) {
        $this->publishEntityEvent('org', $org['org_uuid'], 'OrgCreated', $org);
    }
    
    return $org;
}

/**
 * Mappt org.status auf initialen current_stage
 */
private function mapStatusToInitialStage(string $status): string
{
    return match($status) {
        'lead' => 'UNVERIFIED',
        'prospect' => 'QUALIFIED_LEAD',
        'customer' => 'CUSTOMER',
        'inactive' => 'DORMANT',
        default => 'UNVERIFIED'
    };
}

/**
 * Startet QUALIFY_COMPANY Workflow (nur für UNVERIFIED)
 */
private function triggerQualifyWorkflow(string $orgUuid, ?string $userId): void
{
    $workflowService = new WorkflowTemplateService($this->db);
    $workflowService->startWorkflow('QUALIFY_COMPANY', $orgUuid, $userId);
}
```

### 2. Stage History bei Initialisierung

```php
private function writeStageHistory(
    string $orgUuid,
    ?string $fromStage,
    string $toStage,
    string $reasonCode,
    ?string $reasonNote,
    ?string $userId
): void {
    $historyUuid = UuidHelper::generate($this->db);
    
    $stmt = $this->db->prepare("
        INSERT INTO org_stage_history (
            history_uuid, org_uuid, from_stage, to_stage,
            reason_code, reason_note, changed_by_user_id
        )
        VALUES (
            :history_uuid, :org_uuid, :from_stage, :to_stage,
            :reason_code, :reason_note, :changed_by_user_id
        )
    ");
    
    $stmt->execute([
        'history_uuid' => $historyUuid,
        'org_uuid' => $orgUuid,
        'from_stage' => $fromStage,
        'to_stage' => $toStage,
        'reason_code' => $reasonCode,
        'reason_note' => $reasonNote,
        'changed_by_user_id' => $userId
    ]);
}
```

### 3. Workflow Template Anpassung

Das Workflow Template sollte prüfen, ob der Workflow gestartet werden soll:

```yaml
workflows:
  - key: QUALIFY_COMPANY
    version: 1
    description: "Automatischer Qualifizierungs-Workflow für neue Organisationen"
    
    trigger:
      event: org.created
      condition: |
        # Nur starten, wenn current_stage = UNVERIFIED
        org.current_stage == 'UNVERIFIED'
```

**Oder serverseitig im Service:**

```php
class WorkflowTemplateService {
    public function startWorkflow(string $workflowKey, string $orgUuid, ?string $userId = null): void
    {
        // Prüfe, ob Workflow für diese Org gestartet werden soll
        $org = (new OrgService($this->db))->getOrg($orgUuid);
        
        if ($workflowKey === 'QUALIFY_COMPANY') {
            // Nur starten, wenn UNVERIFIED
            if ($org['current_stage'] !== 'UNVERIFIED') {
                // Log: Workflow übersprungen, da Stage bereits höher
                return;
            }
        }
        
        // ... Workflow starten ...
    }
}
```

---

## Szenarien

### Szenario 1: Neuer Lead (Standard)

**Eingabe:**
- `status = 'lead'` (oder nicht gesetzt)

**System-Verhalten:**
1. `current_stage = 'UNVERIFIED'`
2. Stage History: `null → UNVERIFIED` (reason: `ORG_CREATED`)
3. ✅ `QUALIFY_COMPANY` Workflow startet
4. Case erstellt, Tasks erstellt
5. Stage Transition: `UNVERIFIED → QUALIFYING` (reason: `CASE_OPENED`)

### Szenario 2: Bereits Kunde (Bypass)

**Eingabe:**
- `status = 'customer'`

**System-Verhalten:**
1. `current_stage = 'CUSTOMER'`
2. Stage History: `null → CUSTOMER` (reason: `ORG_CREATED`)
3. ❌ **Kein** `QUALIFY_COMPANY` Workflow
4. Org ist direkt im finalen Stage

### Szenario 3: Bereits qualifiziert (Bypass)

**Eingabe:**
- `status = 'prospect'`

**System-Verhalten:**
1. `current_stage = 'QUALIFIED_LEAD'`
2. Stage History: `null → QUALIFIED_LEAD` (reason: `ORG_CREATED`)
3. ❌ **Kein** `QUALIFY_COMPANY` Workflow
4. Org kann direkt an Outside Sales übergeben werden

### Szenario 4: Inaktiv (Bypass)

**Eingabe:**
- `status = 'inactive'`

**System-Verhalten:**
1. `current_stage = 'DORMANT'`
2. Stage History: `null → DORMANT` (reason: `ORG_CREATED`)
3. ❌ **Kein** `QUALIFY_COMPANY` Workflow
4. Org ist ruhend

---

## UI-Anpassungen

### Formular: Status-Auswahl

Im Org-Erstellungsformular sollte klar sein, was passiert:

```html
<select name="status" id="org-create-status">
    <option value="lead">Lead (wird qualifiziert)</option>
    <option value="prospect">Prospect (bereits qualifiziert)</option>
    <option value="customer">Kunde (bereits Kunde)</option>
    <option value="inactive">Inaktiv (ruhend)</option>
</select>

<!-- Hinweis -->
<p class="help-text">
    <strong>Hinweis:</strong> 
    "Lead" durchläuft automatisch den Qualifizierungs-Workflow. 
    "Prospect" und "Kunde" werden direkt im entsprechenden Stage angelegt.
</p>
```

### Validierung

**Empfehlung:** Wenn `status = 'customer'`, sollte auch `account_owner_user_id` gesetzt sein (Pflichtfeld).

```php
if ($status === 'customer' && empty($data['account_owner_user_id'])) {
    throw new ValidationException('Bei Status "customer" muss ein Account Owner gesetzt sein');
}
```

---

## Migration bestehender Daten

Für bereits vorhandene Orgs:

```sql
-- Migration: Setze current_stage basierend auf status
UPDATE org 
SET current_stage = CASE
    WHEN status = 'lead' THEN 'UNVERIFIED'
    WHEN status = 'prospect' THEN 'QUALIFIED_LEAD'
    WHEN status = 'customer' THEN 'CUSTOMER'
    WHEN status = 'inactive' THEN 'DORMANT'
    ELSE 'UNVERIFIED'
END
WHERE current_stage IS NULL;

-- Stage History für bestehende Orgs (optional)
INSERT INTO org_stage_history (history_uuid, org_uuid, from_stage, to_stage, reason_code, changed_at)
SELECT 
    REPLACE(UUID(), '-', ''),
    org_uuid,
    NULL,
    current_stage,
    'MIGRATION',
    created_at
FROM org
WHERE current_stage IS NOT NULL;
```

---

## Zusammenfassung

✅ **Lösung:** Initial Stage Mapping + Bypass-Mechanismus

1. **Mapping:** `org.status` → `org.current_stage` beim Anlegen
2. **Workflow-Trigger:** Nur für `UNVERIFIED` (Status = `lead`)
3. **Bypass:** `customer`, `prospect`, `inactive` → direkt passender Stage, kein Workflow
4. **Audit:** Stage History wird immer geschrieben

**Vorteile:**
- ✅ Flexible Eingabe (kann Status setzen)
- ✅ Automatische Workflow-Steuerung (nur bei Bedarf)
- ✅ Keine unnötigen Workflows für bereits qualifizierte Orgs
- ✅ Vollständige Historie (Stage History)

**Nachteile:**
- ⚠️ Zwei Status-Felder (`status` + `current_stage`) - aber unterschiedliche Zwecke
- ⚠️ Mapping muss konsistent gehalten werden
