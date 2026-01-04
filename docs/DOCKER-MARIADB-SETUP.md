# TOM3 - MariaDB + phpMyAdmin mit Docker Compose

## Ziel

- MariaDB 10.4.32 läuft in Docker
- phpMyAdmin läuft ebenfalls in Docker
- MariaDB ist vom Host erreichbar (z.B. für deine PHP-App) über Port 3307
- phpMyAdmin ist im Browser erreichbar über Port 8081
- Benutzer + DB werden automatisch angelegt (reproduzierbar)
- **Persistente Logs** für Fehleranalyse und Performance-Monitoring

## A) Setup auf einem neuen System

### 1) Voraussetzungen

- Docker installiert (Docker Desktop unter Windows/macOS oder Docker Engine unter Linux)
- Optional: PowerShell / Terminal

### 2) Projektordner anlegen

**Beispiel (Windows):**

```powershell
mkdir C:\dev\mariadb-docker
cd C:\dev\mariadb-docker
```

### 3) .env Datei anlegen

Erstelle eine Datei `.env` im Projektordner:

```env
# MariaDB Initial-Setup (nur beim ersten Start eines frischen Volumes)
MARIADB_ROOT_PASSWORD=tom@2025!
MARIADB_DATABASE=tom
MARIADB_USER=tomcat
MARIADB_PASSWORD=tim@2025!
```

**Wichtig:**
- Die Variablennamen müssen exakt so heißen.
- Diese Werte werden nur beim ersten Initialisieren des Daten-Volumes angewendet.

### 4) Ordnerstruktur anlegen

Erstelle die benötigten Ordner:

```powershell
mkdir conf.d
mkdir logs
```

**Ordnerstruktur:**
```
mariadb-docker/
  docker-compose.yml
  .env
  conf.d/
    my.cnf
  logs/
```

### 5) my.cnf Konfiguration (Logging aktivieren)

Erstelle `conf.d/my.cnf`:

```ini
[mysqld]
log_error=/var/log/mysql/mariadb-error.log

slow_query_log=1
slow_query_log_file=/var/log/mysql/mariadb-slow.log
long_query_time=0.2

# Optional: hilft bei aria/index repair Situationen
aria_sort_buffer_size=2M
```

**Hinweise:**
- `long_query_time=0.2` ist sehr "sensibel" (gut fürs Profiling)
- Für weniger Noise: `1` oder `2` Sekunden

### 6) docker-compose.yml anlegen

Erstelle `docker-compose.yml`:

```yaml
services:
  mariadb:
    image: mariadb:10.4.32
    container_name: mariadb104
    restart: unless-stopped
    env_file: .env
    ports:
      - "3307:3306"          # Host-Port 3307 -> Container-Port 3306
    volumes:
      - mariadb_data:/var/lib/mysql
      - ./conf.d:/etc/mysql/conf.d:ro    # Config-Mount (read-only)
      - ./logs:/var/log/mysql             # Logs-Mount (persistent)
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
    logging:
      options:
        max-size: "10m"       # Begrenzt docker logs Größe
        max-file: "3"         # Max. 3 Log-Dateien

  phpmyadmin:
    image: phpmyadmin:latest
    container_name: phpmyadmin
    restart: unless-stopped
    depends_on:
      - mariadb
    ports:
      - "8081:80"            # phpMyAdmin im Browser
    environment:
      PMA_HOST: mariadb      # Service-Name aus Compose!
      PMA_PORT: 3306
      PMA_ARBITRARY: 0
      UPLOAD_LIMIT: 256M

volumes:
  mariadb_data:
```

**Merksatz:**
- `services -> mariadb -> volumes` = Mounts (Config, Logs)
- `volumes:` (ganz unten) = Definition des Named Volumes (Daten)

### 7) Starten

Im Projektordner:

```powershell
docker compose up -d
docker compose ps
```

**Nach Config-Änderungen:**

```powershell
docker compose restart mariadb
```

**Optional Logs prüfen:**

