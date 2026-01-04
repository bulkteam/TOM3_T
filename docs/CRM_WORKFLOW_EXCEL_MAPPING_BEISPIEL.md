# CRM Workflow - Excel Import Mapping (Beispiel)

## Analyse der Excel-Datei

**Datei:** `wzw-2026-01-02 18-22-33.xlsx`  
**Worksheet:** "Wer-zu-Wem Branchenliste"  
**Struktur:** 11 Zeilen, Spalten bis BL

**Hinweis:** Die Datei scheint ungewöhnlich strukturiert zu sein. Die folgende Analyse zeigt nur die gefundenen Daten. Für ein vollständiges Mapping benötigen wir die tatsächliche Struktur der Excel-Datei.

---

## Gefundene Daten

- **Zeile 1 (Header?):** 
  - Spalte A: "Zentrale"
  - Spalte B: "FirmaPDF"
- **Zeilen 2-11:** 
  - Spalte A: "Zentrale" (wiederholt)

**Vermutung:** Die Datei könnte:
1. Eine andere Struktur haben (z.B. mehrere Header-Zeilen)
2. Formeln enthalten, die nicht ausgewertet werden
3. Formatierte/versteckte Daten enthalten
4. Ein spezielles Format haben (z.B. Pivot-Tabelle)

---

## Generisches Mapping-Konzept

Basierend auf typischen "Wer-zu-Wem" oder Branchenlisten-Importen:

### Typische Felder für Firmen-Importe

| Excel-Spalte | TOM-Feld | Beschreibung | Beispiel |
|--------------|----------|--------------|----------|
| **Firmenname** | `name` | Name der Organisation | "Musterfirma GmbH" |
| **Rechtsform** | `org_kind` | Art der Organisation | "GmbH", "AG", "e.K." |
| **Branche** | `industry` | Industrie/Branche | "Maschinenbau" |
| **Website** | `website` | Webseite | "www.muster.de" |
| **Straße** | `address.street` | Straße | "Musterstr. 1" |
| **PLZ** | `address.postal_code` | Postleitzahl | "12345" |
| **Ort** | `address.city` | Stadt | "Berlin" |
| **Land** | `address.country_code` | Land (ISO) | "DE" |
| **Telefon** | `address.phone` | Telefonnummer | "+49 30 123456" |
| **E-Mail** | `address.email` | E-Mail-Adresse | "info@muster.de" |
| **USt-ID** | `vat_id` | Umsatzsteuer-ID | "DE123456789" |
| **Mitarbeiter** | `employee_count` | Anzahl Mitarbeiter | "50" |
| **Umsatz** | `revenue_range` | Umsatzgröße | "small", "medium" |

---

## Beispiel-Mapping (YAML)

### Szenario 1: Standard Excel mit Header-Zeile

```yaml
# config/import-mappings/wzw-branchenliste.yaml
mappings:
  wzw_branchenliste:
    name: "Wer-zu-Wem Branchenliste Import"
    source_type: "excel"
    
    # Header-Zeile (1-basiert)
    header_row: 1
    
    # Daten starten ab Zeile
    data_start_row: 2
    
    # Spalten-Mapping (Header-Name → TOM-Feld)
    columns:
      name:
        excel_header: "Firmenname"  # Oder: "Name", "Unternehmen"
        required: true
        validation:
          - type: "not_empty"
          - type: "max_length"
            value: 255
      
      org_kind:
        excel_header: "Rechtsform"
        required: false
        default: "customer"
        mapping:
          "GmbH": "customer"
          "AG": "customer"
          "e.K.": "customer"
          "UG": "customer"
          "mbH": "customer"
      
      industry:
        excel_header: "Branche"  # Oder: "Industrie", "Sektor"
        required: false
      
      website:
        excel_header: "Website"  # Oder: "URL", "Homepage"
        required: false
        validation:
          - type: "url"
        transformation:
          - type: "normalize_url"
      
      address_street:
        excel_header: "Straße"  # Oder: "Street", "Adresse"
        required: false
      
      address_postal_code:
        excel_header: "PLZ"  # Oder: "Postleitzahl", "Postal Code"
        required: false
        validation:
          - type: "postal_code_de"
      
      address_city:
        excel_header: "Ort"  # Oder: "Stadt", "City"
        required: false
      
      address_country:
        excel_header: "Land"  # Oder: "Country"
        required: false
        default: "DE"
        mapping:
          "Deutschland": "DE"
          "Germany": "DE"
          "Österreich": "AT"
          "Austria": "AT"
          "Schweiz": "CH"
          "Switzerland": "CH"
      
      phone:
        excel_header: "Telefon"  # Oder: "Phone", "Tel"
        required: false
      
      email:
        excel_header: "E-Mail"  # Oder: "Email", "E-Mail-Adresse"
        required: false
        validation:
          - type: "email"
      
      employee_count:
        excel_header: "Mitarbeiter"  # Oder: "Employees", "Anzahl Mitarbeiter"
        required: false
        transformation:
          - type: "to_int"
        validation:
          - type: "min"
            value: 0
      
      revenue_range:
        excel_header: "Umsatz"  # Oder: "Revenue", "Umsatzgröße"
        required: false
        mapping:
          "0-1M": "micro"
          "1-10M": "small"
          "10-50M": "medium"
          "50-250M": "large"
          ">250M": "enterprise"
      
      vat_id:
        excel_header: "USt-ID"  # Oder: "VAT-ID", "Umsatzsteuer-ID"
        required: false
        validation:
          - type: "vat_id_format"
      
      external_ref:
        excel_header: "Kundennummer"  # Oder: "Customer No", "Referenz"
        required: false
    
    # Import-Optionen
    options:
      skip_duplicates: true
      duplicate_check_fields: ["name", "website"]
      auto_validate: true
      create_import_validation_task: true
```

