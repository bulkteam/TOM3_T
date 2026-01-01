# TOM3 - Dokumenten-Upload Optimierungen

## Übernommene Verbesserungen

Basierend auf den Anmerkungen wurden folgende Optimierungen umgesetzt:

### 1. Streaming Hash-Berechnung ✅

**Problem:** `hash_file()` lädt die gesamte Datei in den Speicher.

**Lösung:** Hash wird während des Stream-Kopierens berechnet (1MB Chunks).

```php
// Vorher: hash_file('sha256', $filePath) - lädt alles in RAM
// Nachher: Streaming mit hash_init/update/final während Kopieren
```

**Vorteil:**
- Kein RAM-Bloat bei großen Dateien
- Effizienter für Dateien > 100MB
- Hash-Berechnung parallel zum Kopieren

### 2. Race-Condition-Handling ✅

**Problem:** Zwei parallele Uploads derselben Datei können zu Duplikaten führen.

**Lösung:** Unique Constraint + Exception-Handling.

```php
try {
    INSERT INTO blobs (...) VALUES (...);
} catch (PDOException $e) {
    if (isDuplicateKey($e)) {
        // Bestehenden Blob finden und verwenden
        return findBlobByHash(...);
    }
    throw $e;
}
```

**Vorteil:**
- Atomare Deduplication
- Keine Race-Conditions
- Parallele Uploads werden korrekt behandelt

### 3. Transaction-basierter Upload-Flow ✅

**Problem:** Wenn Document-Insert fehlschlägt, bleibt Blob in DB.

**Lösung:** Transaction um Blob + Document + Attachment.

```php
$this->db->beginTransaction();
try {
    $blob = createBlob(...);
    $document = createDocument(...);
    $attachment = attachDocument(...);
    $this->db->commit();
} catch (Exception $e) {
    $this->db->rollBack();
    // Cleanup
}
```

**Vorteil:**
- Atomare Operationen
- Keine "orphaned" Blobs
- Konsistente Datenbank

## Nicht übernommen (Begründung)

### 1. BINARY(16) statt CHAR(36) UUIDs ❌

**Grund:** TOM3 verwendet durchgängig `CHAR(36)` für UUIDs. Umstellung wäre Breaking Change.

**Status:** Bleibt bei `CHAR(36)` (konsistent mit bestehender Architektur).

### 2. Separate `document_text` Tabelle ❌

**Grund:** Aktuell ist `extracted_text` in `documents` Tabelle. Separate Tabelle wäre besser für Performance, aber nicht kritisch für MVP.

**Status:** Kann später migriert werden (nicht dringend).

### 3. DB-Queue für Jobs ⏳

**Grund:** Gute Idee, aber nicht kritisch für MVP. Aktuell werden Jobs noch nicht asynchron verarbeitet.

**Status:** Wird in Phase 2 (Security) implementiert, wenn ClamAV-Scan hinzugefügt wird.

### 4. ClamAV-Integration ⏳

**Grund:** Wird in Phase 2 (Security) implementiert.

**Status:** Geplant, aber nicht für MVP.

## Performance-Verbesserungen

### Vorher:
- Hash-Berechnung: `hash_file()` → lädt gesamte Datei in RAM
- Race-Conditions: Mögliche Duplikate bei parallelen Uploads
- Keine Transactions: Inkonsistente Zustände möglich

### Nachher:
- Hash-Berechnung: Streaming (1MB Chunks) → kein RAM-Bloat
- Race-Conditions: Unique Constraint + Exception-Handling → atomar
- Transactions: Atomare Operationen → konsistente DB

## Nächste Schritte

1. **Phase 2: Security** (geplant)
   - DB-Queue für Jobs
   - ClamAV-Integration
   - Async Processing

2. **Phase 3: Enrichment** (geplant)
   - Text-Extraktion (PDF, DOCX)
   - OCR (optional)
   - Klassifikation

3. **Optional: Performance-Optimierungen**
   - Separate `document_text` Tabelle (wenn nötig)
   - CDN für Downloads (später)
   - Thumbnail-Generierung (später)

---

*Dokument erstellt: 2026-01-01*
