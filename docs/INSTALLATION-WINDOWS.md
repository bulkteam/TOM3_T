# TOM3 - Installation auf Windows (Desktop)

## Übersicht

Diese Anleitung führt dich Schritt für Schritt durch die Installation von PostgreSQL und Neo4j auf Windows für TOM3.

## Voraussetzungen

- Windows 10/11
- Administrator-Rechte
- Internet-Verbindung (für Downloads)

---

## Teil 1: PostgreSQL installieren

### Schritt 1: PostgreSQL herunterladen

1. Gehe zu: https://www.postgresql.org/download/windows/
2. Klicke auf "Download the installer"
3. Wähle die neueste Version (empfohlen: PostgreSQL 15 oder 16)
4. Lade den Windows-Installer herunter

### Schritt 2: PostgreSQL installieren

1. **Installer starten** (als Administrator)
2. **Installation Wizard:**
   - Installation Directory: `C:\Program Files\PostgreSQL\16` (Standard)
   - Select Components: Alle auswählen
   - Data Directory: `C:\Program Files\PostgreSQL\16\data` (Standard)
   - Password: **WICHTIG:** Notiere dir das Passwort für den `postgres`-Benutzer!
   - Port: `5432` (Standard)
   - Advanced Options: `[default locale]` (Standard)
   - Pre Installation Summary: Weiter
   - Ready to Install: Install

3. **Stack Builder** (optional): Kann übersprungen werden

### Schritt 3: PostgreSQL-Service prüfen

1. Öffne **Services** (Windows-Taste + R → `services.msc`)
2. Suche nach `postgresql-x64-16` (oder ähnlich)
3. Status sollte **Running** sein
4. Falls nicht: Rechtsklick → **Start**

### Schritt 4: PostgreSQL zu PATH hinzufügen

1. Windows-Taste → "Umgebungsvariablen" suchen
2. **Umgebungsvariablen** öffnen
3. Unter **Systemvariablen** → **Path** auswählen → **Bearbeiten**
4. **Neu** → `C:\Program Files\PostgreSQL\16\bin` hinzufügen
5. **OK** → **OK** → **OK**
6. **Neue PowerShell/CMD öffnen** (damit PATH aktiv wird)

### Schritt 5: PostgreSQL-Verbindung testen

Öffne PowerShell oder CMD:

```powershell
psql --version
```

Sollte die Version anzeigen (z.B. `psql (PostgreSQL) 16.1`).

### Schritt 6: Datenbank und Benutzer erstellen

```powershell
# Verbinde als postgres-Benutzer
psql -U postgres

# In psql:
CREATE DATABASE tom3;
CREATE USER tom3_user WITH PASSWORD 'tom3_password';
GRANT ALL PRIVILEGES ON DATABASE tom3 TO tom3_user;
\q
```

**WICHTIG:** Notiere dir:
- Datenbankname: `tom3`
- Benutzer: `tom3_user`
- Passwort: `tom3_password` (oder dein gewähltes Passwort)

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

### Schritt 3: Neo4j Desktop einrichten

1. **Neo4j Desktop öffnen**
2. **Account erstellen** (kostenlos, für Updates)
3. **Neues Projekt erstellen:**
   - Klicke auf **"New Project"**
   - Name: `TOM3`
4. **Neue Datenbank erstellen:**
   - Klicke auf **"Add Database"** → **"Local DBMS"**
   - Name: `tom3-graph`
   - Password: **WICHTIG:** Notiere dir das Passwort!
   - Version: Wähle die neueste (z.B. Neo4j 5.x)
   - **Create**

### Schritt 4: Neo4j starten

1. In Neo4j Desktop: Klicke auf **"Start"** bei deiner Datenbank
2. Warte bis Status **"Running"** ist
3. Klicke auf **"Open"** → Browser öffnet sich
4. Login mit:
   - Username: `neo4j`
   - Password: Dein gewähltes Passwort

### Schritt 5: Neo4j-Verbindung testen

