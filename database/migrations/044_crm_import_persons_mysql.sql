-- TOM3 - CRM Import Staging (Personen)
-- Erstellt Staging-Tabellen für Personen-Import

-- ============================================================================
-- PERSON IMPORT STAGING
-- ============================================================================
CREATE TABLE person_import_staging (
    staging_uuid CHAR(36) PRIMARY KEY,
    import_batch_uuid CHAR(36) NOT NULL,
    row_number INT NOT NULL,
    
    -- Rohdaten
    raw_data JSON,
    
    -- Gemappte Daten
    mapped_data JSON,
    corrections_json JSON,
    effective_data JSON,
    
    -- Fingerprints
    row_fingerprint VARCHAR(64),
    file_fingerprint VARCHAR(64),
    
    -- Validierung
    validation_status VARCHAR(50) COMMENT 'valid | warning | error',
    validation_errors JSON,
    
    -- Disposition
    disposition VARCHAR(50) DEFAULT 'pending',
    reviewed_by_user_id VARCHAR(255),
    reviewed_at DATETIME,
    
    -- Import-Status
    import_status VARCHAR(50) DEFAULT 'pending',
    imported_person_uuid CHAR(36),
    imported_at DATETIME,
    failure_reason TEXT,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (import_batch_uuid) REFERENCES org_import_batch(batch_uuid) ON DELETE CASCADE,
    FOREIGN KEY (imported_person_uuid) REFERENCES person(person_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_person_staging_batch ON person_import_staging(import_batch_uuid);
CREATE INDEX idx_person_staging_validation ON person_import_staging(validation_status);
CREATE INDEX idx_person_staging_disposition ON person_import_staging(disposition);
CREATE INDEX idx_person_staging_import ON person_import_staging(import_status);
CREATE UNIQUE INDEX unique_person_batch_row ON person_import_staging(import_batch_uuid, row_number);

-- ============================================================================
-- EMPLOYMENT IMPORT STAGING (Join-Klammer)
-- ============================================================================
CREATE TABLE employment_import_staging (
    staging_uuid CHAR(36) PRIMARY KEY,
    import_batch_uuid CHAR(36) NOT NULL,
    
    -- Verknüpfungen (Staging)
    org_staging_uuid CHAR(36) COMMENT 'Verknüpfung zur Org-Staging-Row',
    person_staging_uuid CHAR(36) COMMENT 'Verknüpfung zur Person-Staging-Row',
    
    -- Oder: Finale UUIDs (wenn bereits importiert)
    org_uuid CHAR(36) COMMENT 'Verknüpfung zur finalen Org',
    person_uuid CHAR(36) COMMENT 'Verknüpfung zur finalen Person',
    
    -- Employment-Daten
    job_title VARCHAR(255),
    job_function VARCHAR(100),
    since_date DATE,
    until_date DATE,
    
    -- Disposition
    disposition VARCHAR(50) DEFAULT 'pending',
    
    -- Import-Status
    import_status VARCHAR(50) DEFAULT 'pending',
    imported_employment_uuid CHAR(36) COMMENT 'Verknüpfung zu person_affiliation',
    imported_at DATETIME,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (import_batch_uuid) REFERENCES org_import_batch(batch_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_staging_uuid) REFERENCES org_import_staging(staging_uuid) ON DELETE CASCADE,
    FOREIGN KEY (person_staging_uuid) REFERENCES person_import_staging(staging_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_employment_staging_batch ON employment_import_staging(import_batch_uuid);
CREATE INDEX idx_employment_staging_org ON employment_import_staging(org_staging_uuid, org_uuid);
CREATE INDEX idx_employment_staging_person ON employment_import_staging(person_staging_uuid, person_uuid);

