# TOM3 - Dokumenten-Upload-Service Implementierungsplan

## Übersicht

Dieses Dokument beschreibt den schrittweisen Implementierungsplan für den zentralen Dokumenten-Upload-Service in TOM3.

## Architektur-Entscheidungen

### ✅ Getroffene Entscheidungen

1. **Storage:** Lokales Filesystem (MVP), später migrierbar zu S3/MinIO
2. **Job-Queue:** Database-basiert (outbox_event Pattern, bereits vorhanden)
3. **OCR:** Tesseract für MVP (optional, später)
4. **Search:** MySQL Full-Text für MVP, später Elasticsearch optional
5. **Dedup:** SHA-256 + size_bytes (byte-identical)
6. **Versionierung:** ✅ `document_groups` Tabelle + `supersedes_document_uuid` (Migration 038)

### 📋 Offene Entscheidungen

1. **Malware-Scan:**
   - ClamAV (lokal, kostenlos) - empfohlen für MVP
   - Oder: Cloud-Service (später)

2. **Max. Dateigröße:**
   - Empfehlung: 50MB für MVP
   - Später: Konfigurierbar pro Tenant/User

3. **Thumbnail-Generierung:**
   - Für Bilder: Ja (später)
   - Für PDFs: Optional (später)

## Implementierungs-Phasen

### Phase 1: MVP - Grundfunktionalität (Priorität: Hoch)

**Ziel:** Upload, Speicherung, Dedup, Basis-Abfragen

#### 1.1 Datenmodell (Migration 036 + 038)

- [x] Migration-Script erstellt (`036_document_upload_service_mysql.sql`)
- [x] Migration 036 ausgeführt
- [x] Versionierung-Migration erstellt (`038_document_versioning_mysql.sql`)
- [x] Migration 038 ausgeführt

#### 1.2 Storage-Struktur

```
storage/
├── tmp/                    # Temporäre Uploads
│   └── {upload_uuid}
│
└── {tenant_id}/            # Tenant-isoliert
    └── {sha256[0:2]}/      # Erste 2 Zeichen
        └── {sha256[2:4]}/  # Nächste 2 Zeichen
            └── {sha256}    # Datei
```

- [ ] Storage-Verzeichnisse erstellen
- [ ] Storage-Service (Pfad-Generierung, Cleanup)

#### 1.3 BlobService

**Datei:** `src/TOM/Service/Document/BlobService.php`

**Funktionen:**
- `createBlobFromFile()` - Hash berechnen, Dedup-Check, Storage
- `findBlobByHash()` - Dedup-Lookup
- `getBlob()` - Blob abrufen
- `getBlobFilePath()` - Dateipfad für Download
- `getBlobReferenceCount()` - Referenzzählung

- [x] BlobService implementiert
- [x] Streaming Hash-Berechnung (optimiert)
- [x] Race-Condition-Handling

#### 1.4 DocumentService ✅

**Datei:** `src/TOM/Service/DocumentService.php`

**Funktionen:**
- ✅ `uploadAndAttach()` - Upload + Attachment (kombiniert)
- ✅ `createDocument()` - Document erstellen (erstellt automatisch document_group)
- ✅ `createVersion()` - Neue Version erstellen (Race-Condition-sicher)
- ✅ `attachDocument()` - Verknüpfung zu Entität
- ✅ `getDocument()` - Abfrage
- ✅ `getEntityDocuments()` - Dokumente einer Entität
- ✅ `getDocumentVersions()` - Alle Versionen einer Gruppe
- ✅ `getDocumentGroup()` - Gruppe mit aktueller Version
- ✅ `searchDocuments()` - Volltext-Suche
- ✅ `searchDocumentsInTitle()` - Titel-Suche (Fallback)
- ✅ `deleteDocument()` - Soft Delete
- ✅ `detachDocument()` - Attachment entfernen

- [x] DocumentService implementiert
- [x] Integration mit BlobService
- [x] Versionierung implementiert
- [x] Volltext-Suche implementiert
- [x] Audit-Trail-Logging

#### 1.5 API-Endpunkte ✅

**Datei:** `public/api/documents.php`

