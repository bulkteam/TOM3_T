# TOM3 - Installation auf Windows (Desktop)

## Übersicht

Diese Anleitung führt dich Schritt für Schritt durch die Installation von MySQL und Neo4j auf Windows für TOM3.

## Voraussetzungen

- Windows 10/11
- Administrator-Rechte
- Internet-Verbindung (für Downloads)

---

## Teil 1: MySQL/MariaDB installieren

### Option A: Docker MariaDB (empfohlen)

**Vorteile:**
- Keine System-Services
- Isoliert von anderen MySQL-Instanzen
- Einfach zu entfernen
- phpMyAdmin inklusive

**Anleitung:** Siehe [DOCKER-MARIADB-SETUP.md](../docs/DOCKER-MARIADB-SETUP.md)

### Option B: XAMPP MySQL

Falls du XAMPP verwendest, ist MySQL bereits installiert:
- Standard-Benutzer: `root`
- Standard-Passwort: (leer) oder `root`
- Datenbank über phpMyAdmin erstellen: http://localhost/phpmyadmin

### Option C: Native MySQL Installation

## Teil 1B: Native MySQL installieren (Alternative)

### Schritt 1: MySQL herunterladen

1. Gehe zu: https://dev.mysql.com/downloads/installer/
2. Wähle "MySQL Installer for Windows"
3. Lade den Windows-Installer herunter (empfohlen: MySQL 8.0 oder höher)

### Schritt 2: MySQL installieren

1. **Installer starten** (als Administrator)
2. **Installation Wizard:**
   - Setup Type: **Developer Default** (empfohlen) oder **Server only**
   - Installation Directory: Standard
   - Data Directory: Standard
   - Password: **WICHTIG:** Notiere dir das Root-Passwort!
   - Port: `3306` (Standard)
   - Windows Service: MySQL als Service installieren
   - Ready to Install: Install

3. **Configuration Wizard:**
   - Server Configuration: **Development Computer** (empfohlen)
   - Authentication Method: **Use Strong Password Encryption**
   - Root Password: Setze ein sicheres Passwort
   - Windows Service: **Start MySQL Server at System Startup**

### Schritt 3: MySQL-Service prüfen

1. Öffne **Services** (Windows-Taste + R → `services.msc`)
2. Suche nach `MySQL80` (oder ähnlich, je nach Version)
3. Status sollte **Running** sein
4. Falls nicht: Rechtsklick → **Start**

### Schritt 4: MySQL zu PATH hinzufügen

1. Windows-Taste → "Umgebungsvariablen" suchen
2. **Umgebungsvariablen** öffnen
3. Unter **Systemvariablen** → **Path** auswählen → **Bearbeiten**
4. **Neu** → `C:\Program Files\MySQL\MySQL Server 8.0\bin` hinzufügen (Pfad anpassen je nach Version)
5. **OK** → **OK** → **OK**
6. **Neue PowerShell/CMD öffnen** (damit PATH aktiv wird)

### Schritt 5: MySQL-Verbindung testen

Öffne PowerShell oder CMD:

```powershell
mysql --version
```

Sollte die Version anzeigen (z.B. `mysql Ver 8.0.xx`).

### Schritt 6: Datenbank und Benutzer erstellen

