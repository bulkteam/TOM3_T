-- TOM3 - Org VAT Registration (USt-ID Verwaltung)
-- USt-IDs werden an Standorten (Adressen) gespeichert, nicht an Organisationen
-- Eine Organisation kann mehrere USt-IDs haben (für verschiedene EU-Länder)

-- ============================================================================
-- ERWEITERUNG: org_address um location_type und country_code
-- ============================================================================
ALTER TABLE org_address 
ADD COLUMN location_type VARCHAR(50) COMMENT 'HQ | Branch | Subsidiary | SalesOffice | Plant | Warehouse | Other',
ADD COLUMN country_code VARCHAR(2) COMMENT 'ISO 2-stellig (DE, AT, FR, etc.)';

CREATE INDEX idx_org_address_location_type ON org_address(location_type);
CREATE INDEX idx_org_address_country_code ON org_address(country_code);

-- ============================================================================
-- NEUE TABELLE: org_vat_registration
-- ============================================================================
CREATE TABLE org_vat_registration (
    vat_registration_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    address_uuid CHAR(36) NOT NULL COMMENT 'Verknüpfung zur Adresse (Standort)',
    vat_id VARCHAR(50) NOT NULL COMMENT 'USt-ID (z.B. DE123456789, ATU98765432)',
    country_code VARCHAR(2) NOT NULL COMMENT 'ISO 2-stellig (DE, AT, FR, etc.)',
    valid_from DATE NOT NULL COMMENT 'Gültig ab',
    valid_to DATE NULL COMMENT 'Gültig bis (NULL = aktuell gültig)',
    is_primary_for_country TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primäre USt-ID für dieses Land',
    notes TEXT COMMENT 'Zusätzliche Hinweise',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (address_uuid) REFERENCES org_address(address_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_vat_reg_org ON org_vat_registration(org_uuid);
CREATE INDEX idx_vat_reg_address ON org_vat_registration(address_uuid);
CREATE INDEX idx_vat_reg_country ON org_vat_registration(country_code);
CREATE INDEX idx_vat_reg_validity ON org_vat_registration(valid_from, valid_to);
CREATE INDEX idx_vat_reg_primary ON org_vat_registration(country_code, is_primary_for_country);

-- ============================================================================
-- OPTIONAL: Default USt-ID auf Organisationsebene (nur als Fallback)
-- ============================================================================
ALTER TABLE org 
ADD COLUMN default_vat_id VARCHAR(50) COMMENT 'Fallback USt-ID (nur wenn keine Standort-spezifische vorhanden)';

CREATE INDEX idx_org_default_vat_id ON org(default_vat_id);

-- ============================================================================
-- HINWEIS
-- ============================================================================
-- Die USt-ID gehört an den Standort (Adresse), nicht an die Organisation!
-- Eine Organisation kann mehrere USt-IDs haben (für verschiedene EU-Länder).
-- 
-- Beispiel:
-- - Müller GmbH, Hauptsitz Berlin → DE123456789
-- - Müller GmbH, Niederlassung Wien → ATU98765432
-- - Müller GmbH, Niederlassung Paris → FRXX123456789
--
-- org.default_vat_id ist nur ein Fallback und sollte nicht als alleinige
-- Wahrheit verwendet werden.


