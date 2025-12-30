-- TOM3 - Users and Roles
-- User-Verwaltung mit Rollen (1:n Beziehung)

-- ============================================================================
-- ROLES (Rollen-Definitionen)
-- ============================================================================
CREATE TABLE role (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'admin | user | readonly | manager | etc.',
    role_name VARCHAR(100) NOT NULL COMMENT 'Anzeigename der Rolle',
    description TEXT COMMENT 'Beschreibung der Rolle',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_role_code ON role(role_code);

-- ============================================================================
-- USERS (Benutzer)
-- ============================================================================
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_active ON users(is_active);

-- ============================================================================
-- USER_ROLES (User ↔ Role M:N Beziehung)
-- ============================================================================
CREATE TABLE user_role (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by_user_id INT NULL COMMENT 'Wer hat die Rolle zugewiesen',
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES role(role_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_user_role_user ON user_role(user_id);
CREATE INDEX idx_user_role_role ON user_role(role_id);

-- ============================================================================
-- DEFAULT ROLES
-- ============================================================================
INSERT INTO role (role_code, role_name, description) VALUES
('admin', 'Administrator', 'Vollzugriff auf alle Funktionen'),
('user', 'Benutzer', 'Standard-Benutzer mit Lese- und Schreibrechten'),
('readonly', 'Nur Lesen', 'Nur Lesezugriff'),
('manager', 'Manager', 'Erweiterte Rechte für Management-Funktionen');

-- ============================================================================
-- DEFAULT DEV USERS (nur für Entwicklung)
-- ============================================================================
INSERT INTO users (email, name, is_active) VALUES
('dev-admin@tom.local', 'Dev Administrator', 1),
('dev-user@tom.local', 'Dev Benutzer', 1),
('dev-readonly@tom.local', 'Dev Nur-Lesen', 1),
('dev-manager@tom.local', 'Dev Manager', 1);

-- Zuweisung der Rollen zu Dev-Usern
INSERT INTO user_role (user_id, role_id)
SELECT u.user_id, r.role_id
FROM users u
CROSS JOIN role r
WHERE u.email = 'dev-admin@tom.local' AND r.role_code = 'admin'
   OR u.email = 'dev-user@tom.local' AND r.role_code = 'user'
   OR u.email = 'dev-readonly@tom.local' AND r.role_code = 'readonly'
   OR u.email = 'dev-manager@tom.local' AND r.role_code = 'manager';



