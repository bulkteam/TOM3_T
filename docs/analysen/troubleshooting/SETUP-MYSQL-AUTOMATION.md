# MySQL Automatisierung - Setup-Anleitung

## ✅ Was wurde bereits eingerichtet:

1. ✅ **Backup der MySQL-Konfiguration** erstellt
   - `C:\xampp\mysql\data\my.ini.backup`
   - `C:\xampp\mysql\bin\my.ini.backup`

2. ✅ **Verbesserte MySQL-Konfiguration** angewendet
   - Optimierte Aria-Einstellungen
   - Bessere InnoDB-Konfiguration
   - Verbesserte Stabilität

3. ✅ **Recovery-Skript** erstellt
   - `scripts/mysql-auto-recovery.bat`
   - `scripts/mysql-auto-recovery.ps1`

4. ✅ **Backup-Skript** erstellt
   - `scripts/mysql-backup.bat`

5. ✅ **Scheduled Task Setup-Skript** erstellt
   - `scripts/setup-scheduled-tasks.bat`

## 📋 Nächste Schritte:

### Schritt 1: MySQL neu starten (mit neuer Konfiguration)

1. **Stoppe MySQL** über XAMPP Control Panel
2. **Starte MySQL** über XAMPP Control Panel
3. Prüfe ob MySQL erfolgreich startet

**Falls MySQL nicht startet:**
- Wiederherstellen der alten Konfiguration:
  ```batch
  Copy-Item C:\xampp\mysql\data\my.ini.backup C:\xampp\mysql\data\my.ini -Force
  ```

### Schritt 2: Recovery-Skript testen

Führe manuell aus:
```batch
C:\xampp\htdocs\TOM3_T\scripts\mysql-auto-recovery.bat
```

**Oder** verwende das neue Start-Skript mit Auto-Recovery:
```batch
C:\xampp\mysql_start_with_recovery.bat
```

### Schritt 3: Backup-Skript testen

1. Stelle sicher, dass MySQL läuft
2. Führe aus:
```batch
C:\xampp\htdocs\TOM3_T\scripts\mysql-backup.bat
```
3. Prüfe ob Backup erstellt wurde:
   - `C:\xampp\mysql\backup\tom_backup_YYYYMMDD_HHMMSS.sql`

### Schritt 4: Scheduled Tasks einrichten (Optional, aber empfohlen)

**Als Administrator ausführen:**
```batch
C:\xampp\htdocs\TOM3_T\scripts\setup-scheduled-tasks.bat
```

Dies erstellt:
- **MySQL-Auto-Recovery**: Läuft beim Systemstart
- **MySQL-Daily-Backup**: Läuft täglich um 02:00 Uhr

**Manuell prüfen:**
1. Windows-Taste + R
2. `taskschd.msc` eingeben
3. Nach "MySQL-Auto-Recovery" und "MySQL-Daily-Backup" suchen

## 🔧 Alternative: Manuelle Integration in XAMPP

Falls du das Recovery-Skript direkt in XAMPP integrieren möchtest:

1. Öffne `C:\xampp\mysql_start.bat`
2. Füge am Anfang hinzu:
```batch
call "C:\xampp\htdocs\TOM3_T\scripts\mysql-auto-recovery.bat"
```

## 📊 Monitoring

### Logs prüfen:
```powershell
# MySQL Error Log
Get-Content C:\xampp\mysql\data\mysql_error.log -Tail 50

# Nach Aria-Fehlern suchen
Select-String -Path C:\xampp\mysql\data\mysql_error.log -Pattern "Aria|aria_chk"
```

### Backup-Verzeichnis prüfen:
```powershell
Get-ChildItem C:\xampp\mysql\backup\ | Sort-Object LastWriteTime -Descending
```

## ⚠️ Wichtige Hinweise

1. **MySQL muss gestoppt sein** bevor die Konfiguration geändert wird
2. **Backup vor Änderungen** immer erstellen
3. **Bei Problemen**: Alte Konfiguration wiederherstellen
4. **Scheduled Tasks** benötigen Administrator-Rechte

## 🆘 Fehlerbehebung

### MySQL startet nicht nach Konfigurationsänderung:
```batch
# Wiederherstellen der alten Konfiguration
Copy-Item C:\xampp\mysql\data\my.ini.backup C:\xampp\mysql\data\my.ini -Force
```

### Recovery-Skript findet keine Dateien:
- Prüfe ob Pfad korrekt ist: `C:\xampp\mysql\data\`
- Prüfe ob MySQL gestoppt ist

### Backup schlägt fehl:
- Prüfe MySQL-Benutzer/Passwort in `scripts/mysql-backup.bat`
- Stelle sicher, dass MySQL läuft
- Prüfe ob Backup-Verzeichnis existiert: `C:\xampp\mysql\backup\`




