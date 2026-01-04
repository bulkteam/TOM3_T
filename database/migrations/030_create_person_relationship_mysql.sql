-- ============================================================================
-- TOM3 Migration 030: Person Relationship Tabelle erstellen
-- ============================================================================
-- Erstellt die person_relationship Tabelle für Person-zu-Person Beziehungen
-- (kennt, freundlich, gegenrisch, berät, mentor, etc.).
-- 
-- WICHTIG: Flexibles System für verschiedene Beziehungstypen mit Richtung,
-- Stärke, Vertrauen und Kontext.
-- ============================================================================

CREATE TABLE IF NOT EXISTS person_relationship (
    relationship_uuid CHAR(36) PRIMARY KEY,
    person_a_uuid CHAR(36) NOT NULL COMMENT 'Person A',
    person_b_uuid CHAR(36) NOT NULL COMMENT 'Person B',
    
    relation_type ENUM(
        'knows',
        'friendly',
        'adversarial',
        'advisor_of',
        'mentor_of',
        'former_colleague',
        'influences',
        'gatekeeper_for'
    ) NOT NULL COMMENT 'Typ der Beziehung',
    
    direction ENUM(
        'a_to_b',
        'b_to_a',
        'bidirectional'
    ) NOT NULL DEFAULT 'bidirectional' COMMENT 'Richtung der Beziehung',
    
    strength TINYINT NULL COMMENT 'Stärke/Valenz (1..10)',
    confidence TINYINT NULL COMMENT 'Vertrauen/Confidence (1..10)',
    
    -- Optionaler Kontext
    context_org_uuid CHAR(36) NULL COMMENT 'Falls "in Firma X kennen sie sich"',
    context_project_uuid CHAR(36) NULL COMMENT 'Später: Projekt/Opportunity',
    
    start_date DATE NULL COMMENT 'Beginn der Beziehung',
    end_date DATE NULL COMMENT 'Ende der Beziehung',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_rel_a (person_a_uuid),
    KEY idx_rel_b (person_b_uuid),
    KEY idx_rel_type (relation_type),
    KEY idx_rel_direction (direction),
    KEY idx_rel_context_org (context_org_uuid),
    KEY idx_rel_dates (start_date, end_date),
    
    FOREIGN KEY (person_a_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    FOREIGN KEY (person_b_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    FOREIGN KEY (context_org_uuid) REFERENCES org(org_uuid) ON DELETE SET NULL,
    FOREIGN KEY (context_project_uuid) REFERENCES project(project_uuid) ON DELETE SET NULL,
    
    -- Check Constraint: Person kann nicht mit sich selbst in Beziehung stehen
    CONSTRAINT chk_relationship_not_self CHECK (person_a_uuid != person_b_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE person_relationship COMMENT = 'Person-zu-Person Beziehungen: kennt, freundlich, berät, etc.';


