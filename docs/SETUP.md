# TOM3 - Setup-Anleitung

## Voraussetzungen

- **MySQL/MariaDB** 5.7+ oder **MySQL** 8.0+ (für operative Daten)
  - **Empfohlen:** Docker mit MariaDB (siehe [DOCKER-MARIADB-SETUP.md](DOCKER-MARIADB-SETUP.md))
  - **Alternative:** XAMPP MySQL oder native MySQL-Installation
- **Neo4j** 4.4+ (optional, für Graph-Intelligence)
- **PHP** 8.1+ mit Extensions:
  - `pdo` und `pdo_mysql` (Datenbank)
  - `json` (API)
  - `zip` (ERFORDERLICH für DOCX/XLSX-Extraktion)
  - `fileinfo` (MIME-Type-Erkennung)
  - `gd` (optional, für Bildverarbeitung)
  
  **WICHTIG:** Siehe [INSTALLATION-WINDOWS.md](INSTALLATION-WINDOWS.md) für die Aktivierung der Extensions in `php.ini`.
- **Composer** (für PHP-Dependencies)
- **Apache** mit mod_rewrite (oder Nginx)

## Installation

### 1. Repository klonen/kopieren

```bash
cd C:\xampp\htdocs
# TOM3 sollte bereits vorhanden sein
```

### 2. Dependencies installieren

```bash
cd TOM3
composer install
```

### 3. Datenbank-Setup

**Option A: Docker MariaDB (empfohlen)**

Siehe [DOCKER-MARIADB-SETUP.md](DOCKER-MARIADB-SETUP.md) für vollständige Anleitung.

Kurzfassung:
1. Docker Compose Setup mit MariaDB 10.4.32
2. Port 3307 (Host) → 3306 (Container)
3. Automatische Erstellung von DB und User

**Option B: XAMPP MySQL**

1. Starte MySQL in XAMPP Control Panel
2. Erstelle Datenbank `tom` über phpMyAdmin

**Option C: Native MySQL**

1. Installiere MySQL/MariaDB
2. Erstelle Datenbank und User manuell

### 4. Datenbank-Konfiguration

**WICHTIG:** Secrets müssen über Umgebungsvariablen gesetzt werden. Siehe [SECURITY-IMPROVEMENTS.md](SECURITY-IMPROVEMENTS.md) für Details.

**Lokale Entwicklung:**

**WICHTIG:** Keine Passwörter mehr im Code! Alle Secrets müssen über Umgebungsvariablen gesetzt werden.

1. Kopiere `.env.example` nach `.env`:
```bash
cp .env.example .env
```

2. Bearbeite `.env` mit deinen lokalen Werten:
```bash
# .env Datei
APP_ENV=local
AUTH_MODE=dev

# Docker MariaDB
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3307
MYSQL_DBNAME=tom
MYSQL_USER=tomcat
MYSQL_PASSWORD=dein_passwort_hier

# Neo4j (optional)
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=dein_neo4j_passwort
```

**Hinweis:** 
- `config/database.php` verwendet automatisch ENV-Variablen
- **Keine Default-Passwörter mehr im Code** (auch nicht für Dev)
- In Production müssen alle Secrets gesetzt sein (fail-closed)
- Siehe [SECURITY-IMPROVEMENTS.md](analysen/security/SECURITY-IMPROVEMENTS.md) für Details

**Für XAMPP MySQL (Legacy):**

Falls du XAMPP verwendest, setze die ENV-Variablen entsprechend:

```bash
MYSQL_HOST=localhost
MYSQL_PORT=3306
MYSQL_DBNAME=tom
MYSQL_USER=root
MYSQL_PASSWORD=
```

### 5. MySQL-Datenbank einrichten

#### Automatisch (empfohlen)

```bash
php scripts/setup-mysql-database.php
```

Oder führe die Migrationen einzeln aus:

