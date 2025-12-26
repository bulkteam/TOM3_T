-- TOM3 - Core Schema
-- Basierend auf TOM-Spezifikation
-- MySQL/MariaDB

-- ============================================================================
-- ORG (Kunde/Lieferant/Berater etc. im operativen TOM)
-- ============================================================================
CREATE TABLE org (
    org_uuid CHAR(36) PRIMARY KEY,
    name TEXT NOT NULL,
    org_kind VARCHAR(50) NOT NULL COMMENT 'customer | supplier | consultant | engineering_firm | internal | other',
    external_ref TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_kind ON org(org_kind);
CREATE INDEX idx_org_name ON org(name(255));

-- ============================================================================
-- PERSON / CONTACT
-- ============================================================================
CREATE TABLE person (
    person_uuid CHAR(36) PRIMARY KEY,
    display_name TEXT NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(50),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_person_email ON person(email);
CREATE INDEX idx_person_name ON person(display_name(255));

-- ============================================================================
-- Zugehörigkeit Person ↔ Org (zeitlich)
-- ============================================================================
CREATE TABLE person_affiliation (
    person_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    kind VARCHAR(50) NOT NULL COMMENT 'employee | contractor | advisor | other',
    title TEXT,
    since_date DATE DEFAULT '1900-01-01',
    until_date DATE,
    PRIMARY KEY (person_uuid, org_uuid, kind, since_date),
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_person_affiliation_org ON person_affiliation(org_uuid);
CREATE INDEX idx_person_affiliation_person ON person_affiliation(person_uuid);

-- ============================================================================
-- PROJECT (Container)
-- ============================================================================
CREATE TABLE project (
    project_uuid CHAR(36) PRIMARY KEY,
    name TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | on_hold | closed',
    priority INT,
    target_date DATE,
    sponsor_org_uuid CHAR(36),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sponsor_org_uuid) REFERENCES org(org_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_project_status ON project(status);
CREATE INDEX idx_project_sponsor ON project(sponsor_org_uuid);

-- ============================================================================
-- Projektpartner (Lieferanten/Berater/Engineering etc.)
-- ============================================================================
CREATE TABLE project_partner (
    project_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    relation VARCHAR(50) NOT NULL COMMENT 'delivers | advises | participates',
    scope TEXT,
    contract_ref TEXT,
    PRIMARY KEY (project_uuid, org_uuid, relation),
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_project_partner_project ON project_partner(project_uuid);
CREATE INDEX idx_project_partner_org ON project_partner(org_uuid);

-- ============================================================================
-- Projekt-Stakeholder (Personen, egal aus welcher Org)
-- ============================================================================
CREATE TABLE project_stakeholder (
    project_uuid CHAR(36) NOT NULL,
    person_uuid CHAR(36) NOT NULL,
    role VARCHAR(50) NOT NULL COMMENT 'Decider | Influencer | User | etc.',
    influence INT COMMENT '1..5',
    decision_power INT COMMENT '0..100',
    since_date DATE DEFAULT '1900-01-01',
    until_date DATE,
    PRIMARY KEY (project_uuid, person_uuid, role, since_date),
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid) ON DELETE CASCADE,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_project_stakeholder_project ON project_stakeholder(project_uuid);
CREATE INDEX idx_project_stakeholder_person ON project_stakeholder(person_uuid);

-- ============================================================================
-- CASE / Vorgang (Motor)
-- ============================================================================
CREATE TABLE case_item (
    case_uuid CHAR(36) PRIMARY KEY,
    case_type VARCHAR(50) NOT NULL,
    engine VARCHAR(50) NOT NULL COMMENT 'customer_inbound | ops | inside_sales | outside_sales | order_admin',
    phase VARCHAR(50) NOT NULL COMMENT 'CI-A, CI-B, OPS-A, ...',
    status VARCHAR(50) NOT NULL COMMENT 'Berechnet: neu | in_bearbeitung | wartend_intern | wartend_extern | blockiert | eskaliert | abgeschlossen',
    owner_role VARCHAR(50) NOT NULL,
    owner_user_id VARCHAR(255),
    org_uuid CHAR(36),
    project_uuid CHAR(36),
    title TEXT,
    description TEXT,
    category VARCHAR(100),
    priority INT,
    due_at DATETIME,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid),
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_case_engine_status ON case_item(engine, status);
CREATE INDEX idx_case_owner_role ON case_item(owner_role);
CREATE INDEX idx_case_org ON case_item(org_uuid);
CREATE INDEX idx_case_project ON case_item(project_uuid);
CREATE INDEX idx_case_status ON case_item(status);

-- ============================================================================
-- PROJECT ↔ CASE (M:N)
-- ============================================================================
CREATE TABLE project_case (
    project_uuid CHAR(36) NOT NULL,
    case_uuid CHAR(36) NOT NULL,
    workstream VARCHAR(100),
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (project_uuid, case_uuid),
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid) ON DELETE CASCADE,
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_project_case_project ON project_case(project_uuid);
CREATE INDEX idx_project_case_case ON project_case(case_uuid);

-- ============================================================================
-- TASKS (Aufgaben)
-- ============================================================================
CREATE TABLE task (
    task_uuid CHAR(36) PRIMARY KEY,
    case_uuid CHAR(36) NOT NULL,
    title TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open | done | cancelled',
    assignee_role VARCHAR(50),
    assignee_user_id VARCHAR(255),
    due_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    done_at DATETIME,
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_task_case_status ON task(case_uuid, status);
CREATE INDEX idx_task_assignee ON task(assignee_role, assignee_user_id);

-- ============================================================================
-- TIMELINE / NOTES
-- ============================================================================
CREATE TABLE case_note (
    note_uuid CHAR(36) PRIMARY KEY,
    case_uuid CHAR(36) NOT NULL,
    note_type VARCHAR(50) NOT NULL COMMENT 'event | comment | handover | return | decision | system',
    author_user_id VARCHAR(255),
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_case_note_case ON case_note(case_uuid);
CREATE INDEX idx_case_note_type ON case_note(note_type);

-- ============================================================================
-- CASE REQUIREMENTS (Pflichtoutputs / Blocker)
-- ============================================================================
CREATE TABLE case_requirement (
    requirement_uuid CHAR(36) PRIMARY KEY,
    case_uuid CHAR(36) NOT NULL,
    requirement_type VARCHAR(50) NOT NULL COMMENT 'document | information | decision | approval | etc.',
    description TEXT NOT NULL,
    is_fulfilled TINYINT(1) NOT NULL DEFAULT 0,
    fulfilled_at DATETIME,
    fulfilled_by_user_id VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_case_requirement_case ON case_requirement(case_uuid);
CREATE INDEX idx_case_requirement_fulfilled ON case_requirement(case_uuid, is_fulfilled);

-- ============================================================================
-- CASE HANDOVER (Übergaben)
-- ============================================================================
CREATE TABLE case_handover (
    handover_uuid CHAR(36) PRIMARY KEY,
    case_uuid CHAR(36) NOT NULL,
    from_role VARCHAR(50) NOT NULL,
    to_role VARCHAR(50) NOT NULL,
    justification TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id VARCHAR(255),
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_case_handover_case ON case_handover(case_uuid);
CREATE INDEX idx_case_handover_to_role ON case_handover(to_role);

-- ============================================================================
-- CASE RETURN (Rückläufer)
-- ============================================================================
CREATE TABLE case_return (
    return_uuid CHAR(36) PRIMARY KEY,
    case_uuid CHAR(36) NOT NULL,
    from_role VARCHAR(50) NOT NULL,
    to_role VARCHAR(50) NOT NULL,
    reason TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id VARCHAR(255),
    FOREIGN KEY (case_uuid) REFERENCES case_item(case_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_case_return_case ON case_return(case_uuid);
CREATE INDEX idx_case_return_to_role ON case_return(to_role);

-- ============================================================================
-- OUTBOX (für Sync nach Neo4j & Integrationen)
-- ============================================================================
CREATE TABLE outbox_event (
    event_uuid CHAR(36) PRIMARY KEY,
    aggregate_type VARCHAR(50) NOT NULL COMMENT 'org | person | project | case_item',
    aggregate_uuid CHAR(36) NOT NULL,
    event_type VARCHAR(100) NOT NULL COMMENT 'OrgCreated | PersonUpdated | etc.',
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_outbox_unprocessed ON outbox_event(processed_at);
CREATE INDEX idx_outbox_aggregate ON outbox_event(aggregate_type, aggregate_uuid);
CREATE INDEX idx_outbox_created ON outbox_event(created_at);

