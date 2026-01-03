-- TOM3 - Industry name_short Support
-- Fügt name_short Spalte und Mapping-Tabelle hinzu

-- ============================================================================
-- 1. Füge name_short Spalte hinzu
-- ============================================================================
ALTER TABLE industry
  ADD COLUMN name_short VARCHAR(120) NULL AFTER name;

-- ============================================================================
-- 2. Erstelle Mapping-Tabelle für Code → Kurzname
-- ============================================================================
CREATE TABLE IF NOT EXISTS industry_code_shortname (
  code VARCHAR(10) PRIMARY KEY,
  name_short VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. Befülle Mapping-Tabelle mit Kurznamen
-- ============================================================================
INSERT INTO industry_code_shortname(code, name_short) VALUES
-- Verarbeitendes Gewerbe (C)
('C10', 'Lebensmittel'),
('C11', 'Getränke'),
('C13', 'Textil'),
('C17', 'Papier/Pappe'),
('C20', 'Chemie'),
('C21', 'Pharma'),
('C22', 'Kunststoff/Gummi'),
('C23', 'Glas/Keramik'),
('C24', 'Metalle'),
('C25', 'Metallprodukte'),
('C26', 'Elektronik/IT-Hardware'),
('C27', 'Elektrotechnik'),
('C28', 'Maschinenbau'),
('C29', 'Automotive'),
('C30', 'Fahrzeugbau'),
('C31', 'Möbel'),
('C32', 'Sonstige Waren'),
('C33', 'Instandhaltung/Installation'),

-- Energie/Wasser/Abfall (D/E)
('D35', 'Energie'),
('E36', 'Wasser'),
('E37', 'Abwasser'),
('E38', 'Abfall/Entsorgung'),
('E39', 'Umwelt/Altlasten'),

-- Bau (F)
('F41', 'Bauträger'),
('F42', 'Tiefbau'),
('F43', 'Bauinstallation'),

-- Handel (G)
('G45', 'Kfz-Handel/Service'),
('G46', 'Großhandel'),
('G47', 'Einzelhandel'),

-- Verkehr/Logistik (H)
('H49', 'Landverkehr'),
('H50', 'Schifffahrt'),
('H51', 'Luftfahrt'),
('H52', 'Logistik/Lagerei'),
('H53', 'Post/Kurier/Express'),

-- Information/Kommunikation (J)
('J58', 'Verlage'),
('J59', 'Film/Video'),
('J60', 'Rundfunk'),
('J61', 'Telekommunikation'),
('J62', 'IT-Dienstleistungen'),
('J63', 'Informationsdienste'),

-- Finanzdienstleistungen (K)
('K64', 'Finanzdienstleistungen'),
('K65', 'Versicherungen'),
('K66', 'Finanz-Services'),

-- Freiberufliche/Technische (M)
('M69', 'Recht/Steuern'),
('M70', 'Unternehmensberatung'),
('M71', 'Architektur/Ingenieur'),
('M72', 'F&E'),
('M73', 'Werbung/Marktforschung'),
('M74', 'Sonstige Freiberufliche'),
('M75', 'Veterinär'),

-- Vermietung/Dienstleistungen (N)
('N77', 'Vermietung'),
('N78', 'Personal/Zeitarbeit'),
('N79', 'Reise'),
('N80', 'Sicherheit'),
('N81', 'Gebäudeservice'),
('N82', 'Backoffice-Services')
ON DUPLICATE KEY UPDATE name_short = VALUES(name_short);

-- ============================================================================
-- 4. Update industry Tabelle mit Kurznamen aus Mapping
-- ============================================================================
UPDATE industry i
INNER JOIN industry_code_shortname m ON m.code = i.code
SET i.name_short = m.name_short
WHERE i.code IS NOT NULL;

-- ============================================================================
-- 5. Level 1: Kurznamen aus Langnamen extrahieren
-- ============================================================================
-- Entferne Prefix "C - " oder "H - " etc. für Level 1
UPDATE industry
SET name_short = TRIM(SUBSTRING_INDEX(name, '-', -1))
WHERE parent_industry_uuid IS NULL 
  AND name LIKE '% - %'
  AND (name_short IS NULL OR name_short = '');

-- ============================================================================
-- 6. Bereinige Duplikate: Behalte kanonische Einträge
-- ============================================================================
-- Für C28: Behalte nur "Maschinenbau" (den mit name_short), lösche andere
DELETE i1 FROM industry i1
INNER JOIN industry i2 ON i1.code = i2.code AND i1.parent_industry_uuid = i2.parent_industry_uuid
WHERE i1.code = 'C28'
  AND i1.name_short IS NULL
  AND i2.name_short IS NOT NULL
  AND i1.industry_uuid != i2.industry_uuid;

-- ============================================================================
-- 7. Unique Index für (parent, code) bei Level 2
-- ============================================================================
-- Nur setzen, wenn keine Duplikate mehr vorhanden sind!
-- Prüfe zuerst mit: SELECT parent_industry_uuid, code, COUNT(*) FROM industry WHERE code IS NOT NULL GROUP BY parent_industry_uuid, code HAVING COUNT(*) > 1;

-- CREATE UNIQUE INDEX uq_industry_parent_code ON industry(parent_industry_uuid, code)
-- WHERE code IS NOT NULL;

-- Für MySQL/MariaDB (ohne WHERE):
-- Erstelle Index nur wenn sicher, dass keine Duplikate mehr existieren
-- CREATE UNIQUE INDEX uq_industry_parent_code ON industry(parent_industry_uuid, code);
