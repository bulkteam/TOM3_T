-- TOM3 - CRM Aktivitäten, Verknüpfungen und Threads
-- Minimalmodell für CRM-Timeline: Aktivitäten, Links, Teilnehmer, Threads

-- ============================================================================
-- CRM Aktivitäten (strukturierte Interaktionen)
-- ============================================================================
CREATE TABLE IF NOT EXISTS crm_activity (
    activity_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL COMMENT 'email | call | meeting | request | note | handoff',
    outcome_code VARCHAR(50) NULL,
    follow_up_at DATETIME NULL,
    payload_json LONGTEXT NULL COMMENT 'JSON-Details (Betreff, Body, Dauer, etc.)',
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_crm_activity_type_date ON crm_activity(type, created_at);
CREATE INDEX idx_crm_activity_follow_up ON crm_activity(follow_up_at);

-- ============================================================================
-- Aktivitäts-Verknüpfungen zu Entitäten
-- ============================================================================
CREATE TABLE IF NOT EXISTS activity_link (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    activity_id BIGINT NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'org | person | case | task | project',
    entity_uuid CHAR(36) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'primary' COMMENT 'primary | participant | related',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_link_activity
        FOREIGN KEY (activity_id) REFERENCES crm_activity(activity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_activity_link_entity ON activity_link(entity_type, entity_uuid, created_at);
CREATE INDEX idx_activity_link_activity ON activity_link(activity_id);

-- ============================================================================
-- Aktivitäts-Teilnehmer (User/Person)
-- ============================================================================
CREATE TABLE IF NOT EXISTS activity_participant (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    activity_id BIGINT NOT NULL,
    person_uuid CHAR(36) NULL,
    user_id VARCHAR(100) NULL,
    role VARCHAR(20) NOT NULL COMMENT 'sender | recipient | attendee',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_participant_activity
        FOREIGN KEY (activity_id) REFERENCES crm_activity(activity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_activity_participant_activity ON activity_participant(activity_id);

-- ============================================================================
-- Threads (Konversationskanäle je Entität)
-- ============================================================================
CREATE TABLE IF NOT EXISTS timeline_thread (
    thread_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,
    entity_uuid CHAR(36) NOT NULL,
    subject VARCHAR(255) NULL,
    topic_code VARCHAR(50) NULL,
    primary_person_uuid CHAR(36) NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_timeline_thread_entity ON timeline_thread(entity_type, entity_uuid, created_at);

CREATE TABLE IF NOT EXISTS thread_activity (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT NOT NULL,
    activity_id BIGINT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_thread_activity_thread
        FOREIGN KEY (thread_id) REFERENCES timeline_thread(thread_id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_activity_activity
        FOREIGN KEY (activity_id) REFERENCES crm_activity(activity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_thread_activity_thread ON thread_activity(thread_id);
CREATE INDEX idx_thread_activity_activity ON thread_activity(activity_id);

-- ============================================================================
-- Org Stage Feld + History
-- ============================================================================
ALTER TABLE org 
    ADD COLUMN IF NOT EXISTS current_stage VARCHAR(50) NULL COMMENT 'Detaillierter Lifecycle-Stage',
    ADD INDEX idx_org_current_stage (current_stage);

CREATE TABLE IF NOT EXISTS org_stage_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    stage_name VARCHAR(50) NOT NULL,
    reason_code VARCHAR(50) NULL,
    changed_by VARCHAR(100) NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_org_stage_history_org
        FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_org_stage_history_org ON org_stage_history(org_uuid, changed_at);

