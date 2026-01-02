-- TOM3 - CRM Import Batch (First-Class Entity)
-- Erstellt Import-Batch-Tabelle für Sandbox/Review-Prozess

-- ============================================================================
-- IMPORT BATCH (First-Class Entity)
-- ============================================================================
CREATE TABLE org_import_batch (
    batch_uuid CHAR(36) PRIMARY KEY,
    
    -- Quelle
    source_type VARCHAR(50) NOT NULL COMMENT 'excel | csv | api | manual',
    filename VARCHAR(255) COMMENT 'Dateiname (bei File-Import)',
    file_hash VARCHAR(64) COMMENT 'SHA-256 Hash der Datei (für Idempotenz)',
    
    -- Mapping
    mapping_template_id VARCHAR(100) COMMENT 'ID des verwendeten Mapping-Templates',
    mapping_config JSON COMMENT 'Tatsächliche Mapping-Konfiguration (kann von Template abweichen)',
    
    -- Status
    status VARCHAR(50) NOT NULL DEFAULT 'DRAFT' 
        COMMENT 'DRAFT | STAGED | IN_REVIEW | APPROVED | IMPORTED | CANCELED',
    
    -- Audit
    uploaded_by_user_id VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    staged_at DATETIME COMMENT 'Wann wurde in Staging importiert',
    reviewed_by_user_id VARCHAR(255) COMMENT 'Wer hat Review durchgeführt',
    reviewed_at DATETIME COMMENT 'Wann wurde Review abgeschlossen',
    approved_by_user_id VARCHAR(255) COMMENT 'Wer hat freigegeben (nur Sales Ops)',
    approved_at DATETIME COMMENT 'Wann wurde freigegeben',
    imported_by_user_id VARCHAR(255) COMMENT 'Wer hat final importiert',
    imported_at DATETIME COMMENT 'Wann wurde final importiert',
    
    -- Statistiken (JSON)
    stats_json JSON COMMENT '{
        "total_rows": 150,
        "valid": 140,
        "warnings": 8,
        "errors": 2,
        "duplicates": 12,
        "imported": 138,
        "skipped": 12
    }',
    
    -- Validierungsregeln
    validation_rule_set_version VARCHAR(50) COMMENT 'Version der Validierungsregeln',
    
    -- Notizen
    notes TEXT COMMENT 'Interne Notizen zum Import',
    
    -- Metadaten
    metadata_json JSON COMMENT 'Zusätzliche Metadaten (z.B. Excel-Sheets, etc.)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_import_batch_status ON org_import_batch(status);
CREATE INDEX idx_import_batch_user ON org_import_batch(uploaded_by_user_id);
CREATE INDEX idx_import_batch_file_hash ON org_import_batch(file_hash);
CREATE INDEX idx_import_batch_created ON org_import_batch(created_at);
