-- TOM3 - Industry 3-Level Hierarchy
-- Erweitert die Branchen-Hierarchie von 2 auf 3 Ebenen:
-- Level 1: Branchenbereich (z.B. "Verarbeitendes Gewerbe")
-- Level 2: Branche (z.B. "Lebensmittel", "Chemie")
-- Level 3: Unterbranche (z.B. "Käserei", "Farbhersteller")

-- ============================================================================
-- ORG INDUSTRY FIELDS (3-stufige Hierarchie)
-- ============================================================================
ALTER TABLE org 
ADD COLUMN industry_level1_uuid CHAR(36) COMMENT 'Branchenbereich (Level 1)',
ADD COLUMN industry_level2_uuid CHAR(36) COMMENT 'Branche (Level 2)',
ADD COLUMN industry_level3_uuid CHAR(36) COMMENT 'Unterbranche (Level 3)';

ALTER TABLE org
ADD FOREIGN KEY (industry_level1_uuid) REFERENCES industry(industry_uuid) ON DELETE SET NULL,
ADD FOREIGN KEY (industry_level2_uuid) REFERENCES industry(industry_uuid) ON DELETE SET NULL,
ADD FOREIGN KEY (industry_level3_uuid) REFERENCES industry(industry_uuid) ON DELETE SET NULL;

CREATE INDEX idx_org_industry_level1 ON org(industry_level1_uuid);
CREATE INDEX idx_org_industry_level2 ON org(industry_level2_uuid);
CREATE INDEX idx_org_industry_level3 ON org(industry_level3_uuid);

-- ============================================================================
-- DATEN MIGRATION
-- ============================================================================
-- Migriere bestehende Daten:
-- industry_main_uuid → industry_level1_uuid
-- industry_sub_uuid → industry_level2_uuid

UPDATE org 
SET industry_level1_uuid = industry_main_uuid
WHERE industry_main_uuid IS NOT NULL;

UPDATE org 
SET industry_level2_uuid = industry_sub_uuid
WHERE industry_sub_uuid IS NOT NULL;

-- ============================================================================
-- HINWEIS: Alte Spalten bleiben vorerst erhalten für Rückwärtskompatibilität
-- ============================================================================
-- Die Spalten industry_main_uuid und industry_sub_uuid werden in einer
-- späteren Migration entfernt, sobald alle Services/UI angepasst sind.
