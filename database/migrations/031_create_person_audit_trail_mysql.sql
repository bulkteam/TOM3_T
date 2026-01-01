-- ============================================================================
-- TOM3 Migration 031: Person Audit Trail Tabelle erstellen
-- ============================================================================
-- Erstellt die person_audit_trail Tabelle für Audit-Trail von Personen
-- (ähnlich wie org_audit_trail für Organisationen)
-- ============================================================================

CREATE TABLE IF NOT EXISTS person_audit_trail (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    person_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(100) NOT NULL COMMENT 'User-ID des Bearbeiters',
    action VARCHAR(50) NOT NULL COMMENT 'create | update | delete',
    field_name VARCHAR(100) COMMENT 'Name des geänderten Feldes (z.B. first_name, email, is_active)',
    old_value TEXT COMMENT 'Alter Wert (JSON für komplexe Objekte)',
    new_value TEXT COMMENT 'Neuer Wert (JSON für komplexe Objekte)',
    change_type VARCHAR(50) COMMENT 'field_change | affiliation_added | affiliation_updated | affiliation_removed | relationship_added | relationship_removed',
    metadata JSON COMMENT 'Zusätzliche Metadaten (z.B. affiliation_uuid, relationship_uuid)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    INDEX idx_person_audit_person (person_uuid),
    INDEX idx_person_audit_user (user_id),
    INDEX idx_person_audit_created (created_at),
    INDEX idx_person_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE person_audit_trail COMMENT = 'Audit-Trail für Personenänderungen';
