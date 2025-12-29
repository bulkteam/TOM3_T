-- TOM3 - Users Audit Fields
-- Erweitert die users Tabelle um Audit-Felder für bessere Nachvollziehbarkeit

ALTER TABLE users
ADD COLUMN created_by_user_id INT NULL COMMENT 'User-ID des Erstellers';

ALTER TABLE users
ADD COLUMN disabled_at DATETIME NULL COMMENT 'Wann wurde der User deaktiviert';

ALTER TABLE users
ADD COLUMN disabled_by_user_id INT NULL COMMENT 'User-ID des Deaktivierers';

CREATE INDEX idx_users_created_by ON users(created_by_user_id);

CREATE INDEX idx_users_disabled_by ON users(disabled_by_user_id);

ALTER TABLE users
ADD FOREIGN KEY (created_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL;

ALTER TABLE users
ADD FOREIGN KEY (disabled_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL;