### Szenario 2: Excel mit Spaltenbuchstaben (ohne Header)

```yaml
mappings:
  wzw_branchenliste_columns:
    name: "Wer-zu-Wem Branchenliste (Spalten-basiert)"
    source_type: "excel"
    
    # Keine Header-Zeile
    header_row: null
    
    # Daten starten ab Zeile 1
    data_start_row: 1
    
    # Spalten-Mapping (Spaltenbuchstabe → TOM-Feld)
    columns:
      name:
        excel_column: "A"
        required: true
      
      org_kind:
        excel_column: "B"
        required: false
        default: "customer"
      
      industry:
        excel_column: "C"
        required: false
      
      website:
        excel_column: "D"
        required: false
      
      address_street:
        excel_column: "E"
        required: false
      
      address_postal_code:
        excel_column: "F"
        required: false
      
      address_city:
        excel_column: "G"
        required: false
      
      # ... weitere Spalten
```

---

## Nächste Schritte

Um ein **konkretes Mapping** für die Excel-Datei zu erstellen, benötigen wir:

1. **Die tatsächliche Struktur der Excel-Datei:**
   - Welche Spalten gibt es?
   - Welche Header-Namen?
   - In welcher Zeile stehen die Header?
   - Welche Daten stehen in den Zeilen?

2. **Beispiel-Zeilen:**
   - 2-3 Beispiel-Zeilen aus der Excel-Datei
   - Damit können wir das Mapping genau anpassen

3. **Feld-Mapping:**
   - Welche Excel-Spalten sollen auf welche TOM-Felder gemappt werden?

---

## Vorschlag: Mapping-Konfigurator

Für die Implementierung könnte ein **Mapping-Konfigurator** in der UI hilfreich sein:

1. **Excel-Datei hochladen**
2. **Erste Zeilen anzeigen** (Header + 2-3 Datenzeilen)
3. **Mapping konfigurieren:**
   - Excel-Spalte → TOM-Feld
   - Transformationen
   - Validierungen
4. **Mapping speichern** (als YAML)
5. **Import testen** (mit Vorschau)
6. **Import ausführen**

---

## Beispiel: Import-Service Aufruf

```php
// Import mit Mapping
$importService = new OrgImportService($db);

$result = $importService->importFromExcel(
    filePath: 'externe Daten/wzw-2026-01-02 18-22-33.xlsx',
    mappingKey: 'wzw_branchenliste',  // Oder: 'wzw_branchenliste_columns'
    userId: 'user123'
);

// Ergebnis
echo "Importiert: " . $result['stats']['imported'] . "\n";
echo "Übersprungen: " . $result['stats']['skipped'] . "\n";
echo "Fehler: " . $result['stats']['errors'] . "\n";
echo "Batch UUID: " . $result['batch_uuid'] . "\n";
```

---

**Fazit:** Das generische Mapping-Konzept ist erstellt. Für ein **konkretes Mapping** benötigen wir die tatsächliche Struktur der Excel-Datei. Können Sie mir ein paar Beispiel-Zeilen aus der Excel-Datei zeigen, oder soll ich einen Mapping-Konfigurator implementieren?

