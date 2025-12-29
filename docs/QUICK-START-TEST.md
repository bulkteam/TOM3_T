# TOM3 - Quick Start für Testversion

## Schritt 1: Datenbank-Einstellungen prüfen

Falls du XAMPP verwendest, ist MySQL bereits installiert:
- Standard-Benutzer: `root`
- Standard-Passwort: (leer) oder `root`
- Datenbank über phpMyAdmin erstellen: http://localhost/phpmyadmin

## Schritt 2: Datenbank erstellen

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

## Schritt 3: Datenbank-Konfiguration

Bearbeite `config/database.php`:

```php
return [
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'tom',
        'user' => 'root',
        'password' => '',  // Leer bei XAMPP Standard
        'charset' => 'utf8mb4'
    ],
    'neo4j' => [
        'uri' => 'bolt://localhost:7687',
        'user' => 'neo4j',
        'password' => 'dein_neo4j_passwort'
    ]
];
```

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
- Prüfe `config/database.php` (Host, Port, Credentials)
- Prüfe ob Datenbank `tom` existiert
- Teste Verbindung: `mysql -u root -p`

### Migration-Fehler

Falls eine Migration fehlschlägt:
- Prüfe ob vorherige Migrationen erfolgreich waren
- Prüfe MySQL-Logs
- Führe Migrationen einzeln aus, um Fehlerquelle zu finden
