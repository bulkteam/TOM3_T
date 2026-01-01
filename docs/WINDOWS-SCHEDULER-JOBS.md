# TOM3 - Windows Task Scheduler Jobs

Diese Dokumentation listet alle notwendigen Windows Task Scheduler Jobs auf, die nach einem Neuaufsetzen des Systems eingerichtet werden müssen.

## Übersicht

TOM3 benötigt folgende automatische Tasks:

| Task-Name | Funktion | Intervall | Status |
|-----------|----------|-----------|--------|
| `TOM3-Neo4j-Sync-Worker` | Synchronisiert Events aus MySQL nach Neo4j | Alle 5 Minuten | **Pflicht** |
| `TOM3-DuplicateCheck` | Prüft auf potenzielle Duplikate in Organisationen und Personen | Täglich 02:00 Uhr | Empfohlen |
| `TOM3-ActivityLog-Maintenance` | Wartung für Activity-Log (Archivierung, Partitionierung, Löschung) | Monatlich am 1. Tag, 02:00 Uhr | Empfohlen |
| `MySQL-Auto-Recovery` | Prüft und startet MySQL automatisch | Beim Systemstart | Optional |
| `MySQL-Daily-Backup` | Erstellt tägliches Datenbank-Backup | Täglich 02:00 Uhr | Empfohlen |

## 1. Neo4j Sync Worker (Pflicht)

**Task-Name:** `TOM3-Neo4j-Sync-Worker`

**Funktion:** Verarbeitet Events aus der `outbox_event` Tabelle und synchronisiert sie nach Neo4j.

**Einrichtung:**

```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-neo4j-sync-automation.ps1
```

**Konfiguration:**
- **Intervall:** Alle 5 Minuten
- **Script:** `wscript.exe` mit `scripts\sync-neo4j-worker.vbs` (unsichtbare Ausführung)
- **Start:** Sofort nach Einrichtung
- **Dauer:** Unbegrenzt (läuft kontinuierlich)
- **Besonderheit:** Läuft unsichtbar im Hintergrund (keine aufblinkende Konsole)

**Status prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Get-ScheduledTaskInfo
```

**Weitere Informationen:** Siehe `docs/NEO4J-AUTOMATION.md`

**Hinweis:** Der Task verwendet automatisch einen VBScript-Wrapper (`sync-neo4j-worker.vbs`), der das Batch-Script unsichtbar startet. Dadurch wird keine Konsole angezeigt und Deprecated-Warnungen werden unterdrückt.

**Task aktualisieren (falls Konsole aufblinkt):**
```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\update-neo4j-sync-task.ps1
```

## 2. MySQL Auto-Recovery (Optional)

**Task-Name:** `MySQL-Auto-Recovery`

**Funktion:** Prüft beim Systemstart, ob MySQL läuft, und startet es bei Bedarf automatisch.

**Einrichtung:**

```batch
cd C:\xampp\htdocs\TOM3
scripts\setup-scheduled-tasks.bat
```

**Konfiguration:**
- **Trigger:** Beim Systemstart
- **Script:** `scripts\ensure-mysql-running.bat`
- **Verzögerung:** 30 Sekunden nach Systemstart

**Hinweis:** Dieser Task ist optional, aber empfohlen, wenn MySQL nicht als Windows-Service läuft.

## 3. Activity-Log Wartung (Empfohlen)

**Task-Name:** `TOM3-ActivityLog-Maintenance`

**Funktion:** Führt monatlich Wartungsaufgaben für das Activity-Log aus:
- Archivierung alter Einträge (älter als 24 Monate)
- Erstellung neuer Partitionen (für nächste 3 Monate)
- Löschung sehr alter Archiv-Einträge (älter als 7 Jahre)

**Einrichtung:**

```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-activity-log-maintenance-job.ps1
```

**Konfiguration:**
- **Trigger:** Monatlich am 1. Tag um 02:00 Uhr
- **Script:** `scripts\jobs\activity-log-maintenance.php`
- **Log-Datei:** `logs\activity-log-maintenance.log`

**Status prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ActivityLog-Maintenance" | Get-ScheduledTaskInfo
```

**Weitere Informationen:** Siehe `docs/ACTIVITY-LOG-AUTOMATION.md`

**Hinweis:** Dieser Task ist optional, aber **empfohlen** für Systeme mit aktivem Activity-Log. Erstellt automatisch neue Partitionen und archiviert alte Daten.

## 4. MySQL Daily Backup (Empfohlen)

**Task-Name:** `MySQL-Daily-Backup`

**Funktion:** Erstellt täglich ein vollständiges Datenbank-Backup.

**Einrichtung:**

```batch
cd C:\xampp\htdocs\TOM3
scripts\setup-scheduled-tasks.bat
```

**Konfiguration:**
- **Trigger:** Täglich um 02:00 Uhr
- **Script:** `scripts\mysql-backup.bat`
- **Backup-Pfad:** `C:\xampp\mysql\backup\`

**Hinweis:** Dieser Task ist optional, aber **stark empfohlen** für Produktionsumgebungen.

## Einrichtung aller Tasks

### Option 1: Automatisch (Empfohlen)

**Neo4j Sync Worker:**
```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-neo4j-sync-automation.ps1
```

**Activity-Log Wartung:**
```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-activity-log-maintenance-job.ps1
```

**MySQL Tasks:**
```batch
cd C:\xampp\htdocs\TOM3
scripts\setup-scheduled-tasks.bat
```

### Option 2: Manuell über Task Scheduler

1. Öffne **Task Scheduler** (`taskschd.msc`)
2. Klicke auf **"Aufgabe erstellen"**
3. Folge den Anweisungen in den jeweiligen Dokumentationen:
   - Neo4j: Siehe `docs/NEO4J-AUTOMATION.md`
   - MySQL: Siehe `docs/SETUP-MYSQL-AUTOMATION.md`

## Prüfung nach Einrichtung

### Alle Tasks anzeigen

```powershell
Get-ScheduledTask | Where-Object {$_.TaskName -like "TOM3-*"} | Format-Table TaskName, State, @{Name='LastRun';Expression={(Get-ScheduledTaskInfo $_.TaskName).LastRunTime}}, @{Name='NextRun';Expression={(Get-ScheduledTaskInfo $_.TaskName).NextRunTime}}
```

### Einzelne Tasks prüfen

**Neo4j Sync Worker:**
```powershell
Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Get-ScheduledTaskInfo
```

**MySQL Auto-Recovery:**
```powershell
Get-ScheduledTask -TaskName "MySQL-Auto-Recovery" | Get-ScheduledTaskInfo
```

**Activity-Log Wartung:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ActivityLog-Maintenance" | Get-ScheduledTaskInfo
```

