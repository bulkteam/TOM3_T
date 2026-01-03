# Checkliste: Format-Agnostizität des Importers

## ✅ Prüfungsergebnis

### 1. Reader-Layer
**Status:** ✅ **OK**

- ✅ `ImportStagingService::readRawRow()` gibt `rowAssoc = [header => value]` zurück
- ✅ Keine Spaltenindizes im Businesscode
- ✅ Header-Row ist konfigurierbar (`mappingConfig['header_row']`)

**Code-Stellen:**
- `ImportStagingService::readRawRow()` - Zeile 150-186
- `OrgImportService::readColumns()` - Zeile 529-568

---

### 2. Mapper-Layer
**Status:** ✅ **OK**

- ✅ Findet Spalte über `mapping_config.column_mapping.*.excel_column` oder `excel_header`
- ✅ Nutzt `mappingConfig['columns']` für alle Mappings
- ✅ Keine harten Header-Namen im Code

**Code-Stellen:**
- `ImportMappingService::readRow()` - Zeile 225-264
- `ImportStagingService::readMappedRow()` - Zeile 192-261

---

### 3. Business-Logic (Resolver/Validator)
**Status:** ✅ **OK**

- ✅ `IndustryResolver` nutzt nur `mappedData['industry']['excel_level2_label']`
- ✅ `ImportCommitService` arbeitet nur auf `mappedData` und `industry_resolution`
- ✅ `ImportValidationService` arbeitet nur auf `mappedData`
- ❌ **KEINE** harten Header-Namen im Code

**Code-Stellen:**
- `ImportStagingService::buildIndustryResolution()` - Zeile 266-340
- `ImportCommitService::commitRow()` - Zeile 126-296
- `ImportValidationService::validateRow()` - Zeile 20-80

**Kommentare (harmlos):**
- `IndustryResolver.php` Zeile 14-16: Kommentar erwähnt "Oberkategorie" (nur Dokumentation)
- `ImportIndustryValidationService.php` Zeile 113-126: Kommentare erwähnen "Oberkategorie" (nur Dokumentation)

---

### 4. Mapping-Suggestions
**Status:** ✅ **OK** (nur Vorschlagslogik)

- ✅ `ImportMappingService::$fieldSuggestions` enthält Keywords
- ✅ Diese werden nur für automatische Vorschläge verwendet
- ✅ Wenn Vorschläge nicht passen, kann Sales Ops manuell mappen

**Code-Stellen:**
- `ImportMappingService::$fieldSuggestions` - Zeile 19-46
- `ImportMappingService::suggestMapping()` - Zeile 91-220

---

## ⚠️ Verbesserungspotenzial (optional)

### 1. Sheet-Auswahl
**Status:** ⚠️ **Noch nicht konfigurierbar**

**Aktuell:**
```php
$worksheet = $spreadsheet->getActiveSheet(); // Immer erstes Sheet
```

**Empfehlung:**
```php
$sheetName = $mappingConfig['sheet_name'] ?? null;
$sheetIndex = $mappingConfig['sheet_index'] ?? 0;

if ($sheetName) {
    $worksheet = $spreadsheet->getSheetByName($sheetName);
} else {
    $worksheet = $spreadsheet->getSheet($sheetIndex);
}
```

**Code-Stelle:**
- `ImportStagingService::stageBatch()` - Zeile 64

---

### 2. Header-Aliases
**Status:** ⚠️ **Noch nicht unterstützt**

**Aktuell:**
```php
$config['excel_header'] = "Firmenname"; // Nur ein Header
```

**Empfehlung:**
```php
$config['excel_headers'] = ["Firmenname", "Unternehmen", "Company Name"]; // Mehrere Varianten
```

**Code-Stelle:**
- `ImportMappingService::readRow()` - Zeile 237-242

---

### 3. findColumnByHeader()
**Status:** ⚠️ **Noch nicht implementiert**

**Aktuell:**
```php
private function findColumnByHeader(string $header, array $mappingConfig): ?string
{
    // TODO: Implementierung
    return null;
}
```

**Empfehlung:**
```php
private function findColumnByHeader(string $header, array $mappingConfig): ?string
{
    $headers = $mappingConfig['headers'] ?? [];
    foreach ($headers as $col => $headerName) {
        if (mb_strtolower(trim($headerName)) === mb_strtolower(trim($header))) {
            return $col;
        }
    }
    return null;
}
```

**Code-Stelle:**
- `ImportMappingService::findColumnByHeader()` - Zeile 269-273

---

## Fazit

### ✅ **Der Code ist bereits format-agnostisch!**

**Bei neuen Excel-Formaten:**
- ✅ Kein Code-Change nötig
- ✅ Nur Mapping anpassen (über UI)
- ✅ Import funktioniert sofort

**Optional (später):**
1. ⚠️ Sheet-Auswahl konfigurierbar (für Excel-Dateien mit mehreren Sheets)
2. ⚠️ Header-Aliases unterstützen (mehrere Varianten pro Feld)
3. ⚠️ `findColumnByHeader()` implementieren (wenn Header-Name statt Spalte verwendet wird)

---

## Empfehlung

**Für jetzt:** ✅ **Nichts ändern - Code ist bereits format-agnostisch**

**Für später (wenn Bedarf):**
- Sheet-Auswahl: Nur wenn Excel-Dateien mit mehreren Sheets importiert werden
- Header-Aliases: Nur wenn ähnliche Excel-Formate häufig vorkommen
- `findColumnByHeader()`: Nur wenn Header-Name statt Spalte verwendet wird
