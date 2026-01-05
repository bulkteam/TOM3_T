# TOM3 - Zentraler Dokumenten-/Upload-Service Konzept

## Übersicht

Zentraler Service für Datei-Uploads und Dokumenten-Management mit Deduplication, Versionierung, Security-Scanning und Integration in alle TOM3-Entitäten (Orgs, Persons, Cases, Projects, etc.).

## Architektur-Prinzipien

### 1. Blob ≠ Document (Trennung von Inhalt und Kontext)

**Blob (Content Addressable Storage):**
- Repräsentiert exakten Dateiinhalt
- Eindeutig über SHA-256 Hash identifiziert
- Immutable (niemals überschrieben)
- Deduplication auf dieser Ebene

**Document (Metadaten + Business-Kontext):**
- Titel, Tags, Klassifikation
- Versionierung
- OCR-Text, Extraktionsfelder
- Besitzer, Sichtbarkeit

**Attachment (Verknüpfung zu Entitäten):**
- Many-to-Many: Ein Blob kann in vielen Vorgängen hängen
- Ein Vorgang kann viele Dokumente haben
- Kontext: "hochgeladen von User X", "aus E-Mail Y", "zu Case Z"

### 2. Deduplication über Unique Index

**Performance:**
- Unique Index auf `(tenant_id, sha256, size_bytes)`
- O(1) Lookup statt O(n) Scan
- Race Conditions durch DB Constraint abgefangen

**Multi-Tenancy:**
- Dedup nur innerhalb Tenant (Privacy-Schutz)
- Kein Hash-Existenz-Leak zwischen Tenants

### 3. Zweistufiger Upload-Flow

**Phase 1: Upload + Dedup (synchron, schnell)**
- Client lädt Datei hoch
- Server berechnet Hash während Stream
- DB-Lookup: Blob existiert?
- Ja: Temp löschen, bestehenden Blob verwenden
- Nein: Blob anlegen, Document erstellen, Attachment verknüpfen

**Phase 2: Asynchrone Verarbeitung (enrichment)**
- Malware-Scan
- MIME-Validierung (Magic Bytes)
- Text-Extraktion (PDF, DOCX, etc.)
- OCR (falls Bild)
- Klassifikation/Tagging
- Indexing für Suche

## Datenmodell (MySQL/MariaDB)

### Tabelle: `blobs`

Speichert exakten Dateiinhalt (dedupliziert, immutable).

