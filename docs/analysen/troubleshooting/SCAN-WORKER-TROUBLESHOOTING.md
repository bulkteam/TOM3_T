# Scan-Worker Troubleshooting

## Problem: Task wurde erstellt, aber PowerShell findet ihn nicht

### Symptom

```powershell
Get-ScheduledTask -TaskName "TOM3-ClamAV-Scan-Worker"
# No MSFT_ScheduledTask objects found
```

Aber:
```powershell
schtasks /query /tn "TOM3-ClamAV-Scan-Worker"
# Task wird gefunden
```

### Ursache

PowerShell `Get-ScheduledTask` und `schtasks` verwenden unterschiedliche APIs. Manchmal gibt es Verzögerungen oder Berechtigungsprobleme.

### Lösung

**Option 1: Verwende schtasks (CMD-kompatibel)**
```cmd
schtasks /query /tn "TOM3-ClamAV-Scan-Worker" /fo LIST
schtasks /run /tn "TOM3-ClamAV-Scan-Worker"
```

**Option 2: Prüfe über Task Scheduler GUI**
1. Öffne `taskschd.msc`
2. Suche nach "TOM3-ClamAV-Scan-Worker"
3. Prüfe Status und letzte Ausführung

**Option 3: Prüfe alle TOM3-Tasks**
```powershell
schtasks /query /fo LIST | Select-String -Pattern "TOM3"
```

## Problem: Dokumente bleiben auf "pending"

### Prüfung

```bash
php scripts/check-scan-status.php
```

### Mögliche Ursachen

1. **Scan-Worker Task läuft nicht**
   - Prüfe: `schtasks /query /tn "TOM3-ClamAV-Scan-Worker"`
   - Lösung: Task manuell starten oder neu einrichten

2. **ClamAV nicht verfügbar**
   - Prüfe: `docker ps | findstr clamav`
   - Lösung: `docker compose up -d clamav`

3. **Events wurden verarbeitet, aber Status nicht aktualisiert**
   - Prüfe: `php scripts/check-scan-status.php`
   - Lösung: Automatischer Fix durch `TOM3-FixPendingScans` Task (läuft alle 15 Minuten)
   - Manueller Fix: `php scripts/jobs/fix-pending-scans.php --verbose`

4. **Dateien nicht gefunden**
   - Prüfe: Storage-Verzeichnis existiert
   - Lösung: Prüfe `storage/` Verzeichnis und Berechtigungen

### Automatischer Fix

Der `TOM3-FixPendingScans` Task läuft automatisch alle 15 Minuten und behebt pending Blobs, deren Jobs bereits verarbeitet wurden.

**Status prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-FixPendingScans" | Get-ScheduledTaskInfo
```

**Manuell ausführen:**
```powershell
Start-ScheduledTask -TaskName "TOM3-FixPendingScans"
```

### Manueller Scan

Falls Dokumente auf "pending" bleiben und der automatische Fix nicht greift:

```bash
php scripts/jobs/fix-pending-scans.php --verbose
```

Dies scannt alle pending Blobs mit verarbeiteten Jobs manuell und aktualisiert den Status.

**Hinweis:** Der automatische Fix-Task sollte normalerweise ausreichen. Manueller Fix nur bei Bedarf.

## Problem: Auto-Refresh funktioniert nicht

### Prüfung

1. Browser-Konsole öffnen (F12)
2. Prüfe auf Logs: `[DocumentList] Starte Auto-Refresh...`
3. Prüfe, ob Dokumente "pending" Status haben

### Mögliche Ursachen

1. **Keine pending-Dokumente**
   - Auto-Refresh startet nur, wenn `scan_status = 'pending'`
   - Lösung: Warte auf neuen Upload oder prüfe Status

2. **JavaScript-Fehler**
   - Prüfe Browser-Konsole auf Fehler
   - Lösung: Seite neu laden (F5)

3. **Container nicht gefunden**
   - Prüfe: `Container #org-documents-list nicht gefunden`
   - Lösung: Stelle sicher, dass Dokumente-Tab geöffnet ist

### Debug

**Console-Logs aktivieren:**
- Logs sind bereits aktiviert in `document-list.js`
- Öffne Browser-Konsole (F12) → Console-Tab
- Suche nach `[DocumentList]`

**Manuell testen:**
```javascript
// In Browser-Konsole:
window.app.modules.documentList.loadDocuments('org', '3dd1ddff', '#org-documents-list', '#org-documents-count-badge');
```

## Problem: Scan-Worker läuft, aber verarbeitet keine Jobs

### Prüfung

```bash
# Prüfe ausstehende Jobs
php -r "require 'vendor/autoload.php'; use TOM\Infrastructure\Database\DatabaseConnection; \$db = DatabaseConnection::getInstance(); \$stmt = \$db->query(\"SELECT COUNT(*) as count FROM outbox_event WHERE aggregate_type = 'blob' AND event_type = 'BlobScanRequested' AND processed_at IS NULL\"); echo 'Ausstehende Jobs: ' . \$stmt->fetch(PDO::FETCH_ASSOC)['count'];"

# Prüfe Worker-Logs
Get-Content logs\scan-blob-worker.log -Tail 50
```

### Mögliche Ursachen

1. **ClamAV nicht verfügbar**
   - Worker überspringt Jobs, wenn ClamAV nicht erreichbar ist
   - Lösung: Prüfe ClamAV-Container

2. **Jobs wurden bereits verarbeitet**
   - Prüfe `processed_at` in `outbox_event`
   - Lösung: Prüfe, ob Status aktualisiert wurde

3. **Max Jobs erreicht**
   - Worker verarbeitet max. 10 Jobs pro Durchlauf
   - Lösung: Warte auf nächsten Durchlauf (5 Minuten)

### Manueller Test

```bash
# Worker manuell ausführen (mit Output)
php scripts/jobs/scan-blob-worker.php --verbose

# Mit mehr Jobs
php scripts/jobs/scan-blob-worker.php --max-jobs=20 --verbose
```

## Checkliste

- [ ] Scan-Worker Task eingerichtet
- [ ] Fix-Pending-Scans Task eingerichtet (empfohlen)
- [ ] ClamAV Container läuft (`docker ps`)
- [ ] ClamAV verfügbar (`php scripts/check-scan-status.php`)
- [ ] Ausstehende Jobs vorhanden (`processed_at IS NULL`)
- [ ] Storage-Verzeichnis existiert und ist beschreibbar
- [ ] Worker-Logs werden geschrieben (`logs/scan-blob-worker.log`)
- [ ] Browser-Konsole zeigt keine JavaScript-Fehler
- [ ] Auto-Refresh-Logs sichtbar in Browser-Konsole
- [ ] Monitoring-Dashboard zeigt keine Warnungen für pending Blobs

## Nützliche Befehle

**Status prüfen:**
```bash
php scripts/check-scan-status.php
```

**Automatischer Fix (Task):**
```powershell
Start-ScheduledTask -TaskName "TOM3-FixPendingScans"
```

**Manueller Scan:**
```bash
php scripts/jobs/fix-pending-scans.php --verbose
```

**Worker manuell ausführen:**
```bash
php scripts/jobs/scan-blob-worker.php --verbose
```

**Task prüfen:**
```cmd
schtasks /query /tn "TOM3-ClamAV-Scan-Worker" /fo LIST
```

**Task manuell starten:**
```cmd
schtasks /run /tn "TOM3-ClamAV-Scan-Worker"
```

**Logs ansehen:**
```powershell
Get-Content logs\scan-blob-worker.log -Tail 50
```