```bash
php scripts/run-migration-001.php
php scripts/run-migration-002.php
# ... weitere Migrationen
```

#### Manuell

1. Erstelle die Datenbank:
```sql
CREATE DATABASE tom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Führe die Migrationen aus (in Reihenfolge):
```bash
mysql -u root -p tom < database/migrations/001_tom_core_schema_mysql.sql
mysql -u root -p tom < database/migrations/002_workflow_definitions_mysql.sql
mysql -u root -p tom < database/migrations/003_org_addresses_and_relations_mysql.sql
# ... weitere Migrationen
```

### 6. Neo4j einrichten (optional)

1. Starte Neo4j:
```bash
# Windows
neo4j start

# Linux/Mac
sudo systemctl start neo4j
```

2. Erstelle Constraints:
```bash
cypher-shell -u neo4j -p dein_passwort < database/neo4j/constraints.cypher
```

3. Erstelle Indexes:
```bash
cypher-shell -u neo4j -p dein_passwort < database/neo4j/indexes.cypher
```

### 7. Apache konfigurieren

Stelle sicher, dass `mod_rewrite` aktiviert ist und der DocumentRoot auf `public/` zeigt:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/TOM3/public"
    ServerName tom3.local
    
    <Directory "C:/xampp/htdocs/TOM3/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Oder verwende die URL direkt:
```
http://localhost/TOM3/public/
```

### 8. Sync-Worker starten (optional)

Für Neo4j-Synchronisation:

```bash
# Einmalig
php scripts/sync-worker.php

# Daemon-Modus
php scripts/sync-worker.php --daemon
```

## Verifizierung

### 1. Datenbank-Verbindung testen

```bash
php -r "
require 'vendor/autoload.php';
use TOM\Infrastructure\Database\DatabaseConnection;
\$db = DatabaseConnection::getInstance();
echo '✅ Datenbank verbunden\n';
"
```

### 2. UI testen

Öffne im Browser:
- Haupt-UI: `http://localhost/TOM3/public/`
- Monitoring: `http://localhost/TOM3/public/monitoring.html`

### 3. API testen

```bash
curl http://localhost/TOM3/public/api/cases
```

## Troubleshooting

### Datenbank-Verbindungsfehler

**Für Docker:**
- Prüfe Container-Status: `docker compose ps`
- Prüfe Logs: `docker logs mariadb104`
- Prüfe ENV-Variablen (MYSQL_HOST, MYSQL_PORT, etc.)
- Siehe [DOCKER-MARIADB-SETUP.md](DOCKER-MARIADB-SETUP.md) für Troubleshooting

**Für XAMPP/native MySQL:**
- Prüfe MySQL-Laufstatus
- Prüfe ENV-Variablen (MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD)
- Prüfe Firewall-Regeln
- Prüfe ob MySQL-Service läuft: `net start MySQL` (Windows) oder `systemctl status mysql` (Linux)

### Neo4j-Verbindungsfehler

- Prüfe Neo4j-Laufstatus
- Prüfe Bolt-Port (Standard: 7687)
- Prüfe ENV-Variablen (NEO4J_URI, NEO4J_USER, NEO4J_PASSWORD)

### Apache mod_rewrite

Falls URLs nicht funktionieren:
- Prüfe `.htaccess` in `public/`
- Aktiviere `mod_rewrite`: `a2enmod rewrite` (Linux)
- Starte Apache neu

### Composer-Fehler

```bash
# Cache leeren
composer clear-cache

# Neu installieren
rm -rf vendor
composer install
```

## Nächste Schritte

1. ✅ Datenbank ist eingerichtet
2. ✅ UI ist verfügbar
3. ⏭️ Erstelle erste Test-Daten (Orgs, Persons, Projects, Cases)
4. ⏭️ Starte Sync-Worker für Neo4j
5. ⏭️ Konfiguriere Monitoring-Alerts

---

*Setup-Anleitung für TOM3*
