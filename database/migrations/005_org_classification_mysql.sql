-- TOM3 - Org Classification (Branchen, Märkte, Tiers, Strategic Flags)
-- Robustes Datenmodell für Klassifizierung und Suche

-- ============================================================================
-- INDUSTRY (Branche - Was ist das Unternehmen?)
-- ============================================================================
CREATE TABLE industry (
    industry_uuid CHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) COMMENT 'NAICS/NACE Code optional',
    parent_industry_uuid CHAR(36),
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_industry_uuid) REFERENCES industry(industry_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_industry_name ON industry(name);
CREATE INDEX idx_industry_code ON industry(code);

-- ============================================================================
-- MARKET SEGMENT (Marktsegment - Go-to-market Sicht)
-- ============================================================================
CREATE TABLE market_segment (
    segment_uuid CHAR(36) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    parent_segment_uuid CHAR(36),
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_segment_uuid) REFERENCES market_segment(segment_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_segment_name ON market_segment(name);

-- ============================================================================
-- LOCATION (Standort mit Geokoordinaten)
-- ============================================================================
CREATE TABLE location (
    location_uuid CHAR(36) PRIMARY KEY,
    country_code VARCHAR(2) NOT NULL,
    postal_code VARCHAR(20),
    city VARCHAR(100),
    region VARCHAR(100) COMMENT 'Bundesland/Kanton',
    street TEXT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_location_city ON location(city);
CREATE INDEX idx_location_country ON location(country_code);
CREATE INDEX idx_location_coords ON location(latitude, longitude);

-- ============================================================================
-- ORG ↔ INDUSTRY (M:N)
-- ============================================================================
CREATE TABLE org_industry (
    org_uuid CHAR(36) NOT NULL,
    industry_uuid CHAR(36) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    confidence INT COMMENT '0-100, optional',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (org_uuid, industry_uuid),
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (industry_uuid) REFERENCES industry(industry_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_industry_org ON org_industry(org_uuid);
CREATE INDEX idx_org_industry_industry ON org_industry(industry_uuid);

-- ============================================================================
-- ORG ↔ MARKET SEGMENT (M:N)
-- ============================================================================
CREATE TABLE org_market_segment (
    org_uuid CHAR(36) NOT NULL,
    segment_uuid CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (org_uuid, segment_uuid),
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (segment_uuid) REFERENCES market_segment(segment_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_segment_org ON org_market_segment(org_uuid);
CREATE INDEX idx_org_segment_segment ON org_market_segment(segment_uuid);

-- ============================================================================
-- ORG METRICS (Umsatz, Mitarbeiter - zeitbezogen)
-- ============================================================================
CREATE TABLE org_metrics (
    metrics_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    year INT NOT NULL,
    revenue_amount DECIMAL(15, 2),
    revenue_currency VARCHAR(3) DEFAULT 'EUR',
    employees INT,
    source VARCHAR(50) COMMENT 'Manual | Import | D&B | ...',
    confidence INT COMMENT '0-100, optional',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    UNIQUE KEY unique_org_year (org_uuid, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_metrics_org ON org_metrics(org_uuid);
CREATE INDEX idx_org_metrics_year ON org_metrics(year);

-- ============================================================================
-- CUSTOMER TIER (A/B/C - Wertklasse)
-- ============================================================================
CREATE TABLE customer_tier (
    tier_code VARCHAR(10) PRIMARY KEY COMMENT 'A, B, C',
    name VARCHAR(50) NOT NULL,
    description TEXT,
    revenue_min DECIMAL(15, 2) COMMENT 'Vorschlag, nicht harte Regel',
    orders_min INT COMMENT 'Vorschlag',
    margin_min DECIMAL(5, 2) COMMENT 'Vorschlag in %',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ORG ↔ CUSTOMER TIER (mit Zeitbezug)
-- ============================================================================
CREATE TABLE org_customer_tier (
    tier_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    tier_code VARCHAR(10) NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE,
    assigned_by VARCHAR(255) COMMENT 'user_id',
    reason TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (tier_code) REFERENCES customer_tier(tier_code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_tier_org ON org_customer_tier(org_uuid);
CREATE INDEX idx_org_tier_code ON org_customer_tier(tier_code);
CREATE INDEX idx_org_tier_dates ON org_customer_tier(valid_from, valid_to);

-- ============================================================================
-- STRATEGIC FLAG (S - Strategische Relevanz)
-- ============================================================================
CREATE TABLE org_strategic_flag (
    flag_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    is_strategic TINYINT(1) NOT NULL DEFAULT 1,
    level VARCHAR(10) COMMENT 'S1, S2, optional',
    reason_code VARCHAR(50) COMMENT 'Reference | Partner | Innovation | KeyAccountOfCompetitor | RegionAnchor | ...',
    reason_text TEXT,
    review_date DATE,
    assigned_by VARCHAR(255) COMMENT 'user_id',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    UNIQUE KEY unique_org_strategic (org_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_strategic_org ON org_strategic_flag(org_uuid);

-- ============================================================================
-- ORG STATUS (Lead/Prospect/Customer/Inactive)
-- ============================================================================
-- Erweitere org Tabelle um status Feld
ALTER TABLE org ADD COLUMN status VARCHAR(50) DEFAULT 'lead' COMMENT 'lead | prospect | customer | inactive';
CREATE INDEX idx_org_status ON org(status);

-- ============================================================================
-- ORG ↔ LOCATION (M:N, falls mehrere Standorte)
-- ============================================================================
CREATE TABLE org_location (
    org_uuid CHAR(36) NOT NULL,
    location_uuid CHAR(36) NOT NULL,
    location_type VARCHAR(50) NOT NULL COMMENT 'HQ | Plant | Office | Warehouse',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (org_uuid, location_uuid, location_type),
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (location_uuid) REFERENCES location(location_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_location_org ON org_location(org_uuid);
CREATE INDEX idx_org_location_location ON org_location(location_uuid);

-- ============================================================================
-- DEFAULT DATA: Customer Tiers
-- ============================================================================
INSERT INTO customer_tier (tier_code, name, description) VALUES
('A', 'A-Kunde', 'Top-Kunde: Hoher Umsatz, viele Aufträge, hohe Marge'),
('B', 'B-Kunde', 'Mittlerer Kunde: Regelmäßiger Umsatz'),
('C', 'C-Kunde', 'Kleiner Kunde: Niedriger Umsatz, seltene Aufträge');

-- ============================================================================
-- DEFAULT DATA: Beispiel-Industrien
-- ============================================================================
-- Verwende MySQL UUID() Funktion
INSERT INTO industry (industry_uuid, name, code) VALUES
(REPLACE(UUID(), '-', ''), 'Maschinenbau', 'C28'),
(REPLACE(UUID(), '-', ''), 'Chemie', 'C20'),
(REPLACE(UUID(), '-', ''), 'Pharma', 'C21'),
(REPLACE(UUID(), '-', ''), 'Lebensmittel', 'C10'),
(REPLACE(UUID(), '-', ''), 'Logistik', 'H49'),
(REPLACE(UUID(), '-', ''), 'Anlagenbau', 'C28');

-- ============================================================================
-- DEFAULT DATA: Beispiel-Marktsegmente
-- ============================================================================
INSERT INTO market_segment (segment_uuid, name, description) VALUES
(REPLACE(UUID(), '-', ''), 'DACH Food', 'Deutschland, Österreich, Schweiz - Lebensmittel'),
(REPLACE(UUID(), '-', ''), 'EMEA Chemicals', 'Europa, Naher Osten, Afrika - Chemie'),
(REPLACE(UUID(), '-', ''), 'Automotive Tier-1', 'Automobilzulieferer Tier 1'),
(REPLACE(UUID(), '-', ''), 'OEM', 'Original Equipment Manufacturer'),
(REPLACE(UUID(), '-', ''), 'Aftermarket', 'Ersatzteilmarkt');