**MySQL Daily Backup:**
```powershell
Get-ScheduledTask -TaskName "MySQL-Daily-Backup" | Get-ScheduledTaskInfo
```

## Task-Verwaltung

### Task starten

```powershell
Start-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker"
```

### Task stoppen

```powershell
Stop-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker"
```

### Task entfernen

```powershell
Unregister-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" -Confirm:$false
```

### Task-Historie anzeigen

1. Öffne `taskschd.msc`
2. Navigiere zu **Task Scheduler Library**
3. Wähle den Task aus
4. Klicke auf **"Historie"** (unten im Fenster)

## Checkliste nach Neuaufsetzen

Nach dem Portieren auf ein neues System:

- [ ] **Neo4j Sync Worker eingerichtet** (Pflicht)
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\setup-neo4j-sync-automation.ps1
  ```
- [ ] **Neo4j Sync Worker Status geprüft**
  ```powershell
  Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Get-ScheduledTaskInfo
  ```
- [ ] **MySQL Auto-Recovery eingerichtet** (Optional)
  ```batch
  scripts\setup-scheduled-tasks.bat
  ```
  ```powershell
  Get-ScheduledTask -TaskName "MySQL-Auto-Recovery" | Get-ScheduledTaskInfo
  ```
- [ ] **Duplikaten-Prüfung eingerichtet** (Empfohlen)
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\setup-duplicate-check-job.ps1
  ```
  ```powershell
  Get-ScheduledTask -TaskName "TOM3-DuplicateCheck" | Get-ScheduledTaskInfo
  ```
- [ ] **Activity-Log Wartung eingerichtet** (Empfohlen)
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\setup-activity-log-maintenance-job.ps1
  ```
  ```powershell
  Get-ScheduledTask -TaskName "TOM3-ActivityLog-Maintenance" | Get-ScheduledTaskInfo
  ```
- [ ] **MySQL Daily Backup eingerichtet** (Empfohlen)
  ```batch
  scripts\setup-scheduled-tasks.bat
  ```
  ```powershell
  Get-ScheduledTask -TaskName "MySQL-Daily-Backup" | Get-ScheduledTaskInfo
  ```
- [ ] **Alle Tasks laufen erfolgreich**
  ```powershell
  Get-ScheduledTask | Where-Object {$_.TaskName -like "TOM3-*"} | Format-Table TaskName, State
  ```

## Troubleshooting

### Task läuft nicht

1. **Prüfe Task-Status:**
   ```powershell
   Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Get-ScheduledTaskInfo
   ```

2. **Prüfe Task-Historie:**
   - Öffne `taskschd.msc`
   - Navigiere zum Task
   - Prüfe **"Historie"** auf Fehler

3. **Prüfe Berechtigungen:**
   - Task muss als User mit PHP-Zugriff laufen
   - Prüfe ob `php.exe` im PATH ist

4. **Manuell testen:**
   ```batch
   # Mit Output (für Debugging)
   php scripts\sync-neo4j-worker.php
   
   # Stumm (wie im Task Scheduler)
   php scripts\sync-neo4j-worker.php --quiet
   
   # Oder über VBScript-Wrapper (unsichtbar)
   wscript scripts\sync-neo4j-worker.vbs
   ```

### Task wird nicht ausgeführt

1. **Prüfe Trigger:**
   ```powershell
   Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Select-Object -ExpandProperty Triggers
   ```

2. **Prüfe ob Task aktiviert ist:**
   ```powershell
   Get-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker" | Select-Object State
   ```
   Sollte `Ready` oder `Running` sein.

3. **Starte Task manuell:**
   ```powershell
   Start-ScheduledTask -TaskName "TOM3-Neo4j-Sync-Worker"
   ```

### Neo4j Sync Worker verarbeitet keine Events

1. **Prüfe Neo4j-Verbindung:**
   ```batch
   php scripts\neo4j-status-check.php
   ```

2. **Prüfe unverarbeitete Events:**
   ```sql
   SELECT COUNT(*) FROM outbox_event WHERE processed_at IS NULL;
   ```

3. **Führe Worker manuell aus:**
   ```batch
   # Mit Output (für Debugging)
   php scripts\sync-neo4j-worker.php
   
   # Stumm (wie im Task Scheduler)
   php scripts\sync-neo4j-worker.php --quiet
   ```

## Weitere Dokumentation

- `docs/NEO4J-AUTOMATION.md` - Detaillierte Neo4j Sync Automatisierung
- `docs/ACTIVITY-LOG-AUTOMATION.md` - Activity-Log Automatisierung und Wartung
- `docs/SETUP-MYSQL-AUTOMATION.md` - MySQL Automatisierung
- `docs/PORTIERUNG-ANLEITUNG.md` - Vollständige Portierungsanleitung

---

*Windows Task Scheduler Jobs für TOM3*
