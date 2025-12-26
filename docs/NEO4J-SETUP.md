# Neo4j Setup für TOM3

Neo4j wird in TOM3 als Graph-Datenbank für intelligente Beziehungsanalyse verwendet. Es ist **optional**, aber empfohlen für die volle Funktionalität.

## Installation

### Option 1: Neo4j Desktop (Empfohlen für Entwicklung)

**Vorteile:**
- Einfache Installation und Verwaltung
- Grafische Benutzeroberfläche
- Einfaches Starten/Stoppen
- Mehrere Datenbanken möglich

**Schritte:**

1. **Download:**
   - Gehe zu: https://neo4j.com/download/
   - Klicke auf **"Download Neo4j Desktop"**
   - Installiere die .exe-Datei

2. **Einrichtung:**
   - Öffne Neo4j Desktop
   - Erstelle ein Konto (kostenlos) oder überspringe
   - Klicke auf **"New Project"**
   - Klicke auf **"Add Database"** → **"Create a Local DBMS"**
   - Wähle Version (empfohlen: Neo4j 5.x)
   - Setze ein Passwort (z.B. `tom3_neo4j`) - **WICHTIG: Merke dir dieses Passwort!**

3. **Starten:**
   - Klicke auf **"Start"** bei deiner Datenbank
   - Warte bis Status "Running" ist

4. **Verbindung testen:**
   - Klicke auf **"Open"** → öffnet Neo4j Browser (http://localhost:7474)
   - Login mit:
     - Username: `neo4j`
     - Password: Dein gesetztes Passwort

### Option 2: Neo4j Community Edition (Für Produktion/Service)

**Vorteile:**
- Kann als Windows-Service laufen
- Keine GUI nötig
- Für Server-Umgebungen geeignet

**Schritte:**

1. **Download:**
   - Gehe zu: https://neo4j.com/download-center/#community
   - Lade Neo4j Community Edition ZIP herunter
   - Entpacke nach `C:\neo4j` (oder anderem Pfad)

2. **Installation mit PowerShell-Skript:**
   ```powershell
   cd C:\xampp\htdocs\TOM3
   .\scripts\install-neo4j.ps1
   ```

3. **Manuelle Installation:**
   ```powershell
   cd C:\neo4j\bin
   .\neo4j.bat console
   ```

## Konfiguration in TOM3

Die Neo4j-Konfiguration ist bereits in `config/database.php` vorhanden:

```php
'neo4j' => [
    'uri' => 'bolt://localhost:7687',
    'user' => 'neo4j',
    'password' => 'dein_neo4j_passwort'  // ← Ändere hier dein Passwort!
]
```

**Wichtig:** Passe das Passwort in `config/database.php` an dein Neo4j-Passwort an!

## Constraints und Indexes erstellen

Nach der Installation müssen die Constraints und Indexes erstellt werden:

### Option A: Mit Neo4j Browser (Empfohlen)

1. Öffne Neo4j Browser: http://localhost:7474
2. Login mit deinen Credentials
3. Öffne `database/neo4j/constraints.cypher` in einem Editor
4. Kopiere den gesamten Inhalt
5. Füge ihn in den Neo4j Browser ein und klicke auf "Run"
6. Wiederhole für `database/neo4j/indexes.cypher`

### Option B: Mit cypher-shell (Falls installiert)

```powershell
cd C:\xampp\htdocs\TOM3

# Constraints erstellen
Get-Content database\neo4j\constraints.cypher | cypher-shell -u neo4j -p dein_passwort

# Indexes erstellen
Get-Content database\neo4j\indexes.cypher | cypher-shell -u neo4j -p dein_passwort
```

### Option C: Mit PowerShell-Skript

Erstelle ein Test-Skript `test-neo4j.ps1`:

```powershell
# Test Neo4j-Verbindung
$config = Get-Content config\database.php -Raw
# ... (siehe unten für vollständiges Skript)
```

## Verbindung testen

### 1. Port-Prüfung

```powershell
netstat -ano | findstr :7687
```

Sollte Port 7687 zeigen = Neo4j läuft

### 2. HTTP-Interface prüfen

Öffne im Browser: http://localhost:7474

Sollte Neo4j Login-Seite zeigen = Neo4j läuft

### 3. PHP-Verbindung testen

Erstelle `test-neo4j-connection.php`:

```php
<?php
require 'vendor/autoload.php';

use Laudis\Neo4j\ClientBuilder;

$config = require 'config/database.php';
$neo4j = $config['neo4j'];

try {
    $client = ClientBuilder::create()
        ->withDriver('bolt', $neo4j['uri'], $neo4j['user'], $neo4j['password'])
        ->build();
    
    $result = $client->run('RETURN 1 as test');
    echo "✓ Neo4j-Verbindung erfolgreich!\n";
    echo "Test-Ergebnis: " . $result->first()->get('test') . "\n";
} catch (Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
```

Führe aus:
```powershell
php test-neo4j-connection.php
```

## Standard-Ports

- **Bolt-Protokoll:** 7687 (für PHP-Verbindungen)
- **HTTP-Interface:** 7474 (für Browser)

## Troubleshooting

### Neo4j startet nicht

1. **Prüfe Logs:**
   - Neo4j Desktop: Klicke auf "..." → "Logs"
   - Community Edition: `C:\neo4j\logs\neo4j.log`

2. **Port bereits belegt:**
   ```powershell
   # Prüfe, was Port 7687 verwendet
   netstat -ano | findstr :7687
   ```

3. **Java nicht gefunden:**
   - Neo4j benötigt Java 11 oder höher
   - Download: https://adoptium.net/

### Verbindungsfehler in PHP

1. **Passwort falsch:**
   - Prüfe `config/database.php`
   - Prüfe Neo4j-Passwort in Neo4j Desktop

2. **Neo4j läuft nicht:**
   - Starte Neo4j Desktop und starte die Datenbank
   - Oder starte Community Edition Service

3. **Firewall blockiert:**
   - Erlaube Port 7687 in Windows Firewall

## Nächste Schritte

Nach erfolgreicher Installation:

1. ✅ Neo4j läuft (Port 7687 aktiv)
2. ✅ Constraints erstellt (`database/neo4j/constraints.cypher`)
3. ✅ Indexes erstellt (`database/neo4j/indexes.cypher`)
4. ✅ Passwort in `config/database.php` angepasst
5. ✅ Verbindung getestet

Neo4j ist jetzt bereit für die Verwendung mit TOM3!

