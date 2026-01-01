-- ============================================================================
-- TOM3 Migration 024: Person Tabelle erweitern
-- ============================================================================
-- Erweitert die person Tabelle um zusätzliche Felder für das Personen-Modul:
-- - first_name, last_name (statt nur display_name)
-- - salutation, title (Anrede und Titel)
-- - mobile_phone (zusätzlich zu phone)
-- - linkedin_url, notes
-- - is_active, archived_at (Soft-Delete)
-- 
-- display_name wird zu einer GENERATED COLUMN, die aus den einzelnen
-- Namensfeldern zusammengesetzt wird.
-- ============================================================================

-- ============================================================================
-- Schritt 1: Neue Spalten hinzufügen
-- ============================================================================

ALTER TABLE person
    ADD COLUMN first_name VARCHAR(120) NULL AFTER person_uuid,
    ADD COLUMN last_name VARCHAR(120) NULL AFTER first_name,
    ADD COLUMN salutation VARCHAR(20) NULL COMMENT 'Herr | Frau | Dr. | Prof. | etc.' AFTER last_name,
    ADD COLUMN title VARCHAR(100) NULL COMMENT 'Dr. | Prof. | etc.' AFTER salutation,
    ADD COLUMN mobile_phone VARCHAR(50) NULL COMMENT 'Mobiltelefon' AFTER phone,
    ADD COLUMN linkedin_url VARCHAR(512) NULL AFTER mobile_phone,
    ADD COLUMN notes TEXT NULL AFTER linkedin_url,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Person aktiv/inaktiv' AFTER notes,
    ADD COLUMN archived_at DATETIME NULL COMMENT 'Zeitstempel der Deaktivierung' AFTER is_active;

-- ============================================================================
-- Schritt 2: Bestehende display_name Daten migrieren (falls vorhanden)
-- ============================================================================
-- Versuche, display_name in first_name und last_name aufzuteilen
-- Einfache Heuristik: Letztes Wort = last_name, Rest = first_name

UPDATE person
SET 
    last_name = TRIM(SUBSTRING_INDEX(display_name, ' ', -1)),
    first_name = TRIM(SUBSTRING(display_name, 1, LENGTH(display_name) - LENGTH(SUBSTRING_INDEX(display_name, ' ', -1)) - 1))
WHERE display_name IS NOT NULL 
  AND display_name != ''
  AND first_name IS NULL
  AND last_name IS NULL;

-- ============================================================================
-- Schritt 3: display_name zu GENERATED COLUMN ändern
-- ============================================================================
-- Entferne alte display_name Spalte und erstelle neue als GENERATED COLUMN

ALTER TABLE person
    DROP COLUMN display_name,
    ADD COLUMN display_name VARCHAR(255) GENERATED ALWAYS AS (
        TRIM(CONCAT(
            COALESCE(salutation, ''), 
            CASE WHEN salutation IS NOT NULL AND salutation != '' THEN ' ' ELSE '' END,
            COALESCE(title, ''), 
            CASE WHEN title IS NOT NULL AND title != '' THEN ' ' ELSE '' END,
            COALESCE(first_name, ''), 
            CASE WHEN first_name IS NOT NULL AND first_name != '' THEN ' ' ELSE '' END,
            COALESCE(last_name, '')
        ))
    ) STORED AFTER title;

-- ============================================================================
-- Schritt 4: Indizes hinzufügen
-- ============================================================================

CREATE INDEX idx_person_first_name ON person(first_name);
CREATE INDEX idx_person_last_name ON person(last_name);
CREATE INDEX idx_person_name ON person(last_name, first_name);
CREATE INDEX idx_person_is_active ON person(is_active);

-- Bestehender Index auf display_name bleibt erhalten (wird automatisch auf GENERATED COLUMN angewendet)

-- ============================================================================
-- Schritt 5: E-Mail Unique Constraint (falls noch nicht vorhanden)
-- ============================================================================
-- Prüfe, ob Unique Constraint bereits existiert, falls nicht, erstelle ihn

-- Hinweis: Falls bereits Daten vorhanden sind, müssen Duplikate zuerst entfernt werden
-- ALTER TABLE person ADD UNIQUE KEY uq_person_email (email);