```sql
CREATE TABLE blobs (
    blob_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,  -- Für Multi-Tenancy (aktuell: immer 1)
    sha256 CHAR(64) NOT NULL,          -- SHA-256 Hash (hex)
    size_bytes BIGINT UNSIGNED NOT NULL,
    mime_detected VARCHAR(255),        -- MIME-Type (Magic Bytes)
    storage_key VARCHAR(512) NOT NULL, -- Pfad: storage/{tenant_id}/{sha256[0:2]}/{sha256[2:4]}/{sha256}
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    
    -- Security
    scan_status ENUM('pending', 'clean', 'infected', 'unsupported', 'error') DEFAULT 'pending',
    scan_engine VARCHAR(50),            -- z.B. 'clamav', 'custom'
    scan_at DATETIME,
    scan_result JSON,                   -- Details vom Scanner
    quarantine_reason TEXT,             -- Warum blockiert
    
    -- Metadaten
    file_extension VARCHAR(20),        -- Original-Endung
    original_filename VARCHAR(255),     -- Original-Name (nur für Audit)
    
    INDEX idx_tenant_sha256_size (tenant_id, sha256, size_bytes),
    UNIQUE KEY uk_tenant_sha256_size (tenant_id, sha256, size_bytes),  -- Dedup-Constraint
    INDEX idx_scan_status (scan_status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabelle: `documents`

Business-Metadaten + Verknüpfung zu Blob.

```sql
CREATE TABLE documents (
    document_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    current_blob_uuid CHAR(36) NOT NULL,  -- FK -> blobs.blob_uuid
    title VARCHAR(255) NOT NULL,
    
    -- Klassifikation
    classification ENUM('invoice', 'quote', 'contract', 'email_attachment', 'other') DEFAULT 'other',
    
    -- Versionierung
    version_group_uuid CHAR(36),          -- FK -> document_groups.group_uuid
    version_number INT UNSIGNED DEFAULT 1,
    supersedes_document_uuid CHAR(36),    -- FK -> documents.document_uuid (Vorgänger-Version)
    is_current_version BOOLEAN DEFAULT TRUE,
    
    -- Quelle
    source_type ENUM('upload', 'email', 'api', 'import') DEFAULT 'upload',
    source_metadata JSON,                 -- z.B. email_message_id, parser_job_id
    
    -- Metadaten
    tags JSON,                            -- Array von Tags
    notes TEXT,
    
    -- Status
    status ENUM('active', 'blocked', 'deleted') DEFAULT 'active',
    
    -- Extraktion
    extracted_text LONGTEXT,              -- Volltext (PDF, DOCX, etc.)
    extraction_status ENUM('pending', 'done', 'failed') DEFAULT 'pending',
    extraction_meta JSON,                 -- Sprache, Seitenzahl, etc.
    
    -- Audit
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_current_blob (current_blob_uuid),
    INDEX idx_version_group (version_group_uuid),
    INDEX idx_classification (classification),
    INDEX idx_created_at (created_at),
    FULLTEXT idx_extracted_text (extracted_text),  -- Für Volltext-Suche
    FOREIGN KEY (current_blob_uuid) REFERENCES blobs(blob_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabelle: `document_attachments`

Verknüpfung Document ↔ Entität (Org, Person, Case, Project, etc.).

```sql
CREATE TABLE document_attachments (
    attachment_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    document_uuid CHAR(36) NOT NULL,     -- FK -> documents.document_uuid
    
    -- Verknüpfung zu Entität
    entity_type ENUM('org', 'person', 'case', 'project', 'task', 'email_message', 'email_thread') NOT NULL,
    entity_uuid CHAR(36) NOT NULL,       -- UUID der Entität
    
    -- Kontext
    role VARCHAR(50),                     -- z.B. 'invoice', 'contract', 'supporting_doc'
    description TEXT,                     -- Optional: Beschreibung
    
    -- Audit
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    
    INDEX idx_entity (tenant_id, entity_type, entity_uuid),
    INDEX idx_document (document_uuid),
    UNIQUE KEY uk_entity_document (entity_type, entity_uuid, document_uuid),  -- Verhindert Duplikate
    FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabelle: `document_versions` (optional, für saubere Versionierung)

Alternativ: `documents` selbst enthält Versionierung (empfohlen für MVP).

```sql
-- Optional: Separate Version-Tabelle
CREATE TABLE document_versions (
    version_uuid CHAR(36) PRIMARY KEY,
    document_uuid CHAR(36) NOT NULL,     -- FK -> documents.document_uuid
    blob_uuid CHAR(36) NOT NULL,         -- FK -> blobs.blob_uuid
    version_number INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    
    INDEX idx_document (document_uuid),
    INDEX idx_blob (blob_uuid),
    FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE,
    FOREIGN KEY (blob_uuid) REFERENCES blobs(blob_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabelle: `document_audit_trail`

Audit-Log für Compliance.

```sql
CREATE TABLE document_audit_trail (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    document_uuid CHAR(36) NOT NULL,
    blob_uuid CHAR(36),
    action ENUM('upload', 'attach', 'detach', 'delete', 'block', 'unblock', 'version_create', 'download', 'preview') NOT NULL,
    user_id INT UNSIGNED,
    entity_type VARCHAR(50),
    entity_uuid CHAR(36),
    metadata JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_document (document_uuid),
    INDEX idx_blob (blob_uuid),
    INDEX idx_created_at (created_at),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Service-Architektur

### DocumentService (Haupt-Service)

```php
namespace TOM\Service;

class DocumentService extends BaseEntityService
{
    /**
     * Upload-Flow: Phase 1 (synchron)
     * 
     * @param array $fileData $_FILES['file'] oder Stream-Daten
     * @param array $metadata title, tags, classification, etc.
     * @return array document_uuid, attachment_uuid, status
     */
    public function uploadDocument(array $fileData, array $metadata): array;
    
    /**
     * Upload abschließen + Dedup-Commit
     * 
     * @param string $uploadId Temporäre Upload-ID
     * @param array $metadata
     * @return array document_uuid, attachment_uuid
     */
    public function completeUpload(string $uploadId, array $metadata): array;
    
    /**
     * Attachment erstellen (verknüpft Document mit Entität)
     * 
     * @param string $documentUuid
     * @param string $entityType 'org', 'person', 'case', etc.
     * @param string $entityUuid
     * @param array $metadata role, description, etc.
     * @return array attachment_uuid
     */
    public function attachDocument(string $documentUuid, string $entityType, string $entityUuid, array $metadata = []): array;
    
    /**
     * Dokument an Entität anhängen (Upload + Attachment in einem)
     * 
     * @param array $fileData
     * @param string $entityType
     * @param string $entityUuid
     * @param array $metadata
     * @return array document_uuid, attachment_uuid
     */
    public function uploadAndAttach(array $fileData, string $entityType, string $entityUuid, array $metadata = []): array;
    
    /**
     * Dokument abrufen
     * 
     * @param string $documentUuid
     * @return array|null
     */
    public function getDocument(string $documentUuid): ?array;
    
    /**
     * Dokumente einer Entität abrufen
     * 
     * @param string $entityType
     * @param string $entityUuid
     * @param bool $activeOnly
     * @return array
     */
    public function getEntityDocuments(string $entityType, string $entityUuid, bool $activeOnly = true): array;
    
    /**
     * Download-URL generieren (nur wenn scan_status = clean)
     * 
     * @param string $documentUuid
     * @return string|null Download-URL oder null wenn blockiert
     */
    public function getDownloadUrl(string $documentUuid): ?string;
    
    /**
     * Dokument löschen (soft delete)
     * 
     * @param string $documentUuid
     * @return bool
     */
    public function deleteDocument(string $documentUuid): bool;
    
    /**
     * Attachment entfernen
     * 
     * @param string $attachmentUuid
     * @return bool
     */
    public function detachDocument(string $attachmentUuid): bool;
    
    /**
     * Neue Version erstellen
     * 
     * @param string $documentUuid Original-Dokument
     * @param array $fileData Neue Datei
     * @param array $metadata
     * @return array Neue document_uuid
     */
    public function createVersion(string $documentUuid, array $fileData, array $metadata = []): array;
}
```

### BlobService (Content Management)

```php
namespace TOM\Service\Document;

class BlobService
{
    /**
     * Blob aus Datei erstellen (mit Dedup-Check)
     * 
     * @param string $filePath Temporärer Pfad
     * @param array $metadata original_filename, mime, etc.
     * @return array blob_uuid, is_new (true wenn neu erstellt)
     */
    public function createBlobFromFile(string $filePath, array $metadata): array;
    
    /**
     * Blob abrufen
     * 
     * @param string $blobUuid
     * @return array|null
     */
    public function getBlob(string $blobUuid): ?array;
    
    /**
     * Blob-Existenz prüfen (für Dedup)
     * 
     * @param string $sha256
     * @param int $sizeBytes
     * @return string|null blob_uuid wenn existiert
     */
    public function findBlobByHash(string $sha256, int $sizeBytes): ?string;
    
    /**
     * Blob-Datei abrufen (für Download)
     * 
     * @param string $blobUuid
     * @return string|null Dateipfad oder null
     */
    public function getBlobFilePath(string $blobUuid): ?string;
    
    /**
     * Blob-Referenzzählung (wie viele Documents verwenden diesen Blob)
     * 
     * @param string $blobUuid
     * @return int
     */
    public function getBlobReferenceCount(string $blobUuid): int;
}
```

### DocumentProcessingService (Async Jobs)

```php
namespace TOM\Service\Document;

class DocumentProcessingService
{
    /**
     * Malware-Scan enqueuen
     * 
     * @param string $blobUuid
     * @return void
     */
    public function enqueueScan(string $blobUuid): void;
    
    /**
     * Text-Extraktion enqueuen
     * 
     * @param string $documentUuid
     * @return void
     */
    public function enqueueExtraction(string $documentUuid): void;
    
    /**
     * Klassifikation enqueuen
     * 
     * @param string $documentUuid
     * @return void
     */
    public function enqueueClassification(string $documentUuid): void;
    
    /**
     * Scan durchführen (Worker)
     * 
     * @param string $blobUuid
     * @return array scan_status, scan_result
     */
    public function scanBlob(string $blobUuid): array;
    
    /**
     * Text extrahieren (Worker)
     * 
     * @param string $documentUuid
     * @return array extracted_text, extraction_meta
     */
    public function extractText(string $documentUuid): array;
}
```

## API-Endpunkte

### Upload-Endpunkte

```
POST /api/documents/upload
Content-Type: multipart/form-data
Body: file, title, tags[], classification, entity_type, entity_uuid

Response:
{
  "document_uuid": "...",
  "attachment_uuid": "...",
  "status": "processing",
  "scan_status": "pending",
  "message": "Upload erfolgreich, wird verarbeitet"
}
```

```
POST /api/documents/{document_uuid}/attach
Body: { entity_type, entity_uuid, role?, description? }

Response:
{
  "attachment_uuid": "...",
  "message": "Dokument erfolgreich verknüpft"
}
```

### Abfrage-Endpunkte

```
GET /api/documents/{document_uuid}
Response: Vollständige Document-Daten inkl. Blob-Info, Scan-Status, etc.

GET /api/documents/entity/{entity_type}/{entity_uuid}
Response: Liste aller Documents dieser Entität

GET /api/documents/{document_uuid}/download
Response: 302 Redirect zu temporärer Download-URL (nur wenn scan_status = clean)
```

### Management-Endpunkte

```
DELETE /api/documents/{document_uuid}
Response: { success: true }

DELETE /api/documents/attachments/{attachment_uuid}
Response: { success: true }

POST /api/documents/groups/{group_uuid}/upload-version
Content-Type: multipart/form-data
Body: file, title, classification, tags[], supersede (true/false)

Response:
{
  "document_uuid": "...",
  "version_number": 2,
  "version_group_uuid": "...",
  "status": "processing"
}

GET /api/documents/groups/{group_uuid}
Response: Gruppe mit allen Versionen + aktueller Version
```

## Upload-Flow (Detailliert)

### Phase 1: Upload + Dedup (synchron)

1. **Client sendet Datei:**
   ```
   POST /api/documents/upload
   Content-Type: multipart/form-data
   - file: [binary]
   - title: "Rechnung Dezember 2025"
   - entity_type: "org"
   - entity_uuid: "..."
   ```

2. **Server verarbeitet:**
   - Datei in temporären Ordner speichern: `storage/tmp/{upload_uuid}`
   - Während Stream: SHA-256 Hash berechnen
   - Dateigröße ermitteln
   - Magic Bytes prüfen (MIME-Type)

3. **Dedup-Check:**
   ```php
   $existingBlob = $blobService->findBlobByHash($sha256, $sizeBytes);
   if ($existingBlob) {
       // Blob existiert bereits
       unlink($tempFile);
       $blobUuid = $existingBlob;
   } else {
       // Neuer Blob
       $storagePath = "storage/{tenant_id}/{sha256[0:2]}/{sha256[2:4]}/{sha256}";
       rename($tempFile, $storagePath);
       $blob = $blobService->createBlob($sha256, $sizeBytes, $storagePath, ...);
       $blobUuid = $blob['blob_uuid'];
   }
   ```

4. **Document + Attachment erstellen:**
   ```php
   $document = $documentService->createDocument([
       'current_blob_uuid' => $blobUuid,
       'title' => $title,
       'classification' => $classification,
       ...
   ]);
   
   $attachment = $documentService->attachDocument(
       $document['document_uuid'],
       $entityType,
       $entityUuid
   );
   ```

5. **Jobs enqueuen:**
   ```php
   $processingService->enqueueScan($blobUuid);
   $processingService->enqueueExtraction($document['document_uuid']);
   ```

6. **Response:**
   ```json
   {
     "document_uuid": "...",
     "attachment_uuid": "...",
     "status": "processing",
     "scan_status": "pending",
     "extraction_status": "pending"
   }
   ```

### Phase 2: Asynchrone Verarbeitung

**Worker: Malware-Scan**

```php
// Job: scan.blob:{blob_uuid}
public function scanBlob(string $blobUuid): void
{
    $blob = $this->blobService->getBlob($blobUuid);
    
    // Idempotency: Wenn bereits gescannt, skip
    if (in_array($blob['scan_status'], ['clean', 'infected', 'unsupported'])) {
        return;
    }
    
    try {
        $filePath = $this->blobService->getBlobFilePath($blobUuid);
        
        // ClamAV Scan (oder anderer Scanner)
        $result = $this->clamavService->scan($filePath);
        
        // Update Blob
        $this->db->prepare("
            UPDATE blobs 
            SET scan_status = :status,
                scan_engine = 'clamav',
                scan_at = NOW(),
                scan_result = :result
            WHERE blob_uuid = :uuid
        ")->execute([
            'uuid' => $blobUuid,
            'status' => $result['status'], // 'clean' | 'infected'
            'result' => json_encode($result)
        ]);
        
        // Wenn infected: Blockiere alle Documents
        if ($result['status'] === 'infected') {
            $this->blockDocumentsForBlob($blobUuid);
            // Audit + Alert
        }
        
    } catch (Exception $e) {
        // Retry-Logik
        $this->db->prepare("
            UPDATE blobs 
            SET scan_status = 'error',
                scan_result = :error
            WHERE blob_uuid = :uuid
        ")->execute([
            'uuid' => $blobUuid,
            'error' => json_encode(['error' => $e->getMessage()])
        ]);
        throw $e; // Für Retry
    }
}
```

**Worker: Text-Extraktion**

```php
// Job: extract.document:{document_uuid}
public function extractText(string $documentUuid): void
{
    $document = $this->documentService->getDocument($documentUuid);
    $blob = $this->blobService->getBlob($document['current_blob_uuid']);
    
    // Idempotency
    if ($document['extraction_status'] === 'done') {
        return;
    }
    
    // Nur wenn Blob clean
    if ($blob['scan_status'] !== 'clean') {
        return;
    }
    
    try {
        $filePath = $this->blobService->getBlobFilePath($blob['blob_uuid']);
        $mime = $blob['mime_detected'];
        
        $text = '';
        $meta = [];
        
        if ($mime === 'application/pdf') {
            $extractor = new PdfTextExtractor();
            $text = $extractor->extract($filePath);
            $meta = $extractor->getMetadata(); // Seitenzahl, Sprache, etc.
        } elseif (in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword'])) {
            $extractor = new DocxTextExtractor();
            $text = $extractor->extract($filePath);
        } elseif (in_array($mime, ['image/png', 'image/jpeg', 'image/tiff'])) {
            // OCR
            $extractor = new OcrExtractor();
            $text = $extractor->extract($filePath);
            $meta['language'] = $extractor->detectLanguage($text);
        }
        
        // Update Document
        $this->db->prepare("
            UPDATE documents 
            SET extracted_text = :text,
                extraction_status = 'done',
                extraction_meta = :meta
            WHERE document_uuid = :uuid
        ")->execute([
            'uuid' => $documentUuid,
            'text' => $text,
            'meta' => json_encode($meta)
        ]);
        
        // Index für Suche (optional: Search Engine)
        $this->indexDocument($documentUuid, $text);
        
    } catch (Exception $e) {
        $this->db->prepare("
            UPDATE documents 
            SET extraction_status = 'failed',
                extraction_meta = :error
            WHERE document_uuid = :uuid
        ")->execute([
            'uuid' => $documentUuid,
            'error' => json_encode(['error' => $e->getMessage()])
        ]);
    }
}
```

## Security-Implementierung

### Filetype-Validierung (Magic Bytes)

```php
class FileTypeValidator
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'image/png',
        'image/jpeg',
        'image/gif',
        'text/plain',
        'text/csv'
    ];
    
    private const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar'
    ];
    
    public function validate(string $filePath, string $originalFilename): array
    {
        // Magic Bytes prüfen
        $mime = $this->detectMimeType($filePath);
        
        // Extension prüfen
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        
        if (in_array($ext, self::BLOCKED_EXTENSIONS)) {
            throw new \InvalidArgumentException("Dateityp nicht erlaubt: .{$ext}");
        }
        
        if (!in_array($mime, self::ALLOWED_MIMES)) {
            throw new \InvalidArgumentException("MIME-Type nicht erlaubt: {$mime}");
        }
        
        // Office-Dateien mit Makros blocken (vereinfacht: prüfe auf .docm, .xlsm)
        if (in_array($ext, ['docm', 'xlsm', 'pptm'])) {
            throw new \InvalidArgumentException("Office-Dateien mit Makros sind nicht erlaubt");
        }
        
        return ['mime' => $mime, 'extension' => $ext];
    }
    
    private function detectMimeType(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mime;
    }
}
```

### Malware-Scan (ClamAV Integration)

```php
class ClamAvService
{
    public function scan(string $filePath): array
    {
        // ClamAV über Socket oder CLI
        $command = sprintf(
            'clamdscan --no-summary --infected --remove %s',
            escapeshellarg($filePath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            return ['status' => 'clean', 'message' => 'No threats found'];
        } elseif ($returnCode === 1) {
            // Infected
            return [
                'status' => 'infected',
                'message' => implode("\n", $output),
                'threats' => $this->parseThreats($output)
            ];
        } else {
            throw new \RuntimeException("ClamAV scan failed: " . implode("\n", $output));
        }
    }
}
```

## Storage-Struktur

```
storage/
├── tmp/                    # Temporäre Uploads
│   └── {upload_uuid}
│
├── {tenant_id}/            # Tenant-isoliert
│   └── {sha256[0:2]}/      # Erste 2 Zeichen (für Performance)
│       └── {sha256[2:4]}/  # Nächste 2 Zeichen
│           └── {sha256}    # Datei (ohne Extension, Hash ist eindeutig)
│
└── quarantine/             # Infizierte Dateien (optional, für Forensik)
    └── {blob_uuid}
```

**Vorteile:**
- Flache Verzeichnisstruktur (keine Millionen Dateien in einem Ordner)
- Hash-basiert (dedup-freundlich)
- Tenant-isoliert

## Integration in TOM3

### UI-Integration

**Org-Detail-View:**
```javascript
// In org-detail-view.js
async showOrgDetail(orgUuid) {
    // ... bestehender Code ...
    
    // Dokumente laden
    const documents = await window.API.getEntityDocuments('org', orgUuid);
    this.renderDocuments(documents);
}

renderDocuments(documents) {
    const container = document.getElementById('org-documents');
    if (!container) return;
    
    container.innerHTML = `
        <div class="documents-section">
            <h3>Dokumente</h3>
            <button id="btn-upload-document" class="btn-primary">
                Dokument hochladen
            </button>
            <div class="documents-list">
                ${documents.map(doc => this.renderDocument(doc)).join('')}
            </div>
        </div>
    `;
    
    // Upload-Button Handler
    document.getElementById('btn-upload-document').addEventListener('click', () => {
        this.showUploadDialog(orgUuid);
    });
}

renderDocument(doc) {
    const statusBadge = doc.scan_status === 'pending' 
        ? '<span class="badge-warning">Wird geprüft...</span>'
        : doc.scan_status === 'clean'
        ? '<span class="badge-success">✓</span>'
        : '<span class="badge-error">Blockiert</span>';
    
    return `
        <div class="document-item">
            <div class="document-icon">📄</div>
            <div class="document-info">
                <strong>${Utils.escapeHtml(doc.title)}</strong>
                <div class="document-meta">
                    ${statusBadge}
                    ${doc.size_bytes ? Utils.formatFileSize(doc.size_bytes) : ''}
                    ${doc.created_at ? new Date(doc.created_at).toLocaleDateString() : ''}
                </div>
            </div>
            <div class="document-actions">
                ${doc.scan_status === 'clean' 
                    ? `<button onclick="window.API.downloadDocument('${doc.document_uuid}')">Download</button>`
                    : '<span class="text-muted">Nicht verfügbar</span>'}
            </div>
        </div>
    `;
}
```

### API-Integration

**Neue API-Endpunkte:**
```
POST   /api/documents/upload
GET    /api/documents/{uuid}
GET    /api/documents/entity/{entity_type}/{entity_uuid}
POST   /api/documents/{uuid}/attach
DELETE /api/documents/attachments/{uuid}
GET    /api/documents/{uuid}/download
POST   /api/documents/{uuid}/versions
```

## Implementierungs-Plan

### Phase 1: MVP (Grundfunktionalität)

1. **Datenmodell erstellen** (Migration)
   - `blobs` Tabelle
   - `documents` Tabelle
   - `document_attachments` Tabelle
   - `document_audit_trail` Tabelle

2. **BlobService** (Dedup + Storage)
   - Hash-Berechnung
   - Storage-Management
   - Dedup-Logik

3. **DocumentService** (CRUD)
   - Upload-Flow
   - Attachment-Management
   - Basis-Abfragen

4. **API-Endpunkte**
   - Upload
   - Abfrage
   - Download (nur clean)

5. **UI-Integration**
   - Upload-Dialog
   - Dokumenten-Liste in Org/Person-Views

### Phase 2: Security

6. **Filetype-Validierung**
   - Magic Bytes
   - Extension-Check
   - Blockliste

7. **Malware-Scan**
   - ClamAV Integration
   - Async Processing
   - Quarantäne-Logik

### Phase 3: Enrichment

8. **Text-Extraktion**
   - PDF
   - DOCX
   - OCR (optional)

9. **Klassifikation**
   - Parser für Rechnungen/Angebote
   - Auto-Tagging

### Phase 4: Erweiterte Features

10. **Versionierung** ✅
    - ✅ Version-Gruppen (Migration 038)
    - ✅ Race-Condition-sichere Version-Erstellung
    - ✅ API-Endpunkte implementiert
    - ⏳ Version-Historie-UI (später)

11. **Suche** ✅
    - ✅ Volltext-Suche (MariaDB FULLTEXT)
    - ✅ Tag-Filter
    - ✅ Entity-Filter
    - ⏳ Erweiterte Filter (später)

12. **Performance-Optimierungen**
    - CDN für Downloads
    - Caching
    - Thumbnail-Generierung

## Offene Fragen / Entscheidungen

1. **Storage-Backend:**
   - Lokales Filesystem (einfach, MVP)
   - S3/MinIO (skalierbar, später)
   - Entscheidung: Start mit Filesystem, später migrierbar

2. **Job-Queue:**
   - Database-basiert (einfach, MVP)
   - Redis/RabbitMQ (skalierbar, später)
   - Entscheidung: Start mit DB-basierter Queue (outbox_event Pattern)

3. **OCR:**
   - Tesseract (lokal, kostenlos)
   - Cloud-Service (Google Vision, AWS Textract)
   - Entscheidung: Tesseract für MVP, später Cloud optional

4. **Search-Engine:**
   - MySQL Full-Text (einfach, MVP)
   - Elasticsearch/OpenSearch (skalierbar, später)
   - Entscheidung: MySQL FTS für MVP

## Nächste Schritte

1. Migration erstellen (Tabellen-Schema)
2. BlobService implementieren
3. DocumentService implementieren
4. API-Endpunkte erstellen
5. UI-Integration (Upload-Dialog)
6. Security-Layer (Filetype + Scan)
7. Async Processing (Jobs)

---

*Konzept erstellt: 2026-01-01*


