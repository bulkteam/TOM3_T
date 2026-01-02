-- TOM3 - CRM Import Validation Rules (Versioniert)
-- Erstellt Tabelle für versionierte Validierungsregeln

-- ============================================================================
-- VALIDATION RULE SET (Versioniert)
-- ============================================================================
CREATE TABLE validation_rule_set (
    rule_set_id VARCHAR(50) PRIMARY KEY COMMENT 'z.B. v1.0, v1.1',
    version VARCHAR(20) NOT NULL,
    rules_json JSON NOT NULL COMMENT 'Regeln als JSON',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_validation_rule_active ON validation_rule_set(is_active);

-- ============================================================================
-- DEFAULT DATA: Validation Rule Set v1.0
-- ============================================================================
INSERT INTO validation_rule_set (rule_set_id, version, rules_json, description) VALUES
('v1.0', '1.0', 
JSON_OBJECT(
    'required_fields', JSON_ARRAY('name'),
    'format_validations', JSON_OBJECT(
        'postal_code', JSON_OBJECT('type', 'postal_code_de', 'required', false),
        'email', JSON_OBJECT('type', 'email', 'required', false),
        'website', JSON_OBJECT('type', 'url', 'required', false)
    ),
    'geodata_validation', JSON_OBJECT(
        'enabled', true,
        'check_postal_code_city_match', true
    ),
    'phone_validation', JSON_OBJECT(
        'enabled', true,
        'check_area_code', true
    )
),
'Standard-Validierungsregeln für Org-Import v1.0');
