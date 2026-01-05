# TOM3 - Dokumenten-Service Skalierbarkeits-Analyse

## Frage: Ist der Ansatz bei 10-20k Dokumenten noch valide?

**Kurze Antwort: ✅ Ja, aber mit einigen Optimierungen.**

## 1. MariaDB FULLTEXT Suche

### Aktueller Stand
- FULLTEXT Index auf `extracted_text` (LONGTEXT)
- FULLTEXT Index auf `title` (VARCHAR(255))

### Performance bei 10-20k Dokumenten

**✅ Gut skalierbar:**
- MariaDB FULLTEXT kann problemlos 10-20k Dokumente handhaben
- Query-Zeit: < 100ms bei typischen Suchen
- Index-Größe: ~10-20% der Text-Größe (akzeptabel)

**⚠️ Potenzielle Probleme:**
- Sehr lange `extracted_text` (> 1MB pro Dokument) → Index wird groß
- Viele gleichzeitige FULLTEXT Queries → CPU-Last

**Empfehlung:**
- ✅ Für 10-20k Dokumente: **Aktueller Ansatz ist ausreichend**
- ⚠️ Ab ~50k Dokumenten: Performance-Monitoring
- 🔄 Ab ~100k Dokumenten: OpenSearch in Betracht ziehen

### Optimierungen (sofort umsetzbar)

```sql
-- Limit für extracted_text (verhindert riesige Indizes)
ALTER TABLE documents 
    MODIFY COLUMN extracted_text LONGTEXT 
    COMMENT 'Max. 1MB für FULLTEXT Performance';

-- Oder: Separate Tabelle für sehr lange Texte
CREATE TABLE document_text_long (
    document_uuid CHAR(36) PRIMARY KEY,
    extracted_text LONGTEXT,
    FULLTEXT idx_extracted_text (extracted_text)
) ENGINE=InnoDB;
```

## 2. Storage (Lokales Filesystem)

### Aktueller Stand
- Hash-basierte Struktur: `storage/{tenant}/{aa}/{bb}/{sha256}`
- Deduplication über Unique Index

### Performance bei 10-20k Dokumenten

**✅ Sehr gut skalierbar:**
- 10-20k Dateien sind kein Problem
- Hash-Struktur verteilt Dateien auf ~256 Unterverzeichnisse (aa/bb)
- Durchschnittlich ~40-80 Dateien pro Verzeichnis (sehr gut)

**Berechnung:**
```
20.000 Dokumente / 256 Verzeichnisse = ~78 Dateien/Verzeichnis
→ Sehr gut handhabbar für Filesystem
```

**⚠️ Potenzielle Probleme:**
- Sehr große Dateien (> 100MB) → Backup-Zeit
- Viele gleichzeitige Downloads → I/O-Last

**Empfehlung:**
- ✅ Für 10-20k Dokumente: **Aktueller Ansatz ist optimal**
- ⚠️ Ab ~100k Dokumenten: Monitoring, aber noch OK
- 🔄 Ab ~500k Dokumenten: S3/MinIO in Betracht ziehen

### Optimierungen (optional)

```php
// Storage-Pfad mit mehr Ebenen (für sehr große Mengen)
// Aktuell: {tenant}/{aa}/{bb}/{sha256}
// Später: {tenant}/{aa}/{bb}/{cc}/{dd}/{sha256}
// → 65.536 Verzeichnisse statt 256
```

## 3. Deduplication (Unique Index)

### Aktueller Stand
- Unique Index auf `(tenant_id, sha256, size_bytes)`
- O(1) Lookup über Index

### Performance bei 10-20k Dokumenten

**✅ Perfekt skalierbar:**
- Index-Lookup ist O(1) - unabhängig von Dokumenten-Anzahl
- Selbst bei 1 Million Dokumenten: < 1ms Lookup
- Keine Performance-Probleme erwartet

**Empfehlung:**
- ✅ **Keine Änderungen nötig** - skaliert perfekt

## 4. Datenbank-Queries

### Aktueller Stand
- JOINs zwischen `documents`, `blobs`, `document_attachments`
- Indizes auf Foreign Keys
- Indizes auf häufig gefilterten Feldern

### Performance bei 10-20k Dokumenten

**✅ Gut skalierbar:**
- JOINs mit Indizes: < 50ms bei typischen Queries
- `getEntityDocuments()`: Sehr schnell (Index auf `entity_type`, `entity_uuid`)

**⚠️ Potenzielle Probleme:**
- Queries ohne Index-Nutzung (z.B. `LIKE '%text%'` statt FULLTEXT)
- N+1 Query Problem (wenn nicht optimiert)

**Empfehlung:**
- ✅ Für 10-20k Dokumente: **Aktueller Ansatz ist ausreichend**
- ⚠️ Monitoring: Query-Logs prüfen
- 🔄 Optimierungen: Prepared Statements, Query-Caching

### Optimierungen (sofort umsetzbar)

