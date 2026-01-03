# Analyse: Format-Agnostizität des Importers

## Frage
**Müssen wir bei einem anderen Excel-Format Code anpassen, oder ist der Importer format-agnostisch?**

---

## Aktuelle Situation

### ✅ Gut (Format-agnostisch)

#### 1. Reader-Layer (Excel lesen)
**Datei:** `ImportStagingService::readRawRow()`
- ✅ Liest Header-Zeile dynamisch
- ✅ Erstellt `raw_data` als Key-Value (Header-Name → Wert)
- ✅ Keine harten Spaltenindizes

#### 2. Mapper-Layer (mapping_config anwenden)
**Datei:** `ImportMappingService::readRow()`
- ✅ Nutzt `mappingConfig['columns']` für Mapping
- ✅ Liest Werte über `excel_column` oder `excel_header`
- ✅ Keine harten Header-Namen im Code

#### 3. Business-Logic (IndustryResolver, etc.)
**Datei:** `ImportStagingService::buildIndustryResolution()`
- ✅ Arbeitet nur auf `mappedData['industry']['excel_level2_label']`
- ✅ Keine Referenzen zu "Oberkategorie" oder "Firmenname"
- ✅ Nutzt nur strukturierte `mapped_data`

**Datei:** `ImportCommitService::commitRow()`
- ✅ Arbeitet nur auf `mappedData` und `industry_resolution`
- ✅ Keine Excel-Header-Referenzen

---

### ⚠️ Verbesserungspotenzial

#### 1. Mapping-Suggestions (nur Vorschlagslogik - OK)
**Datei:** `ImportMappingService::$fieldSuggestions`
```php
'industry_level2' => ['oberkategorie', 'branche', 'subbranche', 'unterbranche'],
'name' => ['firmenname', 'name', 'unternehmen', 'company', 'firma', 'firma1', ...]
```

**Status:** ✅ **OK** - Das ist nur Vorschlagslogik. Wenn ein neues Excel "Unternehmen_Name" hat, wird es evtl. nicht automatisch erkannt, aber Sales Ops kann es manuell mappen.

**Empfehlung:** 
- ✅ Behalten (ist nur UX-Hilfe)
- ⚠️ Optional: Header-Aliases im `mapping_config` unterstützen (siehe unten)

#### 2. Header-Erkennung (konfigurierbar, aber könnte besser sein)
**Datei:** `ImportStagingService::readHeaders()`
- ✅ Liest Header dynamisch
- ⚠️ Header-Row ist konfigurierbar (`mappingConfig['header_row']`)
- ⚠️ Sheet-Auswahl noch nicht konfigurierbar (nutzt immer `getActiveSheet()`)

**Empfehlung:**
- ✅ Header-Row ist bereits konfigurierbar
- ⚠️ Sheet-Auswahl sollte konfigurierbar sein (`mappingConfig['sheet_name']` oder `sheet_index`)

#### 3. Mapping-Struktur (funktioniert, aber könnte erweitert werden)
**Aktuell:**
```php
$mappingConfig = [
    'columns' => [
        'name' => ['excel_column' => 'A'],
        'industry_level2' => ['excel_column' => 'B']
    ]
]
```

**Empfehlung:** Header-Aliases unterstützen:
```php
$mappingConfig = [
    'columns' => [
        'name' => [
            'excel_headers' => ['Firmenname', 'Unternehmen', 'Company Name'], // Mehrere Varianten
            'excel_column' => 'A' // Oder direkt Spalte
        ]
    ]
]
```

---

## Checkliste: Format-Sicherheit

### ✅ Reader-Layer
- ✅ Gibt `rowAssoc = [header => value]` zurück
- ✅ Keine Spaltenindizes im Businesscode
- ✅ Header-Row konfigurierbar

### ✅ Mapper-Layer
- ✅ Findet Spalte über `mapping_config.column_mapping.*.excel_column` oder `excel_header`
- ✅ Businesslogik nutzt nur `mapped_data.org.name`, `mapped_data.industry.excel_level2_label`
- ✅ Keine harten Header-Namen im Resolver/Validator

### ⚠️ Verbesserungen möglich
- ⚠️ Header-Aliases (mehrere Varianten pro Feld)
- ⚠️ Sheet-Auswahl konfigurierbar
- ⚠️ Bessere Header-Erkennung (heuristisch)

---

## Fazit

### ✅ **Der Code ist bereits format-agnostisch!**

**Was funktioniert:**
1. ✅ Reader liest Excel dynamisch (Header-Zeile konfigurierbar)
2. ✅ Mapper nutzt `mapping_config` (keine harten Header-Namen)
3. ✅ Business-Logic arbeitet nur auf `mapped_data` (keine Excel-Referenzen)

**Was verbessert werden könnte (optional):**
1. ⚠️ Header-Aliases im `mapping_config` (mehrere Varianten pro Feld)
2. ⚠️ Sheet-Auswahl konfigurierbar
3. ⚠️ Bessere Header-Erkennung (heuristisch, wenn Header-Row unbekannt)

---

## Empfehlung

### Für neue Excel-Formate:
**✅ Kein Code-Change nötig!**

**Vorgehen:**
1. Excel hochladen
2. Mapping-UI zeigt Vorschläge (basierend auf Keywords)
3. Sales Ops mappt manuell (auch wenn Vorschläge nicht passen)
4. Mapping wird gespeichert
5. Import funktioniert

**Beispiel:**
- Neues Excel hat "Unternehmen_Name" statt "Firmenname"
- Vorschlag erkennt es evtl. nicht automatisch
- Sales Ops mappt "Unternehmen_Name" → `name` manuell
- Import funktioniert ohne Code-Change

### Optional: Header-Aliases (später)
Wenn ihr häufig ähnliche Excel-Formate habt, könnt ihr Header-Aliases im `mapping_config` unterstützen:

```json
{
  "columns": {
    "org.name": {
      "excel_headers": ["Firmenname", "Unternehmen", "Company Name"],
      "excel_column": "A"
    }
  }
}
```

Dann müsst ihr bei ähnlichen Dateien nicht mal ein neues Template anlegen.

---

## Konkrete Code-Stellen

### ✅ Format-agnostisch:
1. `ImportStagingService::readRawRow()` - Liest Header dynamisch
2. `ImportMappingService::readRow()` - Nutzt `mappingConfig['columns']`
3. `ImportStagingService::readMappedRow()` - Arbeitet auf `flatData` (keine Excel-Header)
4. `ImportStagingService::buildIndustryResolution()` - Arbeitet auf `mappedData['industry']['excel_level2_label']`
5. `ImportCommitService::commitRow()` - Arbeitet auf `mappedData` und `industry_resolution`

### ⚠️ Nur Vorschlagslogik (OK):
1. `ImportMappingService::$fieldSuggestions` - Keywords für automatische Vorschläge

### ⚠️ Kommentare (harmlos):
1. `IndustryResolver.php` - Kommentare erwähnen "Oberkategorie" (nur Dokumentation)

---

## Zusammenfassung

**✅ Der Code ist format-agnostisch!**

**Bei neuen Excel-Formaten:**
- ✅ Kein Code-Change nötig
- ✅ Nur Mapping anpassen (über UI)
- ✅ Import funktioniert sofort

**Optional (später):**
- Header-Aliases für bessere UX
- Sheet-Auswahl konfigurierbar
- Heuristische Header-Erkennung
