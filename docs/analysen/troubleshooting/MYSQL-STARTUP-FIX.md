# MySQL Startup-Fix - Lösung für häufige MySQL-Abstürze beim ersten Start

## Problem

MySQL stürzt beim ersten Start der App häufig ab mit Fehlern wie:
- `SQLSTATE[HY000] [2002] Es konnte keine Verbindung hergestellt werden`
- MySQL startet, wird aber sofort wieder beendet
- Aria-Log-Fehler

## Lösung

Es wurden Scripts erstellt, die MySQL automatisch prüfen, reparieren und starten:

### 1. Automatische MySQL-Prüfung in `index.php`

Die `index.php` prüft jetzt automatisch, ob MySQL läuft, bevor die App geladen wird:

- **Prüft MySQL-Verfügbarkeit** (Port 3306)
- **Startet MySQL automatisch** wenn es nicht läuft (Windows)
- **Zeigt benutzerfreundliche Fehlermeldungen** wenn MySQL nicht startet
- **Führt automatisch Recovery durch** wenn Aria-Fehler erkannt werden

### 2. Neue Scripts

#### `scripts/ensure-mysql-running.ps1` (PowerShell)
- Prüft ob MySQL läuft
- Führt Aria-Recovery durch wenn nötig
- Startet MySQL
- Wartet bis MySQL bereit ist (max. 30 Sekunden)
- Detaillierte Ausgabe

#### `scripts/ensure-mysql-running.bat` (Batch)
- Einfache Batch-Version für manuelle Ausführung
- Gleiche Funktionalität wie PowerShell-Version
- Kann direkt ausgeführt werden

## Verwendung

### Automatisch (empfohlen)

Die `index.php` ruft automatisch das Recovery-Script auf, wenn MySQL nicht läuft. Keine manuelle Aktion nötig!

### Manuell

Falls MySQL-Probleme auftreten, führe manuell aus:

```batch
# Windows Batch
C:\xampp\htdocs\TOM3_T\scripts\ensure-mysql-running.bat

# PowerShell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\TOM3_T\scripts\ensure-mysql-running.ps1
```

### Als Scheduled Task

Für automatische Prüfung beim Systemstart:

1. Öffne "Aufgabenplanung" (Task Scheduler)
2. Erstelle neue Aufgabe
3. Trigger: "Beim Anmelden" oder "Beim Starten des Computers"
4. Aktion: `C:\xampp\htdocs\TOM3_T\scripts\ensure-mysql-running.bat`
5. Als Administrator ausführen

## Was passiert beim App-Start?

1. **MySQL-Prüfung**: `index.php` prüft ob MySQL auf Port 3306 antwortet
2. **Automatischer Start**: Wenn nicht, wird `ensure-mysql-running.bat` aufgerufen
3. **Recovery**: Script prüft auf Aria-Fehler und repariert sie automatisch
4. **MySQL-Start**: Script startet MySQL über `mysql_start.bat`
5. **Wartezeit**: Script wartet bis MySQL bereit ist (max. 30 Sekunden)
6. **Fehlerbehandlung**: Wenn MySQL nicht startet, wird eine benutzerfreundliche Fehlerseite angezeigt

## Fehlerbehebung

### MySQL startet nicht automatisch

1. **Prüfe XAMPP Control Panel**: Starte MySQL manuell
2. **Prüfe MySQL-Logs**: `C:\xampp\mysql\data\mysql_error.log`
3. **Führe Recovery-Script aus**: `scripts\ensure-mysql-running.bat`
4. **Prüfe Port 3306**: Wird er von einem anderen Programm blockiert?

### Häufige Fehler

#### "Port 3306 blockiert"
- Prüfe ob ein anderer MySQL-Service läuft
- Prüfe Windows Firewall
- Prüfe ob ein anderer Prozess Port 3306 verwendet

#### "Aria recovery failed"
- Führe `scripts\mysql-auto-recovery.bat` aus
- Oder lösche manuell:
  - `C:\xampp\mysql\data\aria_log.*`
  - `C:\xampp\mysql\data\aria_log_control` (wenn korrupt)

#### "MySQL startet, wird aber sofort beendet"
- Prüfe MySQL-Logs für Fehlerdetails
- Prüfe MySQL-Konfiguration (`my.ini`)
- Stelle sicher, dass genug Speicher verfügbar ist

## Zusätzliche Maßnahmen

### 1. Verbesserte MySQL-Konfiguration

**WICHTIG: Die folgenden kritischen Einstellungen wurden bereits in `C:\xampp\mysql\bin\my.ini` angewendet:**

```ini
# Warnung fixen
key_buffer_size=16M     # statt key_buffer=16M

# PHP-Apps brauchen oft mehr als 1M
max_allowed_packet=16M  # für größere Inserts/Blobs

# HINWEIS: internal_tmp_disk_storage_engine wird in MariaDB 10.4 nicht unterstützt
# Diese Option ist nur in MySQL 8.0+ verfügbar und wurde daher nicht hinzugefügt

# Aria/MyISAM: automatische Reparaturoptionen
aria_sort_buffer_size=1M  # Größerer Buffer für Aria-Reparaturen
aria_recover_options=BACKUP,QUICK
myisam_recover_options=BACKUP,FORCE
```

Diese Einstellungen:
- ✅ Beheben MySQL-Warnungen (`key_buffer_size` statt `key_buffer`)
- ✅ Erlauben größere Datenpakete (wichtig für BLOBs, große Inserts)
- ✅ Reduzieren Aria-Abhängigkeit bei Temp-Tabellen
- ✅ Aktivieren automatische Reparatur bei Aria/MyISAM-Fehlern

**Backup der my.ini wurde erstellt:** `C:\xampp\mysql\bin\my.ini.backup_[Zeitstempel]`

Für weitere Optimierungen siehe `docs/MYSQL-IMPROVED-CONFIG.md`.

### 2. Regelmäßige Backups

Führe `scripts\mysql-backup.bat` regelmäßig aus oder richte einen Scheduled Task ein.

### 3. Monitoring

Überwache MySQL-Logs regelmäßig:
```powershell
Get-Content C:\xampp\mysql\data\mysql_error.log -Tail 50
```

## Zusammenfassung

✅ **Automatische MySQL-Prüfung** beim App-Start
✅ **Automatisches Recovery** bei Aria-Fehlern
✅ **Automatischer MySQL-Start** wenn nicht läuft
✅ **Benutzerfreundliche Fehlermeldungen**
✅ **Manuelle Scripts** für erweiterte Fehlerbehebung

Die App sollte jetzt zuverlässiger starten, auch wenn MySQL beim ersten Start Probleme hat!


