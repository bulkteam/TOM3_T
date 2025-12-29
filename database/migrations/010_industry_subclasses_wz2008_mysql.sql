-- TOM3 - Industry Subclasses (WZ 2008)
-- Fügt wichtige Unterklassen für die Hauptklassen hinzu
-- Fokus auf B2B-relevante Branchen

-- ============================================================================
-- HINWEIS: Dies ist ein Auszug der wichtigsten Unterklassen
-- Die vollständige WZ 2008 Liste kann später ergänzt werden
-- ============================================================================

-- ============================================================================
-- C - VERARBEITENDES GEWERBE (wichtigste Unterklassen)
-- ============================================================================

-- C10 - Herstellung von Nahrungs- und Futtermitteln
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C10 - Herstellung von Nahrungs- und Futtermitteln', 'C10', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C11 - Getränkeherstellung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C11 - Getränkeherstellung', 'C11', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C13 - Herstellung von Textilien
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C13 - Herstellung von Textilien', 'C13', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C17 - Herstellung von Papier, Pappe und Waren daraus
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C17 - Herstellung von Papier, Pappe und Waren daraus', 'C17', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C20 - Herstellung von chemischen Erzeugnissen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C20 - Herstellung von chemischen Erzeugnissen', 'C20', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C21 - Herstellung von pharmazeutischen Erzeugnissen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C21 - Herstellung von pharmazeutischen Erzeugnissen', 'C21', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C22 - Herstellung von Gummi- und Kunststoffwaren
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C22 - Herstellung von Gummi- und Kunststoffwaren', 'C22', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C23 - Herstellung von Glas und Glaswaren, Keramik, Verarbeitung von Steinen und Erden
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C23 - Herstellung von Glas und Glaswaren, Keramik, Verarbeitung von Steinen und Erden', 'C23', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C24 - Herstellung von Metallen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C24 - Herstellung von Metallen', 'C24', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C25 - Herstellung von Metallerzeugnissen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C25 - Herstellung von Metallerzeugnissen', 'C25', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C26 - Herstellung von Datenverarbeitungsgeräten, elektronischen und optischen Erzeugnissen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C26 - Herstellung von Datenverarbeitungsgeräten, elektronischen und optischen Erzeugnissen', 'C26', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C27 - Herstellung von elektrischen Ausrüstungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C27 - Herstellung von elektrischen Ausrüstungen', 'C27', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C28 - Maschinenbau
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C28 - Maschinenbau', 'C28', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C29 - Herstellung von Kraftwagen und Kraftwagenteilen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C29 - Herstellung von Kraftwagen und Kraftwagenteilen', 'C29', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C30 - Herstellung von sonstigen Fahrzeugen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C30 - Herstellung von sonstigen Fahrzeugen', 'C30', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C31 - Herstellung von Möbeln
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C31 - Herstellung von Möbeln', 'C31', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C32 - Herstellung von sonstigen Waren
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C32 - Herstellung von sonstigen Waren', 'C32', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- C33 - Reparatur und Installation von Maschinen und Ausrüstungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'C33 - Reparatur und Installation von Maschinen und Ausrüstungen', 'C33', industry_uuid
FROM industry WHERE code = 'C' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- D - ENERGIEVERSORGUNG
-- ============================================================================

-- D35 - Energieversorgung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'D35 - Energieversorgung', 'D35', industry_uuid
FROM industry WHERE code = 'D' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- E - WASSERVERSORGUNG; ABWASSER- UND ABFALLENTSORGUNG
-- ============================================================================

-- E36 - Wasserversorgung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'E36 - Wasserversorgung', 'E36', industry_uuid
FROM industry WHERE code = 'E' AND parent_industry_uuid IS NULL LIMIT 1;

-- E37 - Abwasserentsorgung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'E37 - Abwasserentsorgung', 'E37', industry_uuid
FROM industry WHERE code = 'E' AND parent_industry_uuid IS NULL LIMIT 1;

-- E38 - Sammlung, Behandlung und Beseitigung von Abfällen; Rückgewinnung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'E38 - Sammlung, Behandlung und Beseitigung von Abfällen; Rückgewinnung', 'E38', industry_uuid
FROM industry WHERE code = 'E' AND parent_industry_uuid IS NULL LIMIT 1;

