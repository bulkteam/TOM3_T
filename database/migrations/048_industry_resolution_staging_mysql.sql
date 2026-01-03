-- TOM3 - Industry Resolution für Import Staging
-- Fügt industry_resolution Feld zu org_import_staging hinzu

-- ============================================================================
-- INDUSTRY RESOLUTION (kritisch für Branchen-Mapping)
-- ============================================================================
ALTER TABLE org_import_staging 
ADD COLUMN industry_resolution JSON NULL 
COMMENT 'Vorschläge + bestätigte Branchen-Entscheidung pro Zeile (excel, suggestions, decision)'
AFTER mapped_data;
