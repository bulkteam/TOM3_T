# ClamAV Virendefinitionen - Update-Management

## Übersicht

ClamAV verwendet **FreshClam** (ClamAV Update Daemon) zur automatischen Aktualisierung der Virendefinitionen. Die Signaturen werden mehrmals täglich von den ClamAV-Servern aktualisiert.

## Update-Mechanismus

### 1. FreshClam (Standard-Tool)

**Funktion:**
- Lädt automatisch neue Virendefinitionen herunter
- Aktualisiert die lokale Datenbank (`*.cvd` Dateien)
- Läuft als Daemon/Service im Hintergrund

**Standard-Intervall:**
- Alle 3 Stunden (konfigurierbar)
- Prüft bei jedem Start auf Updates

### 2. Update-Frequenz

**ClamAV-Server:**
- Mehrere Updates pro Tag (typisch: 3-5x täglich)
- Updates sind inkrementell (nur Änderungen werden geladen)
- Größe: ~50-100 MB initial, dann ~1-5 MB pro Update

## Implementierungsoptionen

### Option A: Windows (ClamWin / Native ClamAV)

**Setup:**
1. **ClamWin installieren** (GUI-basiert)
   - Automatische Updates über GUI konfigurierbar
   - Oder: Windows Task Scheduler für `freshclam.exe`

2. **Native ClamAV** (wenn verfügbar)
   - `freshclam.exe` als Windows Service
   - Oder: Scheduled Task

**Windows Task Scheduler Setup:**
```powershell
# Task erstellen: ClamAV-FreshClam-Update
$action = New-ScheduledTaskAction -Execute "C:\Program Files\ClamAV\freshclam.exe"
$trigger = New-ScheduledTaskTrigger -Daily -At 2AM
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName "ClamAV-FreshClam-Update" -Action $action -Trigger $trigger -Settings $settings
```

**Konfiguration (`freshclam.conf`):**
```ini
# Update-Intervall (in Stunden)
Checks 24

# Datenbank-Spiegel (Deutschland)
DatabaseMirror db.de.clamav.net

# Log-Datei
LogFile C:\Program Files\ClamAV\logs\freshclam.log

# Update-Verzeichnis
DatabaseDirectory C:\Program Files\ClamAV\database
```

### Option B: Docker (Empfohlen)

**Vorteile:**
- Automatische Updates im Container
- Isolierung vom Host-System
- Einfache Wartung

**Docker Compose Setup:**
```yaml
services:
  clamav:
    image: clamav/clamav:latest
    container_name: clamav
    restart: unless-stopped
    volumes:
      - clamav_db:/var/lib/clamav
      - clamav_logs:/var/log/clamav
    environment:
      - CLAMAV_NO_FRESHCLAM=false  # FreshClam aktivieren
      - CLAMAV_NO_CLAMD=false       # ClamAV Daemon aktivieren
    ports:
      - "3310:3310"  # ClamAV Socket
    healthcheck:
      test: ["CMD", "clamdscan", "--version"]
      interval: 30s
      timeout: 10s
      retries: 3
```

**Automatische Updates:**
- Der offizielle ClamAV Docker-Image startet `freshclam` automatisch
- Läuft als separater Prozess im Container
- Aktualisiert alle 3 Stunden (Standard)

**Update-Status prüfen:**
```bash
docker exec clamav freshclam -v
docker logs clamav | grep -i "freshclam"
```

### Option C: Manuelles Update (Notfall)

**Windows:**
```cmd
cd "C:\Program Files\ClamAV"
freshclam.exe
```

**Docker:**
```bash
docker exec clamav freshclam
```

**Linux:**
```bash
sudo freshclam
```

## Monitoring & Status

### 1. Update-Status prüfen

**Letztes Update-Datum:**
```bash
# Windows
type "C:\Program Files\ClamAV\database\main.cvd" | findstr "Version"

# Docker
docker exec clamav ls -lh /var/lib/clamav/*.cvd
```

**FreshClam Logs:**
```bash
# Windows
type "C:\Program Files\ClamAV\logs\freshclam.log"

# Docker
docker logs clamav 2>&1 | grep -i freshclam
```

### 2. PHP-Integration (Status-Check)

**Service-Methode:**
```php
class ClamAvService {
    public function getUpdateStatus(): array {
        // Prüfe, wann letztes Update war
        $dbPath = $this->config['database_directory'] . '/main.cvd';
        if (!file_exists($dbPath)) {
            return ['status' => 'error', 'message' => 'Database not found'];
        }
        
        $lastModified = filemtime($dbPath);
        $ageHours = (time() - $lastModified) / 3600;
        
        return [
            'status' => $ageHours > 24 ? 'stale' : 'current',
            'last_update' => date('Y-m-d H:i:s', $lastModified),
            'age_hours' => round($ageHours, 1)
        ];
    }
}
```

