-- TOM3 - Workflow Definitions
-- Phase-Definitionen und Engine-Checklisten

-- ============================================================================
-- PHASE DEFINITIONS
-- ============================================================================
CREATE TABLE phase_definition (
    phase_code TEXT PRIMARY KEY, -- CI-A, CI-B, OPS-A, etc.
    engine TEXT NOT NULL,
    phase_name TEXT NOT NULL,
    description TEXT,
    order_index INTEGER NOT NULL
);

CREATE INDEX idx_phase_definition_engine ON phase_definition(engine);

COMMENT ON TABLE phase_definition IS 'Definitionen der Workflow-Phasen';

-- ============================================================================
-- PHASE REQUIREMENTS (Checklisten)
-- ============================================================================
CREATE TABLE phase_requirement_definition (
    requirement_def_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    phase_code TEXT NOT NULL REFERENCES phase_definition(phase_code) ON DELETE CASCADE,
    requirement_type TEXT NOT NULL, -- document | information | decision | approval
    description TEXT NOT NULL,
    is_mandatory BOOLEAN NOT NULL DEFAULT true,
    order_index INTEGER NOT NULL
);

CREATE INDEX idx_phase_requirement_phase ON phase_requirement_definition(phase_code);

COMMENT ON TABLE phase_requirement_definition IS 'Pflichtoutputs/Checklisten pro Phase';

-- ============================================================================
-- ENGINE DEFINITIONS
-- ============================================================================
CREATE TABLE engine_definition (
    engine_code TEXT PRIMARY KEY, -- customer_inbound | ops | inside_sales | etc.
    engine_name TEXT NOT NULL,
    description TEXT
);

COMMENT ON TABLE engine_definition IS 'Definitionen der Engines';

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
ON CONFLICT (engine_code) DO NOTHING;

-- Phases: Customer Inbound
INSERT INTO phase_definition (phase_code, engine, phase_name, description, order_index) VALUES
    ('CI-A', 'customer_inbound', 'Annahme', 'Annahme und erste Erfassung', 1),
    ('CI-B', 'customer_inbound', 'Klassifikation', 'Klassifikation und Routing', 2),
    ('CI-C', 'customer_inbound', 'Übergabe', 'Übergabe an Ziel-Engine', 3)
ON CONFLICT (phase_code) DO NOTHING;

-- Phases: OPS
INSERT INTO phase_definition (phase_code, engine, phase_name, description, order_index) VALUES
    ('OPS-A', 'ops', 'Strukturierung', 'Strukturierung und Fakten sammeln', 1),
    ('OPS-B', 'ops', 'Bearbeitung', 'Bearbeitung und Lösung', 2),
    ('OPS-C', 'ops', 'Entscheidungsreife', 'Entscheidungsvorlage', 3)
ON CONFLICT (phase_code) DO NOTHING;

-- Phase Requirements: CI-A (Annahme)
INSERT INTO phase_requirement_definition (phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    ('CI-A', 'information', 'Kontakt/Organisation eindeutig identifiziert', true, 1),
    ('CI-A', 'information', 'Zusammenfassung in 2 Sätzen', true, 2),
    ('CI-A', 'information', 'Kanal + Zeitstempel erfasst', true, 3),
    ('CI-A', 'information', 'Dringlichkeit + Begründung', true, 4)
ON CONFLICT DO NOTHING;

-- Phase Requirements: CI-B (Klassifikation)
INSERT INTO phase_requirement_definition (phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    ('CI-B', 'information', 'Kategorie gesetzt', true, 1),
    ('CI-B', 'decision', 'Ziel-Engine bestimmt + Begründung', true, 2),
    ('CI-B', 'information', 'Konkrete Erwartung formuliert', true, 3)
ON CONFLICT DO NOTHING;

-- Phase Requirements: CI-C (Übergabe)
INSERT INTO phase_requirement_definition (phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    ('CI-C', 'information', 'Übergabeobjekt erstellt (Ziel, Kontext, offene Punkte)', true, 1),
    ('CI-C', 'decision', 'Empfängerrolle gesetzt', true, 2),
    ('CI-C', 'information', 'Rückläuferbedingung klar definiert', true, 3)
ON CONFLICT DO NOTHING;

-- Phase Requirements: OPS-A (Strukturierung)
INSERT INTO phase_requirement_definition (phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    ('OPS-A', 'information', 'Fakten vs. Annahmen getrennt', true, 1),
    ('OPS-A', 'information', 'Offene Punkte als Tasks modelliert', true, 2),
    ('OPS-A', 'information', 'Benötigte Dokumente/Referenzen verlinkt', true, 3),
    ('OPS-A', 'information', 'Risiko-/Impact-Notiz', true, 4)
ON CONFLICT DO NOTHING;

-- Phase Requirements: OPS-C (Entscheidungsreife)
INSERT INTO phase_requirement_definition (phase_code, requirement_type, description, is_mandatory, order_index) VALUES
    ('OPS-C', 'decision', 'Empfehlung mit Vor-/Nachteilen', true, 1),
    ('OPS-C', 'decision', 'Klare Entscheidungsvorlage', true, 2),
    ('OPS-C', 'information', 'Vollständigkeitscheck (wenn an Admin)', true, 3)
ON CONFLICT DO NOTHING;