**Container-Stdout (EntryPoint/Startmeldungen):**
```powershell
docker logs -f mariadb104
docker logs -f phpmyadmin
```

**MariaDB Error-Log (persistent):**
```powershell
# Logs anzeigen
Get-Content .\logs\mariadb-error.log -Wait
```

**MariaDB Slow-Query-Log (persistent):**
```powershell
# Logs anzeigen
Get-Content .\logs\mariadb-slow.log -Wait
```

**Prüfen, ob Logs geschrieben werden:**
```powershell
dir .\logs
```

### 8) Zugriff testen

**phpMyAdmin im Browser:**

```
http://127.0.0.1:8081
```

**Login:**
- User: `tomcat` (oder `root`)
- Passwort: wie in `.env` definiert

**Direkter DB-Test vom Host:**

Wenn du z.B. noch `mysql.exe` hast (z.B. aus XAMPP):

```powershell
cd C:\xampp\mysql\bin
.\mysql.exe -h 127.0.0.1 -P 3307 -u tomcat -p tom
```

Oder mit PHP-Test:

```powershell
cd C:\xampp\htdocs\TOM3
php test-docker-db.php
```

## B) Aufbau der DB und Einrichten

### 1) Datenbank & Benutzer (automatisch)

Wenn das Volume beim ersten Start neu ist, werden automatisch erstellt:

- Datenbank: `tom`
- User: `tomcat` mit Passwort `MARIADB_PASSWORD`
- Root-Passwort: `MARIADB_ROOT_PASSWORD`

**Merke:** Wenn das Volume schon existiert, werden diese Werte nicht erneut angewendet.

### 2) Benutzerrechte prüfen (optional)

In MariaDB einloggen (im Container):

```powershell
docker exec -it mariadb104 mariadb -uroot -p
```

Dann:

```sql
SHOW DATABASES;
SELECT user, host FROM mysql.user;
SHOW GRANTS FOR 'tomcat'@'%';
```

### 3) Passwort zurücksetzen (falls App „Access denied" meldet)

Wenn `tomcat` existiert, aber Passwort falsch ist:

```powershell
docker exec -it mariadb104 mariadb -uroot -p
```

Dann:

```sql
ALTER USER 'tomcat'@'%' IDENTIFIED BY 'tim@2025!';
FLUSH PRIVILEGES;
```

### 4) TOM3 Datenbank-Schema importieren

**Automatisch (empfohlen):**

```powershell
cd C:\xampp\htdocs\TOM3
php scripts/setup-mysql-database.php
```

Dieses Script führt alle Migrationen automatisch aus.

**Oder per phpMyAdmin:**

1. In phpMyAdmin: DB auswählen (`tom`)
2. Tab **Importieren**
3. `.sql` auswählen und importieren

**Oder per CLI (wenn du ein Dump hast `dump.sql` im Projektordner):**

```powershell
docker exec -i mariadb104 mariadb -u tomcat -ptim@2025! tom < dump.sql
```

### 5) Test-User erstellen

Nach dem Schema-Import:

```powershell
cd C:\xampp\htdocs\TOM3
php scripts/check-and-create-test-users.php
```

Dies erstellt die Test-User:
- Admin (admin@tom.local) - Rolle: admin
- Manager (manager@tom.local) - Rolle: manager
- User (user@tom.local) - Rolle: user
- Readonly (readonly@tom.local) - Rolle: readonly

### 6) Typische App-Connection-Settings

Für deine PHP-App (vom Host aus):

**ENV-Variablen setzen:**

Erstelle eine `.env` Datei im Projektroot (oder setze ENV-Variablen):

```bash
APP_ENV=local
AUTH_MODE=dev

MYSQL_HOST=127.0.0.1
MYSQL_PORT=3307
MYSQL_DBNAME=tom
MYSQL_USER=tomcat
MYSQL_PASSWORD=tim@2025!  # Passwort aus Docker .env
```

**Hinweis:** `config/database.php` liest automatisch aus ENV-Variablen. Siehe [SECURITY-IMPROVEMENTS.md](SECURITY-IMPROVEMENTS.md) für Details.

