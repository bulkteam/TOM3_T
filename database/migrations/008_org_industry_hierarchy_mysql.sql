-- TOM3 - Org Industry Hierarchy
-- Erweitert Org um hierarchische Branchenauswahl (Hauptklasse + Unterklasse)

-- ============================================================================
-- ORG INDUSTRY FIELDS (Hauptklasse + Unterklasse)
-- ============================================================================
ALTER TABLE org 
ADD COLUMN industry_main_uuid CHAR(36) COMMENT 'Hauptklasse (WZ 2008)',
ADD COLUMN industry_sub_uuid CHAR(36) COMMENT 'Unterklasse (WZ 2008)',
ADD FOREIGN KEY (industry_main_uuid) REFERENCES industry(industry_uuid) ON DELETE SET NULL,
ADD FOREIGN KEY (industry_sub_uuid) REFERENCES industry(industry_uuid) ON DELETE SET NULL;

CREATE INDEX idx_org_industry_main ON org(industry_main_uuid);
CREATE INDEX idx_org_industry_sub ON org(industry_sub_uuid);

-- ============================================================================
-- WZ 2008 HAUPTKLASSEN (Auszug - die wichtigsten)
-- ============================================================================
-- Hinweis: Dies ist ein Auszug der wichtigsten Hauptklassen.
-- Die vollständige Liste kann später ergänzt werden.

-- A - Land- und Forstwirtschaft, Fischerei
-- B - Bergbau und Gewinnung von Steinen und Erden
-- C - Verarbeitendes Gewerbe
-- D - Energieversorgung
-- E - Wasserversorgung; Abwasser- und Abfallentsorgung und Beseitigung von Umweltverschmutzungen
-- F - Baugewerbe
-- G - Handel; Instandhaltung und Reparatur von Kraftfahrzeugen
-- H - Verkehr und Lagerei
-- I - Gastgewerbe
-- J - Information und Kommunikation
-- K - Erbringung von Finanz- und Versicherungsdienstleistungen
-- L - Grundstücks- und Wohnungswesen
-- M - Erbringung von Freiberuflichen, wissenschaftlichen und technischen Dienstleistungen
-- N - Erbringung von sonstigen wirtschaftlichen Dienstleistungen
-- O - Öffentliche Verwaltung, Verteidigung; Sozialversicherung
-- P - Erziehung und Unterricht
-- Q - Gesundheits- und Sozialwesen
-- R - Kunst, Unterhaltung und Erholung
-- S - Erbringung von sonstigen Dienstleistungen
-- T - Private Haushalte mit Hauspersonal
-- U - Exterritoriale Organisationen und Körperschaften

-- ============================================================================
-- WZ 2008 HAUPTKLASSEN (Auszug - die wichtigsten)
-- ============================================================================
-- Hinweis: Dies ist ein Auszug. Die vollständige Liste kann später ergänzt werden.
-- Format: WZ-Code | Name

-- A - Land- und Forstwirtschaft, Fischerei
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'A - Land- und Forstwirtschaft, Fischerei', 'A', NULL);

-- B - Bergbau und Gewinnung von Steinen und Erden
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'B - Bergbau und Gewinnung von Steinen und Erden', 'B', NULL);

-- C - Verarbeitendes Gewerbe
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'C - Verarbeitendes Gewerbe', 'C', NULL);

-- D - Energieversorgung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'D - Energieversorgung', 'D', NULL);

-- E - Wasserversorgung; Abwasser- und Abfallentsorgung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'E - Wasserversorgung; Abwasser- und Abfallentsorgung', 'E', NULL);

-- F - Baugewerbe
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'F - Baugewerbe', 'F', NULL);

-- G - Handel; Instandhaltung und Reparatur von Kraftfahrzeugen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'G - Handel; Instandhaltung und Reparatur von Kraftfahrzeugen', 'G', NULL);

-- H - Verkehr und Lagerei
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'H - Verkehr und Lagerei', 'H', NULL);

-- I - Gastgewerbe
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'I - Gastgewerbe', 'I', NULL);

-- J - Information und Kommunikation
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'J - Information und Kommunikation', 'J', NULL);

-- K - Erbringung von Finanz- und Versicherungsdienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'K - Erbringung von Finanz- und Versicherungsdienstleistungen', 'K', NULL);

-- L - Grundstücks- und Wohnungswesen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'L - Grundstücks- und Wohnungswesen', 'L', NULL);

-- M - Erbringung von Freiberuflichen, wissenschaftlichen und technischen Dienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'M - Erbringung von Freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'M', NULL);

-- N - Erbringung von sonstigen wirtschaftlichen Dienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'N - Erbringung von sonstigen wirtschaftlichen Dienstleistungen', 'N', NULL);

-- O - Öffentliche Verwaltung, Verteidigung; Sozialversicherung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'O - Öffentliche Verwaltung, Verteidigung; Sozialversicherung', 'O', NULL);

-- P - Erziehung und Unterricht
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'P - Erziehung und Unterricht', 'P', NULL);

-- Q - Gesundheits- und Sozialwesen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'Q - Gesundheits- und Sozialwesen', 'Q', NULL);

-- R - Kunst, Unterhaltung und Erholung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'R - Kunst, Unterhaltung und Erholung', 'R', NULL);

-- S - Erbringung von sonstigen Dienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) VALUES
(REPLACE(UUID(), '-', ''), 'S - Erbringung von sonstigen Dienstleistungen', 'S', NULL);

-- ============================================================================
-- HINWEIS: Unterklassen können später ergänzt werden
-- ============================================================================
-- Beispiel für Unterklassen (wird später ergänzt):
-- C10 - Herstellung von Nahrungs- und Futtermitteln (parent = C)
-- C20 - Herstellung von chemischen Erzeugnissen (parent = C)
-- C28 - Maschinenbau (parent = C)
-- etc.

