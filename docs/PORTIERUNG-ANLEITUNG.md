# TOM3 - Portierungsanleitung für neues System

Diese Anleitung beschreibt die notwendigen Schritte, um TOM3 auf ein neues System zu portieren und sicherzustellen, dass MySQL stabil läuft.

## 1. Stabilitäts-Checkliste nach dem Portieren

### A. Vor dem ersten Start

#### Defender/AV Ausschlüsse setzen

Füge folgende Ausschlüsse zu Windows Defender oder deinem Antivirus-Programm hinzu:

- **Ordner:** `C:\xampp\mysql\data`
- **Ordner:** `C:\xampp\tmp`
- **Prozess:** `C:\xampp\mysql\bin\mysqld.exe`

#### Systemvoraussetzungen prüfen

- ✅ **Freier Speicher:** Ausreichend für Temp-Dateien, `ibtmp1` und Logs
- ✅ **Nur eine Instanz:** Sicherstellen, dass kein anderer MySQL/MariaDB-Dienst parallel läuft (Port 3306)
  - Prüfe mit: `netstat -an | findstr :3306`
  - Beende andere MySQL-Instanzen falls vorhanden

### B. Start-Check

#### MySQL starten

1. **MySQL im XAMPP Control Panel starten**

2. **Sofort prüfen:**
   ```batch
   cd C:\xampp\mysql\bin
   .\mysqladmin.exe -u root ping
   ```
   
   **Erwartung:** `mysqld is alive`

3. **Error-Log prüfen:**
   ```powershell
   Get-Content C:\xampp\mysql\data\mysql_error.log -Tail 30
   ```
   
   **Sollte NICHT vorkommen:**
   - ❌ `Aria recovery failed`
   - ❌ `Cannot find checkpoint record`
   - ❌ `Could not open mysql.plugin table`
   
   **Kurz:** Keine Recovery-Fehler, keine Systemtabellen-Fehler.

### C. App-Last-Check

1. **App im Browser öffnen:** `http://localhost/TOM3/public/`
2. **Mehrere Seiten/Workflows klicken** (5-10 Minuten testen)
3. **Erneut prüfen:**
   ```batch
   cd C:\xampp\mysql\bin
   .\mysqladmin.exe -u root ping
   ```
   
   **Erwartung:** Weiterhin `mysqld is alive`

### D. Sauberer Stop/Start-Zyklus

**WICHTIG:** Stop NIE "hart" (Task Manager), sondern sauber!

1. **Apache stoppen** (XAMPP Control Panel)

2. **MySQL sauber stoppen:**
   ```batch
   cd C:\xampp\mysql\bin
   .\mysqladmin.exe -u root shutdown
   ```

3. **Warten bis mysqld.exe wirklich beendet ist:**
   ```batch
   tasklist | findstr mysqld
   ```
   (Sollte keine Ausgabe zeigen)

4. **Wieder starten** (XAMPP Control Panel)

5. **Error-Log prüfen:**
   ```powershell
   Get-Content C:\xampp\mysql\data\mysql_error.log -Tail 30
   ```
   
   **Sollte NICHT vorkommen:**
   - ❌ "crash recovery" Schleifen
   - ❌ `mysql.plugin` Fehler
   
   **Hinweis:** XAMPP kann dabei "unerwartet beendet" melden, obwohl es sauber war. Entscheidend ist das MariaDB-Log.

## 2. Pflicht-Anpassungen in my.ini für stabile XAMPP-Setups

**Datei:** `C:\xampp\mysql\bin\my.ini`

### A. Korrigiere den Parameter (Warnung/Kompatibilität)

**Ersetzen:**
```ini
key_buffer=16M
```

**Durch:**
```ini
key_buffer_size=16M
```

### B. Reduziere Aria-Risiko für Temp-Tabellen

**HINWEIS:** Diese Option wird in MariaDB 10.4.32 **nicht unterstützt** (nur MySQL 8.0+). Daher **nicht hinzufügen**:
```ini
# internal_tmp_disk_storage_engine=InnoDB  # NICHT in MariaDB 10.4 verfügbar
```

### C. Recovery-Optionen (damit kleine Schäden abgefangen werden)

**Hinzufügen unter `[mysqld]`:**
```ini
aria_recover_options=BACKUP,QUICK
myisam_recover_options=BACKUP,FORCE
```

### D. Repair/Index-Build nicht an Mini-Puffern scheitern lassen

**Hinzufügen unter `[mysqld]`:**
```ini
aria_sort_buffer_size=1M
```

**Grund:** Verhindert Fehler "aria_sort_buffer_size is too small" bei Reparaturen.

