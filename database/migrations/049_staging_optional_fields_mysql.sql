-- TOM3 - Optionale Felder für Import Staging
-- Fügt duplicate_status, duplicate_summary, commit_log hinzu

-- ============================================================================
-- OPTIONALE FELDER FÜR STAGING
-- ============================================================================

-- Duplikat-Status (für schnelle Filterung)
ALTER TABLE org_import_staging 
ADD COLUMN duplicate_status VARCHAR(20) NOT NULL DEFAULT 'unknown' 
    COMMENT 'unknown|none|possible|confirmed' 
    AFTER validation_errors;

-- Duplikat-Zusammenfassung (kurz, Details in import_duplicate_candidates)
ALTER TABLE org_import_staging 
ADD COLUMN duplicate_summary JSON NULL 
    COMMENT 'Kurze Zusammenfassung der Duplikate (z.B. Anzahl, beste Match-Scores)' 
    AFTER duplicate_status;

-- Commit-Log (für Audit-Trail beim finalen Import)
ALTER TABLE org_import_staging 
ADD COLUMN commit_log JSON NULL 
    COMMENT 'Log der Commit-Aktionen (z.B. CREATE_INDUSTRY_LEVEL3, CREATE_ORG)' 
    AFTER imported_at;

-- Index für duplicate_status
CREATE INDEX idx_staging_duplicate_status ON org_import_staging(duplicate_status);

