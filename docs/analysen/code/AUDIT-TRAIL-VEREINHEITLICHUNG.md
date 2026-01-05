# Audit-Trail Vereinheitlichung

## Problem

Die Audit-Trail-Tabellen haben unterschiedliche Strukturen:

### Aktuelle Struktur

**org_audit_trail / person_audit_trail:**
```sql
- audit_id
- {entity}_uuid
- user_id
- action (create | update | delete)
- field_name
- old_value
- new_value
- change_type
- metadata (JSON)
- created_at
```

**document_audit_trail:**
```sql
- audit_id
- document_uuid
- blob_uuid (spezifisch für Dokumente)
- action (ENUM: upload, attach, detach, delete, block, unblock, version_create, download, preview)
- user_id
- entity_type (VARCHAR - verknüpfte Entität)
- entity_uuid (VARCHAR - verknüpfte Entität)
- metadata (JSON)
- created_at
```

## Warum unterschiedlich?

Die `document_audit_trail` wurde später hinzugefügt und hat eine andere Struktur, weil:
1. Dokumente haben spezielle Aktionen (upload, attach, detach, etc.)
2. Dokumente sind mit anderen Entitäten verknüpft (entity_type, entity_uuid)
3. Dokumente haben Blobs (blob_uuid)

## Lösung: Vereinheitlichung

### Option 1: document_audit_trail an Standard-Struktur anpassen

**Vorteile:**
- ✅ Konsistente Struktur
- ✅ AuditTrailService kann einheitlich arbeiten
- ✅ Einfacher zu warten

**Nachteile:**
- ⚠️ Migration nötig
- ⚠️ Spezielle Felder müssen in `metadata` JSON

**Struktur:**
```sql
CREATE TABLE document_audit_trail (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    document_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL, -- 'create' | 'update' | 'delete' | 'attach' | 'detach' | 'block' | 'unblock' | 'version_create' | 'download' | 'preview'
    field_name VARCHAR(100), -- z.B. 'blob_uuid', 'status', 'classification'
    old_value TEXT,
    new_value TEXT,
    change_type VARCHAR(50), -- 'upload' | 'attach' | 'detach' | 'delete' | 'block' | 'unblock' | 'version_create' | 'download' | 'preview' | 'field_change'
    metadata JSON, -- { blob_uuid, entity_type, entity_uuid, ... }
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_uuid) REFERENCES documents(document_uuid) ON DELETE CASCADE,
    INDEX idx_document_audit_document (document_uuid),
    INDEX idx_document_audit_user (user_id),
    INDEX idx_document_audit_created (created_at),
    INDEX idx_document_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Mapping:**
- `blob_uuid` → `metadata['blob_uuid']` oder `field_name='blob_uuid'`, `new_value={blob_uuid}`
- `entity_type` → `metadata['entity_type']`
- `entity_uuid` → `metadata['entity_uuid']`
- Spezielle Aktionen → `change_type` (upload, attach, etc.)

### Option 2: Flexible Struktur mit optionalen Feldern

**Struktur:**
```sql
CREATE TABLE document_audit_trail (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    document_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    field_name VARCHAR(100),
    old_value TEXT,
    new_value TEXT,
    change_type VARCHAR(50),
    metadata JSON,
    -- Optionale spezielle Felder (können NULL sein)
    blob_uuid CHAR(36) NULL,
    related_entity_type VARCHAR(50) NULL,
    related_entity_uuid CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ...
);
```

**Vorteile:**
- ✅ Behält spezielle Felder für Performance (direkte Indizierung)
- ✅ Kompatibel mit Standard-Struktur
- ✅ AuditTrailService kann beide Strukturen unterstützen

**Nachteile:**
- ⚠️ Komplexere Logik im Service

## Empfehlung

**Option 1 (Vereinheitlichung)** ist besser, weil:
1. Konsistenz ist wichtiger als Performance-Gewinn durch spezielle Spalten
2. `metadata` JSON kann alles speichern
3. Einfacher zu warten und zu erweitern
4. AuditTrailService wird einfacher

## Migration

1. Neue Migration erstellen: `039_unify_document_audit_trail_mysql.sql`
2. Daten migrieren:
   - `blob_uuid` → `metadata['blob_uuid']` oder `field_name='blob_uuid'`
   - `entity_type` → `metadata['entity_type']`
   - `entity_uuid` → `metadata['entity_uuid']`
   - `action` → `change_type` (wenn speziell) oder `action` (wenn create/update/delete)
3. AuditTrailService vereinfachen (spezielle Behandlung entfernen)
4. Tests anpassen

## Vorteile nach Vereinheitlichung

- ✅ Einheitliche Struktur für alle Audit-Trails
- ✅ AuditTrailService kann ohne Sonderbehandlung arbeiten
- ✅ Einfacher zu erweitern (neue Entity-Typen)
- ✅ Konsistente API
- ✅ Einfacher zu dokumentieren


