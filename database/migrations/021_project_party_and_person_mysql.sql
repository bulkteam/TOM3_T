-- ============================================================================
-- TOM3 Migration 021: Project Party und Project Person
-- ============================================================================
-- Erstellt neue Tabellen für verbesserte Projekt-Personen-Integration
-- 
-- Verbesserungen gegenüber project_partner + project_stakeholder:
-- - Explizite Zuordnung Person ↔ Projektpartei über project_party_uuid
-- - ENUM statt VARCHAR für Rollen (sauberer)
-- - Unterstützt Mehrfach-Rollen einer Firma am gleichen Projekt
-- 
-- Bestehende Tabellen (project_partner, project_stakeholder) bleiben erhalten
-- für Backward Compatibility und schrittweise Migration.
-- ============================================================================

-- ============================================================================
-- Projektparteien (Welche Firmen sind beteiligt, in welcher Rolle)
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_party (
    party_uuid CHAR(36) PRIMARY KEY,
    project_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    
    party_role ENUM(
        'customer',
        'supplier',
        'consultant',
        'auditor',
        'partner',
        'subcontractor'
    ) NOT NULL COMMENT 'Rolle der Firma im Projekt',
    
    is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hauptkunde/Lieferant',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Eine Firma kann mehrere Rollen am gleichen Projekt haben
    -- Beispiel: Firma A als consultant UND subcontractor
    UNIQUE KEY uq_project_org_role (project_uuid, org_uuid, party_role),
    KEY idx_pp_project (project_uuid),
    KEY idx_pp_org (org_uuid),
    KEY idx_pp_role (party_role),
    
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FOREIGN KEY für project_person wird nach Erstellung der Tabelle hinzugefügt

-- ============================================================================
-- Projektpersonen (Welche Personen wirken mit, für welche Projektpartei)
-- ============================================================================
CREATE TABLE IF NOT EXISTS project_person (
    project_person_uuid CHAR(36) PRIMARY KEY,
    project_uuid CHAR(36) NOT NULL,
    person_uuid CHAR(36) NOT NULL,
    
    -- WICHTIG: Explizite Zuordnung zur Projektpartei
    -- Macht klar, in welcher Rolle die Firma am Projekt beteiligt ist
    project_party_uuid CHAR(36) NULL COMMENT 'Firma + Rolle im Projektkontext',
    
    -- Projektrolle (kontextbezogen, dynamisch)
    project_role ENUM(
        'consultant',
        'lead_consultant',
        'account_contact',
        'delivery_contact',
        'auditor',
        'stakeholder',
        'decision_maker',
        'champion',
        'blocker'
    ) NOT NULL COMMENT 'Rolle der Person im Projekt',
    
    start_date DATE NULL COMMENT 'Beginn der Beteiligung',
    end_date DATE NULL COMMENT 'Ende der Beteiligung',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_prj_person_project (project_uuid),
    KEY idx_prj_person_person (person_uuid),
    KEY idx_prj_person_party (project_party_uuid),
    KEY idx_prj_person_role (project_role),
    
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid) ON DELETE CASCADE,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- FOREIGN KEY für project_party_uuid (muss nach Erstellung beider Tabellen hinzugefügt werden)
-- ============================================================================
ALTER TABLE project_person
    ADD CONSTRAINT fk_prj_person_party 
    FOREIGN KEY (project_party_uuid) REFERENCES project_party(party_uuid) ON DELETE SET NULL;

-- ============================================================================
-- Kommentare für Dokumentation
-- ============================================================================
ALTER TABLE project_party COMMENT = 'Projektparteien: Firmen mit Rollen in Projekten';
ALTER TABLE project_person COMMENT = 'Projektpersonen: Personen mit expliziter Zuordnung zu Projektparteien';