## Log-Wartung

### Log-Wachstum begrenzen

**1) MariaDB-Logfiles (error/slow) werden i.d.R. nicht automatisch rotiert**

Einfacher DEV-Workaround: gelegentlich leeren

```powershell
Clear-Content .\logs\mariadb-error.log
Clear-Content .\logs\mariadb-slow.log
```

**2) Docker docker logs begrenzen (Stdout/Stderr)**

Die `logging`-Optionen in `docker-compose.yml` begrenzen `docker logs`, nicht die MariaDB-Dateilogs:

```yaml
logging:
  options:
    max-size: "10m"
    max-file: "3"
```

### Automatische Log-Rotation (Task Scheduler)

**PowerShell-Skript für Log-Rotation:**

Erstelle `rotate-logs.ps1` im Projektordner:

```powershell
# MariaDB Log-Rotation Script
# Rotiert Logs ab 50MB, behält max. 5 Archive

param(
    [string]$LogsPath = "C:\dev\mariadb-docker\logs",
    [int]$MaxSizeMB = 50,
    [int]$KeepArchives = 5
)

$ErrorActionPreference = "Continue"

Write-Host "=== MariaDB Log-Rotation ===" -ForegroundColor Cyan
Write-Host "Logs-Pfad: $LogsPath" -ForegroundColor Gray
Write-Host "Max. Größe: $MaxSizeMB MB" -ForegroundColor Gray
Write-Host "Archive behalten: $KeepArchives" -ForegroundColor Gray
Write-Host ""

if (-not (Test-Path $LogsPath)) {
    Write-Host "❌ Logs-Pfad nicht gefunden: $LogsPath" -ForegroundColor Red
    exit 1
}

$logFiles = @(
    "mariadb-error.log",
    "mariadb-slow.log"
)

foreach ($logFile in $logFiles) {
    $logPath = Join-Path $LogsPath $logFile
    
    if (-not (Test-Path $logPath)) {
        Write-Host "⏭️  $logFile nicht gefunden, überspringe..." -ForegroundColor Yellow
        continue
    }
    
    $fileInfo = Get-Item $logPath
    $sizeMB = [math]::Round($fileInfo.Length / 1MB, 2)
    
    Write-Host "📄 $logFile: $sizeMB MB" -ForegroundColor Gray
    
    if ($sizeMB -ge $MaxSizeMB) {
        Write-Host "  → Rotiere (≥ $MaxSizeMB MB)..." -ForegroundColor Yellow
        
        # Erstelle Archiv mit Zeitstempel
        $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
        $archiveName = "$logFile.$timestamp"
        $archivePath = Join-Path $LogsPath $archiveName
        
        try {
            # Kopiere aktuelle Log-Datei als Archiv
            Copy-Item $logPath $archivePath -Force
            Write-Host "  ✓ Archiv erstellt: $archiveName" -ForegroundColor Green
            
            # Leere aktuelle Log-Datei
            Clear-Content $logPath -Force
            Write-Host "  ✓ Log-Datei geleert" -ForegroundColor Green
            
            # Lösche alte Archive (behalte nur die neuesten)
            $archives = Get-ChildItem -Path $LogsPath -Filter "$logFile.*" | 
                        Sort-Object LastWriteTime -Descending | 
                        Select-Object -Skip $KeepArchives
            
            foreach ($oldArchive in $archives) {
                Remove-Item $oldArchive.FullName -Force
                Write-Host "  🗑️  Altes Archiv gelöscht: $($oldArchive.Name)" -ForegroundColor Gray
            }
            
        } catch {
            Write-Host "  ❌ Fehler beim Rotieren: $_" -ForegroundColor Red
        }
    } else {
        Write-Host "  ✓ Größe OK" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "✅ Log-Rotation abgeschlossen" -ForegroundColor Green
```

**Manuell ausführen:**

```powershell
cd C:\dev\mariadb-docker
.\rotate-logs.ps1
```

**Mit Parametern:**

