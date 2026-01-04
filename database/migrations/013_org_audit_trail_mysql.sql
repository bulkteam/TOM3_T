-- TOM3 - Audit Trail für Organisationsänderungen
-- Protokolliert alle Änderungen an Stammdaten mit: Wer, Wann, Was

CREATE TABLE IF NOT EXISTS org_audit_trail (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(100) NOT NULL COMMENT 'User-ID des Bearbeiters',
    action VARCHAR(50) NOT NULL COMMENT 'create | update | delete',
    field_name VARCHAR(100) COMMENT 'Name des geänderten Feldes (z.B. name, status, account_owner_user_id)',
    old_value TEXT COMMENT 'Alter Wert (JSON für komplexe Objekte)',
    new_value TEXT COMMENT 'Neuer Wert (JSON für komplexe Objekte)',
    change_type VARCHAR(50) COMMENT 'field_change | relation_added | relation_removed | address_added | address_updated | address_removed | channel_added | channel_updated | channel_removed | vat_added | vat_updated | vat_removed',
    metadata JSON COMMENT 'Zusätzliche Metadaten (z.B. address_uuid, relation_uuid)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    INDEX idx_org_audit_org (org_uuid),
    INDEX idx_org_audit_user (user_id),
    INDEX idx_org_audit_created (created_at),
    INDEX idx_org_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





