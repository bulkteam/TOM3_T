-- TOM3 - Workflow Roles and Account Team Roles
-- Migriert Workflow-Rollen und Account-Team-Rollen in die Datenbank

-- ============================================================================
-- WORKFLOW ROLE (Rollen für Vorgangs-Workflows)
-- ============================================================================
CREATE TABLE IF NOT EXISTS workflow_role (
    workflow_role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'customer_inbound | ops | inside_sales | outside_sales | order_admin',
    role_name VARCHAR(100) NOT NULL COMMENT 'Anzeigename der Rolle',
    description TEXT COMMENT 'Beschreibung der Rolle',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_workflow_role_code ON workflow_role(role_code);

-- ============================================================================
-- USER_WORKFLOW_ROLE (User ↔ Workflow-Rolle M:N Beziehung)
-- ============================================================================
CREATE TABLE IF NOT EXISTS user_workflow_role (
    user_id INT NOT NULL,
    workflow_role_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by_user_id INT NULL COMMENT 'Wer hat die Rolle zugewiesen',
    PRIMARY KEY (user_id, workflow_role_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_role_id) REFERENCES workflow_role(workflow_role_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_user_workflow_role_user ON user_workflow_role(user_id);
CREATE INDEX idx_user_workflow_role_role ON user_workflow_role(workflow_role_id);

-- ============================================================================
-- ACCOUNT TEAM ROLE (Rollen im Account-Team)
-- ============================================================================
CREATE TABLE IF NOT EXISTS account_team_role (
    account_team_role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'co_owner | support | backup | technical',
    role_name VARCHAR(100) NOT NULL COMMENT 'Anzeigename der Rolle',
    description TEXT COMMENT 'Beschreibung der Rolle',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_account_team_role_code ON account_team_role(role_code);

-- ============================================================================
-- DEFAULT WORKFLOW ROLES
-- ============================================================================
INSERT INTO workflow_role (role_code, role_name, description) VALUES
('customer_inbound', 'Customer Inbound', 'Eingehende Kundenanfragen - Annahme, Klassifikation, Routing'),
('ops', 'OPS', 'Operations - Strukturierung, Bearbeitung, Entscheidungsreife'),
('inside_sales', 'Inside Sales', 'Innendienst-Vertrieb - Wachstum, Akquise, Qualifizierung'),
('outside_sales', 'Outside Sales', 'Außendienst-Vertrieb - Entscheidungsführung, Verhandlung, Abschluss'),
('order_admin', 'Order Admin', 'Auftragsverwaltung - Formalität, Dokumente, ERP-Korrektheit')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), description = VALUES(description);

-- ============================================================================
-- DEFAULT ACCOUNT TEAM ROLES
-- ============================================================================
INSERT INTO account_team_role (role_code, role_name, description) VALUES
('co_owner', 'Co-Owner', 'Mitverantwortlicher Account Owner'),
('support', 'Support', 'Technischer Support für den Account'),
('backup', 'Backup', 'Vertretung für den Account Owner'),
('technical', 'Technical', 'Technischer Ansprechpartner')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), description = VALUES(description);





