-- TOM3 - Import Template System
-- Erstellt Tabellen für wiederverwendbare Mapping-Templates mit automatischer Matching-Metadaten-Generierung

-- ============================================================================
-- IMPORT MAPPING TEMPLATE
-- ============================================================================
CREATE TABLE IF NOT EXISTS import_mapping_template (
    template_uuid CHAR(36) PRIMARY KEY,
    
    -- Basis-Info
    name VARCHAR(120) NOT NULL COMMENT 'Template-Name (z.B. "Standard Excel Import")',
    import_type VARCHAR(30) NOT NULL DEFAULT 'ORG_ONLY' 
        COMMENT 'ORG_ONLY | ORG_WITH_PERSONS | PERSON_ONLY',
    version INT NOT NULL DEFAULT 1 COMMENT 'Template-Version',
    
    -- Mapping-Konfiguration (vollständiges mapping_config JSON)
    mapping_config JSON NOT NULL COMMENT 'Komplettes mapping_config (column_mapping, industry_mapping, etc.)',
    
    -- Matching-Metadaten (automatisch generiert aus mapping_config)
    header_fingerprint CHAR(64) NULL 
        COMMENT 'SHA-256 Hash über normalisierte Header (für schnelle Erkennung)',
    header_fingerprint_v INT NOT NULL DEFAULT 1 
        COMMENT 'Version des Fingerprint-Algorithmus (falls Algorithmus geändert wird)',
    required_targets_json JSON NULL 
        COMMENT 'Liste aller Ziel-Felder mit required=true (z.B. ["org.name"])',
    expected_headers_json JSON NULL 
        COMMENT 'Liste aller normalisierten Header (z.B. ["firmenname","website","telefon"])',
    
    -- Status
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Template aktiv?',
    is_default TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Standard-Template für Import-Typ?',
    
    -- Audit
    created_by_user_id VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_template_type_active ON import_mapping_template(import_type, is_active);
CREATE INDEX idx_template_fp ON import_mapping_template(header_fingerprint);
CREATE INDEX idx_template_default ON import_mapping_template(import_type, is_default);

-- ============================================================================
-- IMPORT HEADER ALIAS (Lernfähigkeit)
-- ============================================================================
CREATE TABLE IF NOT EXISTS import_header_alias (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Zuordnung
    import_type VARCHAR(30) NOT NULL DEFAULT 'ORG_ONLY' 
        COMMENT 'ORG_ONLY | ORG_WITH_PERSONS | PERSON_ONLY',
    target_key VARCHAR(120) NOT NULL 
        COMMENT 'Ziel-Feld (z.B. "org.name", "industry.excel_level2_label")',
    header_alias VARCHAR(255) NOT NULL 
        COMMENT 'Header-Alias (z.B. "Firmenname", "Unternehmen", "Company Name")',
    
    -- Audit
    created_by_user_id VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_alias (import_type, target_key, header_alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_alias_type ON import_header_alias(import_type);
CREATE INDEX idx_alias_target ON import_header_alias(target_key);
