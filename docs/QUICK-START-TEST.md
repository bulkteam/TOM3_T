# TOM3 - Quick Start für Testversion

## Schritt 1: Datenbank-Einstellungen finden

Führe dieses Script aus, um zu sehen, welche Datenbank-Einstellungen du hast:

```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\find-database-settings.ps1
```

Dieses Script zeigt dir:
- Ob PostgreSQL installiert ist
- Welcher Port verwendet wird
- Ob der Service läuft
- Ob Neo4j läuft

## Schritt 2: Standard-Testnutzer erstellen (empfohlen)

Dieses Script erstellt automatisch:
- Datenbank: `tom3`
- Benutzer: `tom3_test`
- Passwort: `tom3_test`

Und aktualisiert automatisch `config/database.php`.

```powershell
cd C:\xampp\htdocs\TOM3
powershell -ExecutionPolicy Bypass -File scripts\create-test-users.ps1
```

**Wichtig:** Du wirst nach dem Passwort für den `postgres`-Benutzer gefragt (das Passwort, das du bei der PostgreSQL-Installation gesetzt hast).

## Schritt 3: Datenbank-Schema erstellen

Nachdem die Testnutzer erstellt wurden:

```powershell
php scripts\setup-database.php
```

Dies erstellt alle Tabellen und führt die Migrationen aus.

## Schritt 4: Testen

Öffne im Browser:
- API-Test: http://localhost/TOM3/public/api/test.php
- UI: http://localhost/TOM3/public/

## Manuelle Konfiguration (falls gewünscht)

Falls du die Testnutzer nicht verwenden möchtest, bearbeite manuell:

`config/database.php`

```php
<?php
return [
    'postgresql' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => 'deine_datenbank',
        'user' => 'dein_benutzer',
        'password' => 'dein_passwort',
        'charset' => 'utf8'
    ],
    'neo4j' => [
        'uri' => 'bolt://localhost:7687',
        'user' => 'neo4j',
        'password' => 'dein_neo4j_passwort'
    ]
];
```

## Standard-Testwerte

Wenn du die automatischen Testnutzer verwendest:

**PostgreSQL:**
- Host: `localhost`
- Port: `5432`
- Datenbank: `tom3`
- Benutzer: `tom3_test`
- Passwort: `tom3_test`

**Neo4j (optional):**
- URI: `bolt://localhost:7687`
- Benutzer: `neo4j`
- Passwort: `neo4j` (oder das, was du bei Neo4j Desktop gesetzt hast)

## Troubleshooting

### PostgreSQL nicht gefunden

Das Script sucht standardmäßig in `C:\Program Files\PostgreSQL\16`. Falls PostgreSQL woanders installiert ist:

```powershell
.\create-test-users.ps1 -InstallPath "C:\Program Files\PostgreSQL\15"
```

### Service läuft nicht

```powershell
# Service-Status prüfen
Get-Service | Where-Object { $_.Name -like "*postgres*" }

# Service starten
Start-Service -Name "postgresql-x64-16"
```

### Passwort vergessen

Falls du das postgres-Passwort vergessen hast, kannst du es zurücksetzen:

1. Öffne `pg_hba.conf` (normalerweise in `C:\Program Files\PostgreSQL\16\data\`)
2. Ändere `md5` zu `trust` für localhost
3. Starte PostgreSQL neu
4. Ändere das Passwort: `ALTER USER postgres WITH PASSWORD 'neues_passwort';`
5. Ändere `pg_hba.conf` zurück zu `md5`