### E. PHP-Praxiswert (verhindert unnötige Probleme mit zu kleinen Packets)

**Ersetzen:**
```ini
max_allowed_packet=1M
```

**Durch:**
```ini
max_allowed_packet=16M
```

### Vollständige Beispiel-Konfiguration

```ini
[mysqld]
# ... bestehende Einstellungen ...

# Korrigierte Einstellungen
key_buffer_size=16M
max_allowed_packet=16M

# Aria/MyISAM: automatische Reparaturoptionen
aria_sort_buffer_size=1M
aria_recover_options=BACKUP,QUICK
myisam_recover_options=BACKUP,FORCE

# ... weitere Einstellungen ...
```

**Nach Änderungen:** MySQL neu starten (Stop → Start im XAMPP Control Panel)

## 3. Post-Port-Health-Commands

Diese Befehle können nach dem Portieren ausgeführt werden, um die Systemstabilität zu prüfen:

### A. Systemtabellen check/repair

```batch
cd C:\xampp\mysql\bin
.\mysqlcheck.exe -u root --check mysql
.\mysqlcheck.exe -u root --repair mysql
```

### B. Systemtabellen passend zur Version "gerade ziehen"

**Bei Umzug/Upgrade extrem sinnvoll:**
```batch
cd C:\xampp\mysql\bin
.\mysql_upgrade.exe -u root
```

### C. Gesamtsystem prüfen

**Option 1: Manuell**
```batch
cd C:\xampp\mysql\bin
.\mysqlcheck.exe -u root --check --all-databases
```

**Option 2: Mit Health-Check Script (empfohlen)**
```powershell
cd C:\xampp\htdocs\TOM3
.\scripts\mysql-health-check.ps1 -DoCheckAllDatabases
```

Das Health-Check Script prüft zusätzlich:
- Connectivity
- Port-Verwendung
- Error-Log auf kritische Patterns
- my.ini Konfiguration

## 4. Notfall-Prozedur (wenn Aria wieder "checkpoint record" meldet)

Wenn MySQL-Start scheitert mit `Aria recovery failed`:

### Schritt 1: MySQL stoppen

```batch
cd C:\xampp\mysql\bin
.\mysqladmin.exe -u root shutdown
```

**Sicherstellen, dass mysqld.exe weg ist:**
```batch
tasklist | findstr mysqld
```
(Sollte keine Ausgabe zeigen)

### Schritt 2: Aria-Logs löschen

**In `C:\xampp\mysql\data` löschen:**
- `aria_log_control`
- Alle `aria_log.*` Dateien

**Oder automatisch:**
```batch
cd C:\xampp\htdocs\TOM3
scripts\mysql-fix-aria-logs.bat
```

### Schritt 3: MySQL starten

- XAMPP Control Panel → MySQL → Start

### Schritt 4: Tabellen reparieren

```batch
cd C:\xampp\mysql\bin
.\mysqlcheck.exe -u root --auto-repair --all-databases
```

## 5. Best Practices für "produktiv genug"

### App-Tabellen auf InnoDB

- ✅ **Verwende InnoDB** für Business-Daten (keine MyISAM/Aria, wenn möglich)
- ✅ **InnoDB ist robuster** und weniger anfällig für Fehler
- ✅ **Bessere Transaktionsunterstützung**

### Backups

#### Regelmäßige Dumps (mysqldump)

```batch
cd C:\xampp\mysql\bin
.\mysqldump.exe -u root --all-databases > C:\xampp\mysql\backup\full_backup_%date:~-4,4%%date:~-7,2%%date:~-10,2%.sql
```

**Oder automatisch:**
```batch
cd C:\xampp\htdocs\TOM3
scripts\mysql-backup.bat
```

#### Dateibasierte Sicherung

**WICHTIG:** Nur bei gestopptem MySQL!

1. MySQL stoppen (`mysqladmin shutdown`)
2. Gesamten `C:\xampp\mysql\data` Ordner kopieren
3. MySQL starten

**NICHT im laufenden Betrieb** - kann zu Inkonsistenzen führen!

## 6. Automatisierung (Optional)

### Scheduled Tasks einrichten

Für automatische Wartung:

```batch
cd C:\xampp\htdocs\TOM3
scripts\setup-scheduled-tasks.bat
```

Dies richtet ein:
- **MySQL-Auto-Recovery:** Läuft beim Systemstart
- **MySQL-Daily-Backup:** Läuft täglich um 02:00 Uhr

## 7. Verfügbare Scripts

Nach dem Portieren stehen folgende Scripts zur Verfügung:

