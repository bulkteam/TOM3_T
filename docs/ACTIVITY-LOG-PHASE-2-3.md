# Activity-Log Phase 2 & 3 - Implementierung

## Phase 2: Verknüpfung verbessern ✅

### 1. Audit-Trail-Tabellen erweitert

**Migration 036:** `database/migrations/036_add_activity_log_id_to_audit_trails_mysql.sql`

- `activity_log_id` Spalte zu `org_audit_trail` hinzugefügt
- `activity_log_id` Spalte zu `person_audit_trail` hinzugefügt
- Indizes für schnelle Abfragen erstellt

### 2. AuditTrailService angepasst

**Datei:** `src/TOM/Infrastructure/Audit/AuditTrailService.php`

- `insertAuditEntry()` akzeptiert jetzt `activity_log_id` Parameter
- Automatische Verknüpfung bei Create/Update:
  - Activity-Log-Eintrag wird zuerst erstellt
  - Audit-Trail-Einträge werden mit `activity_log_id` erstellt
  - Activity-Log wird mit `audit_trail_id` aktualisiert
- Rückwärtskompatibilität: Prüft ob `activity_log_id` Spalte existiert

### 3. API-Endpoint erstellt

**Datei:** `public/api/activity-log.php`

**Endpoints:**
- `GET /api/activity-log` - Alle Activities mit Filtern
- `GET /api/activity-log/user/{user_id}` - Activities für einen User
- `GET /api/activity-log/entity/{entity_type}/{entity_uuid}` - Activities für eine Entität

**Filter:**
- `user_id` - User-ID
- `action_type` - Action-Typ (login, logout, export, etc.)
- `entity_type` - Entity-Typ (org, person, etc.)
- `date_from` - Startdatum
- `date_to` - Enddatum
- `limit` - Anzahl Einträge
- `offset` - Offset für Pagination

### 4. UI-Modul erstellt

**Datei:** `public/js/modules/activity-log.js`

**Methoden:**
- `showUserActivityLog(userId, userName)` - Zeigt Activity-Log für einen User
- `showEntityActivityLog(entityType, entityUuid, entityName)` - Zeigt Activity-Log für eine Entität
- `renderActivityLog()` - Rendert Activity-Log mit Gruppierung nach Datum
- `renderActivityEntry()` - Rendert einen Activity-Eintrag

**Features:**
- Gruppierung nach Datum (Heute, Gestern, etc.)
- Action-Badges (Login, Logout, Export, etc.)
- Details-Anzeige (geänderte Felder, Dateinamen, etc.)
- Link zu Audit-Trail (wenn vorhanden)

### 5. CSS-Styles hinzugefügt

**Datei:** `public/css/style.css`

- Styles für Activity-Log-Container
- Styles für Activity-Einträge
- Action-Badge-Styles (success, info, primary, warning, secondary)
- Link-Styles für Audit-Trail-Verknüpfung

### 6. API-Client erweitert

**Datei:** `public/js/api.js`

**Neue Methoden:**
- `getActivityLog(filters, limit, offset)` - Holt Activities mit Filtern
- `getUserActivities(userId, limit, offset)` - Holt Activities für einen User
- `getEntityActivities(entityType, entityUuid, limit)` - Holt Activities für eine Entität

## Phase 3: Performance-Optimierung ✅

### 1. Archivierungs-Service

**Datei:** `src/TOM/Infrastructure/Activity/ActivityLogArchiveService.php`

**Features:**
- `createArchiveTable()` - Erstellt Archiv-Tabelle
- `archiveOldEntries($months)` - Archiviert Einträge älter als X Monate
- `getArchivedEntries($filters, $limit, $offset)` - Holt archivierte Einträge
- `countArchivedEntries($filters)` - Zählt archivierte Einträge
- `deleteOldArchivedEntries($years)` - Löscht archivierte Einträge älter als X Jahre
- `getStatistics()` - Gibt Statistiken zurück

**Strategie:**
- Daten älter als 24 Monate (konfigurierbar) werden in `activity_log_archive` verschoben
- Nur aktuelle Daten bleiben in `activity_log`
- Archiv-Daten können bei Bedarf geladen werden
- Automatische Löschung nach 7 Jahren (konfigurierbar)

### 2. Archivierungs-Skript

**Datei:** `scripts/archive-activity-log.php`

**Verwendung:**
```bash
# Standard (24 Monate Retention)
php scripts/archive-activity-log.php

# Custom Retention
php scripts/archive-activity-log.php --months=12

# Dry-Run (nur anzeigen, nicht archivieren)
php scripts/archive-activity-log.php --dry-run
```

**Features:**
- Zeigt Statistiken vor/nach Archivierung
- Dry-Run Modus für Tests
- Konfigurierbare Retention

### 3. Indizes (bereits in Phase 1)

**Bereits implementiert:**
- `idx_activity_user_date` (user_id, created_at DESC)
- `idx_activity_entity` (entity_type, entity_uuid)
- `idx_activity_action` (action_type, created_at DESC)
- `idx_activity_created` (created_at DESC)
- `idx_activity_audit_trail` (audit_trail_table, audit_trail_id)

### 4. Partitionierung (Optional, für später)

**Vorbereitet in Migration 035:**
- Kommentierter SQL-Code für Partitionierung nach Monat
- Kann aktiviert werden, wenn Tabelle groß wird (> 1M Einträge)

**Aktivierung:**
```sql
ALTER TABLE activity_log 
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202401 VALUES LESS THAN (202402),
    PARTITION p202402 VALUES LESS THAN (202403),
    -- Weitere Partitionen werden automatisch erstellt
);
```

## Verwendung

### Activity-Log in UI anzeigen

```javascript
import { ActivityLogModule } from './modules/activity-log.js';

const activityLogModule = new ActivityLogModule(app);

// Für einen User
await activityLogModule.showUserActivityLog(userId, userName);

// Für eine Entität
await activityLogModule.showEntityActivityLog('org', orgUuid, orgName);
```

### Archivierung ausführen

```bash
# Manuell
php scripts/archive-activity-log.php

# Als Cron-Job (monatlich)
0 2 1 * * cd /path/to/TOM3 && php scripts/archive-activity-log.php
```

### Archivierte Daten abfragen

```php
use TOM\Infrastructure\Activity\ActivityLogArchiveService;

$archiveService = new ActivityLogArchiveService();

// Archivierte Einträge holen
$archived = $archiveService->getArchivedEntries([
    'user_id' => '1',
    'date_from' => '2023-01-01',
    'date_to' => '2023-12-31'
], 100, 0);

// Statistiken
$stats = $archiveService->getStatistics();
```

## Nächste Schritte

1. **UI-Integration:** Activity-Log in bestehende UI integrieren (z.B. User-Profil, Entity-Details)
2. **Export-Funktion:** Activity-Log exportieren (CSV, PDF)
3. **Filter-UI:** Erweiterte Filter in der UI
4. **Monitoring:** Tabellengröße überwachen
5. **Automatisierung:** Archivierung als Cron-Job einrichten

## Performance-Schätzungen

### Mit Archivierung:
- **Aktive Daten:** ~24 Monate = ~720.000 Einträge = ~360 MB
- **Archiv-Daten:** ~5 Jahre = ~1.800.000 Einträge = ~900 MB
- **Query-Performance:** < 50ms (auch bei großen Datenmengen)

### Ohne Archivierung:
- **Nach 5 Jahren:** ~1.800.000 Einträge = ~900 MB
- **Query-Performance:** < 100ms (mit Indizes)
