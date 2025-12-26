-- TOM3 - Workflow Definitions
-- Phase-Definitionen und Engine-Checklisten
-- MySQL/MariaDB

-- ============================================================================
-- PHASE DEFINITIONS
-- ============================================================================
CREATE TABLE phase_definition (
    phase_code VARCHAR(50) PRIMARY KEY COMMENT 'CI-A, CI-B, OPS-A, etc.',
    engine VARCHAR(50) NOT NULL,
    phase_name VARCHAR(255) NOT NULL,
    description TEXT,
    order_index INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_phase_definition_engine ON phase_definition(engine);

-- ============================================================================
-- PHASE REQUIREMENTS (Checklisten)
-- ============================================================================
CREATE TABLE phase_requirement_definition (
    requirement_def_uuid CHAR(36) PRIMARY KEY,
    phase_code VARCHAR(50) NOT NULL,
    requirement_type VARCHAR(50) NOT NULL COMMENT 'document | information | decision | approval',
    description TEXT NOT NULL,
    is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
    order_index INT NOT NULL,
    FOREIGN KEY (phase_code) REFERENCES phase_definition(phase_code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_phase_requirement_phase ON phase_requirement_definition(phase_code);

-- ============================================================================
-- ENGINE DEFINITIONS
-- ============================================================================
CREATE TABLE engine_definition (
    engine_code VARCHAR(50) PRIMARY KEY COMMENT 'customer_inbound | ops | inside_sales | etc.',
    engine_name VARCHAR(255) NOT NULL,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Default Data
-- ============================================================================

-- Engines
INSERT INTO engine_definition (engine_code, engine_name, description) VALUES
    ('customer_inbound', 'Customer Inbound', 'Eingehende Kundenanfragen'),
    ('ops', 'OPS', 'Operations'),
    ('inside_sales', 'Inside Sales', 'Innendienst-Vertrieb'),
    ('outside_sales', 'Outside Sales', 'Außendienst-Vertrieb'),
    ('order_admin', 'Order Admin', 'Auftragsverwaltung')
ON DUPLICATE KEY UPDATE engine_code = engine_code;

-- Phases: Customer Inbound
INSERT INTO phase_definition (phase_code, engine, phase_name, description, order_index) VALUES
    ('CI-A', 'customer_inbound', 'Annahme', 'Annahme und erste Erfassung', 1),
    ('CI-B', 'customer_inbound', 'Klassifikation', 'Klassifikation und Routing', 2),
    ('CI-C', 'customer_inbound', 'Übergabe', 'Übergabe an Ziel-Engine', 3)
ON DUPLICATE KEY UPDATE phase_code = phase_code;

-- Phases: OPS
INSERT INTO phase_definition (phase_code, engine, phase_name, description, order_index) VALUES
    ('OPS-A', 'ops', 'Strukturierung', 'Strukturierung und Fakten sammeln', 1),
    ('OPS-B', 'ops', 'Bearbeitung', 'Bearbeitung und Lösung', 2),
    ('OPS-C', 'ops', 'Entscheidungsreife', 'Entscheidungsvorlage', 3)
ON DUPLICATE KEY UPDATE phase_code = phase_code;

-- Phase Requirements: CI-A (Annahme)
-- Note: requirement_def_uuid wird in der Anwendung generiert
INSERT INTO phase_requirement_definition (requirement_def_uuid, phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    (UUID(), 'CI-A', 'information', 'Kontakt/Organisation eindeutig identifiziert', 1, 1),
    (UUID(), 'CI-A', 'information', 'Zusammenfassung in 2 Sätzen', 1, 2),
    (UUID(), 'CI-A', 'information', 'Kanal + Zeitstempel erfasst', 1, 3),
    (UUID(), 'CI-A', 'information', 'Dringlichkeit + Begründung', 1, 4)
ON DUPLICATE KEY UPDATE requirement_def_uuid = requirement_def_uuid;

-- Phase Requirements: CI-B (Klassifikation)
INSERT INTO phase_requirement_definition (requirement_def_uuid, phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    (UUID(), 'CI-B', 'information', 'Kategorie gesetzt', 1, 1),
    (UUID(), 'CI-B', 'decision', 'Ziel-Engine bestimmt + Begründung', 1, 2),
    (UUID(), 'CI-B', 'information', 'Konkrete Erwartung formuliert', 1, 3)
ON DUPLICATE KEY UPDATE requirement_def_uuid = requirement_def_uuid;

-- Phase Requirements: CI-C (Übergabe)
INSERT INTO phase_requirement_definition (requirement_def_uuid, phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    (UUID(), 'CI-C', 'information', 'Übergabeobjekt erstellt (Ziel, Kontext, offene Punkte)', 1, 1),
    (UUID(), 'CI-C', 'decision', 'Empfängerrolle gesetzt', 1, 2),
    (UUID(), 'CI-C', 'information', 'Rückläuferbedingung klar definiert', 1, 3)
ON DUPLICATE KEY UPDATE requirement_def_uuid = requirement_def_uuid;

-- Phase Requirements: OPS-A (Strukturierung)
INSERT INTO phase_requirement_definition (requirement_def_uuid, phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    (UUID(), 'OPS-A', 'information', 'Fakten vs. Annahmen getrennt', 1, 1),
    (UUID(), 'OPS-A', 'information', 'Offene Punkte als Tasks modelliert', 1, 2),
    (UUID(), 'OPS-A', 'information', 'Benötigte Dokumente/Referenzen verlinkt', 1, 3),
    (UUID(), 'OPS-A', 'information', 'Risiko-/Impact-Notiz', 1, 4)
ON DUPLICATE KEY UPDATE requirement_def_uuid = requirement_def_uuid;

-- Phase Requirements: OPS-C (Entscheidungsreife)
INSERT INTO phase_requirement_definition (requirement_def_uuid, phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    (UUID(), 'OPS-C', 'decision', 'Empfehlung mit Vor-/Nachteilen', 1, 1),
    (UUID(), 'OPS-C', 'decision', 'Klare Entscheidungsvorlage', 1, 2),
    (UUID(), 'OPS-C', 'information', 'Vollständigkeitscheck (wenn an Admin)', 1, 3)
ON DUPLICATE KEY UPDATE requirement_def_uuid = requirement_def_uuid;

