# Activity-Log Automatisierung

## Übersicht

Die Activity-Log-Wartung kann vollständig automatisiert werden durch:
1. **Partitionierung:** Automatische Erstellung neuer Partitionen
2. **Archivierung:** Automatische Archivierung alter Einträge
3. **Löschung:** Automatische Löschung sehr alter Archiv-Einträge

## Skripte

### 1. Partitionierung

**Datei:** `scripts/partition-activity-log.php`

**Funktion:**
- Erstellt neue Partitionen für kommende Monate
- Prüft bestehende Partitionen
- Aktiviert Partitionierung bei Bedarf

**Manuelle Ausführung:**
```bash
# Standard (3 Monate im Voraus)
php scripts/partition-activity-log.php

# Custom (6 Monate im Voraus)
php scripts/partition-activity-log.php --months-ahead=6

# Dry-Run (nur anzeigen)
php scripts/partition-activity-log.php --dry-run
```

**Automatisierung:**
```bash
# Cron-Job: Monatlich am 1. Tag um 2:00 Uhr
0 2 1 * * cd /path/to/TOM3 && php scripts/partition-activity-log.php
```

### 2. Archivierung

**Datei:** `scripts/archive-activity-log.php`

**Funktion:**
- Archiviert Einträge älter als X Monate
- Verschiebt Daten in `activity_log_archive`
- Zeigt Statistiken

**Manuelle Ausführung:**
```bash
# Standard (24 Monate Retention)
php scripts/archive-activity-log.php

# Custom Retention
php scripts/archive-activity-log.php --months=12

# Dry-Run
php scripts/archive-activity-log.php --dry-run
```

**Automatisierung:**
```bash
# Cron-Job: Monatlich am 1. Tag um 2:30 Uhr
30 2 1 * * cd /path/to/TOM3 && php scripts/archive-activity-log.php
```

### 3. Kombinierter Wartungs-Job

**Datei:** `scripts/jobs/activity-log-maintenance.php`

**Funktion:**
- Führt alle Wartungsaufgaben in einem Durchlauf aus:
  1. Archivierung alter Einträge
  2. Erstellung neuer Partitionen
  3. Löschung sehr alter Archiv-Einträge
- Schreibt Log-Datei
- Zeigt Statistiken vor/nach Wartung

**Konfiguration:**
```php
$config = [
    'retention_months' => 24,  // Aktiv: 24 Monate
    'archive_delete_years' => 7,  // Archiv löschen nach 7 Jahren
    'partition_months_ahead' => 3,  // Partitionen für nächste 3 Monate
    'dry_run' => false
];
```

**Manuelle Ausführung:**
```bash
php scripts/jobs/activity-log-maintenance.php
```

**Automatisierung:**
```bash
# Cron-Job: Monatlich am 1. Tag um 2:00 Uhr
0 2 1 * * cd /path/to/TOM3 && php scripts/jobs/activity-log-maintenance.php
```

## Cron-Job Setup

### Linux/Unix

**1. Crontab bearbeiten:**
```bash
crontab -e
```

**2. Eintrag hinzufügen:**
```bash
# Activity-Log Wartung: Monatlich am 1. Tag um 2:00 Uhr
0 2 1 * * cd /path/to/TOM3 && php scripts/jobs/activity-log-maintenance.php >> /path/to/TOM3/logs/cron.log 2>&1
```

**3. Log-Verzeichnis erstellen:**
```bash
mkdir -p /path/to/TOM3/logs
chmod 755 /path/to/TOM3/logs
```

### Windows (Task Scheduler)

**1. Task Scheduler öffnen**

**2. Neuen Task erstellen:**
- **Name:** Activity-Log Maintenance
- **Trigger:** Monatlich, am 1. Tag, um 2:00 Uhr
- **Aktion:** Programm starten
  - **Programm:** `C:\xampp\php\php.exe`
  - **Argumente:** `C:\xampp\htdocs\TOM3\scripts\jobs\activity-log-maintenance.php`
  - **Arbeitsverzeichnis:** `C:\xampp\htdocs\TOM3`

**3. Erweiterte Einstellungen:**
- Task auch ausführen, wenn Benutzer nicht angemeldet ist
- Mit höchsten Privilegien ausführen

### Alternative: Separate Skripte

Falls Sie die Wartungsaufgaben getrennt ausführen möchten:

