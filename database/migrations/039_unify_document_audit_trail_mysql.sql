-- ============================================================================
-- TOM3 Migration 039: Vereinheitlichung document_audit_trail
-- ============================================================================
-- Passt document_audit_trail an die Standard-Struktur an (wie org_audit_trail
-- und person_audit_trail), um Konsistenz zu gewährleisten.
-- ============================================================================

-- ============================================================================
-- Schritt 1: Temporäre Tabelle für Daten-Migration
-- ============================================================================

CREATE TABLE IF NOT EXISTS document_audit_trail_backup AS
SELECT * FROM document_audit_trail;

-- ============================================================================
-- Schritt 2: Alte Tabelle löschen
-- ============================================================================

DROP TABLE IF EXISTS document_audit_trail;

-- ============================================================================
-- Schritt 3: Neue vereinheitlichte Struktur erstellen
-- ============================================================================

CREATE TABLE document_audit_trail (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    document_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(100) NOT NULL COMMENT 'User-ID des Bearbeiters',
    action VARCHAR(50) NOT NULL COMMENT 'create | update | delete | attach | detach | block | unblock | version_create | download | preview',
    field_name VARCHAR(100) COMMENT 'Name des geänderten Feldes (z.B. blob_uuid, status, classification, title)',
    old_value TEXT COMMENT 'Alter Wert (JSON für komplexe Objekte)',
    new_value TEXT COMMENT 'Neuer Wert (JSON für komplexe Objekte)',
    change_type VARCHAR(50) COMMENT 'upload | attach | detach | delete | block | unblock | version_create | download | preview | field_change',
    metadata JSON COMMENT 'Zusätzliche Metadaten (z.B. blob_uuid, entity_type, entity_uuid, ...)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE,
    INDEX idx_document_audit_document (document_uuid),
    INDEX idx_document_audit_user (user_id),
    INDEX idx_document_audit_created (created_at),
    INDEX idx_document_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE document_audit_trail COMMENT = 'Audit-Trail für Dokumentenänderungen (vereinheitlichte Struktur)';

-- ============================================================================
-- Schritt 4: Daten migrieren
-- ============================================================================

INSERT INTO document_audit_trail (
    document_uuid,
    user_id,
    action,
    field_name,
    old_value,
    new_value,
    change_type,
    metadata,
    created_at
)
SELECT 
    document_uuid,
    CAST(user_id AS CHAR(100)) AS user_id, -- Konvertiere INT zu VARCHAR
    CASE 
        WHEN action = 'upload' THEN 'create'
        WHEN action IN ('attach', 'detach', 'block', 'unblock', 'version_create', 'download', 'preview') THEN action
        WHEN action = 'delete' THEN 'delete'
        ELSE 'update'
    END AS action,
    CASE 
        WHEN blob_uuid IS NOT NULL THEN 'blob_uuid'
        ELSE NULL
    END AS field_name,
    NULL AS old_value,
    blob_uuid AS new_value,
    action AS change_type, -- Original action wird zu change_type
    CASE 
        WHEN blob_uuid IS NOT NULL OR entity_type IS NOT NULL OR entity_uuid IS NOT NULL THEN
            CONCAT(
                '{',
                IF(blob_uuid IS NOT NULL, CONCAT('"blob_uuid":"', REPLACE(blob_uuid, '"', '\\"'), '"'), ''),
                IF(blob_uuid IS NOT NULL AND (entity_type IS NOT NULL OR entity_uuid IS NOT NULL), ',', ''),
                IF(entity_type IS NOT NULL, CONCAT('"entity_type":"', REPLACE(entity_type, '"', '\\"'), '"'), ''),
                IF(entity_type IS NOT NULL AND entity_uuid IS NOT NULL, ',', ''),
                IF(entity_uuid IS NOT NULL, CONCAT('"entity_uuid":"', REPLACE(entity_uuid, '"', '\\"'), '"'), ''),
                '}'
            )
        ELSE NULL
    END AS metadata,
    created_at
FROM document_audit_trail_backup
WHERE document_uuid IS NOT NULL;

-- ============================================================================
-- Schritt 5: Backup-Tabelle löschen (optional, kann manuell gemacht werden)
-- ============================================================================

-- DROP TABLE IF EXISTS document_audit_trail_backup;

-- ============================================================================
-- Hinweis: Backup-Tabelle kann nach erfolgreicher Migration manuell gelöscht werden
-- ============================================================================


