-- TOM3 - Org Addresses and Relations
-- Erweitert die Org-Struktur um Adressen und Firmenhierarchien

-- ============================================================================
-- ORG ADDRESSES (Standort-, Liefer- und Rechnungsadressen)
-- ============================================================================
CREATE TABLE org_address (
    address_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    address_type VARCHAR(50) NOT NULL COMMENT 'headquarters | delivery | billing | other',
    street TEXT,
    city VARCHAR(100),
    postal_code VARCHAR(20),
    country VARCHAR(100),
    state VARCHAR(100),
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_address_org ON org_address(org_uuid);
CREATE INDEX idx_org_address_type ON org_address(address_type);
CREATE INDEX idx_org_address_default ON org_address(org_uuid, is_default);

-- ============================================================================
-- ORG RELATIONS (Firmenhierarchien, Beteiligungen, Niederlassungen)
-- ============================================================================
CREATE TABLE org_relation (
    relation_uuid CHAR(36) PRIMARY KEY,
    parent_org_uuid CHAR(36) NOT NULL,
    child_org_uuid CHAR(36) NOT NULL,
    relation_type VARCHAR(50) NOT NULL COMMENT 'subsidiary | division | branch | holding | ownership | partnership',
    ownership_percent DECIMAL(5,2) COMMENT '0-100 für Beteiligungen',
    since_date DATE,
    until_date DATE,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT,
    FOREIGN KEY (child_org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT,
    UNIQUE KEY unique_org_relation (parent_org_uuid, child_org_uuid, relation_type, since_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_relation_parent ON org_relation(parent_org_uuid);
CREATE INDEX idx_org_relation_child ON org_relation(child_org_uuid);
CREATE INDEX idx_org_relation_type ON org_relation(relation_type);
CREATE INDEX idx_org_relation_dates ON org_relation(since_date, until_date);


