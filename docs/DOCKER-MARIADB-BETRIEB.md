# TOM3 - Docker MariaDB Betriebsanleitung

## Übersicht

TOM3 läuft **vollständig auf Docker MariaDB**. XAMPP MySQL wird **nicht mehr benötigt** und sollte **nicht gestartet werden**.

**Wichtig:**
- ✅ **Docker MariaDB** auf Port **3307** (läuft in Docker)
- ❌ **XAMPP MySQL** auf Port **3306** (wird NICHT mehr verwendet)

## Schnellstart

### MariaDB starten

```powershell
cd C:\dev\mariadb-docker
docker compose up -d
```

### MariaDB stoppen

```powershell
cd C:\dev\mariadb-docker
docker compose stop
```

### Status prüfen

```powershell
cd C:\dev\mariadb-docker
docker compose ps
```

## Betriebsanleitung

### 1. MariaDB starten (für Entwicklung/Produktion)

**Im Projektordner:**

```powershell
cd C:\dev\mariadb-docker
docker compose up -d
```

**Was passiert:**
- MariaDB-Container startet
- phpMyAdmin-Container startet
- Container laufen im Hintergrund (`-d` = detached mode)

**Prüfen ob Container laufen:**

```powershell
docker compose ps
```

**Erwartete Ausgabe:**
```
NAME           IMAGE              STATUS
mariadb104     mariadb:10.4.32    Up (healthy)
phpmyadmin     phpmyadmin:latest  Up
```

### 2. MariaDB stoppen

**Container stoppen (Daten bleiben erhalten):**

```powershell
cd C:\dev\mariadb-docker
docker compose stop
```

**Container stoppen und entfernen (Daten bleiben erhalten):**

```powershell
docker compose down
```

**Container stoppen und ALLES löschen (inkl. Daten!):**

```powershell
docker compose down -v
```

⚠️ **Warnung:** `-v` löscht auch das Daten-Volume!

### 3. MariaDB neu starten (nach Stopp)

```powershell
cd C:\dev\mariadb-docker
docker compose start
```

Oder:

```powershell
docker compose up -d
```

### 4. Logs ansehen

**Container-Logs (Startup/Stdout):**

```powershell
docker logs mariadb104
docker logs -f mariadb104  # Live (Strg+C zum Beenden)
```

**Error-Log (persistent):**

```powershell
Get-Content C:\dev\mariadb-docker\logs\mariadb-error.log -Tail 50
```

**Slow-Query-Log (persistent):**

```powershell
Get-Content C:\dev\mariadb-docker\logs\mariadb-slow.log -Tail 50
```

### 5. Verbindung testen

**PHP-Test:**

```powershell
cd C:\xampp\htdocs\TOM3
php test-docker-db.php
```

**phpMyAdmin im Browser:**

```
http://127.0.0.1:8081
```

**Direkter MySQL-Zugriff (falls mysql.exe vorhanden):**

```powershell
cd C:\xampp\mysql\bin
.\mysql.exe -h 127.0.0.1 -P 3307 -u tomcat -p tom
```

## XAMPP MySQL deaktivieren

### XAMPP MySQL NICHT mehr starten

**Wichtig:** XAMPP MySQL sollte **nicht** gestartet werden, da:
- TOM3 verwendet Docker MariaDB auf Port 3307
- XAMPP MySQL würde auf Port 3306 laufen (nicht verwendet)
- Port-Konflikte sind möglich, wenn beide laufen

**XAMPP Control Panel:**
- ❌ MySQL **NICHT** starten
- ✅ Nur Apache starten (für PHP)

### XAMPP MySQL als Windows-Service deaktivieren

Falls XAMPP MySQL als Windows-Service installiert ist:

```powershell
# Service-Status prüfen
Get-Service | Where-Object { $_.Name -like "*mysql*" }

# Service stoppen (falls läuft)
Stop-Service -Name "MySQL" -ErrorAction SilentlyContinue

# Service deaktivieren (startet nicht mehr automatisch)
Set-Service -Name "MySQL" -StartupType Disabled -ErrorAction SilentlyContinue
```

**Oder über Services.msc:**
1. Windows-Taste + R → `services.msc`
2. Suche nach "MySQL" oder "XAMPP MySQL"
3. Rechtsklick → Eigenschaften
4. Starttyp: **Deaktiviert**

