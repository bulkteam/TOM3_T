-- ============================================================================
-- TOM3 Migration 025: Org Unit Tabelle erstellen
-- ============================================================================
-- Erstellt die org_unit Tabelle für Organisationseinheiten innerhalb von Firmen
-- (Abteilungen, Teams, Standorte, etc.)
-- 
-- Eine Org Unit gehört zu einer Organisation und kann hierarchisch verschachtelt sein
-- (parent_org_unit_uuid für rekursive Struktur).
-- ============================================================================

CREATE TABLE IF NOT EXISTS org_unit (
    org_unit_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL COMMENT 'Zugehörige Organisation',
    parent_org_unit_uuid CHAR(36) NULL COMMENT 'Übergeordnete Org Unit (für Hierarchie)',
    
    name VARCHAR(255) NOT NULL COMMENT 'Name der Org Unit (z.B. "Einkauf", "Technik")',
    code VARCHAR(64) NULL COMMENT 'Kurzcode (optional)',
    unit_type VARCHAR(50) NULL COMMENT 'department | team | plant | business_unit | etc.',
    
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_org_unit_name (org_uuid, name),
    KEY idx_org_unit_org (org_uuid),
    KEY idx_org_unit_parent (parent_org_unit_uuid),
    KEY idx_org_unit_active (is_active),
    
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (parent_org_unit_uuid) REFERENCES org_unit(org_unit_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE org_unit COMMENT = 'Organisationseinheiten innerhalb von Organisationen (Abteilungen, Teams, etc.)';