```powershell
# Andere Größe (100MB) und mehr Archive (10)
.\rotate-logs.ps1 -MaxSizeMB 100 -KeepArchives 10

# Anderer Pfad
.\rotate-logs.ps1 -LogsPath "D:\logs\mariadb"
```

**Als Scheduled Task einrichten:**

1. **Task Scheduler öffnen:**
   - Windows-Taste → "Aufgabenplanung" suchen
   - Oder: `taskschd.msc` ausführen

2. **Neue Aufgabe erstellen:**
   - Rechtsklick auf "Aufgabenplanungsbibliothek" → "Aufgabe erstellen..."

3. **Allgemein:**
   - Name: `MariaDB Log-Rotation`
   - Beschreibung: `Rotiert MariaDB-Logs automatisch`
   - ✅ "Unabhängig von der Benutzeranmeldung ausführen"
   - ✅ "Mit höchsten Privilegien ausführen"

4. **Trigger:**
   - "Neu..." → "Täglich" oder "Wöchentlich"
   - Zeit: z.B. 02:00 Uhr (nachts)

5. **Aktion:**
   - "Neu..." → "Programm starten"
   - Programm/Skript: `powershell.exe`
   - Argumente hinzufügen:
     ```
     -ExecutionPolicy Bypass -File "C:\dev\mariadb-docker\rotate-logs.ps1"
     ```
   - Starten in: `C:\dev\mariadb-docker`

6. **Bedingungen:**
   - ✅ "Aufgabe nur starten, wenn Computer im Netzbetrieb ist" (optional deaktivieren)

7. **Einstellungen:**
   - ✅ "Aufgabe so schnell wie möglich nach einem verpassten Start ausführen"

8. **Speichern** und Testen:
   - Rechtsklick auf Aufgabe → "Ausführen"

**Prüfen ob Task funktioniert:**

```powershell
# Task-Status prüfen
Get-ScheduledTask -TaskName "MariaDB Log-Rotation"

# Task manuell ausführen
Start-ScheduledTask -TaskName "MariaDB Log-Rotation"

# Task-Historie ansehen
Get-WinEvent -LogName "Microsoft-Windows-TaskScheduler/Operational" | 
    Where-Object {$_.Message -like "*MariaDB*"} | 
    Select-Object -First 10
```

### Logs live ansehen

**Error-Log:**
```powershell
Get-Content .\logs\mariadb-error.log -Wait
```

**Slow-Query-Log:**
```powershell
Get-Content .\logs\mariadb-slow.log -Wait
```

**Container-Logs:**
```powershell
docker logs -f mariadb104
```

## Wartung / Neuaufsetzen

### Container stoppen/starten

```powershell
docker compose stop
docker compose start
```

### Komplett neu (inkl. Daten löschen!)

⚠️ **Löscht alle DB-Daten:**

```powershell
docker compose down -v
docker compose up -d
```

### Nur Container neu, Daten behalten

```powershell
docker compose down
docker compose up -d
```

### Nach Config-Änderungen

```powershell
docker compose restart mariadb
```

## Logs für Fehleranalyse

### MariaDB Error-Log prüfen

**Häufige Fehlerquellen:**
- InnoDB Recovery-Probleme
- Aria-Log-Fehler
- Permission-Probleme
- Connection-Probleme

**Error-Log ansehen:**

```powershell
# Im Projektordner
Get-Content .\logs\mariadb-error.log -Tail 50
```

**Live-Monitoring:**

```powershell
Get-Content .\logs\mariadb-error.log -Wait
```

### Slow-Query-Log analysieren

**Performance-Probleme identifizieren:**

```powershell
# Langsame Queries anzeigen
Get-Content .\logs\mariadb-slow.log -Tail 50
```

**Typische Probleme:**
- Fehlende Indizes
- Zu große Abfragen
- Lock-Konflikte

### Container-Logs (Stdout/Stderr)

**Startup-Probleme:**

```powershell
docker logs mariadb104
docker logs -f mariadb104  # Live
```

