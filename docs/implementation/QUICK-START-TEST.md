# TOM3 - Quick Start für Testversion

## Schritt 1: Datenbank-Setup

### Option A: Docker MariaDB (empfohlen)

Siehe [DOCKER-MARIADB-SETUP.md](DOCKER-MARIADB-SETUP.md) für vollständige Anleitung.

Kurzfassung:
1. Docker Compose Setup mit MariaDB 10.4.32
2. Port 3307 (Host) → 3306 (Container)
3. Automatische Erstellung von DB und User

### Option B: XAMPP MySQL

Falls du XAMPP verwendest, ist MySQL bereits installiert:
- Standard-Benutzer: `root`
- Standard-Passwort: (leer) oder `root`
- Datenbank über phpMyAdmin erstellen: http://localhost/phpmyadmin

## Schritt 2: Datenbank erstellen (nur für XAMPP/native MySQL)

### Option A: Über phpMyAdmin (XAMPP)

1. Öffne http://localhost/phpmyadmin
2. Klicke auf **"Neu"** (neue Datenbank)
3. Datenbankname: `tom`
4. Kollation: `utf8mb4_unicode_ci`
5. Klicke auf **"Erstellen"**

### Option B: Über MySQL-Kommandozeile

```powershell
mysql -u root -p
CREATE DATABASE tom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

**Hinweis:** Bei Docker wird die Datenbank automatisch erstellt.

## Schritt 3: Datenbank-Konfiguration

**WICHTIG:** Secrets müssen über Umgebungsvariablen gesetzt werden.

**Erstelle eine `.env` Datei** im Projektroot:

```bash
APP_ENV=local
AUTH_MODE=dev

# Docker MariaDB
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
```

Siehe [SECURITY-IMPROVEMENTS.md](SECURITY-IMPROVEMENTS.md) für Details.

## Schritt 4: Datenbank-Schema erstellen

```powershell
cd C:\xampp\htdocs\TOM3
php scripts/setup-mysql-database.php
```

Oder führe die Migrationen einzeln aus:

```powershell
php scripts/run-migration-018.php
php scripts/run-migration-019.php
# ... weitere Migrationen nach Bedarf
```

## Schritt 5: Testen

Öffne im Browser:
- API-Test: http://localhost/TOM3/public/api/orgs
- UI: http://localhost/TOM3/public/
- Login: http://localhost/TOM3/public/login.php

## Standard-Testwerte

**MySQL/MariaDB (Docker - empfohlen):**
- Host: `127.0.0.1`
- Port: `3307`
- Datenbank: `tom`
- Benutzer: `tomcat`
- Passwort: Über ENV-Variable `MYSQL_PASSWORD` setzen
- phpMyAdmin: http://127.0.0.1:8081

**Hinweis:** Passwörter werden nicht mehr in `config/database.php` gespeichert, sondern über ENV-Variablen gesetzt.

**MySQL (XAMPP):**
- Host: `localhost`
- Port: `3306`
- Datenbank: `tom`
- Benutzer: `root`
- Passwort: (leer) oder `root`

**Neo4j (optional):**
- URI: `bolt://localhost:7687`
- Benutzer: `neo4j`
- Passwort: `neo4j` (oder das, was du bei Neo4j Desktop gesetzt hast)

## Troubleshooting

### MySQL-Service läuft nicht

```powershell
# Service-Status prüfen
Get-Service | Where-Object { $_.Name -like "*mysql*" }

# Service starten (XAMPP)
# Über XAMPP Control Panel: MySQL → Start
```

### Datenbank-Verbindungsfehler

- Prüfe ob MySQL-Service läuft
- Prüfe ENV-Variablen (MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD)
- Prüfe ob Datenbank `tom` existiert
- Teste Verbindung: `mysql -u root -p`

### Migration-Fehler

Falls eine Migration fehlschlägt:
- Prüfe ob vorherige Migrationen erfolgreich waren
- Prüfe MySQL-Logs
- Führe Migrationen einzeln aus, um Fehlerquelle zu finden
