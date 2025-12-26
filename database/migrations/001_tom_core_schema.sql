-- TOM3 - Core Schema
-- Basierend auf TOM-Spezifikation
-- PostgreSQL

-- Extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- ============================================================================
-- ORG (Kunde/Lieferant/Berater etc. im operativen TOM)
-- ============================================================================
CREATE TABLE org (
    org_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    org_kind TEXT NOT NULL, -- customer | supplier | consultant | engineering_firm | internal | other
    external_ref TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_org_kind ON org(org_kind);
CREATE INDEX idx_org_name ON org(name);

COMMENT ON TABLE org IS 'Organisationen (Kunde, Lieferant, Berater, etc.)';
COMMENT ON COLUMN org.org_kind IS 'customer | supplier | consultant | engineering_firm | internal | other';

-- ============================================================================
-- PERSON / CONTACT
-- ============================================================================
CREATE TABLE person (
    person_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    display_name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_person_email ON person(email) WHERE email IS NOT NULL;
CREATE INDEX idx_person_name ON person(display_name);

COMMENT ON TABLE person IS 'Personen/Kontakte';

-- ============================================================================
-- Zugehörigkeit Person ↔ Org (zeitlich)
-- ============================================================================
CREATE TABLE person_affiliation (
    person_uuid UUID NOT NULL REFERENCES person(person_uuid) ON DELETE CASCADE,
    org_uuid UUID NOT NULL REFERENCES org(org_uuid) ON DELETE CASCADE,
    kind TEXT NOT NULL, -- employee | contractor | advisor | other
    title TEXT,
    since_date DATE,
    until_date DATE,
    PRIMARY KEY (person_uuid, org_uuid, kind, COALESCE(since_date, DATE '1900-01-01'))
);

CREATE INDEX idx_person_affiliation_org ON person_affiliation(org_uuid);
CREATE INDEX idx_person_affiliation_person ON person_affiliation(person_uuid);

COMMENT ON TABLE person_affiliation IS 'Zugehörigkeit Person ↔ Org (zeitlich)';

-- ============================================================================
-- PROJECT (Container)
-- ============================================================================
CREATE TABLE project (
    project_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active', -- active | on_hold | closed
    priority INTEGER,
    target_date DATE,
    sponsor_org_uuid UUID REFERENCES org(org_uuid), -- Kunde/Owner-Org
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_project_status ON project(status);
CREATE INDEX idx_project_sponsor ON project(sponsor_org_uuid);

COMMENT ON TABLE project IS 'Projekte (Container für Vorgänge)';
COMMENT ON COLUMN project.status IS 'active | on_hold | closed';

-- ============================================================================
-- Projektpartner (Lieferanten/Berater/Engineering etc.)
-- ============================================================================
CREATE TABLE project_partner (
    project_uuid UUID NOT NULL REFERENCES project(project_uuid) ON DELETE CASCADE,
    org_uuid UUID NOT NULL REFERENCES org(org_uuid) ON DELETE RESTRICT,
    relation TEXT NOT NULL, -- delivers | advises | participates
    scope TEXT,
    contract_ref TEXT,
    PRIMARY KEY (project_uuid, org_uuid, relation)
);

CREATE INDEX idx_project_partner_project ON project_partner(project_uuid);
CREATE INDEX idx_project_partner_org ON project_partner(org_uuid);

COMMENT ON TABLE project_partner IS 'Projektpartner (Lieferanten, Berater, etc.)';

-- ============================================================================
-- Projekt-Stakeholder (Personen, egal aus welcher Org)
-- ============================================================================
CREATE TABLE project_stakeholder (
    project_uuid UUID NOT NULL REFERENCES project(project_uuid) ON DELETE CASCADE,
    person_uuid UUID NOT NULL REFERENCES person(person_uuid) ON DELETE RESTRICT,
    role TEXT NOT NULL, -- Decider | Influencer | User | etc.
    influence INTEGER, -- 1..5
    decision_power INTEGER, -- 0..100
    since_date DATE,
    until_date DATE,
    PRIMARY KEY (project_uuid, person_uuid, role, COALESCE(since_date, DATE '1900-01-01'))
);

CREATE INDEX idx_project_stakeholder_project ON project_stakeholder(project_uuid);
CREATE INDEX idx_project_stakeholder_person ON project_stakeholder(person_uuid);

COMMENT ON TABLE project_stakeholder IS 'Projekt-Stakeholder (Personen, egal aus welcher Org)';

-- ============================================================================
-- CASE / Vorgang (Motor)
-- ============================================================================
CREATE TABLE case_item (
    case_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    case_type TEXT NOT NULL,
    engine TEXT NOT NULL, -- customer_inbound | ops | inside_sales | outside_sales | order_admin
    phase TEXT NOT NULL, -- CI-A, CI-B, OPS-A, ...
    status TEXT NOT NULL, -- berechnet: neu | in_bearbeitung | wartend_intern | wartend_extern | blockiert | eskaliert | abgeschlossen
    owner_role TEXT NOT NULL,
    owner_user_id TEXT,
    org_uuid UUID REFERENCES org(org_uuid),
    project_uuid UUID REFERENCES project(project_uuid),
    title TEXT,
    description TEXT,
    category TEXT,
    priority INTEGER,
    due_at TIMESTAMPTZ,
    opened_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    closed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_case_engine_status ON case_item(engine, status);
CREATE INDEX idx_case_owner_role ON case_item(owner_role);
CREATE INDEX idx_case_org ON case_item(org_uuid);
CREATE INDEX idx_case_project ON case_item(project_uuid);
CREATE INDEX idx_case_status ON case_item(status);

COMMENT ON TABLE case_item IS 'Vorgang (Motor des Systems)';
COMMENT ON COLUMN case_item.status IS 'Berechnet: neu | in_bearbeitung | wartend_intern | wartend_extern | blockiert | eskaliert | abgeschlossen';

-- ============================================================================
-- PROJECT ↔ CASE (M:N)
-- ============================================================================
CREATE TABLE project_case (
    project_uuid UUID NOT NULL REFERENCES project(project_uuid) ON DELETE CASCADE,
    case_uuid UUID NOT NULL REFERENCES case_item(case_uuid) ON DELETE CASCADE,
    workstream TEXT,
    is_primary BOOLEAN NOT NULL DEFAULT false,
    PRIMARY KEY (project_uuid, case_uuid)
);

CREATE INDEX idx_project_case_project ON project_case(project_uuid);
CREATE INDEX idx_project_case_case ON project_case(case_uuid);

COMMENT ON TABLE project_case IS 'Verknüpfung Projekt ↔ Vorgang';

-- ============================================================================
-- TASKS (Aufgaben)
-- ============================================================================
CREATE TABLE task (
    task_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    case_uuid UUID NOT NULL REFERENCES case_item(case_uuid) ON DELETE CASCADE,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open', -- open | done | cancelled
    assignee_role TEXT,
    assignee_user_id TEXT,
    due_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    done_at TIMESTAMPTZ
);

CREATE INDEX idx_task_case_status ON task(case_uuid, status);
CREATE INDEX idx_task_assignee ON task(assignee_role, assignee_user_id);

COMMENT ON TABLE task IS 'Aufgaben innerhalb eines Vorgangs';

-- ============================================================================
-- TIMELINE / NOTES
-- ============================================================================
CREATE TABLE case_note (
    note_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    case_uuid UUID NOT NULL REFERENCES case_item(case_uuid) ON DELETE CASCADE,
    note_type TEXT NOT NULL, -- event | comment | handover | return | decision | system
    author_user_id TEXT,
    body TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_case_note_case ON case_note(case_uuid);
CREATE INDEX idx_case_note_type ON case_note(note_type);

COMMENT ON TABLE case_note IS 'Notizen/Timeline-Einträge zu Vorgängen';

-- ============================================================================
-- CASE REQUIREMENTS (Pflichtoutputs / Blocker)
-- ============================================================================
CREATE TABLE case_requirement (
    requirement_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    case_uuid UUID NOT NULL REFERENCES case_item(case_uuid) ON DELETE CASCADE,
    requirement_type TEXT NOT NULL, -- document | information | decision | approval | etc.
    description TEXT NOT NULL,
    is_fulfilled BOOLEAN NOT NULL DEFAULT false,
    fulfilled_at TIMESTAMPTZ,
    fulfilled_by_user_id TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_case_requirement_case ON case_requirement(case_uuid);
CREATE INDEX idx_case_requirement_fulfilled ON case_requirement(case_uuid, is_fulfilled) WHERE is_fulfilled = false;

COMMENT ON TABLE case_requirement IS 'Pflichtoutputs / Blocker für Vorgänge';

-- ============================================================================
-- CASE HANDOVER (Übergaben)
-- ============================================================================
CREATE TABLE case_handover (
    handover_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    case_uuid UUID NOT NULL REFERENCES case_item(case_uuid) ON DELETE CASCADE,
    from_role TEXT NOT NULL,
    to_role TEXT NOT NULL,
    justification TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by_user_id TEXT
);

CREATE INDEX idx_case_handover_case ON case_handover(case_uuid);
CREATE INDEX idx_case_handover_to_role ON case_handover(to_role);

COMMENT ON TABLE case_handover IS 'Übergaben zwischen Rollen';

-- ============================================================================
-- CASE RETURN (Rückläufer)
-- ============================================================================
CREATE TABLE case_return (
    return_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    case_uuid UUID NOT NULL REFERENCES case_item(case_uuid) ON DELETE CASCADE,
    from_role TEXT NOT NULL,
    to_role TEXT NOT NULL,
    reason TEXT NOT NULL, -- Pflichtfeld
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by_user_id TEXT
);

CREATE INDEX idx_case_return_case ON case_return(case_uuid);
CREATE INDEX idx_case_return_to_role ON case_return(to_role);

COMMENT ON TABLE case_return IS 'Rückläufer zwischen Rollen';

-- ============================================================================
-- OUTBOX (für Sync nach Neo4j & Integrationen)
-- ============================================================================
CREATE TABLE outbox_event (
    event_uuid UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    aggregate_type TEXT NOT NULL, -- org | person | project | case_item
    aggregate_uuid UUID NOT NULL,
    event_type TEXT NOT NULL, -- OrgCreated | PersonUpdated | etc.
    payload JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at TIMESTAMPTZ
);

CREATE INDEX idx_outbox_unprocessed ON outbox_event(processed_at) WHERE processed_at IS NULL;
CREATE INDEX idx_outbox_aggregate ON outbox_event(aggregate_type, aggregate_uuid);
CREATE INDEX idx_outbox_created ON outbox_event(created_at);

COMMENT ON TABLE outbox_event IS 'Event-Outbox für Sync nach Neo4j';

-- ============================================================================
-- Status-Berechnungsfunktion
-- ============================================================================
CREATE OR REPLACE FUNCTION calculate_case_status(
    p_case_uuid UUID
) RETURNS TEXT AS $$
DECLARE
    v_case RECORD;
    v_has_open_tasks BOOLEAN;
    v_has_unfulfilled_requirements BOOLEAN;
    v_is_waiting_external BOOLEAN;
    v_is_waiting_internal BOOLEAN;
    v_is_escalated BOOLEAN;
BEGIN
    -- Hole Vorgang
    SELECT * INTO v_case FROM case_item WHERE case_uuid = p_case_uuid;
    
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;
    
    -- Prüfe auf offene Tasks
    SELECT EXISTS(
        SELECT 1 FROM task 
        WHERE case_uuid = p_case_uuid 
          AND status = 'open'
    ) INTO v_has_open_tasks;
    
    -- Prüfe auf unerfüllte Requirements
    SELECT EXISTS(
        SELECT 1 FROM case_requirement 
        WHERE case_uuid = p_case_uuid 
          AND is_fulfilled = false
    ) INTO v_has_unfulfilled_requirements;
    
    -- Prüfe auf externe Wartezeiten (z.B. durch Tasks mit externem Assignee)
    SELECT EXISTS(
        SELECT 1 FROM task 
        WHERE case_uuid = p_case_uuid 
          AND status = 'open'
          AND assignee_role LIKE '%external%'
    ) INTO v_is_waiting_external;
    
    -- Prüfe auf interne Wartezeiten
    SELECT EXISTS(
        SELECT 1 FROM task 
        WHERE case_uuid = p_case_uuid 
          AND status = 'open'
          AND assignee_role NOT LIKE '%external%'
    ) INTO v_is_waiting_internal;
    
    -- Prüfe auf Eskalation (z.B. überfällige Tasks)
    SELECT EXISTS(
        SELECT 1 FROM task 
        WHERE case_uuid = p_case_uuid 
          AND status = 'open'
          AND due_at < now()
    ) INTO v_is_escalated;
    
    -- Status-Logik
    IF v_case.closed_at IS NOT NULL THEN
        RETURN 'abgeschlossen';
    ELSIF v_has_unfulfilled_requirements THEN
        RETURN 'blockiert';
    ELSIF v_is_escalated THEN
        RETURN 'eskaliert';
    ELSIF v_is_waiting_external THEN
        RETURN 'wartend_extern';
    ELSIF v_is_waiting_internal THEN
        RETURN 'wartend_intern';
    ELSIF v_has_open_tasks OR v_case.owner_role IS NOT NULL THEN
        RETURN 'in_bearbeitung';
    ELSE
        RETURN 'neu';
    END IF;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION calculate_case_status IS 'Berechnet den Status eines Vorgangs basierend auf Tasks, Requirements und anderen Faktoren';


