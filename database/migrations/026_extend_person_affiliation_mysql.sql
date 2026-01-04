-- ============================================================================
-- TOM3 Migration 026: Person Affiliation erweitern
-- ============================================================================
-- Erweitert die person_affiliation Tabelle um zusätzliche Felder:
-- - affiliation_uuid (neuer Primary Key, statt Composite Key)
-- - org_unit_uuid (Zuordnung zu Org Unit)
-- - job_function (fachliche Funktion)
-- - seniority (Hierarchieebene)
-- - is_primary (Hauptarbeitgeber)
-- 
-- WICHTIG: Die bestehende Composite Primary Key Struktur wird beibehalten
-- für Backward Compatibility, aber affiliation_uuid wird als zusätzlicher
-- eindeutiger Identifier hinzugefügt.
-- ============================================================================

-- ============================================================================
-- Schritt 1: affiliation_uuid Spalte hinzufügen
-- ============================================================================

ALTER TABLE person_affiliation
    ADD COLUMN affiliation_uuid CHAR(36) NULL FIRST;

-- ============================================================================
-- Schritt 2: UUIDs für bestehende Einträge generieren
-- ============================================================================

UPDATE person_affiliation
SET affiliation_uuid = UUID()
WHERE affiliation_uuid IS NULL;

-- ============================================================================
-- Schritt 3: affiliation_uuid als NOT NULL und UNIQUE setzen
-- ============================================================================

ALTER TABLE person_affiliation
    MODIFY COLUMN affiliation_uuid CHAR(36) NOT NULL,
    ADD UNIQUE KEY uq_affiliation_uuid (affiliation_uuid);

-- ============================================================================
-- Schritt 4: Neue Spalten hinzufügen
-- ============================================================================

ALTER TABLE person_affiliation
    ADD COLUMN org_unit_uuid CHAR(36) NULL COMMENT 'Zuordnung zu Org Unit' AFTER org_uuid,
    ADD COLUMN job_function VARCHAR(255) NULL COMMENT 'Fachliche Funktion (Einkauf, Technik, etc.)' AFTER title,
    ADD COLUMN seniority ENUM(
        'intern',
        'junior',
        'mid',
        'senior',
        'lead',
        'head',
        'vp',
        'cxo'
    ) NULL COMMENT 'Hierarchieebene' AFTER job_function,
    ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hauptarbeitgeber' AFTER seniority;

-- ============================================================================
-- Schritt 5: Foreign Key für org_unit_uuid
-- ============================================================================

ALTER TABLE person_affiliation
    ADD CONSTRAINT fk_affiliation_org_unit 
    FOREIGN KEY (org_unit_uuid) REFERENCES org_unit(org_unit_uuid) ON DELETE SET NULL;

-- ============================================================================
-- Schritt 6: Indizes hinzufügen
-- ============================================================================

CREATE INDEX idx_affiliation_org_unit ON person_affiliation(org_unit_uuid);
CREATE INDEX idx_affiliation_job_function ON person_affiliation(job_function);
CREATE INDEX idx_affiliation_seniority ON person_affiliation(seniority);
CREATE INDEX idx_affiliation_is_primary ON person_affiliation(is_primary);

-- ============================================================================
-- Hinweis: Composite Primary Key bleibt erhalten für Backward Compatibility
-- ============================================================================