```powershell
# Verbinde als root-Benutzer
mysql -u root -p

# In MySQL:
CREATE DATABASE tom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tom3_user'@'localhost' IDENTIFIED BY 'tom3_password';
GRANT ALL PRIVILEGES ON tom.* TO 'tom3_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**WICHTIG:** Notiere dir:
- Datenbankname: `tom`
- Benutzer: `tom3_user`
- Passwort: `tom3_password` (oder dein gewähltes Passwort)

**Alternative mit Docker:**
Siehe [DOCKER-MARIADB-SETUP.md](../docs/DOCKER-MARIADB-SETUP.md) für Docker-Setup.

**Alternative mit XAMPP:**
Falls du XAMPP verwendest, ist MySQL bereits installiert:
- Standard-Benutzer: `root`
- Standard-Passwort: (leer) oder `root`
- Datenbank über phpMyAdmin erstellen: http://localhost/phpmyadmin

---

## Teil 2: Neo4j installieren

### Schritt 1: Neo4j Desktop herunterladen (empfohlen)

**Option A: Neo4j Desktop (einfachste Methode)**

1. Gehe zu: https://neo4j.com/download/
2. Klicke auf **"Download Neo4j Desktop"**
3. Lade den Windows-Installer herunter

### Schritt 2: Neo4j Desktop installieren

1. **Installer starten**
2. Installation Wizard:
   - Install Location: Standard (oder wähle einen Pfad)
   - Install

3. **Neo4j Desktop öffnen:**
   - Erstelle ein neues Projekt
   - Erstelle eine neue Datenbank (Local DB)
   - Setze ein Passwort (notiere es!)

### Schritt 3: Neo4j-Verbindung testen

Öffne Browser:
```
http://localhost:7474
```

Du solltest das Neo4j Browser-Interface sehen.

---

## Teil 3: TOM3 konfigurieren

### Schritt 0: PHP-Extensions aktivieren (PFLICHT)

**WICHTIG:** Für die Dokumenten-Funktionalität müssen folgende PHP-Extensions aktiviert sein:

1. **Öffne `php.ini`** (meist `C:\xampp\php\php.ini`)
   - Finde die Datei mit: `php --ini` oder `php -r "echo php_ini_loaded_file();"`

2. **Aktiviere folgende Extensions:**
   ```ini
   ; Suche nach diesen Zeilen und entferne das ; (Kommentar entfernen):
   
   extension=zip      ; Zeile ~962 - ERFORDERLICH für DOCX/XLSX-Extraktion
   extension=gd       ; Zeile ~931 - Optional, für Bildverarbeitung
   extension=fileinfo ; Zeile ~930 - Meist bereits aktiviert
   ```

3. **Apache/PHP neu starten:**
   - XAMPP Control Panel: Apache stoppen → Apache starten
   - Oder PowerShell (als Administrator):
     ```powershell
     Stop-Service -Name Apache2.4 -ErrorAction SilentlyContinue
     Start-Service -Name Apache2.4 -ErrorAction SilentlyContinue
     ```

4. **Verifizierung:**
   ```powershell
   php -r "echo 'ZIP: ' . (extension_loaded('zip') ? 'OK' : 'FEHLT') . PHP_EOL; echo 'GD: ' . (extension_loaded('gd') ? 'OK' : 'FEHLT') . PHP_EOL;"
   ```

**Weitere Informationen:** Siehe [DOCUMENT-TEXT-EXTRACTION.md](DOCUMENT-TEXT-EXTRACTION.md) für Details zu den Extensions.

### Schritt 1: Datenbank-Konfiguration

**WICHTIG:** Secrets müssen über Umgebungsvariablen gesetzt werden. Siehe [SECURITY-IMPROVEMENTS.md](SECURITY-IMPROVEMENTS.md) für Details.

**Erstelle eine `.env` Datei** im Projektroot:

```bash
# .env
APP_ENV=local
AUTH_MODE=dev

# Docker MariaDB (empfohlen)
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3307
MYSQL_DBNAME=tom
MYSQL_USER=tomcat
MYSQL_PASSWORD=dein_passwort_hier

# Oder XAMPP MySQL
# MYSQL_HOST=localhost
# MYSQL_PORT=3306
# MYSQL_DBNAME=tom
# MYSQL_USER=root
# MYSQL_PASSWORD=

# Neo4j (optional)
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=dein_neo4j_passwort
```

**Hinweis:** `config/database.php` liest automatisch aus ENV-Variablen. In Production müssen alle Secrets gesetzt sein.

### Schritt 2: Datenbank-Schema erstellen

```powershell
cd C:\xampp\htdocs\TOM3
php scripts/setup-mysql-database.php
```

Oder führe die Migrationen einzeln aus:

```powershell
php scripts/run-migration-018.php
php scripts/run-migration-019.php
# ... weitere Migrationen
```

### Schritt 3: Neo4j Constraints erstellen

```powershell
php scripts/setup-neo4j-constraints.php
```

---

## Troubleshooting

### MySQL startet nicht

**Für Docker:**
```powershell
# Container-Status prüfen
docker compose ps

# Container starten
docker compose start

# Logs prüfen
docker logs mariadb104
```

**Für XAMPP/native MySQL:**
```powershell
# Service-Status prüfen
Get-Service | Where-Object { $_.Name -like "*mysql*" }

# Service starten
Start-Service -Name "MySQL80"
```

### MySQL-Verbindung fehlgeschlagen

**Für Docker:**
- Prüfe Container läuft: `docker compose ps`
- Prüfe Port 3307 (nicht 3306!)
- Prüfe `config/database.php` verwendet `127.0.0.1:3307`
- Siehe [DOCKER-MARIADB-SETUP.md](../docs/DOCKER-MARIADB-SETUP.md) für Troubleshooting

**Für XAMPP/native MySQL:**
- Prüfe ob MySQL-Service läuft
- Prüfe Port 3306 (Standard)
- Prüfe Firewall-Regeln
- Prüfe Credentials in `config/database.php`

### Passwort vergessen

Falls du das MySQL-Root-Passwort vergessen hast:

1. Stoppe MySQL-Service
2. Starte MySQL im Safe-Mode:
```powershell
mysqld --skip-grant-tables
```
3. In neuer CMD/PowerShell:
```powershell
mysql -u root
ALTER USER 'root'@'localhost' IDENTIFIED BY 'neues_passwort';
FLUSH PRIVILEGES;
EXIT;
```
4. Starte MySQL-Service neu

### Neo4j startet nicht

- Prüfe ob Neo4j Desktop läuft
- Prüfe Port 7474 (HTTP) und 7687 (Bolt)
- Prüfe Firewall-Regeln

---

✅ **Installation abgeschlossen!**

Öffne im Browser:
- UI: `http://localhost/TOM3/public/`
- Monitoring: `http://localhost/TOM3/public/monitoring.html`
