-- ============================================================================
-- TOM3 Migration 040: User Document Access Tabelle erstellen
-- ============================================================================
-- Erstellt die user_document_access Tabelle für Tracking von Dokumentzugriffen
-- (analog zu user_org_access und user_person_access)
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_document_access (
    access_uuid CHAR(36) PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    document_uuid CHAR(36) NOT NULL,
    access_type VARCHAR(50) NOT NULL COMMENT 'recent | favorite | tag',
    tag_name VARCHAR(100),
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE,
    INDEX idx_user_document_user (user_id),
    INDEX idx_user_document_document (document_uuid),
    INDEX idx_user_document_type (user_id, access_type, accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_document_access COMMENT = 'Tracking von Dokumentzugriffen (recent, favorite, tags)';
