# ClamAV Docker-Integration für TOM3

## Aktuelle Architektur

**TOM3 Setup:**
- ✅ **PHP-App:** Läuft auf XAMPP (Host-System, nicht in Docker)
- ✅ **MariaDB:** Läuft in Docker (`C:\dev\mariadb-docker`)
- ⏳ **ClamAV:** Soll als Docker-Container hinzugefügt werden

## Antwort: Kein separater CRM-Container nötig!

**Wichtig:** Du musst **KEINEN** separaten PHP/CRM-Container erstellen. Die PHP-App läuft weiterhin auf XAMPP (Host).

**❌ NICHT nötig:**
```yaml
  crm:  # ← Dieser Service ist NICHT nötig!
    image: dein-crm-image
    volumes:
      - uploads:/uploads
    depends_on:
      - clamav
```

**✅ Korrekt:**
- ClamAV läuft als **separater Service** in Docker
- PHP-App (auf Host) kommuniziert mit ClamAV über **Socket/Port**
- Keine Abhängigkeit zwischen PHP-Container und ClamAV nötig

## Option 1: ClamAV zu bestehender MariaDB docker-compose.yml hinzufügen

**Wenn MariaDB in `C:\dev\mariadb-docker` läuft:**

Füge ClamAV zur bestehenden `docker-compose.yml` hinzu:

```yaml
services:
  mariadb:
    image: mariadb:10.4.32
    container_name: mariadb104
    restart: unless-stopped
    # ... bestehende Konfiguration ...
  
  phpmyadmin:
    image: phpmyadmin:latest
    container_name: phpmyadmin
    # ... bestehende Konfiguration ...
  
  # NEU: ClamAV Service
  clamav:
    image: clamav/clamav:latest
    container_name: tom3-clamav
    restart: unless-stopped
    volumes:
      - clamav_db:/var/lib/clamav
      - clamav_logs:/var/log/clamav
    environment:
      - CLAMAV_NO_FRESHCLAM=false  # FreshClam aktivieren (automatische Updates)
      - CLAMAV_NO_CLAMD=false       # ClamAV Daemon aktivieren
    ports:
      - "3310:3310"  # ClamAV Socket (für clamdscan)
    healthcheck:
      test: ["CMD", "clamdscan", "--version"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  mariadb_data:
    # ... bestehendes Volume ...
  clamav_db:      # NEU: ClamAV Virendefinitionen
  clamav_logs:    # NEU: ClamAV Logs
  # uploads Volume NICHT nötig, wenn Volume-Mount verwendet wird
```

**Vorteil:**
- Alles in einer docker-compose.yml
- Einfache Verwaltung (`docker compose up -d`)
- ClamAV startet automatisch mit MariaDB

## Option 2: Separates ClamAV docker-compose Setup (wie MariaDB)

**Wenn du ClamAV getrennt verwalten möchtest:**

Erstelle `C:\dev\clamav-docker\docker-compose.yml`:

```yaml
services:
  clamav:
    image: clamav/clamav:latest
    container_name: tom3-clamav
    restart: unless-stopped
    volumes:
      - clamav_db:/var/lib/clamav
      - clamav_logs:/var/log/clamav
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
  clamav_logs:
```

**Vorteil:**
- Getrennte Verwaltung (wie MariaDB)
- Unabhängig starten/stoppen
- Klare Trennung der Services

## PHP-Integration (Host → Docker)

**Die PHP-App (auf XAMPP) kommuniziert mit ClamAV (Docker) über:**

### Option A: Socket-Verbindung (empfohlen)

**ClamAV Service muss Socket freigeben:**

```yaml
services:
  clamav:
    # ... wie oben ...
    ports:
      - "3310:3310"  # Socket-Port
```

**PHP ClamAvService:**
```php
class ClamAvService {
    private $clamdSocket = '127.0.0.1:3310';  // Docker-Port
    
    public function scan(string $filePath): array {
        // Verwende clamdscan mit Socket
        $command = sprintf(
            'clamdscan --no-summary --infected --fdpass %s',
            escapeshellarg($filePath)
        );
        
        exec($command, $output, $returnCode);
        // ... Ergebnis verarbeiten ...
    }
}
```

