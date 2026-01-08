# TOM3 - Neo4j Sync Automation

## Überblick

Der Neo4j Sync-Worker verarbeitet Events aus der `outbox_event` Tabelle und synchronisiert sie nach Neo4j. Damit die Synchronisation automatisch läuft, ohne dass ein Benutzer den Worker manuell starten muss, kann er als Windows Task Scheduler Job eingerichtet werden.

## Automatisierung einrichten

### Option 1: PowerShell-Script (Empfohlen)

```powershell
cd C:\xampp\htdocs\TOM3_T
powershell -ExecutionPolicy Bypass -File scripts\setup-neo4j-sync-automation.ps1
```

Das Script:
- Erstellt einen Windows Task Scheduler Job
- Führt den Sync-Worker alle 5 Minuten aus (konfigurierbar)
- Läuft automatisch im Hintergrund (unsichtbar, keine aufblinkende Konsole)
- Startet bei Windows-Start
- Verwendet VBScript-Wrapper für unsichtbare Ausführung

**Parameter:**
- `-IntervalMinutes 5` - Intervall in Minuten (Standard: 5)
- `-ScriptPath` - Pfad zum Batch-Script (Standard: `scripts\sync-neo4j-worker.bat`)

**Hinweis:** Der Task verwendet automatisch einen VBScript-Wrapper (`sync-neo4j-worker.vbs`), der das Batch-Script unsichtbar startet. Dadurch wird keine Konsole angezeigt und Deprecated-Warnungen werden unterdrückt.

**Beispiel mit anderem Intervall:**
```powershell
powershell -ExecutionPolicy Bypass -File scripts\setup-neo4j-sync-automation.ps1 -IntervalMinutes 10
```

### Option 2: Manuell über Task Scheduler

1. Öffne **Task Scheduler** (`taskschd.msc`)
2. Klicke auf **"Aufgabe erstellen"**
3. **Allgemein:**
   - Name: `TOM3-Neo4j-Sync-Worker`
   - Beschreibung: `TOM3 Neo4j Sync Worker - Verarbeitet Events aus der Outbox`
   - Ausführen: `Unabhängig davon, ob Benutzer angemeldet ist oder nicht`
4. **Trigger:**
   - Neu → Wiederholen alle 5 Minuten
   - Dauer: Unbegrenzt
5. **Aktionen:**
- Neu → Programm starten
- Programm/Script: `wscript.exe`
- Argumente: `"C:\xampp\htdocs\TOM3_T\scripts\sync-neo4j-worker.vbs"`
- **Hinweis:** Verwende den VBScript-Wrapper für unsichtbare Ausführung (keine aufblinkende Konsole)
6. **Bedingungen:**
   - ✅ "Aufgabe starten, unabhängig davon, ob Computer im Netzbetrieb oder Batteriebetrieb ist"
7. **Einstellungen:**
   - ✅ "Aufgabe so schnell wie möglich nach einem verpassten Start ausführen"
   - ✅ "Aufgabe beenden, wenn sie länger als ausgeführt wird": 10 Minuten

## Status prüfen

### Status-Check Script

```bash
php scripts/neo4j-status-check.php
```

Zeigt:
- Neo4j-Verbindungsstatus
- Anzahl Nodes in Neo4j
- Anzahl unverarbeiteter Events
- MySQL-Datenstatistiken
- Empfehlungen für nächste Schritte

### Manuelle Prüfung

**Unverarbeitete Events:**
```sql
SELECT COUNT(*) 
FROM outbox_event 
WHERE processed_at IS NULL;
```

**Event-Details:**
```sql
SELECT 
    aggregate_type,
    event_type,
    COUNT(*) as count,
    MIN(created_at) as oldest,
    MAX(created_at) as newest
FROM outbox_event
WHERE processed_at IS NULL
GROUP BY aggregate_type, event_type;
```

**Neo4j Node-Anzahl:**
```cypher
MATCH (o:Org) RETURN count(o) as org_count;
MATCH (p:Person) RETURN count(p) as person_count;
MATCH ()-[r]->() RETURN count(r) as relation_count;
```

## Task verwalten

### PowerShell

```powershell
# Task anzeigen
Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker"

# Task starten
Start-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker"

# Task stoppen
Stop-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker"

# Task entfernen
Unregister-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" -Confirm:$false
```

### Task Scheduler GUI

1. Öffne `taskschd.msc`
2. Navigiere zu **Task Scheduler Library**
3. Suche nach `TOM3-Neo4j-Sync-Worker`
4. Rechtsklick für Optionen:
   - **Ausführen** - Startet Task sofort
   - **Beenden** - Stoppt laufenden Task
   - **Eigenschaften** - Bearbeiten
   - **Löschen** - Entfernen

