-- TOM3 - Org Metadata (Branche, Größe, etc.)
-- Erweitert Org um zusätzliche Such- und Filterfelder

-- ============================================================================
-- ORG METADATA (Branche, Größe, Umsatz, etc.)
-- ============================================================================
ALTER TABLE org ADD COLUMN industry VARCHAR(100) COMMENT 'Branche / Industrie';
ALTER TABLE org ADD COLUMN revenue_range VARCHAR(50) COMMENT 'Umsatzgröße: micro | small | medium | large | enterprise';
ALTER TABLE org ADD COLUMN employee_count INT COMMENT 'Anzahl Mitarbeiter (ca.)';
ALTER TABLE org ADD COLUMN website VARCHAR(255);
ALTER TABLE org ADD COLUMN notes TEXT COMMENT 'Interne Notizen';
CREATE INDEX idx_org_industry ON org(industry);
CREATE INDEX idx_org_revenue_range ON org(revenue_range);

-- ============================================================================
-- ORG ALIASES (frühere Namen, Handelsnamen, Kürzel)
-- ============================================================================
CREATE TABLE org_alias (
    alias_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    alias_name VARCHAR(255) NOT NULL,
    alias_type VARCHAR(50) COMMENT 'former_name | trade_name | abbreviation | other',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_alias_org ON org_alias(org_uuid);
CREATE INDEX idx_org_alias_name ON org_alias(alias_name);

-- ============================================================================
-- USER ORG ACCESS (Zuletzt verwendet, Favoriten)
-- ============================================================================
CREATE TABLE user_org_access (
    access_uuid CHAR(36) PRIMARY KEY,
    user_id VARCHAR(255) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    access_type VARCHAR(50) NOT NULL COMMENT 'recent | favorite | tag',
    tag_name VARCHAR(100),
    accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_user_org_user ON user_org_access(user_id);
CREATE INDEX idx_user_org_org ON user_org_access(org_uuid);
CREATE INDEX idx_user_org_type ON user_org_access(user_id, access_type, accessed_at);

