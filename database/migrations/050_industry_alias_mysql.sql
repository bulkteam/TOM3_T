-- TOM3 - Industry Alias (Learning für besseres Matching)
-- Erstellt Tabelle für Alias-Learning

-- ============================================================================
-- INDUSTRY ALIAS (Learning)
-- ============================================================================
CREATE TABLE industry_alias (
  alias_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  alias VARCHAR(255) NOT NULL COMMENT 'Normalisierter Alias (z.B. "chemieindustrie")',
  industry_uuid CHAR(36) NOT NULL COMMENT 'Verknüpfung zur Industry',
  level TINYINT NOT NULL COMMENT '1|2|3 (Branchenbereich|Branche|Unterbranche)',
  source VARCHAR(30) NOT NULL DEFAULT 'user' COMMENT 'user|import|system',
  created_by_user_id VARCHAR(255) NULL COMMENT 'Wer hat den Alias erstellt',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  UNIQUE KEY uq_alias_level (alias, level),
  FOREIGN KEY (industry_uuid) REFERENCES industry(industry_uuid) ON DELETE CASCADE,
  INDEX idx_alias_industry (industry_uuid),
  INDEX idx_alias_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Alias-Learning: System lernt aus Bestätigungen (z.B. "Chemieindustrie" → C20)';
