# TOM3 - Windows Task Scheduler Jobs

Diese Dokumentation listet alle notwendigen Windows Task Scheduler Jobs auf, die nach einem Neuaufsetzen des Systems eingerichtet werden müssen.

## Wichtiger Hinweis: Benutzer-Konfiguration

**Alle TOM3-Tasks laufen als aktueller Benutzer** (nicht als SYSTEM), damit sie:
- ✅ **Sichtbar** sind in normalen PowerShell-Sessions (`Get-ScheduledTask`)
- ✅ **Einfach überwachbar** sind (Status, Logs, Fehler)
- ✅ **Im Monitoring angezeigt werden** (keine "Zugriff verweigert"-Fehler)
- ✅ **Trotzdem im Hintergrund laufen** (auch ohne eingeloggten Benutzer, dank `LogonType ServiceAccount`)

**Vorteile gegenüber SYSTEM:**
- Tasks sind in `Get-ScheduledTask` sichtbar (auch ohne Admin-Rechte)
- Einfache Überwachung und Fehlerbehebung
- Monitoring kann Tasks abfragen (keine Berechtigungsprobleme)
- Keine versteckten Tasks, die schwer zu finden sind

**Nachteile gegenüber SYSTEM:**
- ⚠️ Geringfügig weniger Berechtigungen (für unsere Anwendung nicht relevant)
- ⚠️ Benutzer muss existieren (bei lokalen Benutzern unkritisch)

**Wichtig:** Mit `LogonType ServiceAccount` laufen die Tasks auch ohne eingeloggten Benutzer im Hintergrund.

**Hinweis:** Die Setup-Scripts konfigurieren automatisch den aktuellen Benutzer mit `LogonType ServiceAccount`. Falls Tasks noch als SYSTEM laufen, siehe "Tasks auf aktuellen Benutzer umstellen" weiter unten.

## Übersicht

TOM3 benötigt folgende automatische Tasks:

| Task-Name | Funktion | Intervall | Status |
|-----------|----------|-----------|--------|
| `TOM3-Neo4j-Sync-Worker` | Synchronisiert Events aus MySQL nach Neo4j | Alle 5 Minuten | **Pflicht** |
| `TOM3-ClamAV-Scan-Worker` | Verarbeitet Scan-Jobs für Dokumente (ClamAV) | Alle 5 Minuten | **Pflicht** (wenn ClamAV aktiv) |
| `TOM3-ExtractTextWorker` | Extrahiert Text aus Dokumenten (PDF, DOCX, XLSX, etc.) | Alle 5 Minuten | **Pflicht** |
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

## 3. Extract Text Worker (Pflicht)

**Task-Name:** `TOM3-ExtractTextWorker`

**Funktion:** Extrahiert Text aus hochgeladenen Dokumenten für die Volltext-Suche. Unterstützt:
- PDF (mit smalot/pdfparser)
- DOCX (Word-Dokumente)
- DOC (altes Word-Format, benötigt LibreOffice/Antiword)
- XLSX/XLS (Excel-Tabellen)
- TXT, CSV, HTML (Text-Dateien)
- Bilder mit OCR (PNG, JPEG, TIFF - benötigt Tesseract)

**Warum asynchron?**
Die Text-Extraktion erfolgt **nicht** direkt beim Upload, sondern asynchron über einen Worker. Gründe:
1. **Performance:** Upload bleibt schnell (keine Wartezeit für Benutzer)
2. **Timeouts vermeiden:** Große Dokumente oder OCR können mehrere Sekunden/Minuten dauern
3. **Skalierbarkeit:** Mehrere Worker können parallel arbeiten
4. **Fehlerbehandlung:** Fehlerhafte Extraktionen blockieren nicht den Upload
5. **Ressourcen:** CPU-intensive Operationen (OCR, PDF-Parsing) laufen im Hintergrund

