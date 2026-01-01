# Activity-Log Implementierung

## Übersicht

Das Activity-Log-System wurde erfolgreich implementiert. Es bietet ein **hybrides System** mit:
- **Activity-Log** (zentral): High-Level-Aktionen (Login, Export, Upload, Entity-Änderungen)
- **Audit-Trail** (entity-spezifisch): Detaillierte Feld-Änderungen

## Implementierte Komponenten

### 1. Datenbank-Migration

**Datei:** `database/migrations/035_create_activity_log_mysql.sql`

**Struktur:**
- `activity_id` (BIGINT, PK)
- `user_id` (VARCHAR)
- `action_type` (login, logout, export, upload, download, entity_change, assignment, system_action)
- `entity_type` (org, person, project, system, etc.)
- `entity_uuid` (optional)
- `audit_trail_id` (Verknüpfung zu entity-spezifischem Audit-Trail)
- `audit_trail_table` (Tabellenname des Audit-Trails)
- `details` (JSON - zusätzliche Informationen)
- `ip_address`, `user_agent`
- `created_at`

**Indizes:**
- `idx_activity_user_date` (user_id, created_at DESC)
- `idx_activity_entity` (entity_type, entity_uuid)
- `idx_activity_action` (action_type, created_at DESC)
- `idx_activity_created` (created_at DESC)
- `idx_activity_audit_trail` (audit_trail_table, audit_trail_id)

### 2. ActivityLogService

**Datei:** `src/TOM/Infrastructure/Activity/ActivityLogService.php`

**Hauptmethoden:**
- `logActivity()` - Generische Logging-Methode
- `logEntityChange()` - Loggt Entity-Änderungen mit Verknüpfung zu Audit-Trail
- `logLogin()` - Loggt Login
- `logLogout()` - Loggt Logout
- `logExport()` - Loggt Exporte
- `logUpload()` - Loggt Datei-Uploads
- `logDownload()` - Loggt Datei-Downloads
- `logAssignment()` - Loggt Zuweisungen (z.B. Account Owner)
- `getUserActivities()` - Holt Activities für einen User
- `getEntityActivities()` - Holt Activities für eine Entität
- `getActivities()` - Holt Activities mit Filtern
- `countActivities()` - Zählt Activities (für Pagination)

### 3. Integration in AuditTrailService

**Datei:** `src/TOM/Infrastructure/Audit/AuditTrailService.php`

**Änderungen:**
- `ActivityLogService` als optionaler Dependency
- Automatische Erstellung von Activity-Log-Einträgen bei:
  - **Create:** Ein Activity-Log-Eintrag pro Entity-Erstellung
  - **Update:** Ein Activity-Log-Eintrag pro Update (nicht pro Feld)
  - Verknüpfung mit Audit-Trail über `audit_trail_id` und `audit_trail_table`

### 4. Integration in AuthService

**Datei:** `src/TOM/Infrastructure/Auth/AuthService.php`

**Änderungen:**
- `ActivityLogService` als optionaler Dependency
- `logLogin()` wird bei erfolgreichem Login aufgerufen
- `logLogout()` wird bei Logout aufgerufen

### 5. Integration in BaseEntityService

**Datei:** `src/TOM/Service/BaseEntityService.php`

**Änderungen:**
- `ActivityLogService` wird automatisch erstellt und an `AuditTrailService` übergeben
- Alle Entity-Services (OrgService, PersonService, etc.) profitieren automatisch von Activity-Log

## Verwendung

### Automatisches Logging

Die folgenden Aktionen werden automatisch geloggt:

1. **Login/Logout:** Automatisch über `AuthService`
2. **Entity-Änderungen:** Automatisch über `AuditTrailService` (bei Create/Update)
3. **Alle Services:** Automatisch über `BaseEntityService`

### Manuelles Logging