**Endpunkte:**
- ✅ `POST /api/documents/upload` - Upload + Attachment
- ✅ `GET /api/documents/{uuid}` - Abfrage
- ✅ `GET /api/documents/entity/{entity_type}/{entity_uuid}` - Entität-Dokumente
- ✅ `POST /api/documents/{uuid}/attach` - Verknüpfen
- ✅ `DELETE /api/documents/attachments/{uuid}` - Verknüpfung entfernen
- ✅ `GET /api/documents/{uuid}/download` - Download (nur clean)
- ✅ `GET /api/documents/search?q=...` - Volltext-Suche
- ✅ `POST /api/documents/groups/{uuid}/upload-version` - Neue Version
- ✅ `GET /api/documents/groups/{uuid}` - Gruppe mit Versionen

- [x] API-Endpunkte implementiert
- [x] Error-Handling
- [x] Input-Validation

#### 1.6 UI-Integration

**Dateien:**
- `public/js/modules/document-upload.js` - Upload-Dialog
- `public/js/modules/document-list.js` - Dokumenten-Liste
- Integration in `org-detail-view.js`, `person-detail-view.js`

**Features:**
- Upload-Dialog (Drag & Drop)
- Dokumenten-Liste in Org/Person-Views
- Status-Anzeige (pending, clean, blocked)
- Download-Button (nur wenn clean)

- [ ] Upload-Dialog-Komponente
- [ ] Dokumenten-Liste-Komponente
- [ ] Integration in Org-Detail
- [ ] Integration in Person-Detail

### Phase 2: Security (Priorität: Hoch)

#### 2.1 Filetype-Validierung

**Datei:** `src/TOM/Infrastructure/Document/FileTypeValidator.php`

**Features:**
- Magic Bytes Detection
- Extension-Check
- Blockliste (exe, bat, js, etc.)
- Office-Makro-Erkennung

- [ ] FileTypeValidator implementieren
- [ ] Integration in Upload-Flow
- [ ] Tests

#### 2.2 Malware-Scan (Basis)

**Datei:** `src/TOM/Infrastructure/Document/ClamAvService.php`

**Features:**
- ClamAV Integration (CLI oder Socket)
- Async Processing (Job)
- Status-Update (pending → clean/infected)
- Blockierung bei Infected

- [ ] ClamAV Service implementieren
- [ ] Job-Integration (scan.blob)
- [ ] Status-Update-Logik
- [ ] Blockierung bei Infected

#### 2.3 Quarantäne-Logik

- [ ] Quarantäne-Verzeichnis
- [ ] Blockierte Dokumente nicht downloadbar
- [ ] Admin-Benachrichtigung bei Infected

### Phase 3: Enrichment (Priorität: Mittel)

#### 3.1 Text-Extraktion

**Datei:** `src/TOM/Infrastructure/Document/TextExtractor.php`

**Features:**
- PDF-Text-Extraktion
- DOCX-Text-Extraktion
- Sprache-Erkennung
- Metadaten-Extraktion (Seitenzahl, etc.)

- [ ] PDF-Extraktor (z.B. smalot/pdfparser)
- [ ] DOCX-Extraktor (z.B. PHPWord)
- [ ] Job-Integration (extract.document)
- [ ] Volltext in DB speichern

#### 3.2 OCR (Optional)

**Datei:** `src/TOM/Infrastructure/Document/OcrExtractor.php`

**Features:**
- Tesseract Integration
- Bild-zu-Text
- Sprache-Erkennung

- [ ] Tesseract Service
- [ ] Job-Integration
- [ ] Performance-Optimierung

#### 3.3 Klassifikation (Optional)

**Features:**
- Parser für Rechnungen
- Parser für Angebote
- Auto-Tagging

- [ ] Rechnungs-Parser (später)
- [ ] Angebots-Parser (später)

### Phase 4: Erweiterte Features (Priorität: Niedrig)

#### 4.1 Versionierung ✅

- [x] Version-Gruppen-Logik (Migration 038)
- [x] Race-Condition-sichere Version-Erstellung
- [x] API-Endpunkte für Versionierung
- [ ] Version-Historie-UI (später)
- [ ] "Als neue Version speichern" Feature in UI (später)

#### 4.2 Suche

- [ ] Volltext-Suche (MySQL FTS)
- [ ] Tag-Filter
- [ ] Erweiterte Filter (Datum, Typ, etc.)

#### 4.3 Performance

- [ ] Thumbnail-Generierung (Bilder)
- [ ] PDF-Preview (serverseitig)
- [ ] CDN-Integration (später)

## Technische Details

### Upload-Flow (Detailliert)

