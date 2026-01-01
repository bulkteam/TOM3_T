# Dokumenten-Sicherheit - Roadmap für Production

## Status: MVP abgeschlossen ✅

**Datum:** 2026-01-01  
**Status:** MVP vollständig implementiert, Production-Vorbereitung geplant

## ✅ MVP - Bereits implementiert

- ✅ ClamAV Integration (Docker)
- ✅ Automatisches Scannen (Worker + Task Scheduler)
- ✅ Status-Anzeige in UI ("Wird geprüft...", "✓ Verfügbar", "⚠ Blockiert")
- ✅ Download-Blockierung bei `scan_status != 'clean'`
- ✅ Filetype-Validierung (Magic Bytes, Extension-Check)
- ✅ Blockliste für riskante Dateitypen
- ✅ Automatische Virendefinition-Updates (FreshClam)

## 🔒 Security-Hardening vor Production

### 1. Quarantäne-System (Hoch)

**Ziel:** Dateien sind erst nach erfolgreichem Scan verfügbar

**Implementierung:**
- Upload → `storage/quarantine/` (nicht direkt in `storage/{tenant}/`)
- Scan läuft asynchron
- Bei `clean`: Verschieben nach `storage/{tenant}/...`
- Bei `infected`: Löschen oder in `storage/quarantine/infected/` isolieren
- Download nur aus `storage/{tenant}/`, nie aus `quarantine/`

**Vorteile:**
- Kein Zugriff auf potenziell infizierte Dateien
- Klare Trennung: Quarantäne vs. Clean
- Bessere Sicherheit

**Aufwand:** ~4-6 Stunden

**Dateien zu ändern:**
- `BlobService::createBlobFromFile()` - Upload nach Quarantäne
- `scan-blob-worker.php` - Verschieben nach Scan
- `DocumentService::getDownloadUrl()` - Nur Clean-Dateien erlauben

### 2. Admin-Benachrichtigung bei Infected (Mittel)

**Ziel:** Admin wird sofort informiert, wenn Malware erkannt wird

**Implementierung:**
- E-Mail-Benachrichtigung an Admin
- Activity-Log-Eintrag mit hoher Priorität
- Optional: Dashboard-Warnung

**Aufwand:** ~2-3 Stunden

**Komponenten:**
- E-Mail-Service (SMTP)
- Activity-Log-Integration
- Admin-Dashboard-Warnung

### 3. Scan-Timeout & Retry-Logik (Mittel)

**Ziel:** Große Dateien nicht hängen lassen, Retry bei Fehlern

**Implementierung:**
- Timeout für Scan (z.B. 5 Minuten)
- Retry-Logik bei Fehlern (max. 3 Versuche)
- Dead-Letter-Queue für fehlgeschlagene Scans
- Admin-Benachrichtigung bei wiederholten Fehlern

**Aufwand:** ~2-3 Stunden

**Dateien zu ändern:**
- `ClamAvService::scan()` - Timeout hinzufügen
- `scan-blob-worker.php` - Retry-Logik
- `outbox_event` - Retry-Counter

### 4. Rate Limiting für Scans (Niedrig)

**Ziel:** ClamAV nicht überlasten

**Implementierung:**
- Max. X Scans gleichzeitig
- Queue-Management
- Priorisierung (kleine Dateien zuerst)

**Aufwand:** ~2 Stunden

### 5. Scan-Status-Monitoring (Mittel)

**Ziel:** Überwachung der Scan-Performance

**Implementierung:**
- Dashboard: Anzahl ausstehender Scans
- Durchschnittliche Scan-Zeit
- Fehlerrate
- Alerts bei zu vielen ausstehenden Scans (> 100)

**Aufwand:** ~3-4 Stunden

**Komponenten:**
- Monitoring-Endpunkt `/api/monitoring/scan-status`
- Dashboard-Widget
- Alert-System

### 6. Erweiterte Filetype-Validierung (Mittel)

**Ziel:** Zusätzliche Sicherheitsschichten

**Implementierung:**
- Office-Makro-Erkennung (tiefere Analyse)
- PDF-Struktur-Validierung
- ZIP-Bomb-Erkennung
- Dateigröße-Limits pro Typ

**Aufwand:** ~4-6 Stunden

**Dateien zu ändern:**
- `FileTypeValidator.php` - Erweiterte Checks
- Optional: Externe Bibliotheken (z.B. `phpoffice/phpword` für Makro-Check)

### 7. Sandbox für Processing (Hoch - später)

**Ziel:** Isolierung von Datei-Verarbeitung

**Implementierung:**
- Text-Extraktion in isoliertem Container
- OCR in isoliertem Container
- Kein direkter Zugriff auf Host-System

**Aufwand:** ~8-12 Stunden (komplex)

**Komponenten:**
- Docker-Container für Processing
- Job-Queue für Processing-Jobs
- Isolierte Umgebung

### 8. Serverseitige Preview (Mittel)

**Ziel:** Keine direkten Browser-Downloads von PDFs

**Implementierung:**
- PDF → Bilder rendern (serverseitig)
- Preview über API-Endpunkt
- Kein direkter Download von Original-PDFs

**Aufwand:** ~6-8 Stunden