```php
use TOM\Infrastructure\Activity\ActivityLogService;

$activityLogService = new ActivityLogService();

// Export loggen
$activityLogService->logExport(
    $userId,
    'org',  // entity_type
    'csv',  // export_type
    ['filter' => 'active']  // filters
);

// Upload loggen
$activityLogService->logUpload(
    $userId,
    'document.pdf',  // file_name
    1024000,  // file_size in Bytes
    'org',  // entity_type
    $orgUuid  // entity_uuid
);

// Zuweisung loggen
$activityLogService->logAssignment(
    $userId,
    'org',  // entity_type
    $orgUuid,  // entity_uuid
    'account_owner',  // assignment_type
    $assignedToUserId  // assigned_to_user_id
);
```

### Abfragen

```php
use TOM\Infrastructure\Activity\ActivityLogService;

$activityLogService = new ActivityLogService();

// Activities für einen User
$activities = $activityLogService->getUserActivities($userId, 50, 0);

// Activities für eine Entität
$activities = $activityLogService->getEntityActivities('org', $orgUuid, 50);

// Activities mit Filtern
$activities = $activityLogService->getActivities([
    'user_id' => $userId,
    'action_type' => 'entity_change',
    'entity_type' => 'org',
    'date_from' => '2024-01-01',
    'date_to' => '2024-12-31'
], 100, 0);
```

## Verknüpfung Activity-Log ↔ Audit-Trail

### Beispiel-Daten

**Activity-Log:**
```json
{
  "activity_id": 12345,
  "user_id": "1",
  "action_type": "entity_change",
  "entity_type": "org",
  "entity_uuid": "abc-123",
  "audit_trail_id": 67890,
  "audit_trail_table": "org_audit_trail",
  "details": {
    "action": "update",
    "change_type": "field_change",
    "changed_fields": ["name", "status"],
    "changed_fields_count": 2
  },
  "created_at": "2024-01-15 10:30:00"
}
```

**Org-Audit-Trail:**
```json
{
  "audit_id": 67890,
  "org_uuid": "abc-123",
  "user_id": "1",
  "action": "update",
  "field_name": "name",
  "old_value": "Firma XY Alt",
  "new_value": "Firma XY Neu",
  "change_type": "field_change",
  "created_at": "2024-01-15 10:30:00"
}
```

### Abfrage mit Verknüpfung

```sql
SELECT 
    a.*,
    at.field_name,
    at.old_value,
    at.new_value
FROM activity_log a
LEFT JOIN org_audit_trail at ON (
    a.audit_trail_table = 'org_audit_trail' 
    AND a.audit_trail_id = at.audit_id
)
WHERE a.entity_uuid = 'abc-123'
ORDER BY a.created_at DESC;
```

## Performance-Optimierungen

### Indizes
Alle wichtigen Felder sind indiziert:
- User + Datum (häufigste Query)
- Entity-Typ + UUID (für Entity-Übersicht)
- Action-Typ + Datum (für Filter)
- Audit-Trail-Verknüpfung (für Details)

### Partitionierung (Optional)

Für sehr große Datenmengen kann die Tabelle nach Monat partitioniert werden:

```sql
ALTER TABLE activity_log 
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202401 VALUES LESS THAN (202402),
    PARTITION p202402 VALUES LESS THAN (202403),
    -- Weitere Partitionen werden automatisch erstellt
);
```

### Archivierung (Zukünftig)

- Daten älter als 2 Jahre in Archiv-Tabelle verschieben
- Nur aktuelle Daten in Haupttabelle
- Archiv-Daten bei Bedarf laden

## Nächste Schritte

1. **UI-Integration:** Activity-Log in der UI anzeigen
2. **Export-Funktion:** Activity-Log exportieren
3. **Filter-UI:** Filter für Activity-Log in der UI
4. **Archivierung:** Automatische Archivierung alter Daten
5. **Partitionierung:** Aktivieren, wenn Tabelle groß wird (> 1M Einträge)

## Migration ausführen

```bash
php scripts/run-migration-035.php
```

Die Migration wurde bereits ausgeführt und die Tabelle `activity_log` ist verfügbar.
