-- ============================================================================
-- TOM3 Migration 034: User Person Access Tabelle erstellen
-- ============================================================================
-- Erstellt die user_person_access Tabelle für Tracking von Personenzugriffen
-- (analog zu user_org_access für Organisationen)
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_person_access (
    access_uuid CHAR(36) PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    person_uuid CHAR(36) NOT NULL,
    access_type VARCHAR(50) NOT NULL COMMENT 'recent | favorite | tag',
    tag_name VARCHAR(100),
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    INDEX idx_user_person_user (user_id),
    INDEX idx_user_person_person (person_uuid),
    INDEX idx_user_person_type (user_id, access_type, accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_person_access COMMENT = 'Tracking von Personenzugriffen (recent, favorite, tags)';
