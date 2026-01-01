-- ============================================================================
-- TOM3 Migration 038: Dokumenten-Versionierung
-- ============================================================================
-- Fügt document_groups Tabelle und supersedes_document_uuid Feld hinzu
-- für saubere Versionierung mit Race-Condition-Schutz
-- ============================================================================

-- ============================================================================
-- Tabelle: document_groups
-- ============================================================================
-- Verwaltet Version-Gruppen (alle Versionen eines Dokuments)
-- ============================================================================

CREATE TABLE document_groups (
    group_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    current_document_uuid CHAR(36) NULL COMMENT 'Aktuelle Version',
    title VARCHAR(255) NULL COMMENT 'Titel der Gruppe (wird von aktueller Version übernommen)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_current (tenant_id, current_document_uuid),
    INDEX idx_tenant (tenant_id),
    FOREIGN KEY (current_document_uuid) REFERENCES documents(document_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Erweitere documents Tabelle
-- ============================================================================

ALTER TABLE documents
    ADD COLUMN supersedes_document_uuid CHAR(36) NULL COMMENT 'Vorgänger-Version' AFTER version_number,
    ADD INDEX idx_supersedes (supersedes_document_uuid),
    ADD FOREIGN KEY (supersedes_document_uuid) REFERENCES documents(document_uuid) ON DELETE SET NULL;

-- ============================================================================
-- Index für Version-Queries optimieren
-- ============================================================================

ALTER TABLE documents
    ADD INDEX idx_version_group_number (version_group_uuid, version_number);
