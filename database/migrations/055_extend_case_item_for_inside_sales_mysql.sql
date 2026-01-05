-- TOM3 - Fix priority_stars DEFAULT to 0
-- Setzt priority_stars DEFAULT auf 0 für neue Leads

-- ============================================================================
-- FIX: priority_stars DEFAULT-Wert auf 0 setzen
-- ============================================================================

-- Ändere DEFAULT-Wert auf 0
ALTER TABLE case_item MODIFY COLUMN priority_stars INT DEFAULT 0 COMMENT 'Priorität 0-5 Sterne (0 = keine Priorität)';

-- Setze priority_stars auf 0 für alle bestehenden NEW Leads, die noch keine Priorität haben
UPDATE case_item 
SET priority_stars = 0 
WHERE (priority_stars IS NULL OR priority_stars = 0) 
  AND stage = 'NEW' 
  AND engine = 'inside_sales';

