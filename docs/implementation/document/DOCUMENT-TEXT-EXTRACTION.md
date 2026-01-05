# TOM3 - Dokumenten-Text-Extraktion

## Überblick

Die Text-Extraktion ermöglicht die Volltext-Suche in Dokumenten. Sie erfolgt **asynchron** über einen Worker, damit der Upload schnell bleibt.

## Architektur

### Asynchrone Verarbeitung

```
1. Upload → Dokument wird gespeichert
2. Job wird enqueued (outbox_event)
3. Worker verarbeitet Job (alle 5 Minuten)
4. Text wird in documents.extracted_text gespeichert
5. Suche findet den Text sofort
```

**Warum asynchron?**
- ✅ Upload bleibt schnell (keine Wartezeit)
- ✅ Timeouts vermeiden (OCR kann Minuten dauern)
- ✅ Skalierbarkeit (mehrere Worker möglich)
- ✅ Fehlerbehandlung (fehlerhafte Extraktionen blockieren nicht den Upload)

## Unterstützte Formate

| Format | Status | Bibliothek/Tool | Anmerkung |
|-------|--------|-----------------|-----------|
| **PDF** | ✅ | smalot/pdfparser | Vollständig |
| **DOCX** | ✅ | Native PHP (ZIP+XML) | Vollständig |
| **DOC** | ✅ | LibreOffice/Antiword | Benötigt externes Tool |
| **XLSX/XLS** | ✅ | phpoffice/phpspreadsheet | Vollständig |
| **TXT** | ✅ | Native PHP | Vollständig |
| **CSV** | ✅ | Native PHP | Vollständig |
| **HTML** | ✅ | Native PHP (DOMDocument) | Vollständig |
| **Bilder (OCR)** | ✅ | Tesseract OCR | Benötigt Tesseract |

## Implementierung

### Extraktor-Klassen

**Pfad:** `src/TOM/Infrastructure/Document/`

- `PdfTextExtractor.php` - PDF-Extraktion
- `DocxTextExtractor.php` - DOCX-Extraktion
- `DocTextExtractor.php` - DOC-Extraktion (altes Format)
- `XlsxTextExtractor.php` - Excel-Extraktion
- `TextFileExtractor.php` - TXT, CSV, HTML
- `OcrExtractor.php` - Bild-OCR

### Worker

**Datei:** `scripts/jobs/extract-text-worker.php`

**Funktion:**
- Verarbeitet Jobs aus `outbox_event`
- Extrahiert Text aus Dokumenten
- Speichert Text in `documents.extracted_text`
- Aktualisiert `extraction_status` (pending → done/failed)

**Ausführung:**
```bash
# Manuell
php scripts/jobs/extract-text-worker.php -v

# Als Windows Task (automatisch alle 5 Minuten)
# Siehe: scripts/setup-extract-text-worker-task.ps1
```

### Windows Task Scheduler

**Task-Name:** `TOM3-ExtractTextWorker`

**Einrichtung:**
```powershell
powershell -ExecutionPolicy Bypass -File scripts\setup-extract-text-worker-task.ps1
```

**Konfiguration:**
- **Intervall:** Alle 5 Minuten
- **User:** SYSTEM (höchste Berechtigung)
- **PHP-Pfad:** Automatisch erkannt (C:\xampp\php\php.exe)
- **Log-Datei:** `logs/extract-text-worker.log`

**Status prüfen:**
```powershell
Get-ScheduledTask -TaskName "TOM3-ExtractTextWorker" | Get-ScheduledTaskInfo
```

## Abhängigkeiten

### PHP Extensions (PFLICHT)

- ✅ `ext-zip` - **ERFORDERLICH** für DOCX und XLSX-Extraktion
  - Aktivierung: In `php.ini` die Zeile `;extension=zip` zu `extension=zip` ändern
  - Nach Änderung: Apache/PHP neu starten
- ✅ `ext-fileinfo` - Für MIME-Type-Erkennung (meist standardmäßig aktiviert)

### PHP-Bibliotheken (via Composer)

- ✅ `smalot/pdfparser` - PDF-Extraktion
- ✅ `phpoffice/phpspreadsheet` - Excel-Extraktion

### Externe Tools (Optional)

**Für DOC-Extraktion:**
- LibreOffice (empfohlen): https://www.libreoffice.org/
- Oder: Antiword (Linux)
- Oder: catdoc (Linux)

