-- ============================================================================
-- TOM3 Migration 037: FULLTEXT Index für Dokumenten-Titel
-- ============================================================================
-- Fügt FULLTEXT Index für documents.title hinzu (für Titel-Suche)
-- ============================================================================

ALTER TABLE documents
    ADD FULLTEXT KEY idx_title (title) COMMENT 'Für Titel-Suche';


