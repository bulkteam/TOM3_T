-- ============================================================================
-- TOM3 Migration 036: Dokumenten-/Upload-Service
-- ============================================================================
-- Zentraler Service für Datei-Uploads mit Deduplication, Versionierung,
-- Security-Scanning und Integration in alle TOM3-Entitäten.
-- ============================================================================

-- ============================================================================
-- Tabelle: blobs
-- ============================================================================
-- Speichert exakten Dateiinhalt (dedupliziert, immutable)
-- ============================================================================

CREATE TABLE blobs (
    blob_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    sha256 CHAR(64) NOT NULL COMMENT 'SHA-256 Hash (hex)',
    size_bytes BIGINT UNSIGNED NOT NULL,
    mime_detected VARCHAR(255) COMMENT 'MIME-Type (Magic Bytes)',
    storage_key VARCHAR(512) NOT NULL COMMENT 'Pfad: storage/{tenant_id}/{sha256[0:2]}/{sha256[2:4]}/{sha256}',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    
    -- Security
    scan_status ENUM('pending', 'clean', 'infected', 'unsupported', 'error') DEFAULT 'pending',
    scan_engine VARCHAR(50) COMMENT 'z.B. clamav, custom',
    scan_at DATETIME,
    scan_result JSON COMMENT 'Details vom Scanner',
    quarantine_reason TEXT COMMENT 'Warum blockiert',
    
    -- Metadaten
    file_extension VARCHAR(20) COMMENT 'Original-Endung',
    original_filename VARCHAR(255) COMMENT 'Original-Name (nur für Audit)',
    
    INDEX idx_tenant_sha256_size (tenant_id, sha256, size_bytes),
    UNIQUE KEY uk_tenant_sha256_size (tenant_id, sha256, size_bytes) COMMENT 'Dedup-Constraint',
    INDEX idx_scan_status (scan_status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Tabelle: documents
-- ============================================================================
-- Business-Metadaten + Verknüpfung zu Blob
-- ============================================================================

CREATE TABLE documents (
    document_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    current_blob_uuid CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    
    -- Klassifikation
    classification ENUM('invoice', 'quote', 'contract', 'email_attachment', 'other') DEFAULT 'other',
    
    -- Versionierung
    version_group_uuid CHAR(36) COMMENT 'UUID für Version-Gruppe (alle Versionen haben gleiche UUID)',
    version_number INT UNSIGNED DEFAULT 1,
    is_current_version BOOLEAN DEFAULT TRUE,
    
    -- Quelle
    source_type ENUM('upload', 'email', 'api', 'import') DEFAULT 'upload',
    source_metadata JSON COMMENT 'z.B. email_message_id, parser_job_id',
    
    -- Metadaten
    tags JSON COMMENT 'Array von Tags',
    notes TEXT,
    
    -- Status
    status ENUM('active', 'blocked', 'deleted') DEFAULT 'active',
    
    -- Extraktion
    extracted_text LONGTEXT COMMENT 'Volltext (PDF, DOCX, etc.)',
    extraction_status ENUM('pending', 'done', 'failed') DEFAULT 'pending',
    extraction_meta JSON COMMENT 'Sprache, Seitenzahl, etc.',
    
    -- Audit
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_current_blob (current_blob_uuid),
    INDEX idx_version_group (version_group_uuid),
    INDEX idx_classification (classification),
    INDEX idx_created_at (created_at),
    FULLTEXT idx_extracted_text (extracted_text) COMMENT 'Für Volltext-Suche',
    FULLTEXT idx_title (title) COMMENT 'Für Titel-Suche',
    FOREIGN KEY (current_blob_uuid) REFERENCES blobs(blob_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Tabelle: document_attachments
-- ============================================================================
-- Verknüpfung Document ↔ Entität (Org, Person, Case, Project, etc.)
-- ============================================================================

CREATE TABLE document_attachments (
    attachment_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    document_uuid CHAR(36) NOT NULL,
    
    -- Verknüpfung zu Entität
    entity_type ENUM('org', 'person', 'case', 'project', 'task', 'email_message', 'email_thread') NOT NULL,
    entity_uuid CHAR(36) NOT NULL COMMENT 'UUID der Entität',
    
    -- Kontext
    role VARCHAR(50) COMMENT 'z.B. invoice, contract, supporting_doc',
    description TEXT COMMENT 'Optional: Beschreibung',
    
    -- Audit
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    
    INDEX idx_entity (tenant_id, entity_type, entity_uuid),
    INDEX idx_document (document_uuid),
    UNIQUE KEY uk_entity_document (entity_type, entity_uuid, document_uuid) COMMENT 'Verhindert Duplikate',
    FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Tabelle: document_audit_trail
-- ============================================================================
-- Audit-Log für Compliance
-- ============================================================================

CREATE TABLE document_audit_trail (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
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

-- ============================================================================
-- Tabelle: document_versions (optional, für saubere Versionierung)
-- ============================================================================
-- Alternative: documents selbst enthält Versionierung (empfohlen für MVP)
-- Diese Tabelle ist optional und kann später hinzugefügt werden
-- ============================================================================

-- CREATE TABLE document_versions (
--     version_uuid CHAR(36) PRIMARY KEY,
--     document_uuid CHAR(36) NOT NULL,
--     blob_uuid CHAR(36) NOT NULL,
--     version_number INT UNSIGNED NOT NULL,
--     created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     created_by_user_id INT UNSIGNED,
--     
--     INDEX idx_document (document_uuid),
--     INDEX idx_blob (blob_uuid),
--     FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE,
--     FOREIGN KEY (blob_uuid) REFERENCES blobs(blob_uuid) ON DELETE RESTRICT
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