## Troubleshooting

### Task läuft nicht

1. **Prüfe Task-Status:**
   ```powershell
   Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Get-ScheduledTaskInfo
   ```

2. **Prüfe Task-Historie:**
   - Task Scheduler → `TOM3-Neo4j-Sync-Worker` → **Historie**
   - Prüfe Fehler in den letzten Ausführungen

3. **Prüfe Berechtigungen:**
   - Task muss als User mit PHP-Zugriff laufen
   - Prüfe ob `php.exe` im PATH ist oder vollständiger Pfad verwendet wird

4. **Manuell testen:**
   ```bash
   # Mit Output (für Debugging)
   php scripts/sync-neo4j-worker.php
   
   # Stumm (wie im Task Scheduler)
   php scripts/sync-neo4j-worker.php --quiet
   
   # Oder über VBScript-Wrapper (unsichtbar)
   wscript scripts\sync-neo4j-worker.vbs
   ```

### Task zeigt aufblinkende Konsole

**Problem:** Task zeigt alle 5 Minuten kurz eine Konsole an.

**Lösung:** Task aktualisieren, um VBScript-Wrapper zu verwenden:

```powershell
cd C:\xampp\htdocs\TOM3_T
powershell -ExecutionPolicy Bypass -File scripts\update-neo4j-sync-task.ps1
```

Dies aktualisiert den Task, sodass er den VBScript-Wrapper verwendet und keine Konsole mehr anzeigt.

### Events werden nicht verarbeitet

1. **Prüfe Neo4j-Verbindung:**
   ```bash
   php scripts/neo4j-status-check.php
   ```

2. **Prüfe ob Task läuft:**
   ```powershell
   Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Get-ScheduledTaskInfo
   ```

3. **Prüfe Logs:**
   - Task Scheduler → Historie
   - PHP Error Logs
   - Windows Event Viewer

### Neo4j-Verbindungsfehler

1. **Prüfe Konfiguration:**
   - `config/database.php` → Neo4j-Credentials
   - Prüfe ob Neo4j läuft (Remote oder lokal)

2. **Teste Verbindung:**
   ```bash
   # Mit Output (für Debugging)
   php scripts/sync-neo4j-worker.php
   
   # Stumm (wie im Task Scheduler)
   php scripts/sync-neo4j-worker.php --quiet
   ```

3. **Prüfe Firewall/Netzwerk:**
   - Remote Neo4j: Port 7687 (Bolt) oder 7474 (HTTP)
   - Lokal: Prüfe ob Neo4j-Service läuft

## Alternative: Daemon-Modus

Für Entwicklung oder wenn kein Task Scheduler verfügbar ist:

```bash
php scripts/sync-neo4j-worker.php --daemon
```

**Hinweis:** Läuft nur solange die Konsole offen ist. Für Produktion sollte der Task Scheduler verwendet werden.

## Script-Modi

Der Sync-Worker unterstützt verschiedene Modi:

| Modus | Command | Beschreibung |
|-------|---------|--------------|
| **Normal** | `php scripts/sync-neo4j-worker.php` | Einmalige Verarbeitung mit Output |
| **Quiet** | `php scripts/sync-neo4j-worker.php --quiet` | Einmalige Verarbeitung ohne Output (für Task Scheduler) |
| **Daemon** | `php scripts/sync-neo4j-worker.php --daemon` | Kontinuierliche Verarbeitung (läuft bis Ctrl+C) |

**Hinweis:** Der Task Scheduler verwendet automatisch den `--quiet` Modus über den VBScript-Wrapper, sodass keine Konsole angezeigt wird und Deprecated-Warnungen unterdrückt werden.

## Empfohlene Konfiguration

- **Intervall:** 5 Minuten (ausreichend für die meisten Fälle)
- **Timeout:** 10 Minuten (verhindert hängende Tasks)
- **Retry:** 3 Versuche bei Fehlern
- **Start:** Automatisch bei Windows-Start

## Monitoring

### Regelmäßige Checks

1. **Täglich:** Status-Check ausführen
   ```bash
   php scripts/neo4j-status-check.php
   ```

2. **Wöchentlich:** Prüfe ob Events verarbeitet werden
   ```sql
   SELECT 
       DATE(created_at) as date,
       COUNT(*) as total,
       COUNT(CASE WHEN processed_at IS NOT NULL THEN 1 END) as processed
   FROM outbox_event
   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
   GROUP BY DATE(created_at);
   ```

3. **Monatlich:** Prüfe Neo4j-Datenkonsistenz
   - Vergleich MySQL vs. Neo4j Node-Anzahl
   - Prüfe auf fehlende Relationen

---

*Neo4j Sync Automation für TOM3*