**Häufige Startup-Fehler:**
- Port bereits belegt
- Volume-Berechtigungen
- Config-Syntax-Fehler

### Logs für Support/Entwicklung

**Komplette Logs exportieren:**

```powershell
# Error-Log
Get-Content .\logs\mariadb-error.log > error-log-export.txt

# Slow-Query-Log
Get-Content .\logs\mariadb-slow.log > slow-log-export.txt

# Container-Logs
docker logs mariadb104 > container-log-export.txt
```

## Troubleshooting Kurzliste

### „Access denied for user 'tomcat'@'172.x.x.x'"

**Fast immer:** Passwort passt nicht

**Fix:**

```sql
ALTER USER 'tomcat'@'%' IDENTIFIED BY 'tim@2025!';
FLUSH PRIVILEGES;
```

### „Meine .env wird nicht übernommen"

`.env` wirkt nur beim ersten Start auf leerem Volume.

Wenn du Werte ändern willst:

- entweder `ALTER USER...` (siehe oben)
- oder `docker compose down -v` und neu starten

### XAMPP läuft parallel

**Kein Problem**, solange:

- Docker-MariaDB läuft auf **3307**
- App nutzt `127.0.0.1:3307` und nicht `localhost:3306`

### Verbindungsfehler

**Prüfe:**

1. Container läuft: `docker compose ps`
2. Port ist frei: `netstat -an | findstr 3307`
3. Firewall blockiert Port 3307 nicht
4. ENV-Variablen gesetzt: `MYSQL_HOST=127.0.0.1`, `MYSQL_PORT=3307`

### Datenbank ist leer nach Setup

**Führe Migrationen aus:**

```powershell
cd C:\xampp\htdocs\TOM3
php scripts/setup-mysql-database.php
```

### Permission denied beim Schreiben in ./logs

**Problem:** Container kann nicht in gemounteten Windows-Ordner schreiben.

**Fallback (robust):** Logs ins Daten-Volume schreiben (keine Host-Dateien, aber persistent im Volume)

**In `conf.d/my.cnf` ändern:**

```ini
[mysqld]
log_error=/var/lib/mysql/mariadb-error.log
slow_query_log_file=/var/lib/mysql/mariadb-slow.log
```

**In `docker-compose.yml` entfernen:**

```yaml
volumes:
  - mariadb_data:/var/lib/mysql
  - ./conf.d:/etc/mysql/conf.d:ro
  # - ./logs:/var/log/mysql  # ENTFERNEN
```

**Logs dann aus Volume kopieren:**

```powershell
docker cp mariadb104:/var/lib/mysql/mariadb-error.log .\logs\
docker cp mariadb104:/var/lib/mysql/mariadb-slow.log .\logs\
```

## Vorteile von Docker gegenüber XAMPP

✅ **Isoliert:** Läuft in eigenem Container, keine Konflikte mit anderen MySQL-Instanzen  
✅ **Reproduzierbar:** Gleiche Umgebung auf jedem System  
✅ **Einfach zu entfernen:** `docker compose down -v` löscht alles  
✅ **Port-Flexibilität:** Kann auf beliebigem Port laufen (z.B. 3307 statt 3306)  
✅ **phpMyAdmin inklusive:** Keine separate Installation nötig  
✅ **Keine System-Services:** Keine Windows-Services, die Probleme machen können  
✅ **Persistente Logs:** Logs bleiben im Projektordner für Fehleranalyse  

## Migration von XAMPP zu Docker

1. **Docker-Setup durchführen** (siehe oben)
2. **Datenbank migrieren** (optional):
   ```powershell
   # Export von XAMPP
   mysqldump -u root -p tom > backup.sql
   
   # Import in Docker
   docker exec -i mariadb104 mariadb -u tomcat -ptim@2025! tom < backup.sql
   ```
3. **Konfiguration anpassen:**
   - `config/database.php`: Port auf `3307` ändern
   - Host auf `127.0.0.1` ändern
4. **Testen:**
   ```powershell
   php test-docker-db.php
   ```

---

*Docker-Setup-Anleitung für TOM3*