-- E39 - Beseitigung von Umweltverschmutzungen und sonstige Entsorgung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'E39 - Beseitigung von Umweltverschmutzungen und sonstige Entsorgung', 'E39', industry_uuid
FROM industry WHERE code = 'E' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- F - BAUGEWERBE
-- ============================================================================

-- F41 - Erschließung von Grundstücken; Bauträger
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'F41 - Erschließung von Grundstücken; Bauträger', 'F41', industry_uuid
FROM industry WHERE code = 'F' AND parent_industry_uuid IS NULL LIMIT 1;

-- F42 - Tiefbau
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'F42 - Tiefbau', 'F42', industry_uuid
FROM industry WHERE code = 'F' AND parent_industry_uuid IS NULL LIMIT 1;

-- F43 - Vorbereitende Baustellenarbeiten, Bauinstallation und sonstiges Ausbaugewerbe
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'F43 - Vorbereitende Baustellenarbeiten, Bauinstallation und sonstiges Ausbaugewerbe', 'F43', industry_uuid
FROM industry WHERE code = 'F' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- G - HANDEL; INSTANDHALTUNG UND REPARATUR VON KRAFTFAHRZEUGEN
-- ============================================================================

-- G45 - Handel mit Kraftfahrzeugen; Instandhaltung und Reparatur von Kraftfahrzeugen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'G45 - Handel mit Kraftfahrzeugen; Instandhaltung und Reparatur von Kraftfahrzeugen', 'G45', industry_uuid
FROM industry WHERE code = 'G' AND parent_industry_uuid IS NULL LIMIT 1;

-- G46 - Großhandel (ohne Handel mit Kraftfahrzeugen)
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'G46 - Großhandel (ohne Handel mit Kraftfahrzeugen)', 'G46', industry_uuid
FROM industry WHERE code = 'G' AND parent_industry_uuid IS NULL LIMIT 1;

-- G47 - Einzelhandel (ohne Handel mit Kraftfahrzeugen)
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'G47 - Einzelhandel (ohne Handel mit Kraftfahrzeugen)', 'G47', industry_uuid
FROM industry WHERE code = 'G' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- H - VERKEHR UND LAGEREI
-- ============================================================================

-- H49 - Landverkehr und Transport in Rohrfernleitungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'H49 - Landverkehr und Transport in Rohrfernleitungen', 'H49', industry_uuid
FROM industry WHERE code = 'H' AND parent_industry_uuid IS NULL LIMIT 1;

-- H50 - Schifffahrt
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'H50 - Schifffahrt', 'H50', industry_uuid
FROM industry WHERE code = 'H' AND parent_industry_uuid IS NULL LIMIT 1;

-- H51 - Luftfahrt
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'H51 - Luftfahrt', 'H51', industry_uuid
FROM industry WHERE code = 'H' AND parent_industry_uuid IS NULL LIMIT 1;

-- H52 - Lagerei sowie Erbringung von sonstigen Dienstleistungen für den Verkehr
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'H52 - Lagerei sowie Erbringung von sonstigen Dienstleistungen für den Verkehr', 'H52', industry_uuid
FROM industry WHERE code = 'H' AND parent_industry_uuid IS NULL LIMIT 1;

-- H53 - Post-, Kurier- und Expressdienste
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'H53 - Post-, Kurier- und Expressdienste', 'H53', industry_uuid
FROM industry WHERE code = 'H' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- J - INFORMATION UND KOMMUNIKATION
-- ============================================================================

-- J58 - Verlagsgewerbe
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'J58 - Verlagsgewerbe', 'J58', industry_uuid
FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1;

-- J59 - Herstellung von Filmen, Videoprogrammen und Fernsehprogrammen; Tonaufnahmen und Musikveröffentlichungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'J59 - Herstellung von Filmen, Videoprogrammen und Fernsehprogrammen; Tonaufnahmen und Musikveröffentlichungen', 'J59', industry_uuid
FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1;

-- J60 - Rundfunkveranstalter und Übertragung von Rundfunkprogrammen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'J60 - Rundfunkveranstalter und Übertragung von Rundfunkprogrammen', 'J60', industry_uuid
FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1;

-- J61 - Telekommunikation
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'J61 - Telekommunikation', 'J61', industry_uuid
FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1;

-- J62 - Erbringung von Dienstleistungen der Informationstechnologie
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'J62 - Erbringung von Dienstleistungen der Informationstechnologie', 'J62', industry_uuid
FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1;

