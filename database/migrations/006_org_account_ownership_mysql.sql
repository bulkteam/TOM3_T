-- TOM3 - Account Ownership
-- Fügt Account-Verantwortung pro Organisation hinzu

-- ============================================================================
-- ACCOUNT OWNERSHIP (strategische Verantwortung pro Organisation)
-- ============================================================================
ALTER TABLE org
ADD COLUMN account_owner_user_id VARCHAR(255) NULL COMMENT 'Verantwortlicher Account Owner (strategisch)',
ADD COLUMN account_owner_since DATE NULL COMMENT 'Seit wann ist dieser Owner verantwortlich',
ADD INDEX idx_org_account_owner (account_owner_user_id);

-- Kommentar
ALTER TABLE org 
MODIFY COLUMN account_owner_user_id VARCHAR(255) NULL 
COMMENT 'Verantwortlicher Account Owner (strategisch). Pflicht ab Status "prospect"';

-- ============================================================================
-- ACCOUNT TEAM (M:N - Co-Owner, Support, etc.)
-- ============================================================================
CREATE TABLE org_account_team (
    team_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL COMMENT 'co_owner | support | backup | technical',
    since_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    until_date DATE NULL,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    UNIQUE KEY unique_org_account_team (org_uuid, user_id, role, since_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_account_team_org ON org_account_team(org_uuid);
CREATE INDEX idx_org_account_team_user ON org_account_team(user_id);
CREATE INDEX idx_org_account_team_active ON org_account_team(org_uuid, until_date);

