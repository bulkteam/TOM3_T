# ClamAV - Behandlung infizierter Dateien

## Was passiert mit infizierten Uploads?

### Aktueller Flow (MVP)

**1. Upload:**
- Datei wird hochgeladen
- Blob wird erstellt (`scan_status = 'pending'`)
- Scan-Job wird in `outbox_event` eingefügt
- Dokument ist sofort sichtbar (Status: "Wird geprüft...")

**2. Scan (asynchron):**
- Worker scannt Blob mit ClamAV
- Wenn **infiziert**:
  - `blobs.scan_status` wird auf `'infected'` gesetzt
  - `blobs.scan_result` enthält Threat-Details (JSON)
  - **Alle zugehörigen Documents werden blockiert** (`status = 'blocked'`)

**3. Blockierung:**
- Dokumente mit `status = 'blocked'` sind:
  - ✅ In der Liste sichtbar (mit Warnung)
  - ❌ **NICHT downloadbar** (Download-Endpunkt prüft `scan_status = 'clean'`)
  - ❌ Nicht löschbar (über normalen Delete-Flow)

**4. Datei bleibt im Storage:**
- Die Datei bleibt in `storage/{tenant}/...` gespeichert
- **Keine automatische Löschung** (für Forensik/Review)
- **Keine Quarantäne** (aktuell)

### Was passiert NICHT (aktuell)

- ❌ Datei wird nicht automatisch gelöscht
- ❌ Datei wird nicht in Quarantäne verschoben
- ❌ Admin wird nicht benachrichtigt
- ❌ Datei wird nicht aus Storage entfernt

### Was passiert (aktuell)

- ✅ Dokument wird blockiert (`status = 'blocked'`)
- ✅ Download ist nicht möglich
- ✅ Scan-Ergebnis wird gespeichert (`scan_result` JSON)
- ✅ Threat-Namen werden gespeichert

## Monitoring-Integration

### Status-Anzeige

**Im Monitoring-Dashboard:**
- ClamAV-Status-Card (läuft / Fehler)
- Virendefinitionen-Alter (aktuell / veraltet)
- Anzahl ausstehender Scans
- Anzahl sauberer Dateien
- **⚠️ Anzahl infizierter Dateien** (wenn > 0)

### Infizierte Dateien-Liste

**Wenn infizierte Dateien vorhanden:**
- Liste wird automatisch angezeigt
- Zeigt für jede infizierte Datei:
  - Titel/Dateiname
  - Erkannte Bedrohungen (Threat-Namen)
  - Upload-Zeit
  - Scan-Zeit
  - Uploader (User-ID)
  - Status: "Datei ist blockiert und nicht downloadbar"

## Quarantäne (später - Production)

**Geplant für Production** (siehe `docs/DOCUMENT-SECURITY-ROADMAP.md`):

**Flow:**
1. Upload → `storage/quarantine/` (nicht direkt in `storage/{tenant}/`)
2. Scan läuft asynchron
3. Bei `clean`: Verschieben nach `storage/{tenant}/...`
4. Bei `infected`: Löschen oder in `storage/quarantine/infected/` isolieren
5. Download nur aus `storage/{tenant}/`, nie aus `quarantine/`

**Vorteile:**
- Kein Zugriff auf potenziell infizierte Dateien
- Klare Trennung: Quarantäne vs. Clean
- Bessere Sicherheit

## Aktuelle Implementierung

### Code-Stellen

**1. Scan-Worker** (`scripts/jobs/scan-blob-worker.php`):
```php
// Wenn infected: Blockiere alle zugehörigen Documents
if ($scanResult['status'] === 'infected') {
    $this->blockDocumentsForBlob($blobUuid, $scanResult);
}
```

**2. Blockierung** (`blockDocumentsForBlob`):
```php
UPDATE documents
SET status = 'blocked'
WHERE current_blob_uuid = :blob_uuid
  AND status = 'active'
```

**3. Download-Blockierung** (`DocumentService::getDownloadUrl`):
```php
if ($document['scan_status'] !== 'clean') {
    throw new \RuntimeException('Document not scanned or infected');
}
```

## Admin-Aktionen (manuell)

**Aktuell müssen Admins manuell handeln:**

1. **Infizierte Datei löschen:**
   - Über UI: Dokument löschen (wenn möglich)
   - Oder: Direkt in DB `status = 'deleted'` setzen
   - Oder: Blob aus Storage löschen

2. **Datei prüfen:**
   - Scan-Ergebnis in `blobs.scan_result` (JSON) ansehen
   - Threat-Namen prüfen
   - Entscheiden: False Positive oder echte Bedrohung

3. **Datei freigeben (False Positive):**
   - `blobs.scan_status = 'clean'` setzen
   - `documents.status = 'active'` setzen
   - Dokument wird wieder verfügbar

## Empfehlungen

### Für MVP (aktuell)

✅ **Ausreichend:**
- Blockierung funktioniert
- Download ist verhindert
- Monitoring zeigt Infektionen
- Dateien bleiben für Review

### Für Production

⏳ **Empfohlen:**
- Quarantäne-System (siehe Roadmap)
- Admin-Benachrichtigung bei Infected
- Automatische Löschung nach X Tagen (konfigurierbar)
- Audit-Trail für alle Aktionen

## Monitoring-Endpunkte

### GET /api/monitoring/clamav

**Response:**
```json
{
  "available": true,
  "version": "ClamAV 1.5.1/27863/...",
  "update_status": {
    "status": "current",
    "last_update": "2026-01-01 14:30:00",
    "age_hours": 2.5
  },
  "worker_status": {
    "status": "ok",
    "message": "Läuft",
    "recent_processed": 5,
    "stuck_jobs": 0
  },
  "scan_statistics": {
    "total": 100,
    "pending": 2,
    "clean": 95,
    "infected": 3,
    "scans_24h": 15,
    "pending_jobs": 2
  },
  "infected_files": [
    {
      "document_uuid": "...",
      "title": "Rechnung.pdf",
      "original_filename": "rechnung.pdf",
      "created_at": "2026-01-01 10:00:00",
      "created_by_user_id": "user123",
      "scan_at": "2026-01-01 10:05:00",
      "threats": ["Trojan.Generic.123456"],
      "message": "Threat detected: Trojan.Generic.123456"
    }
  ]
}
```

### GET /api/monitoring/status

**Enthält jetzt auch:**
```json
{
  "clamav": {
    "status": "ok",
    "message": "Läuft",
    "available": true,
    "version": "ClamAV 1.5.1/27863/...",
    "update_status": {...},
    "worker_status": {...},
    "infected_count": 3
  }
}
```

## Zusammenfassung

**Aktuell (MVP):**
- ✅ Infizierte Dateien werden blockiert
- ✅ Download ist verhindert
- ✅ Monitoring zeigt Infektionen
- ⚠️ Dateien bleiben im Storage (keine automatische Löschung)
- ⚠️ Keine Quarantäne (Dateien sind in normalem Storage)

**Später (Production):**
- ⏳ Quarantäne-System
- ⏳ Automatische Löschung
- ⏳ Admin-Benachrichtigung


