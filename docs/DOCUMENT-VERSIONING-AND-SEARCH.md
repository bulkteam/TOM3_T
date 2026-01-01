# TOM3 - Dokumenten-Versionierung und Suche

## Überblick

Dieses Dokument beschreibt die Implementierung von Versionierung und Volltext-Suche für Dokumente.

## 1. Volltext-Suche (✅ Implementiert)

### MariaDB FULLTEXT Index

**Status:** ✅ Bereits in Migration 036 vorhanden

```sql
FULLTEXT idx_extracted_text (extracted_text)
FULLTEXT idx_title (title)
```

### Service-Methoden

**Datei:** `src/TOM/Service/DocumentService.php`

- `searchDocuments($query, $filters)` - Suche in `extracted_text`
- `searchDocumentsInTitle($query, $filters)` - Fallback: Suche in `title`

### API-Endpunkt

```
GET /api/documents/search?q={query}&entity_type={type}&entity_uuid={uuid}&classification={class}&tags={tag1,tag2}&limit={limit}
```

**Parameter:**
- `q` (required): Suchbegriff
- `entity_type` (optional): Filter nach Entität-Typ
- `entity_uuid` (optional): Filter nach Entität-UUID
- `classification` (optional): Filter nach Klassifikation
- `tags` (optional): Kommagetrennte Tags
- `limit` (optional): Max. Ergebnisse (default: 50)

**Response:**
```json
[
  {
    "document_uuid": "...",
    "title": "...",
    "relevance_score": 1.5,
    "extracted_text": "...",
    ...
  }
]
```

### Features

- ✅ NATURAL LANGUAGE MODE (Standard)
- ✅ Relevanz-Score (sortiert nach Score)
- ✅ Filter: Entity, Klassifikation, Tags
- ✅ Fallback: Titel-Suche wenn keine Text-Extraktion vorhanden

### Erweiterte Suche (später)

Für BOOLEAN MODE (Pflichtwörter, Exklusionen):

```php
// Beispiel: +rechnung +müller -entwurf
MATCH(d.extracted_text) AGAINST('+rechnung +müller -entwurf' IN BOOLEAN MODE)
```

**Status:** Nicht implementiert, kann später hinzugefügt werden.

## 2. Versionierung (✅ Implementiert)

### Status

**✅ Vollständig implementiert:**
- ✅ `documents.version_group_uuid` - UUID für Version-Gruppe
- ✅ `documents.version_number` - Versionsnummer
- ✅ `documents.is_current_version` - Flag für aktuelle Version
- ✅ `documents.supersedes_document_uuid` - Verknüpfung zur Vorgänger-Version
- ✅ `document_groups` Tabelle (Migration 038)
- ✅ API-Endpunkte für Versionierung
- ✅ Race-Condition-sichere Version-Erstellung (FOR UPDATE)

### Implementierung

#### Migration 038 ✅

```sql
CREATE TABLE document_groups (
    group_uuid CHAR(36) PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
    current_document_uuid CHAR(36) NULL,
    title VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED,
    
    INDEX idx_current (tenant_id, current_document_uuid),
    FOREIGN KEY (current_document_uuid) REFERENCES documents(document_uuid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: supersedes_document_id hinzufügen
ALTER TABLE documents
    ADD COLUMN supersedes_document_uuid CHAR(36) NULL COMMENT 'Vorgänger-Version' AFTER version_number,
    ADD INDEX idx_supersedes (supersedes_document_uuid);
```

#### API-Endpunkte ✅

```
POST /api/documents/groups/{group_uuid}/upload-version
Content-Type: multipart/form-data
Body: file, title, classification, tags[], supersede (true/false)

Response:
{
  "document_uuid": "...",
  "version_number": 2,
  "version_group_uuid": "...",
  "status": "processing"
}

GET /api/documents/groups/{group_uuid}
Response: Gruppe mit allen Versionen + aktueller Version
```

#### Race-Condition-Handling

```php
// FOR UPDATE für atomare Version-Erstellung
SELECT current_document_uuid FROM document_groups WHERE id=? FOR UPDATE;
SELECT MAX(version_number) FROM documents WHERE version_group_uuid=? FOR UPDATE;
// Neue Version mit version_number = max + 1
```

**Status:** ✅ Implementiert (Migration 038)

**Features:**
- Automatische Gruppen-Erstellung bei neuem Dokument
- Race-Condition-sichere Version-Erstellung mit `FOR UPDATE`
- Version-Historie über `supersedes_document_uuid`
- Aktuelle Version immer über `document_groups.current_document_uuid`

## 3. Attachment auf Group (❌ Nicht empfohlen)

### Vorgeschlagene Änderung

**Variante 1:** Attachment referenziert `document_group_id` statt `document_id`

**Probleme:**
- Breaking Change (alle bestehenden Attachments müssten migriert werden)
- Komplexere Queries (immer über Group → current_document)
- Nicht rückwärtskompatibel

**Status:** ❌ Nicht empfohlen für MVP

**Alternative:**
- Attachments bleiben auf `document_id`
- Bei neuer Version: Optional alte Attachment "ersetzen" (soft)
- Oder: Beide Attachments behalten (Historie)

## 4. OpenSearch (⏳ Phase 3)

### Warum später?

**MariaDB FULLTEXT ist ausreichend für:**
- MVP/kleine bis mittlere Datenmengen
- Einfache Volltext-Suche
- Basis-Relevanz-Ranking

**OpenSearch wird nötig bei:**
- Großen Datenmengen (>100k Dokumente)
- Erweiterten Features (Fuzzy, Autocomplete, Facetten)
- Semantischer Suche (Vektorsuche)
- Komplexen Filter-Kombinationen

### Migrations-Strategie

1. **Phase 1:** MariaDB FULLTEXT (aktuell)
2. **Phase 2:** Parallel-Indexierung (OpenSearch + MariaDB)
3. **Phase 3:** OpenSearch als primäre Suche

## 5. Empfehlungen

### Sofort umsetzbar ✅

1. **FULLTEXT Suche** - ✅ Implementiert
   - Index vorhanden
   - Service-Methoden vorhanden
   - API-Endpunkt vorhanden

### Implementiert ✅

2. **Versionierung mit document_groups**
   - ✅ Migration 038 ausgeführt
   - ✅ Service-Methoden implementiert
   - ✅ API-Endpunkte vorhanden
   - ✅ Race-Condition-Handling implementiert

3. **BOOLEAN MODE Suche**
   - Kann bei Bedarf hinzugefügt werden
   - Nicht kritisch

### Später/Phase 3 ⏳

4. **OpenSearch**
   - Nur wenn Datenmengen/Anforderungen wachsen
   - Parallel-Indexierung möglich

5. **Attachment auf Group**
   - Breaking Change
   - Nicht empfohlen

## 6. Nächste Schritte

1. ✅ **FULLTEXT Suche testen**
   - API-Endpunkt testen
   - Relevanz-Score prüfen
   - Filter testen

2. ✅ **Versionierung testen**
   - API-Endpunkte testen
   - Race-Condition-Handling prüfen
   - Version-Historie verifizieren

3. ⏳ **OpenSearch (später)**
   - Nur wenn nötig (ab ~100k Dokumenten)
   - Parallel-Indexierung

---

*Dokument erstellt: 2026-01-01*
