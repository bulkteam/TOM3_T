-- TOM3 - CRM Import Duplicate Candidates
-- Erstellt Tabelle für Duplikat-Kandidaten (separater Review-Workflow)

-- ============================================================================
-- IMPORT DUPLICATE CANDIDATES
-- ============================================================================
CREATE TABLE import_duplicate_candidates (
    candidate_uuid CHAR(36) PRIMARY KEY,
    staging_uuid CHAR(36) NOT NULL,
    candidate_org_uuid CHAR(36) NOT NULL COMMENT 'Bestehende Org in Produktion',
    
    -- Match-Informationen
    match_score DECIMAL(5,2) NOT NULL COMMENT '0.00 - 100.00',
    match_reason_json JSON COMMENT '{
        "name_match": 0.95,
        "domain_match": 0.90,
        "postal_code_match": 0.85,
        "phone_match": 0.80
    }',
    
    -- Entscheidung
    decision VARCHAR(50) COMMENT 'NEW | LINK_EXISTING | MERGE | SKIP',
    decided_by_user_id VARCHAR(255),
    decided_at DATETIME,
    decision_notes TEXT,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (staging_uuid) REFERENCES org_import_staging(staging_uuid) ON DELETE CASCADE,
    FOREIGN KEY (candidate_org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_duplicate_staging ON import_duplicate_candidates(staging_uuid);
CREATE INDEX idx_duplicate_org ON import_duplicate_candidates(candidate_org_uuid);
CREATE INDEX idx_duplicate_decision ON import_duplicate_candidates(decision);
CREATE INDEX idx_duplicate_score ON import_duplicate_candidates(match_score);
