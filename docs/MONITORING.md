# TOM3 - Monitoring Dashboard

## Überblick

Das Monitoring-Dashboard bietet eine umfassende Übersicht über den Systemstatus, Metriken und Performance-Daten von TOM3.

## Features

### System Status
- **MySQL Status**: Datenbank-Verbindungsstatus
- **Neo4j Status**: Graph-Datenbank-Verbindungsstatus
- **Sync-Worker Status**: Status des Neo4j-Sync-Workers

### Outbox Events
- **Pending Events**: Anzahl unverarbeiteter Events
- **Processed (24h)**: Anzahl verarbeiteter Events in den letzten 24 Stunden
- **Errors (24h)**: Anzahl fehlerhafter Events
- **Sync Lag**: Durchschnittliche Verzögerung bei der Event-Verarbeitung
- **Hourly Chart**: Zeitreihen-Diagramm der Event-Verarbeitung

### Case Statistics
- **Total Cases**: Gesamtanzahl der Vorgänge
- **Active**: Vorgänge in Bearbeitung
- **Waiting**: Wartende Vorgänge (intern/extern)
- **Blocked**: Blockierte Vorgänge
- **Status Distribution**: Donut-Chart der Status-Verteilung

### Neo4j Sync Statistics
- **Total Synced**: Gesamtanzahl synchronisierter Events
- **Sync Rate**: Events pro Minute
- **Orgs in Neo4j**: Anzahl Organisationen in Neo4j
- **Persons in Neo4j**: Anzahl Personen in Neo4j

### Recent Errors
- Liste der letzten Fehler (Events, die nicht verarbeitet wurden)
- Details zu jedem Fehler
- Zeitstempel

### Event Types Distribution
- Balkendiagramm der Event-Typen-Verteilung
- Letzte 24 Stunden

### ClamAV Security Status
- **ClamAV Status**: Verfügbarkeit des ClamAV-Scanners
- **Pending Scans**: Anzahl ausstehender Scan-Jobs
- **Infected Files**: Liste infizierter Dateien (falls vorhanden)
- **Scan Metrics**: 
  - Pending Blobs (Fix): Anzahl Blobs mit pending Status, die behoben werden müssen
  - Behoben (Gesamt): Gesamtanzahl behobener Blobs
  - Vorkommen (Gesamt): Gesamtanzahl der Vorkommen des Problems
  - Letztes Vorkommen: Zeitpunkt des letzten Vorkommens

### Scheduled Tasks
- **Tabellarische Übersicht** aller überwachten Windows Task Scheduler Jobs
- **Status-Anzeige**: OK, Warning, Error, Unknown
- **Pflicht-Badges**: Schwarze Badges mit weißer Schrift für erforderliche Tasks
- **Letzte/Nächste Ausführung**: Zeitstempel der letzten und nächsten geplanten Ausführung
- **Fehlercode**: Exit-Code der letzten Ausführung
- **Überwachte Tasks**:
  - `TOM3-Neo4j-Sync-Worker` (Pflicht)
  - `TOM3-ClamAV-Scan-Worker` (Pflicht, wenn ClamAV aktiv)
  - `TOM3-ExtractTextWorker` (Pflicht)
  - `TOM3-FixPendingScans` (Empfohlen)
  - `TOM3-DuplicateCheck` (Empfohlen)
  - `TOM3-ActivityLog-Maintenance` (Empfohlen)
  - `MySQL-Auto-Recovery` (Optional)
  - `MySQL-Daily-Backup` (Empfohlen)

## Technologie

- **Chart.js**: Für Diagramme und Visualisierungen
- **Vanilla JavaScript**: Keine Framework-Abhängigkeiten
- **Dark Theme**: Optimiert für Monitoring

## API-Endpunkte

### GET /api/monitoring/status
Gibt den Systemstatus zurück:
```json
{
  "database": {
    "status": "ok",
    "message": "Verbunden"
  },
  "neo4j": {
    "status": "unknown",
    "message": "Nicht geprüft"
  },
  "sync_worker": {
    "status": "ok",
    "message": "Läuft"
  }
}
```