```bash
# Partitionierung: Monatlich am 1. Tag um 2:00 Uhr
0 2 1 * * cd /path/to/TOM3 && php scripts/partition-activity-log.php

# Archivierung: Monatlich am 1. Tag um 2:30 Uhr
30 2 1 * * cd /path/to/TOM3 && php scripts/archive-activity-log.php
```

## Log-Dateien

### Automatische Logs

Der kombinierte Wartungs-Job schreibt automatisch in:
```
logs/activity-log-maintenance.log
```

**Format:**
```
==========================================
Activity-Log Wartungs-Job
Ausgeführt: 2024-01-01 02:00:00
==========================================

Statistiken vor Wartung:
  - Aktive Einträge: 720.000
  - Archivierte Einträge: 1.200.000

1. Archivierung alter Einträge...
   ✓ 15.000 Einträge archiviert.

2. Partitionierung...
   ✓ Partition p202404 erstellt.

3. Löschung alter Archiv-Einträge...
   ✓ 500 sehr alte archivierte Einträge gelöscht.

Statistiken nach Wartung:
  - Aktive Einträge: 705.000
  - Archivierte Einträge: 1.215.000

==========================================
Wartung abgeschlossen.
==========================================
```

### Log-Rotation

Für große Log-Dateien empfohlen:

```bash
# Logrotate-Konfiguration: /etc/logrotate.d/tom3-activity-log
/path/to/TOM3/logs/activity-log-maintenance.log {
    monthly
    rotate 12
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
}
```

## Monitoring

### E-Mail-Benachrichtigungen

Bei Fehlern können E-Mails versendet werden:

```php
// In scripts/jobs/activity-log-maintenance.php
if (/* Fehler aufgetreten */) {
    mail(
        'admin@example.com',
        'Activity-Log Wartung - Fehler',
        $errorMessage
    );
}
```

### Health-Check

Erstellen Sie ein Health-Check-Skript:

```php
// scripts/health-check-activity-log.php
$stats = $archiveService->getStatistics();

// Warnung bei zu vielen aktiven Einträgen
if ($stats['active_count'] > 1000000) {
    echo "WARNUNG: Zu viele aktive Einträge: " . $stats['active_count'];
}

// Warnung bei fehlenden Partitionen
// ...
```

## Best Practices

### 1. Retention-Policy

**Empfehlung:**
- **Aktiv:** 24 Monate (2 Jahre)
- **Archiv:** 5 Jahre
- **Löschung:** Nach 7 Jahren

**Anpassung:**
```php
$config = [
    'retention_months' => 12,  // Kürzer für kleine Systeme
    'archive_delete_years' => 5  // Kürzer für Compliance
];
```

### 2. Ausführungszeit

**Empfehlung:**
- **Zeitpunkt:** Nachts (2:00-3:00 Uhr)
- **Häufigkeit:** Monatlich
- **Tag:** 1. Tag des Monats

**Begründung:**
- Niedrige Systemlast
- Genug Zeit für Wartung
- Konsistente Ausführung

### 3. Backup

**Vor Wartung:**
```bash
# Backup vor Archivierung
mysqldump -u user -p database activity_log > backup_activity_log_$(date +%Y%m%d).sql
```

### 4. Testing

**Dry-Run vor Produktion:**
```bash
# Testen ohne Änderungen
php scripts/jobs/activity-log-maintenance.php --dry-run
```

## Troubleshooting

### Problem: Partitionierung schlägt fehl

**Lösung:**
```bash
# Prüfe bestehende Partitionen
mysql> SHOW CREATE TABLE activity_log;

# Manuell aktivieren
php scripts/partition-activity-log.php
```

### Problem: Archivierung zu langsam

**Lösung:**
- Reduziere Retention (z.B. 12 Monate statt 24)
- Führe Archivierung in kleineren Batches aus
- Optimiere Indizes

### Problem: Log-Datei wird zu groß

**Lösung:**
- Log-Rotation einrichten
- Alte Logs löschen
- Nur Fehler loggen

## Zusammenfassung

✅ **Partitionierung:** Automatisiert per Cron-Job
✅ **Archivierung:** Automatisiert per Cron-Job
✅ **Löschung:** Automatisiert per Cron-Job
✅ **Logging:** Automatisch in Datei
✅ **Monitoring:** Optional per E-Mail

**Empfohlener Setup:**
```bash
# Einmaliger Cron-Job für alle Wartungsaufgaben
0 2 1 * * cd /path/to/TOM3 && php scripts/jobs/activity-log-maintenance.php
```