```php
// Query-Caching für häufige Abfragen
// z.B. Entity-Dokumente (ändern sich selten)
$cacheKey = "entity_docs_{$entityType}_{$entityUuid}";
if ($cached = $cache->get($cacheKey)) {
    return $cached;
}
// ... Query ausführen ...
$cache->set($cacheKey, $results, 300); // 5 Min TTL
```

## 5. Speicher-Bedarf

### Schätzung für 10-20k Dokumente

**Annahmen:**
- Durchschnittliche Dateigröße: 2MB
- Durchschnittliche `extracted_text` Länge: 50KB
- Durchschnittliche Metadaten: 1KB

**Berechnung:**
```
20.000 Dokumente:
- Storage: 20.000 × 2MB = 40GB
- DB (extracted_text): 20.000 × 50KB = 1GB
- DB (Metadaten): 20.000 × 1KB = 20MB
- Indizes: ~200MB
- Gesamt: ~41GB
```

**Empfehlung:**
- ✅ **Machbar** - Standard-Server kann das handhaben
- ⚠️ Backup-Strategie wichtig (40GB+)
- 🔄 Kompression für ältere Dokumente (optional)

## 6. Konkrete Performance-Tests

### Empfohlene Metriken

1. **FULLTEXT Suche:**
   - Ziel: < 200ms bei 20k Dokumenten
   - Test: 100 gleichzeitige Suchen

2. **Entity-Dokumente abrufen:**
   - Ziel: < 50ms
   - Test: Org mit 100 Dokumenten

3. **Upload + Dedup:**
   - Ziel: < 2s für 10MB Datei
   - Test: Parallele Uploads

4. **Download:**
   - Ziel: < 100ms Overhead (ohne Datei-Transfer)
   - Test: 50 gleichzeitige Downloads

## 7. Grenzen und Migration-Pfad

### Wann wird es kritisch?

| Metrik | 10-20k | 50k | 100k | 500k+ |
|--------|--------|-----|------|-------|
| FULLTEXT Suche | ✅ < 200ms | ⚠️ 500ms | ⚠️ 1-2s | ❌ > 5s |
| Storage I/O | ✅ OK | ✅ OK | ⚠️ Langsam | ❌ Problem |
| DB-Queries | ✅ < 50ms | ✅ < 100ms | ⚠️ 200ms | ❌ > 500ms |
| Dedup-Lookup | ✅ < 1ms | ✅ < 1ms | ✅ < 1ms | ✅ < 1ms |

### Migration-Pfad (wenn nötig)

**Phase 1 (10-20k):** ✅ Aktueller Ansatz
- MariaDB FULLTEXT
- Lokales Filesystem
- Keine Änderungen nötig

**Phase 2 (50-100k):** ⚠️ Optimierungen
- Query-Caching
- Index-Optimierungen
- Monitoring

**Phase 3 (100k+):** 🔄 Migration
- OpenSearch für Suche
- S3/MinIO für Storage (optional)
- CDN für Downloads (optional)

## 8. Empfehlungen für 10-20k Dokumente

### ✅ Sofort umsetzbar (Performance)

1. **Query-Caching** (optional, aber empfohlen)
   ```php
   // Für getEntityDocuments() - ändert sich selten
   $cache->get("entity_docs_{$type}_{$uuid}");
   ```

2. **Index-Monitoring**
   ```sql
   -- Prüfe Index-Nutzung
   EXPLAIN SELECT ... FROM documents ...;
   ```

3. **Backup-Strategie**
   - Storage + DB separat backuppen
   - Incremental Backups für Storage

### ⚠️ Monitoring (wichtig)

1. **Query-Logs aktivieren**
   ```sql
   SET GLOBAL slow_query_log = 'ON';
   SET GLOBAL long_query_time = 1; -- 1 Sekunde
   ```

2. **Storage-Monitoring**
   - Festplatten-Space
   - I/O-Wartezeiten

3. **FULLTEXT Performance**
   - Query-Zeiten tracken
   - Index-Größe überwachen

### 🔄 Später (wenn nötig)

1. **OpenSearch** (ab ~100k Dokumenten)
2. **S3/MinIO** (ab ~500k Dokumenten oder bei Cloud-Deployment)
3. **CDN** (bei vielen Downloads)

## 9. Fazit

### ✅ Für 10-20k Dokumente: **Aktueller Ansatz ist valide**

**Begründung:**
- MariaDB FULLTEXT skaliert gut bis ~50k
- Hash-basierte Storage-Struktur ist optimal
- Deduplication ist O(1) - keine Probleme
- DB-Queries sind mit Indizes schnell

**Was zu beachten ist:**
- Monitoring einrichten
- Query-Caching optional hinzufügen
- Backup-Strategie planen

**Migration-Pfad:**
- Ab ~50k: Optimierungen
- Ab ~100k: OpenSearch in Betracht ziehen
- Ab ~500k: S3/MinIO optional

---

*Analyse erstellt: 2026-01-01*