### Option B: CLI-Aufruf (einfacher, aber langsamer)

**PHP ClamAvService:**
```php
class ClamAvService {
    public function scan(string $filePath): array {
        // Datei muss für Docker-Container erreichbar sein
        // Option 1: Volume-Mount (siehe unten)
        // Option 2: clamdscan über Docker exec
        
        $command = sprintf(
            'docker exec tom3-clamav clamdscan --no-summary --infected %s',
            escapeshellarg($filePath)
        );
        
        exec($command, $output, $returnCode);
        // ... Ergebnis verarbeiten ...
    }
}
```

**Problem:** Datei muss für Container erreichbar sein!

**Lösung: Volume-Mount für Uploads:**

```yaml
services:
  clamav:
    # ... wie oben ...
    volumes:
      - clamav_db:/var/lib/clamav
      - clamav_logs:/var/log/clamav
      - C:/xampp/htdocs/TOM3/storage:/scans:ro  # NEU: Uploads für Scan zugänglich
```

**Dann in PHP:**
```php
// Datei-Pfad für Container
$containerPath = '/scans/' . basename($filePath);
$command = sprintf(
    'docker exec tom3-clamav clamdscan --no-summary --infected %s',
    escapeshellarg($containerPath)
);
```

## Empfehlung: Option 1 (ClamAV zu MariaDB docker-compose.yml)

**Warum:**
- ✅ Einfachste Verwaltung
- ✅ Alles in einem Setup
- ✅ Automatischer Start mit `docker compose up -d`

**Schritte:**

1. **Öffne `C:\dev\mariadb-docker\docker-compose.yml`**

2. **Füge ClamAV-Service hinzu:**
   ```yaml
   services:
     mariadb:
       # ... bestehend ...
     
     phpmyadmin:
       # ... bestehend ...
     
     clamav:
       image: clamav/clamav:latest
       container_name: tom3-clamav
       restart: unless-stopped
       volumes:
         - clamav_db:/var/lib/clamav
         - clamav_logs:/var/log/clamav
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
     mariadb_data:
       # ... bestehend ...
     clamav_db:
     clamav_logs:
   ```

3. **Starte Services:**
   ```powershell
   cd C:\dev\mariadb-docker
   docker compose up -d
   ```

4. **Prüfe Status:**
   ```powershell
   docker compose ps
   ```

**Erwartete Ausgabe:**
```
NAME           IMAGE              STATUS
mariadb104     mariadb:10.4.32    Up (healthy)
phpmyadmin     phpmyadmin:latest  Up
tom3-clamav    clamav/clamav      Up (healthy)
```

## Kommunikation: PHP (Host) → ClamAV (Docker)

**Wichtig:** Die PHP-App läuft auf XAMPP (Host), ClamAV läuft in Docker.

**Verbindung:**
- PHP ruft `clamdscan` auf (lokal auf Host)
- `clamdscan` verbindet sich mit ClamAV-Socket auf `127.0.0.1:3310`
- Docker leitet Port 3310 an Container weiter

**Voraussetzung:**
- `clamdscan` muss auf dem Host installiert sein (oder über Docker exec)

**Alternative (ohne clamdscan auf Host):**
- Volume-Mount für Uploads
- PHP ruft `docker exec tom3-clamav clamdscan ...` auf

## Zusammenfassung

**❌ KEIN separater CRM-Container nötig!**

**✅ ClamAV als Service hinzufügen:**
- Option 1: Zu bestehender `docker-compose.yml` (empfohlen)
- Option 2: Separates Setup (wie MariaDB)

**✅ PHP-App läuft weiterhin auf XAMPP (Host)**
- Kommunikation über Socket/Port
- Keine Containerisierung der PHP-App nötig

**✅ Automatische Updates:**
- FreshClam läuft automatisch im Container
- Keine zusätzliche Konfiguration nötig
