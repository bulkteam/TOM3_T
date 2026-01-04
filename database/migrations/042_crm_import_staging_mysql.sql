-- TOM3 - CRM Import Staging (Org)
-- Erstellt Staging-Tabellen für Import-Review-Prozess

-- ============================================================================
-- ORG IMPORT STAGING
-- ============================================================================
CREATE TABLE org_import_staging (
    staging_uuid CHAR(36) PRIMARY KEY,
    import_batch_uuid CHAR(36) NOT NULL,
    row_number INT NOT NULL,
    
    -- Rohdaten (Original Excel-Zeile)
    raw_data JSON COMMENT 'Original Excel-Zeile als JSON',
    
    -- Gemappte Daten
    mapped_data JSON COMMENT 'Gemappte Org-Daten als JSON',
    corrections_json JSON COMMENT 'Manuelle Overrides (Patch)',
    effective_data JSON COMMENT 'Computed: mapped_data + corrections_json',
    
    -- Fingerprints (für Idempotenz)
    row_fingerprint VARCHAR(64) COMMENT 'Hash über normalisierte Schlüsselfelder',
    file_fingerprint VARCHAR(64) COMMENT 'Hash der Datei (für Batch-Idempotenz)',
    
    -- Validierung (System)
    validation_status VARCHAR(50) COMMENT 'valid | warning | error',
    validation_errors JSON COMMENT 'Liste von Validierungsfehlern/Warnungen',
    
    -- Disposition (Mensch)
    disposition VARCHAR(50) DEFAULT 'pending' 
        COMMENT 'pending | approve_new | link_existing | skip | needs_fix',
    reviewed_by_user_id VARCHAR(255),
    reviewed_at DATETIME,
    review_notes TEXT,
    
    -- Import-Status
    import_status VARCHAR(50) DEFAULT 'pending' 
        COMMENT 'pending | imported | failed | skipped',
    imported_org_uuid CHAR(36) COMMENT 'Verknüpfung zur finalen Org',
    imported_at DATETIME,
    failure_reason TEXT COMMENT 'Grund bei fehlgeschlagenem Import',
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (import_batch_uuid) REFERENCES org_import_batch(batch_uuid) ON DELETE CASCADE,
    FOREIGN KEY (imported_org_uuid) REFERENCES org(org_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_staging_batch ON org_import_staging(import_batch_uuid);
CREATE INDEX idx_staging_validation ON org_import_staging(validation_status);
CREATE INDEX idx_staging_disposition ON org_import_staging(disposition);
CREATE INDEX idx_staging_import ON org_import_staging(import_status);
CREATE INDEX idx_staging_fingerprint ON org_import_staging(row_fingerprint);
CREATE UNIQUE INDEX unique_batch_row ON org_import_staging(import_batch_uuid, row_number);