### 3. Monitoring-Endpunkt (Optional)

**API-Endpunkt:**
```php
// GET /api/monitoring/clamav-status
{
    "update_status": {
        "status": "current",
        "last_update": "2026-01-01 14:30:00",
        "age_hours": 2.5
    },
    "clamd_running": true,
    "database_version": "27000"
}
```

## Best Practices

### 1. Update-Intervall

**Empfohlen:**
- **Minimum:** 1x täglich
- **Optimal:** Alle 3-6 Stunden
- **Maximum:** Alle 24 Stunden (nicht länger!)

**Begründung:**
- Neue Malware wird täglich veröffentlicht
- ClamAV-Updates enthalten Zero-Day-Schutz
- Ältere Definitionen bieten weniger Schutz

### 2. Fehlerbehandlung

**Wenn Update fehlschlägt:**
- Logs prüfen (`freshclam.log`)
- Netzwerk-Verbindung prüfen
- Manuelles Update versuchen
- Bei wiederholten Fehlern: Admin-Benachrichtigung

**PHP-Integration:**
```php
public function ensureFreshDefinitions(): bool {
    $status = $this->getUpdateStatus();
    
    if ($status['age_hours'] > 48) {
        // Warnung: Definitionen zu alt
        $this->logger->warning('ClamAV definitions are stale', $status);
        
        // Versuche manuelles Update
        try {
            $this->runFreshClam();
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update ClamAV definitions', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    return true;
}
```

### 3. Automatisierung

**Windows Task Scheduler:**
- Täglich um 02:00 Uhr: `freshclam.exe`
- Zusätzlich: Alle 6 Stunden (optional)

**Docker:**
- Automatisch im Container (keine zusätzliche Konfiguration nötig)
- Health-Check überwacht Status

**Monitoring:**
- Prüfe täglich, ob Updates erfolgreich waren
- Alert bei Definitionen älter als 48 Stunden

## Implementierung für TOM3

### Schritt 1: Docker-Setup (Empfohlen)

**docker-compose.yml:**
```yaml
services:
  mariadb:
    # ... bestehende Konfiguration ...
  
  clamav:
    image: clamav/clamav:latest
    container_name: tom3-clamav
    restart: unless-stopped
    volumes:
      - clamav_db:/var/lib/clamav
    environment:
      - CLAMAV_NO_FRESHCLAM=false
      - CLAMAV_NO_CLAMD=false
    ports:
      - "3310:3310"
    healthcheck:
      test: ["CMD", "clamdscan", "--version"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  clamav_db:
```

### Schritt 2: Monitoring-Script

**scripts/jobs/check-clamav-updates.php:**
```php
<?php
// Prüft, ob ClamAV-Definitionen aktuell sind
// Wird täglich vom Task Scheduler ausgeführt

require_once __DIR__ . '/../../vendor/autoload.php';

$db = \TOM\Infrastructure\Database\DatabaseConnection::getInstance();

// Prüfe Update-Status
$dbPath = '/var/lib/clamav/main.cvd'; // Docker-Pfad
// Oder: $dbPath = 'C:\Program Files\ClamAV\database\main.cvd'; // Windows

if (!file_exists($dbPath)) {
    echo "ERROR: ClamAV database not found\n";
    exit(1);
}

$lastModified = filemtime($dbPath);
$ageHours = (time() - $lastModified) / 3600;

if ($ageHours > 48) {
    echo "WARNING: ClamAV definitions are stale (age: {$ageHours}h)\n";
    // Optional: Admin-Benachrichtigung
    exit(1);
}

echo "OK: ClamAV definitions are current (age: " . round($ageHours, 1) . "h)\n";
exit(0);
```

### Schritt 3: Windows Task Scheduler

**scripts/setup-clamav-monitoring.ps1:**
```powershell
# Erstellt Task für ClamAV-Update-Monitoring
$action = New-ScheduledTaskAction -Execute "php" -Argument "C:\xampp\htdocs\TOM3_T\scripts\jobs\check-clamav-updates.php"
$trigger = New-ScheduledTaskTrigger -Daily -At 3AM
Register-ScheduledTask -TaskName "TOM3-ClamAV-Update-Check" -Action $action -Trigger $trigger
```

## Zusammenfassung

**Automatische Updates:**
- ✅ Docker: Automatisch (FreshClam läuft im Container)
- ✅ Windows: Task Scheduler für `freshclam.exe`
- ✅ Standard-Intervall: Alle 3 Stunden

**Monitoring:**
- Täglich prüfen, ob Updates erfolgreich waren
- Alert bei Definitionen älter als 48 Stunden
- Logs überwachen für Fehler

**Empfehlung:**
- **Docker-Lösung** nutzen (einfachste Wartung)
- Monitoring-Script einrichten
- Bei Problemen: Manuelles Update als Fallback