| Script | Funktion |
|--------|----------|
| `scripts\mysql-health-check.ps1` | **Umfassender Health-Check** (empfohlen nach Portierung) |
| `scripts\mysql-health-check.bat` | Batch-Wrapper für Health-Check |
| `scripts\mysql-fix-aria-logs.bat` | Löscht Aria-Logs (bei Aria-Fehlern) |
| `scripts\mysql-fix-aria-and-plugin.bat` | Repariert Aria-Logs und mysql.plugin |
| `scripts\mysql-repair-tables.bat` | Repariert alle Tabellen |
| `scripts\mysql-diagnose-and-fix.bat` | Umfassende Diagnose und Reparatur |
| `scripts\ensure-mysql-running.bat` | Prüft und startet MySQL |
| `scripts\mysql-backup.bat` | Erstellt Datenbank-Backup |

### Health-Check Script (empfohlen)

Das `mysql-health-check.ps1` Script führt einen umfassenden Check durch:

**Basis-Check (nur Prüfung, keine Änderungen):**
```powershell
cd C:\xampp\htdocs\TOM3
.\scripts\mysql-health-check.ps1
```

**Mit Reparatur/Upgrade (nur wenn nötig):**
```powershell
.\scripts\mysql-health-check.ps1 -DoRepairMysqlSystem -DoUpgrade -DoCheckAllDatabases
```

**Mit Passwort-Abfrage (falls root ein Passwort hat):**
```powershell
.\scripts\mysql-health-check.ps1 -PromptForPassword
```

**Was wird geprüft:**
- ✅ MariaDB Connectivity (mysqladmin ping)
- ✅ Port 3306 Verwendung
- ✅ Error-Log auf kritische Patterns (Aria, mysql.plugin, etc.)
- ✅ my.ini auf empfohlene Stabilitäts-Settings
- ✅ Optional: mysqlcheck (Systemtabellen / alle DBs)
- ✅ Optional: mysql_upgrade

**Hinweis:** Standardmäßig macht es nur Checks (keine Änderungen). Reparatur/Upgrade nur mit Switches.

## 8. Checkliste nach Portierung

- [ ] Defender/AV Ausschlüsse gesetzt
- [ ] Port 3306 frei (keine andere MySQL-Instanz)
- [ ] `my.ini` angepasst (alle Pflicht-Einstellungen)
- [ ] MySQL sauber gestartet
- [ ] `mysqladmin ping` erfolgreich
- [ ] Error-Log ohne Recovery-Fehler
- [ ] App getestet (5-10 Minuten)
- [ ] Sauberer Stop/Start-Zyklus erfolgreich
- [ ] Systemtabellen geprüft (`mysqlcheck`)
- [ ] `mysql_upgrade` ausgeführt (bei Version-Änderung)
- [ ] Backups eingerichtet

## 9. Fehlerbehebung

### MySQL startet nicht

1. Prüfe Error-Log: `C:\xampp\mysql\data\mysql_error.log`
2. Führe aus: `scripts\mysql-fix-aria-logs.bat`
3. Prüfe Port 3306: `netstat -an | findstr :3306`
4. Prüfe `my.ini` auf Syntax-Fehler

### "Aria recovery failed"

Siehe Abschnitt 4: Notfall-Prozedur

### "Could not open mysql.plugin table"

```batch
scripts\mysql-fix-aria-and-plugin.bat
```

### Tabellen beschädigt

```batch
scripts\mysql-repair-tables.bat
```

## 10. Weitere Dokumentation

- `docs/MYSQL-CONFIG-CHANGES.md` - Detaillierte Konfigurationsänderungen
- `docs/MYSQL-IMPROVED-CONFIG.md` - Vollständige optimierte Konfiguration
- `docs/MYSQL-MAINTENANCE.md` - Wartung und Fehlerbehebung
- `docs/MYSQL-STARTUP-FIX.md` - Lösung für häufige Startprobleme

## Zusammenfassung

Nach dem Portieren auf ein neues System:

1. ✅ **Vorbereitung:** Ausschlüsse setzen, Port prüfen
2. ✅ **Konfiguration:** `my.ini` anpassen (Pflicht-Einstellungen)
3. ✅ **Start-Check:** MySQL starten, Logs prüfen
4. ✅ **App-Test:** Mehrere Minuten testen
5. ✅ **Sauberer Zyklus:** Stop/Start testen
6. ✅ **Health-Check:** Systemtabellen prüfen/reparieren
7. ✅ **Backups:** Einrichten

**Bei Problemen:** Siehe Abschnitt 4 (Notfall-Prozedur) und verfügbare Scripts.