**Timing:**
- **Upload:** Sofort (Dokument wird gespeichert, Status: `extraction_status = 'pending'`)
- **Extraktion:** Innerhalb von 5 Minuten (Worker läuft alle 5 Minuten)
- **Suche:** Funktioniert sofort nach Extraktion (Text wird in `documents.extracted_text` gespeichert)

**Einrichtung:**

```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-extract-text-worker-task.ps1
```

**Konfiguration:**
- **Intervall:** Alle 5 Minuten
- **Script:** `scripts\jobs\extract-text-worker.php`
- **Log-Datei:** `logs\extract-text-worker.log`
- **Max. Jobs pro Run:** 10 (konfigurierbar)

**Status prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ExtractTextWorker" | Get-ScheduledTaskInfo
```

**Manuell testen:**
```batch
# Mit Output (für Debugging)
php scripts\jobs\extract-text-worker.php -v

# Stumm (wie im Task Scheduler)
php scripts\jobs\extract-text-worker.php
```

**Abhängigkeiten:**
- **PHP:** Muss im PATH sein oder in `setup-extract-text-worker-task.ps1` angepasst werden
- **PHP Extensions (PFLICHT):**
  - `ext-zip` - Für DOCX und XLSX-Extraktion (muss in `php.ini` aktiviert sein)
  - `ext-fileinfo` - Für MIME-Type-Erkennung
- **PHP-Bibliotheken (via Composer):**
  - `smalot/pdfparser` - PDF-Extraktion
  - `phpoffice/phpspreadsheet` - Excel-Extraktion
- **Externe Tools (optional):**
  - **DOC-Extraktion:** LibreOffice oder Antiword (für bessere Qualität)
  - **OCR:** Tesseract OCR (für Bild-Text-Extraktion)

**Weitere Informationen:** Siehe `docs/DOCUMENT-UPLOAD-STATUS.md`

**Hinweis:** Dieser Task ist **Pflicht**, damit die Volltext-Suche funktioniert. Ohne diesen Worker werden Dokumente zwar hochgeladen, aber der Text wird nicht extrahiert und die Suche findet nur Titel, nicht den Inhalt.

**Unsichtbare Ausführung:** Der Task verwendet einen VBScript-Wrapper (`scripts/extract-text-worker.vbs`), der das PHP-Script unsichtbar startet. Dadurch wird keine Konsole angezeigt.

## 4. Activity-Log Wartung (Empfohlen)

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

## 5. ClamAV Scan Worker (Pflicht - wenn ClamAV aktiv)

**Task-Name:** `TOM3-ClamAV-Scan-Worker`

**Funktion:** Verarbeitet Malware-Scan-Jobs für hochgeladene Dokumente mit ClamAV.

**Einrichtung:**

```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-clamav-scan-worker.ps1
```

**Konfiguration:**
- **Intervall:** Alle 5 Minuten
- **Script:** `scripts\jobs\scan-blob-worker.php`
- **Log-Datei:** `logs\scan-blob-worker.log`

**Status prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ClamAV-Scan-Worker" | Get-ScheduledTaskInfo
```

**Weitere Informationen:** Siehe `docs/CLAMAV-IMPLEMENTATION-COMPLETE.md`

**Hinweis:** Dieser Task ist nur erforderlich, wenn ClamAV aktiviert ist. Ohne ClamAV werden Dokumente als "clean" markiert, ohne tatsächlichen Scan.

**Unsichtbare Ausführung:** Der Task verwendet einen VBScript-Wrapper (`scripts/scan-blob-worker.vbs`), der das PHP-Script unsichtbar startet. Dadurch wird keine Konsole angezeigt.

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

**Extract Text Worker:**
```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-extract-text-worker-task.ps1
```

**ClamAV Scan Worker (wenn aktiv):**
```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-clamav-scan-worker.ps1
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

**Extract Text Worker:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ExtractTextWorker" | Get-ScheduledTaskInfo
```

