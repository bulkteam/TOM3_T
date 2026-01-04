-- ============================================================================
-- TOM3 Migration 036: Activity-Log-ID zu Audit-Trail-Tabellen hinzufügen
-- ============================================================================
-- Fügt activity_log_id Feld zu org_audit_trail und person_audit_trail hinzu
-- Ermöglicht Rückverknüpfung von Audit-Trail zu Activity-Log
-- ============================================================================

-- Org Audit Trail erweitern
ALTER TABLE org_audit_trail 
ADD COLUMN activity_log_id BIGINT NULL COMMENT 'Verknüpfung zu activity_log.activity_id' AFTER audit_id,
ADD INDEX idx_org_audit_activity_log (activity_log_id);

-- Person Audit Trail erweitern
ALTER TABLE person_audit_trail 
ADD COLUMN activity_log_id BIGINT NULL COMMENT 'Verknüpfung zu activity_log.activity_id' AFTER audit_id,
ADD INDEX idx_person_audit_activity_log (activity_log_id);

-- Foreign Keys (optional, kann später hinzugefügt werden wenn nötig)
-- ALTER TABLE org_audit_trail 
-- ADD CONSTRAINT fk_org_audit_activity_log 
-- FOREIGN KEY (activity_log_id) REFERENCES activity_log(activity_id) ON DELETE SET NULL;
-- 
-- ALTER TABLE person_audit_trail 
-- ADD CONSTRAINT fk_person_audit_activity_log 
-- FOREIGN KEY (activity_log_id) REFERENCES activity_log(activity_id) ON DELETE SET NULL;


