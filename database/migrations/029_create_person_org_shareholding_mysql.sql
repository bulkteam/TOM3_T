-- ============================================================================
-- TOM3 Migration 029: Person Org Shareholding Tabelle erstellen
-- ============================================================================
-- Erstellt die person_org_shareholding Tabelle für Beteiligungen/Anteile
-- von Personen an Organisationen.
-- ============================================================================

CREATE TABLE IF NOT EXISTS person_org_shareholding (
    shareholding_uuid CHAR(36) PRIMARY KEY,
    person_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    
    percent DECIMAL(6,3) NULL COMMENT 'Prozentanteil (z.B. 12.500)',
    shares_count BIGINT NULL COMMENT 'Anzahl der Anteile',
    voting_percent DECIMAL(6,3) NULL COMMENT 'Stimmrechtsanteil (%)',
    
    is_direct TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Direkte Beteiligung (nicht über Zwischengesellschaft)',
    
    start_date DATE NULL COMMENT 'Beginn der Beteiligung',
    end_date DATE NULL COMMENT 'Ende der Beteiligung',
    source VARCHAR(512) NULL COMMENT 'Quelle der Information',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_sh_person (person_uuid),
    KEY idx_sh_org (org_uuid),
    KEY idx_sh_dates (start_date, end_date),
    KEY idx_sh_direct (is_direct),
    
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE person_org_shareholding COMMENT = 'Beteiligungen/Anteile von Personen an Organisationen';
