-- ============================================================================
-- TOM3 Migration 028: Person Org Role Tabelle erstellen
-- ============================================================================
-- Erstellt die person_org_role Tabelle für Mandate/Organfunktionen
-- (Geschäftsführer, Vorstand, Prokura, Beirat, etc.).
-- 
-- WICHTIG: Das ist nicht gleich "arbeitet dort" – kann aber parallel existieren.
-- ============================================================================

CREATE TABLE IF NOT EXISTS person_org_role (
    role_uuid CHAR(36) PRIMARY KEY,
    person_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    
    role_type ENUM(
        'ceo',
        'cfo',
        'cto',
        'managing_director',
        'board_member',
        'authorized_signatory',
        'advisor',
        'owner_rep'
    ) NOT NULL COMMENT 'Typ der Rolle',
    
    role_title VARCHAR(255) NULL COMMENT 'Freitext: "Geschäftsführer", "Prokurist" etc.',
    
    start_date DATE NULL COMMENT 'Beginn des Mandats',
    end_date DATE NULL COMMENT 'Ende des Mandats',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_pcr_person (person_uuid),
    KEY idx_pcr_org (org_uuid),
    KEY idx_pcr_role (role_type),
    KEY idx_pcr_dates (start_date, end_date),
    
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE person_org_role COMMENT = 'Mandate/Organfunktionen: Geschäftsführer, Vorstand, Prokura, etc.';