**Komponenten:**
- PDF-Rendering-Service (z.B. ImageMagick, Ghostscript)
- Preview-API-Endpunkt
- UI-Integration

### 9. Audit-Trail für Security-Events (Mittel)

**Ziel:** Vollständige Nachverfolgbarkeit

**Implementierung:**
- Alle Scan-Events loggen
- Infected-Dateien: Wer hat hochgeladen? Wann? Von wo?
- Download-Versuche von blockierten Dateien loggen

**Aufwand:** ~2-3 Stunden

**Dateien zu ändern:**
- `scan-blob-worker.php` - Audit-Log bei Infected
- `DocumentService::getDownloadUrl()` - Audit-Log bei Blocked

### 10. Compliance & DSGVO (Hoch - später)

**Ziel:** Rechtliche Anforderungen erfüllen

**Implementierung:**
- Löschkonzept für infizierte Dateien
- Aufbewahrungsfristen
- Datenexport für betroffene User
- Privacy-by-Design

**Aufwand:** ~8-12 Stunden (komplex)

## 📋 Priorisierung für Production

### Phase 1: Kritisch (vor Go-Live)

1. ✅ **Quarantäne-System** - Verhindert Zugriff auf infizierte Dateien
2. ✅ **Admin-Benachrichtigung** - Sofortige Reaktion bei Infected
3. ✅ **Scan-Timeout & Retry** - Zuverlässigkeit

**Geschätzter Aufwand:** ~8-12 Stunden

### Phase 2: Wichtig (kurz nach Go-Live)

4. ✅ **Scan-Status-Monitoring** - Überwachung
5. ✅ **Audit-Trail für Security** - Nachverfolgbarkeit
6. ✅ **Erweiterte Filetype-Validierung** - Zusätzliche Sicherheit

**Geschätzter Aufwand:** ~9-12 Stunden

### Phase 3: Optional (später)

7. ✅ **Rate Limiting** - Performance-Optimierung
8. ✅ **Serverseitige Preview** - Zusätzliche Sicherheit
9. ✅ **Sandbox für Processing** - Isolierung
10. ✅ **Compliance & DSGVO** - Rechtliche Anforderungen

**Geschätzter Aufwand:** ~22-32 Stunden

## 🔍 Testing vor Production

### Security-Tests

- [ ] EICAR-Test-Virus hochladen → Wird erkannt?
- [ ] Infizierte Datei hochladen → Wird blockiert?
- [ ] Download von blockierter Datei → Wird verhindert?
- [ ] Große Datei (> 100MB) → Timeout funktioniert?
- [ ] Viele gleichzeitige Uploads → Rate Limiting funktioniert?

### Performance-Tests

- [ ] 100 Dokumente gleichzeitig hochladen
- [ ] Scan-Zeit messen (Durchschnitt, Max)
- [ ] Worker-Performance unter Last
- [ ] ClamAV-Container unter Last

### Integration-Tests

- [ ] Upload → Scan → Status-Update → Download
- [ ] Infected → Blockierung → Admin-Benachrichtigung
- [ ] Worker-Fehler → Retry → Erfolg
- [ ] ClamAV nicht verfügbar → Graceful Degradation

## 📝 Checkliste vor Production

### Konfiguration

- [ ] ClamAV Container läuft stabil
- [ ] FreshClam aktualisiert automatisch (prüfen: `docker logs tom3-clamav`)
- [ ] Worker läuft als Task Scheduler Job
- [ ] Storage-Verzeichnis korrekt gemountet
- [ ] Logs werden geschrieben

### Monitoring

- [ ] Scan-Status-Dashboard vorhanden
- [ ] Alert-System für Infected-Dateien
- [ ] Log-Rotation konfiguriert
- [ ] Backup-Strategie für Storage

### Dokumentation

- [ ] Admin-Anleitung für Infected-Dateien
- [ ] Troubleshooting-Guide
- [ ] Incident-Response-Prozedur
- [ ] Backup & Restore-Prozedur

### Sicherheit

- [ ] Quarantäne-System aktiv
- [ ] Admin-Benachrichtigung aktiv
- [ ] Audit-Trail vollständig
- [ ] Filetype-Validierung erweitert
- [ ] Rate Limiting aktiv

## 🚀 Go-Live Voraussetzungen

**Minimum (MVP):**
- ✅ ClamAV läuft
- ✅ Automatisches Scannen aktiv
- ✅ Status-Anzeige funktioniert
- ✅ Download-Blockierung aktiv

**Empfohlen (Production):**
- ✅ Quarantäne-System
- ✅ Admin-Benachrichtigung
- ✅ Scan-Timeout & Retry
- ✅ Monitoring-Dashboard

**Optional (später):**
- ⏳ Erweiterte Features (siehe Phase 3)

## 📚 Weitere Ressourcen

- `docs/CLAMAV-IMPLEMENTATION-COMPLETE.md` - Aktuelle Implementierung
- `docs/CLAMAV-DOCKER-INTEGRATION.md` - Docker-Setup
- `docs/CLAMAV-UPDATE-MANAGEMENT.md` - Update-Verwaltung
- `docs/DOCUMENT-SCAN-IMPLEMENTATION.md` - Aufwandsschätzung
