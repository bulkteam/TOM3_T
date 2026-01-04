-- ============================================================================
-- TOM3 Migration 027: Person Affiliation Reporting Tabelle erstellen
-- ============================================================================
-- Erstellt die person_affiliation_reporting Tabelle für Reporting-Lines
-- (Vorgesetzte & Mitarbeiter).
-- 
-- WICHTIG: Reporting hängt an Affiliation, nicht an Person direkt,
-- da eine Person mehrere Affiliations haben kann.
-- ============================================================================

CREATE TABLE IF NOT EXISTS person_affiliation_reporting (
    reporting_uuid CHAR(36) PRIMARY KEY,
    employment_affiliation_uuid CHAR(36) NOT NULL COMMENT 'Affiliation des Mitarbeiters',
    manager_affiliation_uuid CHAR(36) NOT NULL COMMENT 'Affiliation des Vorgesetzten',
    
    start_date DATE NULL COMMENT 'Beginn der Reporting-Line',
    end_date DATE NULL COMMENT 'Ende der Reporting-Line',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Eine Person kann nicht auf sich selbst berichten
    -- Eine Reporting-Line kann nur einmal aktiv sein (start_date)
    UNIQUE KEY uq_reporting_active (employment_affiliation_uuid, manager_affiliation_uuid, start_date),
    KEY idx_reporting_employment (employment_affiliation_uuid),
    KEY idx_reporting_manager (manager_affiliation_uuid),
    KEY idx_reporting_dates (start_date, end_date),
    
    FOREIGN KEY (employment_affiliation_uuid) REFERENCES person_affiliation(affiliation_uuid) ON DELETE CASCADE,
    FOREIGN KEY (manager_affiliation_uuid) REFERENCES person_affiliation(affiliation_uuid) ON DELETE CASCADE,
    
    -- Check Constraint: Person kann nicht auf sich selbst berichten
    CONSTRAINT chk_reporting_not_self CHECK (employment_affiliation_uuid != manager_affiliation_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE person_affiliation_reporting COMMENT = 'Reporting-Lines: Vorgesetzte & Mitarbeiter (hängt an Affiliations)';