-- J63 - Informationsdienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'J63 - Informationsdienstleistungen', 'J63', industry_uuid
FROM industry WHERE code = 'J' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- K - ERBRINGUNG VON FINANZ- UND VERSICHERUNGSDIENSTLEISTUNGEN
-- ============================================================================

-- K64 - Erbringung von Finanzdienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'K64 - Erbringung von Finanzdienstleistungen', 'K64', industry_uuid
FROM industry WHERE code = 'K' AND parent_industry_uuid IS NULL LIMIT 1;

-- K65 - Versicherungen, Rückversicherungen sowie Pensionskassen (ohne Sozialversicherung)
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'K65 - Versicherungen, Rückversicherungen sowie Pensionskassen (ohne Sozialversicherung)', 'K65', industry_uuid
FROM industry WHERE code = 'K' AND parent_industry_uuid IS NULL LIMIT 1;

-- K66 - Erbringung von Dienstleistungen des Finanzwesens und von Versicherungsdienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'K66 - Erbringung von Dienstleistungen des Finanzwesens und von Versicherungsdienstleistungen', 'K66', industry_uuid
FROM industry WHERE code = 'K' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- M - ERBRINGUNG VON FREIBERUFLICHEN, WISSENSCHAFTLICHEN UND TECHNISCHEN DIENSTLEISTUNGEN
-- ============================================================================

-- M69 - Erbringung von Dienstleistungen der Rechts- und Steuerberatung, Wirtschaftsprüfung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M69 - Erbringung von Dienstleistungen der Rechts- und Steuerberatung, Wirtschaftsprüfung', 'M69', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- M70 - Erbringung von Dienstleistungen der Unternehmensführung und Managementberatung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M70 - Erbringung von Dienstleistungen der Unternehmensführung und Managementberatung', 'M70', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- M71 - Erbringung von Dienstleistungen der Architektur- und Ingenieurbüros sowie der technischen, physikalischen und chemischen Untersuchung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M71 - Erbringung von Dienstleistungen der Architektur- und Ingenieurbüros sowie der technischen, physikalischen und chemischen Untersuchung', 'M71', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- M72 - Forschung und Entwicklung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M72 - Forschung und Entwicklung', 'M72', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- M73 - Werbung und Marktforschung
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M73 - Werbung und Marktforschung', 'M73', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- M74 - Erbringung von sonstigen freiberuflichen, wissenschaftlichen und technischen Dienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M74 - Erbringung von sonstigen freiberuflichen, wissenschaftlichen und technischen Dienstleistungen', 'M74', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- M75 - Veterinärwesen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'M75 - Veterinärwesen', 'M75', industry_uuid
FROM industry WHERE code = 'M' AND parent_industry_uuid IS NULL LIMIT 1;

-- ============================================================================
-- N - ERBRINGUNG VON SONSTIGEN WIRTSCHAFTLICHEN DIENSTLEISTUNGEN
-- ============================================================================

-- N77 - Vermietung von beweglichen Sachen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'N77 - Vermietung von beweglichen Sachen', 'N77', industry_uuid
FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1;

-- N78 - Vermittlung und Überlassung von Arbeitskräften
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'N78 - Vermittlung und Überlassung von Arbeitskräften', 'N78', industry_uuid
FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1;

-- N79 - Reisebüros, Reiseveranstalter und Erbringung von sonstigen Reservierungsdienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'N79 - Reisebüros, Reiseveranstalter und Erbringung von sonstigen Reservierungsdienstleistungen', 'N79', industry_uuid
FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1;

-- N80 - Erbringung von Sicherheits- und Ermittlungsdienstleistungen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'N80 - Erbringung von Sicherheits- und Ermittlungsdienstleistungen', 'N80', industry_uuid
FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1;

-- N81 - Erbringung von Dienstleistungen für Gebäude und Grünanlagen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'N81 - Erbringung von Dienstleistungen für Gebäude und Grünanlagen', 'N81', industry_uuid
FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1;

-- N82 - Erbringung von wirtschaftlichen Dienstleistungen für Unternehmen und Privatpersonen
INSERT INTO industry (industry_uuid, name, code, parent_industry_uuid) 
SELECT REPLACE(UUID(), '-', ''), 'N82 - Erbringung von wirtschaftlichen Dienstleistungen für Unternehmen und Privatpersonen', 'N82', industry_uuid
FROM industry WHERE code = 'N' AND parent_industry_uuid IS NULL LIMIT 1;


