# Dokumenten-Scan Implementierung - Aufwandsschätzung

## Aktueller Status

**Was bereits vorhanden ist:**
- ✅ Datenbank-Schema (`scan_status`, `scan_engine`, `scan_at`, `scan_result`)
- ✅ UI-Anzeige ("Wird geprüft...", "✓ Verfügbar", "⚠ Blockiert")
- ✅ Download-Blockierung bei `scan_status != 'clean'`
- ✅ Status-Badges mit Farben

**Was fehlt:**
- ⏳ ClamAV Service (PHP-Integration)
- ⏳ Job-Queue für asynchrones Scannen
- ⏳ Worker-Script für Scan-Verarbeitung
- ⏳ ClamAV Installation & Konfiguration

## Aufwandsschätzung

### Option 1: Minimaler Scan (MVP) - **~2-3 Stunden**

**Was wird implementiert:**
- ClamAV Service (PHP-Wrapper)
- Einfacher Worker (PHP CLI Script)
- Job-Enqueue beim Upload
- Status-Update nach Scan

**Komponenten:**
1. **ClamAvService.php** (~100 Zeilen)
   - CLI-Aufruf von `clamdscan`
   - Ergebnis-Parsing
   - Error-Handling

2. **Worker-Script** (~50 Zeilen)
   - Liest Jobs aus DB
   - Ruft ClamAvService auf
   - Aktualisiert `scan_status`

3. **Job-Enqueue** (~20 Zeilen)
   - In `DocumentService::uploadAndAttach()`
   - Erstellt Job in `outbox_event` Tabelle

**Voraussetzungen:**
- ClamAV installiert (Windows: ClamWin oder Docker)
- `clamdscan` im PATH oder absoluter Pfad

**Schwierigkeit:** ⭐⭐ (Einfach bis Mittel)

### Option 2: Vollständiger Scan mit Queue - **~4-6 Stunden**

**Zusätzlich zu Option 1:**
- Proper Job-Queue mit Retry-Logik
- Worker mit mehreren Threads/Prozessen
- Monitoring & Logging
- Admin-Benachrichtigung bei Infected

**Schwierigkeit:** ⭐⭐⭐ (Mittel)

### Option 3: Docker-basierte Lösung - **~6-8 Stunden**

**Zusätzlich:**
- ClamAV in Docker-Container
- Service-Discovery
- Health-Checks
- Skalierbarkeit

**Schwierigkeit:** ⭐⭐⭐⭐ (Komplex)

## Empfehlung: Option 1 (MVP)

**Warum:**
- Schnell umsetzbar
- Genügt für den Anfang
- Später erweiterbar

**Implementierungsschritte:**

1. **ClamAV installieren** (Windows)
   - ClamWin installieren ODER
   - ClamAV Docker-Container starten

2. **ClamAvService erstellen** (~1 Stunde)
   ```php
   class ClamAvService {
       public function scan(string $filePath): array {
           // clamdscan aufrufen
           // Ergebnis parsen
           // return ['status' => 'clean'|'infected', ...]
       }
   }
   ```

3. **Job-Enqueue** (~15 Minuten)
   - In `DocumentService::uploadAndAttach()`
   - Job in `outbox_event` einfügen

4. **Worker-Script** (~30 Minuten)
   - PHP CLI Script
   - Liest Jobs, scannt, aktualisiert Status
   - Als Windows Task Scheduler Job

5. **Testing** (~30 Minuten)
   - Test mit sauberer Datei
   - Test mit EICAR-Test-Virus

**Gesamtaufwand:** ~2-3 Stunden

## Alternative: Temporär "clean" setzen

**Aktuell implementiert:**
- Neue Dokumente werden automatisch als `clean` markiert
- Downloads funktionieren sofort
- Scan kann später nachgerüstet werden

**Vorteil:**
- Sofort funktionsfähig
- Keine zusätzliche Infrastruktur nötig

**Nachteil:**
- Keine echte Malware-Erkennung
- Nur Filetype-Validierung (Magic Bytes, Extension)

## Entscheidung

**Status:** ✅ **Option 1 implementiert!**

**Implementiert:**
- ✅ ClamAvService (`src/TOM/Infrastructure/Document/ClamAvService.php`)
- ✅ DocumentService Integration (Job-Enqueue)
- ✅ Scan Worker (`scripts/jobs/scan-blob-worker.php`)
- ✅ Task Scheduler Setup (`scripts/setup-clamav-scan-worker.ps1`)

**Siehe:** `docs/CLAMAV-IMPLEMENTATION-COMPLETE.md` für Details

Soll ich Option 1 jetzt implementieren?

## Update-Management der Virendefinitionen

**Wichtig:** ClamAV benötigt regelmäßige Updates der Virendefinitionen.

**Automatische Updates:**
- **Docker:** FreshClam läuft automatisch im Container (alle 3 Stunden)
- **Windows:** Task Scheduler für `freshclam.exe` (täglich oder alle 6 Stunden)

**Monitoring:**
- Täglich prüfen, ob Updates erfolgreich waren
- Alert bei Definitionen älter als 48 Stunden

**Weitere Informationen:** Siehe `docs/CLAMAV-UPDATE-MANAGEMENT.md`
