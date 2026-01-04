-- ============================================================================
-- TOM3 Migration 035: Activity Log Tabelle erstellen
-- ============================================================================
-- Erstellt die activity_log Tabelle für zentrales Logging aller User-Aktionen
-- Verknüpft mit entity-spezifischen Audit-Trails für Details
-- ============================================================================

CREATE TABLE IF NOT EXISTS activity_log (
    activity_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL COMMENT 'User-ID des ausführenden Users',
    action_type VARCHAR(50) NOT NULL COMMENT 'login, logout, export, upload, download, entity_change, assignment, system_action, etc.',
    entity_type VARCHAR(50) COMMENT 'org, person, project, case, system, etc. (NULL für system-Aktionen)',
    entity_uuid CHAR(36) COMMENT 'UUID der betroffenen Entität (NULL für system-Aktionen)',
    audit_trail_id INT COMMENT 'Verknüpfung zu entity-spezifischem Audit-Trail (z.B. org_audit_trail.audit_id)',
    audit_trail_table VARCHAR(50) COMMENT 'Tabellenname des Audit-Trails (org_audit_trail, person_audit_trail, etc.)',
    details JSON COMMENT 'Zusätzliche Informationen (summary, changed_fields, file_name, etc.)',
    ip_address VARCHAR(45) COMMENT 'IP-Adresse des Users',
    user_agent TEXT COMMENT 'User-Agent des Browsers',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Indizes für häufige Queries
    INDEX idx_activity_user_date (user_id, created_at DESC),
    INDEX idx_activity_entity (entity_type, entity_uuid),
    INDEX idx_activity_action (action_type, created_at DESC),
    INDEX idx_activity_created (created_at DESC),
    INDEX idx_activity_audit_trail (audit_trail_table, audit_trail_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Zentrales Activity-Log für alle User-Aktionen';

-- Optional: Partitionierung nach Monat (für bessere Performance bei großen Datenmengen)
-- Kann später aktiviert werden, wenn die Tabelle groß wird
-- ALTER TABLE activity_log PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
--     PARTITION p202401 VALUES LESS THAN (202402),
--     PARTITION p202402 VALUES LESS THAN (202403),
--     -- Weitere Partitionen werden automatisch erstellt
-- );


