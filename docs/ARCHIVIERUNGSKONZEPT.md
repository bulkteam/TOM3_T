# TOM3 - Archivierungskonzept

## Überblick

Das Archivierungssystem ermöglicht es, Datensätze (Organisationen, Personen, Vorgänge, etc.) zu archivieren, sodass sie:
- **Nicht mehr in aktiven Listen/Reports erscheinen**
- **Aber weiterhin in der Suche auffindbar sind** (mit visueller Markierung)
- **Wieder reaktivierbar sind**

## Datenmodell

### Ansatz: `archived_at` Timestamp

**Vorteile:**
- Einfach zu implementieren
- Ermöglicht "Wann wurde archiviert?" zu sehen
- NULL = aktiv, DATETIME = archiviert
- Kann später erweitert werden (z.B. `archived_by_user_id`, `archive_reason`)

**Implementierung:**
```sql
ALTER TABLE org ADD COLUMN archived_at DATETIME NULL COMMENT 'Archivierungsdatum (NULL = aktiv)';
ALTER TABLE org ADD COLUMN archived_by_user_id VARCHAR(100) NULL COMMENT 'User, der archiviert hat';
ALTER TABLE org ADD INDEX idx_org_archived (archived_at);
```

### Alternative: Status erweitern

**Vorteile:**
- Nutzt bestehende Status-Logik
- Einfacher Filter

**Nachteile:**
- Status-Feld wird überladen (business status vs. archiviert)
- Weniger flexibel für zukünftige Erweiterungen

**Empfehlung:** `archived_at` Timestamp

## Funktionalität

### 1. Archivierung

**UI:**
- Button "Archivieren" im Organisations-Modal
- Bestätigungsdialog: "Organisation wirklich archivieren?"
- Optional: Grund für Archivierung erfassen

**Backend:**
- Setzt `archived_at = NOW()`
- Setzt `archived_by_user_id = current_user_id`
- Protokolliert im Audit-Trail

### 2. Suche

**Standard-Suche (ohne Filter):**
- Zeigt nur **aktive** Organisationen (`archived_at IS NULL`)

**Erweiterte Suche / "Auch Archivierte anzeigen":**
- Checkbox "Auch archivierte anzeigen"
- Zeigt alle Organisationen
- **Visuelle Markierung:**
  - Grauer Hintergrund / abgeschwächte Farbe
  - Label "(Archiv)" hinter dem Namen
  - Icon (z.B. 📦) zur Kennzeichnung

### 3. Reaktivierung

**UI:**
- Button "Reaktivieren" bei archivierten Organisationen
- Bestätigungsdialog

**Backend:**
- Setzt `archived_at = NULL`
- Setzt `archived_by_user_id = NULL`
- Protokolliert im Audit-Trail

### 4. Filter in Listen/Reports

**Standard:**
- Alle Queries filtern automatisch: `WHERE archived_at IS NULL`
- Reports, Dashboards, Statistiken zeigen nur aktive Datensätze

**Ausnahme:**
- Explizite Archiv-Suche (mit Checkbox)
- Admin-Bereich für Archiv-Verwaltung (später)

## Erweiterung auf andere Entitäten

### Konsistente Implementierung

**Für alle Entitäten:**
- `archived_at DATETIME NULL`
- `archived_by_user_id VARCHAR(100) NULL`
- Index auf `archived_at`

**Betroffene Tabellen:**
- `org` (Organisationen)
- `person` (Personen)
- `case` (Vorgänge)
- `project` (Projekte)
- `task` (Aufgaben)
- Später: `email`, `order`, etc.

### Kaskadierte Archivierung

**Option 1: Automatisch**
- Bei Archivierung einer Organisation:
  - Alle zugehörigen Personen archivieren
  - Alle zugehörigen Vorgänge archivieren
  - Alle zugehörigen Projekte archivieren
  - etc.

**Option 2: Manuell**
- Jede Entität wird separat archiviert
- Flexibler, aber mehr Aufwand

**Empfehlung:** **Option 1 (Automatisch)** mit Bestätigungsdialog:
- "Diese Organisation und alle zugehörigen Datensätze archivieren?"
- Liste der betroffenen Entitäten anzeigen (z.B. "5 Personen, 12 Vorgänge, 3 Projekte")

## UI/UX

### Suchergebnisse

**Aktive Organisationen:**
- Normale Darstellung
- Klickbar, öffnet Modal

**Archivierte Organisationen:**
- Grauer Hintergrund / abgeschwächte Farben
- Label "(Archiv)" oder Icon 📦
- Klickbar, öffnet Modal (aber mit Warnung "Diese Organisation ist archiviert")
- Button "Reaktivieren" prominent im Modal

### Modal für archivierte Organisationen

**Visuelle Kennzeichnung:**
- Banner oben: "⚠️ Diese Organisation ist archiviert"
- Alle Daten sichtbar (read-only oder editierbar?)
- Button "Reaktivieren" prominent

**Frage:** Sollen archivierte Organisationen editierbar sein?
- **Ja:** Ermöglicht Korrekturen auch nach Archivierung
- **Nein:** Nur Anzeige, Reaktivierung erforderlich für Änderungen

**Empfehlung:** **Read-only** (nur Anzeige), Reaktivierung für Änderungen

## Migration

### Schritt 1: Organisationen

1. Migration: `archived_at` und `archived_by_user_id` hinzufügen
2. Backend: Filter in `searchOrgs()`, `listOrgs()` etc.
3. Frontend: UI für Archivierung/Reaktivierung
4. Frontend: Visuelle Markierung in Suchergebnissen

### Schritt 2: Andere Entitäten

1. Migration für jede Entität
2. Kaskadierte Archivierung implementieren
3. Filter in allen Queries

## SQL-Beispiele

### Standard-Query (nur aktive)
```sql
SELECT * FROM org WHERE archived_at IS NULL;
```

### Mit Archivierten
```sql
SELECT * FROM org; -- oder
SELECT * FROM org WHERE archived_at IS NULL OR archived_at IS NOT NULL;
```

### Archivierung
```sql
UPDATE org 
SET archived_at = NOW(), 
    archived_by_user_id = :user_id
WHERE org_uuid = :org_uuid;
```

### Reaktivierung
```sql
UPDATE org 
SET archived_at = NULL, 
    archived_by_user_id = NULL
WHERE org_uuid = :org_uuid;
```

## Offene Fragen

1. **Sollen archivierte Organisationen editierbar sein?**
   - Empfehlung: Nein (read-only)

2. **Soll Archivierungsgrund erfasst werden?**
   - Optional: `archive_reason TEXT`

3. **Soll Archivierung rückgängig gemacht werden können?**
   - Ja, über Reaktivierung

4. **Wie lange sollen archivierte Datensätze aufbewahrt werden?**
   - Später: Automatische Löschung nach X Jahren (mit Bestätigung)

5. **Sollen archivierte Datensätze in Statistiken/Reports erscheinen?**
   - Nein, nur aktive Datensätze


