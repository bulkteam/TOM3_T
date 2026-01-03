-- TOM3 - Industry Tabelle komplett neu aufsetzen
-- Da keine Referenzen vorhanden sind, können wir die Tabelle leeren und sauber neu befüllen

-- ============================================================================
-- 1. Lösche alle bestehenden Daten
-- ============================================================================
DELETE FROM industry;

-- ============================================================================
-- 2. Setze AUTO_INCREMENT zurück (falls vorhanden)
-- ============================================================================
-- ALTER TABLE industry AUTO_INCREMENT = 1; -- Nur wenn ID-Spalte vorhanden

-- ============================================================================
-- 3. Füge Level 1 (Branchenbereiche) ein
-- ============================================================================
-- Format: code, name (offiziell), name_short (ohne "X - " Prefix)

INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
-- A - Land- und Forstwirtschaft, Fischerei
(UUID(), 'A - Land- und Forstwirtschaft, Fischerei', 'Land- und Forstwirtschaft, Fischerei', 'A', NULL, NULL),

-- B - Bergbau und Gewinnung von Steinen und Erden
(UUID(), 'B - Bergbau und Gewinnung von Steinen und Erden', 'Bergbau und Gewinnung von Steinen und Erden', 'B', NULL, NULL),

-- C - Verarbeitendes Gewerbe
(UUID(), 'C - Verarbeitendes Gewerbe', 'Verarbeitendes Gewerbe', 'C', NULL, NULL),

-- D - Energieversorgung
(UUID(), 'D - Energieversorgung', 'Energieversorgung', 'D', NULL, NULL),

-- E - Wasserversorgung; Abwasser- und Abfallentsorgung
(UUID(), 'E - Wasserversorgung; Abwasser- und Abfallentsorgung', 'Wasserversorgung; Abwasser- und Abfallentsorgung', 'E', NULL, NULL),

-- F - Baugewerbe
(UUID(), 'F - Baugewerbe', 'Baugewerbe', 'F', NULL, NULL),

-- G - Handel; Instandhaltung und Reparatur von Kraftfahrzeugen
(UUID(), 'G - Handel; Instandhaltung und Reparatur von Kraftfahrzeugen', 'Handel; Instandhaltung und Reparatur von Kraftfahrzeugen', 'G', NULL, NULL),

-- H - Verkehr und Lagerei
(UUID(), 'H - Verkehr und Lagerei', 'Verkehr und Lagerei', 'H', NULL, NULL),

-- I - Gastgewerbe
(UUID(), 'I - Gastgewerbe', 'Gastgewerbe', 'I', NULL, NULL),

-- J - Information und Kommunikation
(UUID(), 'J - Information und Kommunikation', 'Information und Kommunikation', 'J', NULL, NULL),

-- K - Erbringung von Finanz- und Versicherungsdienstleistungen
(UUID(), 'K - Erbringung von Finanz- und Versicherungsdienstleistungen', 'Erbringung von Finanz- und Versicherungsdienstleistungen', 'K', NULL, NULL),

-- L - Grundstücks- und Wohnungswesen
(UUID(), 'L - Grundstücks- und Wohnungswesen', 'Grundstücks- und Wohnungswesen', 'L', NULL, NULL),

-- M - Erbringung von freiberuflichen, wissenschaftlichen und technischen Dienstleistungen
(UUID(), 'M - Erbringung von freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'Erbringung von freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'M', NULL, NULL),

-- N - Erbringung von sonstigen Dienstleistungen
(UUID(), 'N - Erbringung von sonstigen Dienstleistungen', 'Erbringung von sonstigen Dienstleistungen', 'N', NULL, NULL),

-- O - Öffentliche Verwaltung, Verteidigung; Sozialversicherung
(UUID(), 'O - Öffentliche Verwaltung, Verteidigung; Sozialversicherung', 'Öffentliche Verwaltung, Verteidigung; Sozialversicherung', 'O', NULL, NULL),

-- P - Erziehung und Unterricht
(UUID(), 'P - Erziehung und Unterricht', 'Erziehung und Unterricht', 'P', NULL, NULL),

-- Q - Gesundheits- und Sozialwesen
(UUID(), 'Q - Gesundheits- und Sozialwesen', 'Gesundheits- und Sozialwesen', 'Q', NULL, NULL),

-- R - Kunst, Unterhaltung und Erholung
(UUID(), 'R - Kunst, Unterhaltung und Erholung', 'Kunst, Unterhaltung und Erholung', 'R', NULL, NULL),

-- S - Erbringung von sonstigen Dienstleistungen
(UUID(), 'S - Erbringung von sonstigen Dienstleistungen', 'Erbringung von sonstigen Dienstleistungen', 'S', NULL, NULL),

-- T - Private Haushalte mit Hauspersonal
(UUID(), 'T - Private Haushalte mit Hauspersonal', 'Private Haushalte mit Hauspersonal', 'T', NULL, NULL),

-- U - Exterritoriale Organisationen und Körperschaften
(UUID(), 'U - Exterritoriale Organisationen und Körperschaften', 'Exterritoriale Organisationen und Körperschaften', 'U', NULL, NULL);