**ClamAV Scan Worker:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ClamAV-Scan-Worker" | Get-ScheduledTaskInfo
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
- [ ] **ClamAV Scan Worker eingerichtet** (Pflicht - wenn ClamAV aktiv)
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\setup-clamav-scan-worker.ps1
  ```
- [ ] **ClamAV Scan Worker Status geprüft**
  ```powershell
  Get-ScheduledTask -TaskName "TOM3-ClamAV-Scan-Worker" | Get-ScheduledTaskInfo
  ```
- [ ] **Extract Text Worker eingerichtet** (Pflicht)
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts\setup-extract-text-worker-task.ps1
  ```
- [ ] **Extract Text Worker Status geprüft**
  ```powershell
  Get-ScheduledTask -TaskName "TOM3-ExtractTextWorker" | Get-ScheduledTaskInfo
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

## Tasks auf aktuellen Benutzer umstellen

**Problem:** Tasks laufen als SYSTEM und sind nicht im Monitoring sichtbar.

**Lösung:** Tasks als aktueller Benutzer neu erstellen.

### Automatisch (Empfohlen)

```powershell
# PowerShell als Administrator öffnen
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\recreate-all-tasks-as-user.ps1
```

Dieses Script:
1. Löscht alte Tasks (falls vorhanden)
2. Erstellt alle Tasks neu als aktueller Benutzer
3. Prüft, ob alle Tasks sichtbar sind

### Manuell

**Extract Text Worker:**
```powershell
# PowerShell als Administrator
cd C:\xampp\htdocs\TOM3

# Alten Task löschen
Unregister-ScheduledTask -TaskName "TOM3-ExtractTextWorker" -Confirm:$false

# Neu erstellen
powershell -ExecutionPolicy Bypass -File scripts\setup-extract-text-worker-task.ps1
```

**ClamAV Scan Worker:**
```powershell
# Alten Task löschen
Unregister-ScheduledTask -TaskName "TOM3-ClamAV-Scan-Worker" -Confirm:$false

# Neu erstellen
powershell -ExecutionPolicy Bypass -File scripts\setup-clamav-scan-worker.ps1
```

**Verifizierung:**
```powershell
# Alle TOM3-Tasks anzeigen (sollten jetzt sichtbar sein)
Get-ScheduledTask | Where-Object { $_.TaskName -like "TOM3-*" } | Format-Table TaskName, State, @{Name="User";Expression={$_.Principal.UserId}}
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

### Extract Text Worker verarbeitet keine Jobs

1. **Prüfe unverarbeitete Extraction-Jobs:**
   ```sql
   SELECT COUNT(*) FROM outbox_event 
   WHERE aggregate_type = 'document' 
     AND event_type = 'DocumentExtractionRequested' 
     AND processed_at IS NULL;
   ```

2. **Prüfe Document-Extraction-Status:**
   ```sql
   SELECT extraction_status, COUNT(*) 
   FROM documents 
   GROUP BY extraction_status;
   ```

3. **Führe Worker manuell aus:**
   ```batch
   # Mit Output (für Debugging)
   php scripts\jobs\extract-text-worker.php -v
   
   # Stumm (wie im Task Scheduler)
   php scripts\jobs\extract-text-worker.php
   ```

4. **Prüfe Log-Datei:**
   ```batch
   type logs\extract-text-worker.log
   ```

## Weitere Dokumentation

- `docs/NEO4J-AUTOMATION.md` - Detaillierte Neo4j Sync Automatisierung
- `docs/ACTIVITY-LOG-AUTOMATION.md` - Activity-Log Automatisierung und Wartung
- `docs/SETUP-MYSQL-AUTOMATION.md` - MySQL Automatisierung
- `docs/CLAMAV-IMPLEMENTATION-COMPLETE.md` - ClamAV Scan Worker Details
- `docs/DOCUMENT-UPLOAD-STATUS.md` - Dokumenten-Upload und Text-Extraktion
- `docs/DOCUMENT-SECURITY-ROADMAP.md` - Security-Roadmap für Production
- `docs/PORTIERUNG-ANLEITUNG.md` - Vollständige Portierungsanleitung

---

*Windows Task Scheduler Jobs für TOM3*
