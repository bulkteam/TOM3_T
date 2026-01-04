# Dokumenten-Scan - Timing & Auto-Refresh

## Scan-Dauer

### Wie lange dauert das Prüfen?

**Typische Scan-Zeit:**
- **Kleine Dateien (< 1MB):** 1-5 Sekunden
- **Mittlere Dateien (1-10MB):** 5-15 Sekunden
- **Große Dateien (10-50MB):** 15-60 Sekunden
- **Sehr große Dateien (> 50MB):** 1-5 Minuten

**Faktoren, die die Scan-Zeit beeinflussen:**
- Dateigröße (größer = länger)
- ClamAV-Last (wenn viele Scans parallel laufen)
- System-Performance
- Netzwerk-Latenz (wenn ClamAV in Docker läuft)

### Scan-Worker-Intervall

**Aktuell:**
- Scan-Worker läuft **alle 5 Minuten** (Windows Task Scheduler)
- Verarbeitet bis zu 10 Jobs pro Durchlauf (konfigurierbar)

**Das bedeutet:**
- **Best Case:** Scan startet sofort nach Upload (wenn Worker gerade läuft) → 1-60 Sekunden
- **Worst Case:** Scan startet bis zu 5 Minuten nach Upload → dann noch 1-60 Sekunden Scan-Zeit
- **Typisch:** 2-6 Minuten Gesamtzeit (Upload → Scan abgeschlossen)

## Auto-Refresh im Frontend

### Automatische Status-Aktualisierung

**Implementiert:** ✅ Ja

**Funktionsweise:**
- Wenn Dokumente mit Status "pending" vorhanden sind, startet automatisch ein Auto-Refresh
- **Intervall:** Alle 10 Sekunden
- **Maximale Dauer:** 5 Minuten (dann stoppt Auto-Refresh)
- **Automatisches Stoppen:** Sobald alle Dokumente nicht mehr "pending" sind

**Vorteile:**
- ✅ Kein manuelles Neuladen nötig
- ✅ Status aktualisiert sich automatisch
- ✅ Stoppt automatisch, wenn alle Scans abgeschlossen sind

### Manuelles Neuladen

**Falls Auto-Refresh nicht aktiv ist:**
- Tab wechseln (z.B. zu "Grunddaten" und zurück zu "Dokumente")
- Seite neu laden (F5)
- Dokument hochladen (lädt Liste automatisch neu)

## Scan-Worker-Konfiguration

### Aktuelle Einstellungen

**Datei:** `scripts/jobs/scan-blob-worker.php`

**Parameter:**
- `maxJobsPerRun`: 10 (Standard)
- `verbose`: false (Standard)

**Aufruf:**
```bash
# Manuell (mit Output)
php scripts/jobs/scan-blob-worker.php --verbose

# Mit mehr Jobs pro Durchlauf
php scripts/jobs/scan-blob-worker.php --max-jobs=20
```

### Task Scheduler

**Konfiguration:**
- **Intervall:** Alle 5 Minuten
- **Script:** `scripts/jobs/scan-blob-worker.php`
- **Max Jobs:** 10 pro Durchlauf

**Prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ClamAV-Scan-Worker" | Get-ScheduledTaskInfo
```

## Optimierungen (später)

### Schnelleres Scannen

**Optionen:**
1. **Kürzeres Worker-Intervall:** Alle 1-2 Minuten statt 5 Minuten
   - Vorteil: Schnellere Reaktion
   - Nachteil: Mehr Last auf ClamAV

2. **Mehr Jobs pro Durchlauf:** 20-50 statt 10
   - Vorteil: Mehr parallele Scans
   - Nachteil: Höhere ClamAV-Last

3. **Sofort-Scan für kleine Dateien:** < 5MB direkt beim Upload scannen
   - Vorteil: Sofort verfügbar
   - Nachteil: Upload dauert länger

### Monitoring

**Im Monitoring-Dashboard:**
- Anzahl ausstehender Scan-Jobs
- Durchschnittliche Scan-Zeit
- Anzahl hängender Jobs (> 10 Minuten)

**Siehe:** `docs/CLAMAV-IMPLEMENTATION-COMPLETE.md`

## Zusammenfassung

**Aktuell:**
- ⏱️ **Typische Scan-Zeit:** 2-6 Minuten (Upload → Scan abgeschlossen)
- 🔄 **Auto-Refresh:** Alle 10 Sekunden, wenn "pending"-Dokumente vorhanden
- 📊 **Worker-Intervall:** Alle 5 Minuten

**Benutzer-Erfahrung:**
- ✅ Status aktualisiert sich automatisch
- ✅ Kein manuelles Neuladen nötig
- ✅ Badge ändert sich automatisch von "Wird geprüft..." zu "✓ Verfügbar"

**Später (Production):**
- ⏳ Kürzeres Worker-Intervall (1-2 Minuten)
- ⏳ Sofort-Scan für kleine Dateien
- ⏳ Monitoring & Alerting


