# TOM3 - Setup-Anleitung

## Voraussetzungen

- **MySQL/MariaDB** 5.7+ oder **MySQL** 8.0+ (für operative Daten)
- **Neo4j** 4.4+ (optional, für Graph-Intelligence)
- **PHP** 8.1+ mit Extensions:
  - `pdo`
  - `pdo_mysql`
  - `json`
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

### 3. Datenbank-Konfiguration

Kopiere die Beispiel-Konfiguration:

```bash
cp config/database.php.example config/database.php
```

Bearbeite `config/database.php` und passe die Werte an:

```php
return [
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'tom',
        'user' => 'root',
        'password' => 'dein_passwort',
        'charset' => 'utf8mb4'
    ],
    'neo4j' => [
        'uri' => 'bolt://localhost:7687',
        'user' => 'neo4j',
        'password' => 'dein_neo4j_passwort'
    ]
];
```

### 4. MySQL-Datenbank einrichten

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

### 5. Neo4j einrichten (optional)

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

### 6. Apache konfigurieren

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

### 7. Sync-Worker starten (optional)

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

- Prüfe MySQL-Laufstatus
- Prüfe `config/database.php` (Host, Port, Credentials)
- Prüfe Firewall-Regeln
- Prüfe ob MySQL-Service läuft: `net start MySQL` (Windows) oder `systemctl status mysql` (Linux)

### Neo4j-Verbindungsfehler

- Prüfe Neo4j-Laufstatus
- Prüfe Bolt-Port (Standard: 7687)
- Prüfe Credentials in `config/database.php`

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
