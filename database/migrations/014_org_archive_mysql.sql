-- TOM3 - Archivierung für Organisationen
-- Ermöglicht Archivierung von Organisationen, sodass sie nicht mehr in aktiven Listen/Reports erscheinen,
-- aber weiterhin in der Suche auffindbar sind

ALTER TABLE org 
ADD COLUMN archived_at DATETIME NULL COMMENT 'Archivierungsdatum (NULL = aktiv, DATETIME = archiviert)',
ADD COLUMN archived_by_user_id VARCHAR(100) NULL COMMENT 'User-ID, der die Organisation archiviert hat';

-- Index für schnelle Filterung
CREATE INDEX idx_org_archived ON org(archived_at);

-- Standard: Alle bestehenden Organisationen sind aktiv (archived_at IS NULL)