### GET /api/monitoring/outbox
Gibt Outbox-Metriken zurück:
```json
{
  "pending": 5,
  "processed_24h": 1234,
  "errors_24h": 2,
  "avg_lag_seconds": 12.5,
  "hourly_data": [...]
}
```

### GET /api/monitoring/cases
Gibt Case-Statistiken zurück:
```json
{
  "total": 150,
  "active": 45,
  "waiting": 30,
  "blocked": 5,
  "status_distribution": {
    "neu": 20,
    "in_bearbeitung": 45,
    "wartend_intern": 15,
    "wartend_extern": 15,
    "blockiert": 5,
    "abgeschlossen": 50
  }
}
```

### GET /api/monitoring/sync
Gibt Sync-Statistiken zurück:
```json
{
  "total_synced": 5000,
  "events_per_minute": 2.5,
  "orgs_count": 120,
  "persons_count": 350
}
```

### GET /api/monitoring/errors
Gibt die letzten Fehler zurück:
```json
[
  {
    "type": "OrgCreated",
    "aggregate_type": "org",
    "message": "Event nicht verarbeitet: OrgCreated",
    "details": "{...}",
    "created_at": "2024-01-01 12:00:00"
  }
]
```

### GET /api/monitoring/event-types
Gibt die Event-Typen-Verteilung zurück:
```json
{
  "OrgCreated": 50,
  "PersonCreated": 30,
  "ProjectCreated": 20,
  "CaseCreated": 100
}
```

### GET /api/monitoring/clamav
Gibt ClamAV-Status und Metriken zurück:
```json
{
  "status": "ok",
  "message": "ClamAV verfügbar",
  "pending_scans": 0,
  "infected_files": []
}
```

### GET /api/monitoring/scheduled-tasks
Gibt den Status aller überwachten Scheduled Tasks zurück:
```json
{
  "tasks": [
    {
      "name": "TOM3-Neo4j-Sync-Worker",
      "status": "ok",
      "message": "Läuft",
      "required": true,
      "description": "Neo4j Sync Worker",
      "last_run_time": "2024-01-01 12:00:00",
      "next_run_time": "2024-01-01 12:05:00",
      "last_task_result": 0
    }
  ]
}
```

### GET /api/monitoring/scan-metrics
Gibt Metriken zu pending Blobs und Fixes zurück:
```json
{
  "pending_blobs_to_fix": 0,
  "total_fixed": 5,
  "total_occurrences": 10,
  "last_occurrence": "2024-01-01 10:30:00"
}
```

## Verwendung

### Zugriff
Öffne `http://localhost/TOM3/public/monitoring.html` im Browser.

### Auto-Refresh
Das Dashboard aktualisiert sich automatisch alle 30 Sekunden. Dies kann über den Toggle deaktiviert werden.

### Scheduled Tasks Ansicht
Die Scheduled Tasks werden in einer **tabellarischen Ansicht** dargestellt (statt Kacheln), um Platz zu sparen. Die Tabelle zeigt:
- Name des Tasks mit Status-Icon
- Status-Badge (OK, Warning, Error, Unknown)
- Beschreibung
- Letzte Ausführung
- Nächste Ausführung
- Fehlercode (Exit-Code)

**Pflicht-Badges:** Schwarze Badges mit weißer Schrift kennzeichnen erforderliche Tasks.

### Manuelle Aktualisierung
Klicke auf den "Aktualisieren"-Button für eine sofortige Aktualisierung.

## Status-Indikatoren

- **Grün (ok)**: System funktioniert normal
- **Gelb (warning)**: Warnung (z.B. hängende Events)
- **Rot (error)**: Fehler (z.B. Datenbank-Verbindungsfehler)
- **Grau (unknown)**: Status unbekannt

## Erweiterungen

### Geplante Features

- [ ] Neo4j-Status-Check implementieren
- [ ] Performance-Metriken (Response-Zeiten, Query-Performance)
- [ ] Alerting bei kritischen Zuständen
- [ ] Export-Funktionen (PDF, CSV)
- [ ] Historische Daten (länger als 24h)
- [ ] Benutzer-spezifische Dashboards
- [ ] Webhook-Integration für Alerts

---

*Monitoring-Dashboard für TOM3 - Systemüberwachung*