## Verifizierung: Läuft alles auf Docker?

### Checkliste

✅ **Konfiguration prüfen:**

```powershell
# Prüfe ENV-Variablen
echo $env:MYSQL_HOST
echo $env:MYSQL_PORT
echo $env:MYSQL_DBNAME
echo $env:MYSQL_USER
# Passwort nicht ausgeben!
```

**Oder prüfe .env Datei:**

```powershell
Get-Content .env | Select-String "MYSQL_"
```

**Sollte zeigen:**
- `MYSQL_HOST=127.0.0.1`
- `MYSQL_PORT=3307`
- `MYSQL_DBNAME=tom`
- `MYSQL_USER=tomcat`
- `MYSQL_PASSWORD=...` (gesetzt, aber nicht sichtbar)

**Hinweis:** Wenn du innerhalb der Datenbank `SELECT @@port` ausführst, zeigt es `3306` - das ist der Container-interne Port. Vom Host aus wird Port `3307` verwendet (Port-Mapping in docker-compose.yml).

✅ **Docker Container läuft:**

```powershell
docker compose ps
```

**Sollte zeigen:** `mariadb104` Status: `Up`

✅ **Port 3307 ist aktiv:**

```powershell
netstat -an | findstr 3307
```

**Sollte zeigen:** `127.0.0.1:3307` im LISTENING-Status

✅ **Port 3306 ist NICHT aktiv (XAMPP MySQL läuft nicht):**

```powershell
netstat -an | findstr 3306
```

**Sollte KEINE Ausgabe zeigen** (oder nur andere IPs, nicht localhost)

✅ **Verbindungstest:**

```powershell
cd C:\xampp\htdocs\TOM3
php test-docker-db.php
```

**Sollte zeigen:** `✓ Verbindung erfolgreich!`

✅ **Monitoring-Test:**

```powershell
cd C:\xampp\htdocs\TOM3
php test-monitoring-db.php
```

**Sollte zeigen:**
- `✅ Monitoring verwendet Docker MariaDB`
- `✅ Datenbank-Verbindung OK`
- `✅ Tabellen gefunden: 49`

**Hinweis:** Das Monitoring verwendet automatisch die Docker-DB, da es `DatabaseConnection::getInstance()` verwendet, welches die ENV-Variablen (MYSQL_HOST, MYSQL_PORT, etc.) liest.

### Code-Verifizierung

**Alle Datenbankverbindungen verwenden ENV-Variablen (über `config/database.php`):**

```powershell
# Prüfe ob irgendwo hardcodiert auf Port 3306 zugegriffen wird
cd C:\xampp\htdocs\TOM3
Select-String -Path "src\**\*.php" -Pattern "3306|localhost.*mysql" -Recurse
```

**Sollte keine Treffer zeigen** (außer in Kommentaren oder Dokumentation)

## Automatischer Start beim Systemstart

### Docker Desktop Auto-Start

**Docker Desktop** sollte so konfiguriert sein, dass es beim Windows-Start automatisch startet:
- Docker Desktop → Settings → General → ✅ "Start Docker Desktop when you log in"

### Container Restart-Policy: `restart: unless-stopped`

Die `docker-compose.yml` enthält für alle Services (mariadb, phpmyadmin, clamav):

```yaml
restart: unless-stopped
```

**Was bedeutet das?**

✅ **Sobald Docker Desktop wieder läuft, startet Docker diese Container automatisch wieder** – außer du hast sie vorher bewusst gestoppt.

**Konkret:**
- Nach einem **PC-Neustart**: Container starten automatisch, sobald Docker Desktop läuft
- Nach einem **Docker-Neustart**: Container starten automatisch wieder
- Nach einem **manuellen Stopp** (`docker stop ...` oder `docker compose stop`): Container starten **nicht** automatisch beim nächsten Docker-Start

### Nach einem PC-Neustart

**Standard-Prozedur:**

1. **Docker Desktop starten** (falls es nicht automatisch startet)
   - Container sollten dank `restart: unless-stopped` automatisch wieder laufen

