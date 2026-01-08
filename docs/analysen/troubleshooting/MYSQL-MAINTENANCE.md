# MySQL/MariaDB Wartung und Fehlerbehebung

## Warum kommt es zu Aria-Fehlern?

### Häufige Ursachen:

1. **Unerwartetes Herunterfahren**
   - Windows-Neustart ohne sauberes MySQL-Shutdown
   - Stromausfall oder Systemabsturz
   - Task Manager: MySQL-Prozess beendet

2. **Festplattenprobleme**
   - Voller Datenträger
   - Langsame/defekte Festplatte
   - Dateisystem-Fehler

3. **Speicherprobleme**
   - Zu wenig RAM
   - Speicher-Überlauf während Transaktionen
   - Swap-Datei-Probleme

4. **Konfigurationsprobleme**
   - Zu kleine Buffer-Pools
   - Falsche InnoDB-Einstellungen
   - Aria-Log-Dateien werden nicht korrekt geschrieben

5. **Gleichzeitige Zugriffe**
   - Zu viele gleichzeitige Verbindungen
   - Lange laufende Transaktionen
   - Deadlocks

## Ist das besorgniserregend?

**Ja, wenn es regelmäßig passiert!** 

- ✅ **Einmalig**: Normal nach unerwartetem Shutdown
- ⚠️ **Wiederholt**: Hinweis auf tieferliegendes Problem
- 🚨 **Häufig**: Risiko für Datenverlust

## Präventive Maßnahmen

### 1. Verbesserte MySQL-Konfiguration

Die aktuelle `my.ini` ist sehr minimal. Erweitere sie mit robusteren Einstellungen.

### 2. Automatisches Recovery-Skript

Ein Skript, das bei jedem Start prüft und repariert.

### 3. Regelmäßige Backups

Automatische Backups vor kritischen Operationen.

### 4. Monitoring

Überwachung der MySQL-Logs und automatische Benachrichtigung bei Fehlern.

## Empfohlene Maßnahmen

1. **MySQL-Konfiguration optimieren** (siehe `MYSQL-IMPROVED-CONFIG.md`)
2. **Automatisches Recovery-Skript einrichten** (`scripts/mysql-auto-recovery.bat`)
3. **Regelmäßige Backups konfigurieren** (`scripts/mysql-backup.bat`)
4. **MySQL-Logs überwachen**

## Sofortige Maßnahmen

### 1. Automatisches Recovery einrichten

**Option A: Manuell vor jedem Start**
- Führe `scripts/mysql-auto-recovery.bat` aus, bevor du MySQL startest

**Option B: Als Scheduled Task**
1. Öffne "Aufgabenplanung" (Task Scheduler)
2. Erstelle neue Aufgabe
3. Trigger: "Beim Anmelden" oder "Beim Starten des Computers"
4. Aktion: `C:\xampp\htdocs\TOM3_T\scripts\mysql-auto-recovery.bat`
5. Als Administrator ausführen

### 2. Regelmäßige Backups

- Führe `scripts/mysql-backup.bat` täglich aus
- Oder als Scheduled Task einrichten
- Backups werden in `C:\xampp\mysql\backup\` gespeichert
- Alte Backups (>7 Tage) werden automatisch gelöscht

### 3. MySQL-Konfiguration verbessern

**WICHTIG: Die folgenden Einstellungen wurden bereits in `C:\xampp\mysql\bin\my.ini` angewendet:**

- ✅ `key_buffer_size=16M` (statt veraltetem `key_buffer`)
- ✅ `max_allowed_packet=16M` (für größere Datenpakete)
- ✅ `internal_tmp_disk_storage_engine=InnoDB` (weniger Aria-Abhängigkeit)
- ✅ `aria_recover_options=BACKUP,QUICK` (automatische Aria-Reparatur)
- ✅ `myisam_recover_options=BACKUP,FORCE` (automatische MyISAM-Reparatur)

Diese Einstellungen reduzieren Aria-Probleme erheblich und verbessern die Stabilität.

Für weitere Optimierungen siehe `docs/MYSQL-IMPROVED-CONFIG.md`.

## Monitoring

### Logs prüfen

```powershell
# Letzte 50 Zeilen des Error-Logs
Get-Content C:\xampp\mysql\data\mysql_error.log -Tail 50

# Nach Aria-Fehlern suchen
Select-String -Path C:\xampp\mysql\data\mysql_error.log -Pattern "Aria|aria_chk"
```

### MySQL-Status prüfen

```sql
SHOW STATUS LIKE 'Uptime';
SHOW STATUS LIKE 'Threads_connected';
SHOW STATUS LIKE 'Slow_queries';
```