Im Neo4j Browser (http://localhost:7474):

```cypher
RETURN "Hello TOM3!" as message;
```

Sollte funktionieren.

**Alternative: Neo4j Community Edition (ohne Desktop)**

Falls du Neo4j ohne Desktop installieren möchtest:

1. Gehe zu: https://neo4j.com/download-center/#community
2. Lade **Neo4j Community Edition** herunter
3. Entpacke in `C:\neo4j` (oder ähnlich)
4. Starte Neo4j:
```powershell
cd C:\neo4j\bin
.\neo4j.bat console
```

---

## Teil 3: TOM3 konfigurieren

### Schritt 1: Datenbank-Konfiguration erstellen

1. Gehe zu `C:\xampp\htdocs\TOM3\config\`
2. Kopiere `database.php.example` → `database.php`
3. Bearbeite `database.php`:

```php
<?php
return [
    'postgresql' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => 'tom3',              // Deine erstellte DB
        'user' => 'tom3_user',            // Dein erstellter Benutzer
        'password' => 'tom3_password',    // Dein Passwort
        'charset' => 'utf8'
    ],
    'neo4j' => [
        'uri' => 'bolt://localhost:7687', // Standard Neo4j Port
        'user' => 'neo4j',                // Standard Neo4j User
        'password' => 'dein_neo4j_passwort' // Dein Neo4j Passwort
    ]
];
```

### Schritt 2: Datenbank-Schema erstellen

```powershell
cd C:\xampp\htdocs\TOM3
php scripts\setup-database.php
```

Dieses Script:
- Prüft die Datenbank-Verbindung
- Erstellt die Datenbank (falls nicht vorhanden)
- Führt alle Migrationen aus
- Richtet alle Tabellen ein

### Schritt 3: Neo4j Constraints und Indexes erstellen

**Option A: Mit Neo4j Desktop**

1. Öffne Neo4j Browser (http://localhost:7474)
2. Kopiere den Inhalt von `database\neo4j\constraints.cypher`
3. Füge ihn in den Browser ein und führe aus
4. Wiederhole für `database\neo4j\indexes.cypher`

**Option B: Mit cypher-shell (falls installiert)**

```powershell
# Falls cypher-shell im PATH ist
cd C:\xampp\htdocs\TOM3
Get-Content database\neo4j\constraints.cypher | cypher-shell -u neo4j -p dein_passwort
Get-Content database\neo4j\indexes.cypher | cypher-shell -u neo4j -p dein_passwort
```

---

## Teil 4: Verifizierung

### PostgreSQL testen

```powershell
psql -U tom3_user -d tom3 -c "SELECT COUNT(*) FROM org;"
```

Sollte `0` zurückgeben (keine Fehler = OK).

### Neo4j testen

Im Neo4j Browser:

```cypher
MATCH (n) RETURN count(n) as node_count;
```

Sollte `0` zurückgeben (keine Fehler = OK).

### TOM3 UI testen

1. Öffne Browser: `http://localhost/TOM3/public/`
2. Sollte die TOM3-UI anzeigen
3. Monitoring: `http://localhost/TOM3/public/monitoring.html`

---

## Troubleshooting

### PostgreSQL startet nicht

```powershell
# Service-Status prüfen
Get-Service -Name "*postgres*"

# Service starten
Start-Service -Name "postgresql-x64-16"

# Oder manuell
& "C:\Program Files\PostgreSQL\16\bin\pg_ctl.exe" start -D "C:\Program Files\PostgreSQL\16\data"
```

### Neo4j startet nicht

1. In Neo4j Desktop: Prüfe Logs
2. Port 7687 bereits belegt? Prüfe:
```powershell
netstat -ano | findstr 7687
```

### Verbindungsfehler

- **PostgreSQL:** Prüfe, ob Service läuft und Port 5432 offen ist
- **Neo4j:** Prüfe, ob Neo4j Desktop läuft und Port 7687 offen ist
- **Firewall:** Stelle sicher, dass Ports nicht blockiert sind

### PATH nicht gefunden

1. Schließe alle PowerShell/CMD-Fenster
2. Öffne neue PowerShell/CMD
3. Teste erneut: `psql --version`

---

## Nächste Schritte

✅ PostgreSQL installiert und Datenbank erstellt
✅ Neo4j installiert und eingerichtet
✅ TOM3 konfiguriert
✅ Datenbank-Schema erstellt

**Jetzt kannst du:**
1. TOM3 UI öffnen: `http://localhost/TOM3/public/`
2. Sync-Worker starten: `php scripts/sync-worker.php --daemon`
3. Erste Daten anlegen (Orgs, Persons, Projects, Cases)

---

*Installations-Anleitung für TOM3 auf Windows*