2. **Status prüfen:**
   ```powershell
   docker ps
   ```

   **Erwartete Ausgabe:**
   ```
   CONTAINER ID   IMAGE                  STATUS                        PORTS                                         NAMES
   a6e57edf8614   clamav/clamav:latest   Up X minutes (healthy)        0.0.0.0:3310->3310/tcp                        tom3-clamav
   76c3034f8e1d   phpmyadmin:latest      Up X minutes                  0.0.0.0:8081->80/tcp                          phpmyadmin
   aab58b58ee00   mariadb:10.4.32        Up X minutes (healthy)        0.0.0.0:3307->3306/tcp                        mariadb104
   ```

   ✅ Alle Container sollten `Up` und `healthy` (bei mariadb/clamav) sein.

3. **Falls Container nicht automatisch gestartet sind:**
   ```powershell
   cd C:\dev\mariadb-docker
   docker compose up -d
   ```

### Wenn Container manuell gestoppt wurden

**Wichtig:** Wenn du Container absichtlich gestoppt hast (z.B. mit `docker stop ...` oder `docker compose stop`), startet `restart: unless-stopped` sie beim nächsten Docker-Start **nicht** automatisch.

**Container wieder starten:**

**Option 1: Alle Container starten (empfohlen):**
```powershell
cd C:\dev\mariadb-docker
docker compose up -d
```

**Option 2: Einzelne Container starten:**
```powershell
docker start mariadb104
docker start phpmyadmin
docker start tom3-clamav
```

### Zusammenfassung: PC-Neustart

**Normaler Ablauf:**
1. ✅ Docker Desktop starten (falls nicht automatisch)
2. ✅ Container laufen automatisch (dank `restart: unless-stopped`)
3. ✅ Status prüfen: `docker ps`

**Falls Container nicht laufen:**
```powershell
cd C:\dev\mariadb-docker
docker compose up -d
```

**Merksatz:**
- `restart: unless-stopped` = Container starten automatisch, außer sie wurden manuell gestoppt
- Nach PC-Neustart: Nur Docker Desktop starten reicht (Container starten automatisch)
- Nach manuellem Stopp: Container müssen manuell wieder gestartet werden

## Troubleshooting

### "Connection refused" oder "Can't connect"

**Prüfe:**
1. Container läuft: `docker compose ps`
2. Port 3307 ist aktiv: `netstat -an | findstr 3307`
3. ENV-Variablen gesetzt: `MYSQL_HOST=127.0.0.1`, `MYSQL_PORT=3307`

**Fix:**
```powershell
cd C:\dev\mariadb-docker
docker compose restart mariadb
```

### XAMPP MySQL läuft parallel

**Problem:** Beide MySQL-Instanzen laufen gleichzeitig.

**Lösung:**
1. XAMPP Control Panel öffnen
2. MySQL **stoppen**
3. Docker MariaDB läuft weiter auf Port 3307

### Container startet nicht

**Prüfe Logs:**
```powershell
docker logs mariadb104
```

**Häufige Ursachen:**
- Port 3307 bereits belegt
- Volume-Berechtigungen
- Config-Syntax-Fehler in `my.cnf`

**Fix:**
```powershell
# Container neu starten
docker compose restart mariadb

# Falls das nicht hilft: Container neu erstellen
docker compose down
docker compose up -d
```

## Zusammenfassung

**Für den Betrieb:**

1. **MariaDB starten:**
   ```powershell
   cd C:\dev\mariadb-docker
   docker compose up -d
   ```

2. **MariaDB stoppen:**
   ```powershell
   cd C:\dev\mariadb-docker
   docker compose stop
   ```

3. **Status prüfen:**
   ```powershell
   docker compose ps
   ```

4. **XAMPP MySQL NICHT starten** (wird nicht mehr benötigt)

**Alle Datenbankverbindungen laufen über:**
- Host: `127.0.0.1` (ENV: `MYSQL_HOST`)
- Port: `3307` (ENV: `MYSQL_PORT`)
- Konfiguration: ENV-Variablen (gelesen über `config/database.php`)

**WICHTIG:** Secrets müssen über ENV-Variablen gesetzt werden. Siehe [SECURITY-IMPROVEMENTS.md](../SECURITY-IMPROVEMENTS.md) für Details.

---

*Betriebsanleitung für Docker MariaDB in TOM3*
