# ClamAV Integration - Implementierung abgeschlossen ✅

## Status: Vollständig implementiert

**Datum:** 2026-01-01  
**Status:** ✅ Alle Komponenten erstellt

## Implementierte Komponenten

### 1. ClamAvService ✅

**Datei:** `src/TOM/Infrastructure/Document/ClamAvService.php`

**Funktionen:**
- ✅ `scan(string $filePath)` - Scannt Datei auf Malware
- ✅ `isAvailable()` - Prüft, ob ClamAV verfügbar ist
- ✅ `getVersion()` - Gibt ClamAV-Version zurück
- ✅ Docker-Integration (über `docker exec`)
- ✅ Socket-Integration (für lokale Installation)
- ✅ Automatische Pfad-Konvertierung (Host → Container)

**Verwendung:**
```php
$clamAv = new ClamAvService();
if ($clamAv->isAvailable()) {
    $result = $clamAv->scan('/path/to/file.pdf');
    // $result = ['status' => 'clean'|'infected'|'error', ...]
}
```

### 2. DocumentService Integration ✅

**Datei:** `src/TOM/Service/DocumentService.php`

**Änderungen:**
- ✅ ClamAvService-Integration (lazy loading)
- ✅ `enqueueScan(string $blobUuid)` - Erstellt Scan-Job in `outbox_event`
- ✅ Automatisches Enqueuen beim Upload

**Flow:**
1. Dokument wird hochgeladen
2. Blob wird erstellt (Status: `pending`)
3. Scan-Job wird in `outbox_event` eingefügt
4. Worker verarbeitet Job asynchron

### 3. Scan Worker ✅

**Datei:** `scripts/jobs/scan-blob-worker.php`

**Funktionen:**
- ✅ Liest ausstehende Jobs aus `outbox_event`
- ✅ Scannt Blobs mit ClamAV
- ✅ Aktualisiert `scan_status` in `blobs` Tabelle
- ✅ Blockiert Documents bei infizierten Blobs
- ✅ Idempotenz (überspringt bereits gescannte Blobs)
- ✅ Logging

**Usage:**
```bash
php scripts/jobs/scan-blob-worker.php
php scripts/jobs/scan-blob-worker.php --verbose
php scripts/jobs/scan-blob-worker.php --max-jobs=20
```

### 4. Windows Task Scheduler Setup ✅

**Datei:** `scripts/setup-clamav-scan-worker.ps1`

**Funktionen:**
- ✅ Erstellt Windows Task Scheduler Job
- ✅ Läuft alle 5 Minuten automatisch
- ✅ Läuft als SYSTEM (höchste Rechte)

**Setup:**
```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\setup-clamav-scan-worker.ps1
```

**WICHTIG:** Dieser Task ist **Pflicht** für ClamAV! Ohne diesen Task bleiben Dokumente auf "Wird geprüft..." stehen.

**Dokumentation:** Siehe auch `docs/WINDOWS-SCHEDULER-JOBS.md` (Abschnitt 4)

## Docker-Konfiguration

### docker-compose.yml

**WICHTIG:** Storage-Verzeichnis muss gemountet werden!

```yaml
services:
  clamav:
    image: clamav/clamav:latest
    container_name: tom3-clamav
    volumes:
      - clamav_db:/var/lib/clamav
      - clamav_logs:/var/log/clamav
      # WICHTIG: Storage-Verzeichnis mounten
      - C:/xampp/htdocs/TOM3/storage:/scans:ro
    ports:
      - "3310:3310"
    environment:
      - CLAMAV_NO_FRESHCLAM=false  # Automatische Updates
      - CLAMAV_NO_CLAMD=false
```

**Hinweis:** Passe den Pfad `C:/xampp/htdocs/TOM3/storage` an deinen tatsächlichen Pfad an!

## Workflow

### Upload → Scan → Status-Update

1. **Upload:**
   - User lädt Dokument hoch
   - Blob wird erstellt (`scan_status = 'pending'`)
   - Scan-Job wird in `outbox_event` eingefügt
   - Dokument ist sofort sichtbar (Status: "Wird geprüft...")

2. **Scan (asynchron):**
   - Worker läuft alle 5 Minuten
   - Liest ausstehende Jobs
   - Scannt Blob mit ClamAV
   - Aktualisiert `scan_status` (`clean` oder `infected`)

3. **Status-Update:**
   - UI zeigt neuen Status an
   - Download nur bei `scan_status = 'clean'`

## Konfiguration

### Umgebungsvariablen (optional)

```bash
# ClamAV Container-Name
CLAMAV_CONTAINER=tom3-clamav

# ClamAV Socket
CLAMAV_SOCKET=127.0.0.1:3310

# Docker verwenden (true/false)
CLAMAV_USE_DOCKER=true
```

**Standard:** Docker wird verwendet, Container-Name: `tom3-clamav`

## Testing

### 1. ClamAV-Verfügbarkeit prüfen

```bash
docker exec tom3-clamav clamdscan --version
```

### 2. Worker manuell testen

```bash
cd C:\xampp\htdocs\TOM3
php scripts/jobs/scan-blob-worker.php --verbose
```

### 3. Test-Scan durchführen

```bash
# Test mit einer Datei
docker exec tom3-clamav clamdscan /scans/test.pdf
```

### 4. EICAR-Test-Virus (optional)

**Warnung:** EICAR ist ein Test-Virus, der von Antivirus-Software erkannt wird, aber harmlos ist.

```bash
# EICAR-Test-String erstellen
echo "X5O!P%@AP[4\PZX54(P^)7CC)7}\$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!\$H+H*" > test-eicar.txt

# Upload in TOM3 und prüfen, ob als "infected" erkannt wird
```

## Monitoring

### Logs

**Worker-Logs:**
```powershell
Get-Content logs\scan-blob-worker.log -Tail 50
```

**ClamAV-Logs:**
```bash
docker logs tom3-clamav --tail 50
```

### Status prüfen

**Ausstehende Jobs:**
```sql
SELECT COUNT(*) 
FROM outbox_event 
WHERE aggregate_type = 'blob' 
  AND event_type = 'BlobScanRequested' 
  AND processed_at IS NULL;
```

**Blobs mit Status:**
```sql
SELECT scan_status, COUNT(*) 
FROM blobs 
GROUP BY scan_status;
```

## Nächste Schritte

### Production-Vorbereitung

**Siehe:** `docs/DOCUMENT-SECURITY-ROADMAP.md` für vollständige Roadmap

**Kritische Punkte vor Production:**
- ⏳ Quarantäne-System (verhindert Zugriff auf infizierte Dateien)
- ⏳ Admin-Benachrichtigung bei Infected
- ⏳ Scan-Timeout & Retry-Logik

**Optional später:**
- ⏳ Sofort-Scan für kleine Dateien (< 5MB)
- ⏳ Erweiterte Filetype-Validierung
- ⏳ Serverseitige Preview
- ⏳ Sandbox für Processing

## Zusammenfassung

✅ **ClamAV Service** - Implementiert  
✅ **DocumentService Integration** - Implementiert  
✅ **Scan Worker** - Implementiert  
✅ **Task Scheduler Setup** - Script erstellt  
✅ **Docker-Konfiguration** - Dokumentiert  

**Status:** MVP abgeschlossen ✅  
**Production:** Siehe `docs/DOCUMENT-SECURITY-ROADMAP.md` 🎯