```
1. Client → POST /api/documents/upload
   - multipart/form-data
   - file, title, entity_type, entity_uuid

2. Server:
   a) Datei in tmp/ speichern
   b) Während Stream: SHA-256 berechnen
   c) Magic Bytes prüfen (MIME)
   d) Extension prüfen
   e) Dedup-Check: SELECT blob WHERE (sha256, size)
   
3. Wenn Blob existiert:
   - Temp-Datei löschen
   - Bestehenden blob_uuid verwenden
   
4. Wenn Blob neu:
   - Storage-Pfad generieren
   - Datei nach Storage verschieben
   - INSERT blobs(...)
   
5. INSERT documents(...)
6. INSERT document_attachments(...)
7. Jobs enqueuen: scan, extract
8. Response: document_uuid, status

9. Worker: Scan
   - ClamAV scan
   - UPDATE blobs.scan_status
   - Wenn infected: Blockiere Documents
   
10. Worker: Extract
    - Text extrahieren
    - UPDATE documents.extracted_text
```

### Job-Processing

**Queue-System:** Bereits vorhanden (`outbox_event` Pattern)

**Jobs:**
- `scan.blob:{blob_uuid}` - Malware-Scan
- `extract.document:{document_uuid}` - Text-Extraktion
- `classify.document:{document_uuid}` - Klassifikation (später)

**Idempotency:**
- Jeder Job prüft Status vor Verarbeitung
- Wenn bereits verarbeitet → skip

**Retry-Policy:**
- Exponential Backoff
- Max. 3 Versuche
- Dann: failed + Dead Letter

### Security-Checkliste

- [x] Magic Bytes Detection
- [x] Extension-Blockliste
- [x] Office-Makro-Erkennung
- [ ] ClamAV Integration
- [ ] Quarantäne-Logik
- [ ] Serverseitige Preview (später)
- [ ] Sandbox für Processing (später)

### Performance-Optimierungen

- [x] Unique Index für Dedup (O(1))
- [x] Storage-Struktur (flach, Hash-basiert)
- [ ] Streaming-Upload (große Dateien)
- [ ] Thumbnail-Caching
- [ ] CDN für Downloads (später)

## Abhängigkeiten

### PHP-Packages (Composer)

```json
{
    "require": {
        "smalot/pdfparser": "^2.0",  // PDF-Text-Extraktion
        "phpoffice/phpword": "^1.0"  // DOCX-Text-Extraktion
    }
}
```

### System-Anforderungen

- ClamAV (für Malware-Scan)
- Tesseract (optional, für OCR)
- PHP Extensions: `fileinfo`, `hash`, `json`

## Testing-Strategie

### Unit-Tests

- BlobService: Hash-Berechnung, Dedup-Logik
- FileTypeValidator: Magic Bytes, Extension-Check
- DocumentService: CRUD-Operationen

### Integration-Tests

- Upload-Flow (komplett)
- Dedup-Szenario (gleiche Datei 2x hochladen)
- Security-Szenario (infizierte Datei)
- Attachment-Szenario (Dokument an Org/Person)

### E2E-Tests

- UI: Upload-Dialog
- UI: Dokumenten-Liste
- UI: Download (nur clean)

## Migration-Plan

1. **Backup** bestehender Daten
2. **Migration ausführen** (036_document_upload_service_mysql.sql)
3. **Storage-Verzeichnisse erstellen**
4. **Services implementieren**
5. **API-Endpunkte testen**
6. **UI-Integration**
7. **Production-Deployment**

## Rollout-Strategie

### Staging

1. Migration auf Staging
2. Upload-Flow testen
3. Security-Scan testen
4. Performance testen

### Production

1. Migration auf Production
2. Monitoring aktivieren
3. Schrittweise Rollout (zuerst interne User)
4. Dokumentation aktualisieren

## Monitoring & Alerting

### Metriken

- Upload-Rate
- Dedup-Rate (wie viele Duplikate)
- Scan-Dauer
- Infected-Rate
- Storage-Verbrauch

### Alerts

- Infizierte Datei gefunden
- Scan-Service down
- Storage-Quota erreicht
- Extraction-Fehler-Rate hoch

## Dokumentation

- [ ] API-Dokumentation
- [ ] UI-Dokumentation
- [ ] Admin-Dokumentation (Quarantäne, etc.)
- [ ] Developer-Dokumentation

---

*Implementierungsplan erstellt: 2026-01-01*