-- ============================================================================
-- 4. Füge Level 2 (Branchen) ein - nur kanonische Einträge mit Code
-- ============================================================================
-- WICHTIG: Nur EIN Eintrag pro Code, mit offiziellem Langnamen und name_short

-- Hole Parent UUIDs für Level 2
SET @parent_c = (SELECT industry_uuid FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_h = (SELECT industry_uuid FROM industry WHERE code = 'H' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_d = (SELECT industry_uuid FROM industry WHERE code = 'D' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_e = (SELECT industry_uuid FROM industry WHERE code = 'E' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_f = (SELECT industry_uuid FROM industry WHERE code = 'F' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_g = (SELECT industry_uuid FROM industry WHERE code = 'G' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_j = (SELECT industry_uuid FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_k = (SELECT industry_uuid FROM industry WHERE code = 'K' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_m = (SELECT industry_uuid FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1);
SET @parent_n = (SELECT industry_uuid FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1);

-- Verarbeitendes Gewerbe (C)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'C10 - Herstellung von Nahrungs- und Futtermitteln', 'Lebensmittel', 'C10', @parent_c, NULL),
(UUID(), 'C11 - Herstellung von Getränken', 'Getränke', 'C11', @parent_c, NULL),
(UUID(), 'C13 - Herstellung von Textilien', 'Textil', 'C13', @parent_c, NULL),
(UUID(), 'C17 - Herstellung von Papier, Pappe und Waren daraus', 'Papier/Pappe', 'C17', @parent_c, NULL),
(UUID(), 'C20 - Herstellung von chemischen Erzeugnissen', 'Chemie', 'C20', @parent_c, NULL),
(UUID(), 'C21 - Herstellung von pharmazeutischen Erzeugnissen', 'Pharma', 'C21', @parent_c, NULL),
(UUID(), 'C22 - Herstellung von Gummi- und Kunststoffwaren', 'Kunststoff/Gummi', 'C22', @parent_c, NULL),
(UUID(), 'C23 - Herstellung von Glas und Glaswaren, Keramik, Verarbeitung von Steinen und Erden', 'Glas/Keramik', 'C23', @parent_c, NULL),
(UUID(), 'C24 - Herstellung von Metallen', 'Metalle', 'C24', @parent_c, NULL),
(UUID(), 'C25 - Herstellung von Metallerzeugnissen', 'Metallprodukte', 'C25', @parent_c, NULL),
(UUID(), 'C26 - Herstellung von Datenverarbeitungsgeräten, elektronischen und optischen Erzeugnissen', 'Elektronik/IT-Hardware', 'C26', @parent_c, NULL),
(UUID(), 'C27 - Herstellung von elektrischen Ausrüstungen', 'Elektrotechnik', 'C27', @parent_c, NULL),
(UUID(), 'C28 - Maschinenbau', 'Maschinenbau', 'C28', @parent_c, NULL),
(UUID(), 'C29 - Herstellung von Kraftwagen und Kraftwagenteilen', 'Automotive', 'C29', @parent_c, NULL),
(UUID(), 'C30 - Herstellung von sonstigen Fahrzeugen', 'Fahrzeugbau', 'C30', @parent_c, NULL),
(UUID(), 'C31 - Herstellung von Möbeln', 'Möbel', 'C31', @parent_c, NULL),
(UUID(), 'C32 - Herstellung von sonstigen Waren', 'Sonstige Waren', 'C32', @parent_c, NULL),
(UUID(), 'C33 - Reparatur und Installation von Maschinen und Ausrüstungen', 'Instandhaltung/Installation', 'C33', @parent_c, NULL);

-- Energie/Wasser/Abfall (D/E)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'D35 - Energieversorgung', 'Energie', 'D35', @parent_d, NULL),
(UUID(), 'E36 - Wasserversorgung', 'Wasser', 'E36', @parent_e, NULL),
(UUID(), 'E37 - Abwasserentsorgung', 'Abwasser', 'E37', @parent_e, NULL),
(UUID(), 'E38 - Sammlung, Behandlung und Beseitigung von Abfällen; Rückgewinnung', 'Abfall/Entsorgung', 'E38', @parent_e, NULL),
(UUID(), 'E39 - Beseitigung von Umweltverschmutzungen und sonstige Entsorgung', 'Umwelt/Altlasten', 'E39', @parent_e, NULL);

-- Bau (F)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'F41 - Erschließung von Grundstücken; Bauträger', 'Bauträger', 'F41', @parent_f, NULL),
(UUID(), 'F42 - Hochbau', 'Hochbau', 'F42', @parent_f, NULL),
(UUID(), 'F43 - Tiefbau', 'Tiefbau', 'F43', @parent_f, NULL);

-- Handel (G)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'G45 - Handel mit Kraftfahrzeugen; Instandhaltung und Reparatur von Kraftfahrzeugen', 'Kfz-Handel/Service', 'G45', @parent_g, NULL),
(UUID(), 'G46 - Großhandel (ohne Handel mit Kraftfahrzeugen)', 'Großhandel', 'G46', @parent_g, NULL),
(UUID(), 'G47 - Einzelhandel (ohne Handel mit Kraftfahrzeugen)', 'Einzelhandel', 'G47', @parent_g, NULL);

-- Verkehr/Logistik (H) - KORRIGIERT
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'H49 - Landverkehr und Transport in Rohrfernleitungen', 'Landverkehr', 'H49', @parent_h, NULL),
(UUID(), 'H50 - Schifffahrt', 'Schifffahrt', 'H50', @parent_h, NULL),
(UUID(), 'H51 - Luftfahrt', 'Luftfahrt', 'H51', @parent_h, NULL),
(UUID(), 'H52 - Lagerei sowie Erbringung von sonstigen Dienstleistungen für den Verkehr', 'Logistik/Lagerei', 'H52', @parent_h, NULL),
(UUID(), 'H53 - Post-, Kurier- und Expressdienste', 'Post/Kurier/Express', 'H53', @parent_h, NULL);

-- Information/Kommunikation (J)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'J58 - Verlagsaktivitäten', 'Verlage', 'J58', @parent_j, NULL),
(UUID(), 'J59 - Herstellung, Verleih und Vertrieb von Filmen und Fernsehprogrammen; Kino', 'Film/Video', 'J59', @parent_j, NULL),
(UUID(), 'J60 - Rundfunkveranstalter und -programmanbieter', 'Rundfunk', 'J60', @parent_j, NULL),
(UUID(), 'J61 - Telekommunikation', 'Telekommunikation', 'J61', @parent_j, NULL),
(UUID(), 'J62 - Erbringung von Dienstleistungen der Informationstechnologie', 'IT-Dienstleistungen', 'J62', @parent_j, NULL),
(UUID(), 'J63 - Informationsdienstleistungen', 'Informationsdienste', 'J63', @parent_j, NULL);

-- Finanzdienstleistungen (K)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'K64 - Erbringung von Finanzdienstleistungen', 'Finanzdienstleistungen', 'K64', @parent_k, NULL),
(UUID(), 'K65 - Versicherungen, Rückversicherungen und Pensionskassen (ohne Sozialversicherung)', 'Versicherungen', 'K65', @parent_k, NULL),
(UUID(), 'K66 - Erbringung von Finanz- und Versicherungsdienstleistungen a. n. g.', 'Finanz-Services', 'K66', @parent_k, NULL);

-- Freiberufliche/Technische (M)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'M69 - Erbringung von Rechts- und Steuerberatung, wirtschaftlicher Beratung und Unternehmensführung', 'Recht/Steuern', 'M69', @parent_m, NULL),
(UUID(), 'M70 - Erbringung von Dienstleistungen der Unternehmensführung und Managementberatung', 'Unternehmensberatung', 'M70', @parent_m, NULL),
(UUID(), 'M71 - Erbringung von Dienstleistungen der Architektur und Ingenieurbüros; technische, physikalische und chemische Untersuchung', 'Architektur/Ingenieur', 'M71', @parent_m, NULL),
(UUID(), 'M72 - Forschung und Entwicklung', 'F&E', 'M72', @parent_m, NULL),
(UUID(), 'M73 - Werbung und Marktforschung', 'Werbung/Marktforschung', 'M73', @parent_m, NULL),
(UUID(), 'M74 - Erbringung von sonstigen freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'Sonstige Freiberufliche', 'M74', @parent_m, NULL),
(UUID(), 'M75 - Veterinärwesen', 'Veterinär', 'M75', @parent_m, NULL);

-- Dienstleistungen (N)
INSERT INTO industry (industry_uuid, name, name_short, code, parent_industry_uuid, description) VALUES
(UUID(), 'N77 - Vermietung von beweglichen Sachen', 'Vermietung', 'N77', @parent_n, NULL),
(UUID(), 'N78 - Vermittlung und Überlassung von Arbeitskräften', 'Personal/Zeitarbeit', 'N78', @parent_n, NULL),
(UUID(), 'N79 - Reisebüros, Reiseveranstalter und Erbringung von sonstigen Reservierungsdienstleistungen', 'Reise', 'N79', @parent_n, NULL),
(UUID(), 'N80 - Erbringung von Sicherheits- und Bewachungsdienstleistungen', 'Sicherheit', 'N80', @parent_n, NULL),
(UUID(), 'N81 - Erbringung von Dienstleistungen für Gebäude und Grünanlagen', 'Gebäudeservice', 'N81', @parent_n, NULL),
(UUID(), 'N82 - Erbringung von wirtschaftlichen Dienstleistungen für Unternehmen und Privatpersonen a. n. g.', 'Backoffice-Services', 'N82', @parent_n, NULL);

-- ============================================================================
-- 5. Erstelle Unique Index für (parent, code) - verhindert Duplikate
-- ============================================================================
CREATE UNIQUE INDEX IF NOT EXISTS uq_industry_parent_code 
ON industry(parent_industry_uuid, code)
WHERE code IS NOT NULL;

-- Für MySQL/MariaDB (ohne WHERE):
-- CREATE UNIQUE INDEX uq_industry_parent_code ON industry(parent_industry_uuid, code);