**Für OCR:**
- Tesseract OCR: https://github.com/UB-Mannheim/tesseract/wiki
- Linux: `sudo apt-get install tesseract-ocr tesseract-ocr-deu`
- macOS: `brew install tesseract`

## Datenbank

### Tabellen

**`documents` Tabelle:**
```sql
extracted_text LONGTEXT COMMENT 'Volltext (PDF, DOCX, etc.)',
extraction_status ENUM('pending', 'done', 'failed') DEFAULT 'pending',
extraction_meta JSON COMMENT 'Sprache, Seitenzahl, etc.',
FULLTEXT idx_extracted_text (extracted_text) COMMENT 'Für Volltext-Suche'
```

**`outbox_event` Tabelle:**
```sql
-- Jobs für Text-Extraktion
aggregate_type = 'document'
event_type = 'DocumentExtractionRequested'
processed_at IS NULL -- Noch nicht verarbeitet
```

## Metadaten

Die Extraktion speichert zusätzliche Metadaten in `extraction_meta` (JSON):

**PDF:**
```json
{
  "pages": 10,
  "title": "Dokument-Titel",
  "author": "Autor",
  "created": "2026-01-01"
}
```

**XLSX:**
```json
{
  "sheets": 3,
  "sheet_names": ["Tabelle1", "Tabelle2", "Tabelle3"],
  "total_cells": 1500,
  "total_rows": 500
}
```

**CSV:**
```json
{
  "lines": 100,
  "columns": 5
}
```

**OCR:**
```json
{
  "width": 1920,
  "height": 1080,
  "language": "de"
}
```

## Suche

Nach erfolgreicher Extraktion ist der Text sofort durchsuchbar:

```sql
-- Suche in extracted_text + title
SELECT * FROM documents 
WHERE MATCH(extracted_text, title) AGAINST('Suchbegriff' IN NATURAL LANGUAGE MODE)
ORDER BY relevance_score DESC
```

**Relevanz-Score:**
- Titel-Treffer: 1.5x Gewichtung
- Text-Treffer: 1.0x Gewichtung

## Troubleshooting

### Worker verarbeitet keine Jobs

1. **Prüfe unverarbeitete Jobs:**
   ```sql
   SELECT COUNT(*) FROM outbox_event 
   WHERE aggregate_type = 'document' 
     AND event_type = 'DocumentExtractionRequested' 
     AND processed_at IS NULL;
   ```

2. **Prüfe Extraction-Status:**
   ```sql
   SELECT extraction_status, COUNT(*) 
   FROM documents 
   GROUP BY extraction_status;
   ```

3. **Log-Datei prüfen:**
   ```bash
   type logs\extract-text-worker.log
   ```

### DOCX/XLSX-Extraktion funktioniert nicht

**Fehler:** "ZIP-Extension ist nicht verfügbar"

**Lösung:**
1. Öffne `C:\xampp\php\php.ini`
2. Suche nach `;extension=zip`
3. Entferne das `;` (Kommentar entfernen): `extension=zip`
4. Speichere die Datei
5. Starte Apache/PHP neu (oder Task Scheduler)

**Prüfen:**
```bash
php -r "echo extension_loaded('zip') ? 'ZIP aktiv' : 'ZIP NICHT aktiv';"
```

### DOC-Extraktion funktioniert nicht

- Prüfe, ob LibreOffice installiert ist
- Windows: Typische Pfade werden automatisch geprüft
- Fallback: Einfache binäre Text-Extraktion (weniger zuverlässig)

### OCR funktioniert nicht

- Prüfe, ob Tesseract installiert ist: `tesseract --version`
- Windows: Typische Pfade werden automatisch geprüft
- Ohne Tesseract: OCR wird übersprungen (kein Fehler)

### Performance

- **Max. Jobs pro Run:** 10 (konfigurierbar in Worker)
- **Timeout:** 1 Stunde pro Job (Task Scheduler)
- **Retry:** Automatisch beim nächsten Worker-Lauf

## Weitere Dokumentation

- `docs/DOCUMENT-UPLOAD-STATUS.md` - Gesamt-Status
- `docs/DOCUMENT-VERSIONING-AND-SEARCH.md` - Suche-Architektur
- `docs/WINDOWS-SCHEDULER-JOBS.md` - Windows Task Scheduler Setup

---

*Dokument erstellt: 2026-01-02*


