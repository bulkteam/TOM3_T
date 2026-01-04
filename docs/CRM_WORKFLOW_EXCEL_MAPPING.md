# CRM Workflow - Excel Import Mapping

## Excel-Datei Analyse

**Datei:** `wzw-2026-01-02 18-22-33.xlsx`  
**Struktur:** 11 Zeilen, Spalten bis AMJ  
**Hinweis:** Die Datei scheint ungewöhnlich strukturiert zu sein. Das folgende Mapping ist ein generisches Konzept, das an die tatsächliche Struktur angepasst werden muss.

---

## Typisches Excel-Import-Mapping

### Standard-Felder für Organisationen

Basierend auf typischen Excel-Importen für Firmendaten:

| Excel-Spalte (Beispiel) | TOM-Feld | Typ | Pflicht | Validierung |
|-------------------------|----------|-----|--------|-------------|
| **Firmenname** / **Name** / **Unternehmen** | `name` | string | ✅ Ja | Nicht leer |
| **Rechtsform** / **Form** | `org_kind` | enum | Nein | customer\|supplier\|consultant\|... |
| **Branche** / **Industry** | `industry` | string | Nein | - |
| **Website** / **URL** | `website` | string | Nein | URL-Format |
| **Telefon** / **Phone** | (Adresse) | string | Nein | - |
| **E-Mail** / **Email** | (Adresse) | string | Nein | E-Mail-Format |
| **Straße** / **Street** | `org_address.street` | string | Nein | - |
| **PLZ** / **Postleitzahl** | `org_address.postal_code` | string | Nein | 5-stellig |
| **Ort** / **Stadt** / **City** | `org_address.city` | string | Nein | - |
| **Land** / **Country** | `org_address.country_code` | string | Nein | ISO 2-stellig |
| **USt-ID** / **VAT-ID** | `org_vat_registration.vat_id` | string | Nein | - |
| **Mitarbeiter** / **Employees** | `employee_count` | int | Nein | > 0 |
| **Umsatz** / **Revenue** | `revenue_range` | enum | Nein | micro\|small\|medium\|large\|enterprise |
| **Kundennummer** / **Customer No** | `external_ref` | string | Nein | - |
| **Notizen** / **Notes** | `notes` | text | Nein | - |

---

## Mapping-Konfiguration (YAML)

### Beispiel: Flexibles Mapping-System

```yaml
# config/import-mappings/org-excel-default.yaml
mappings:
  org_excel_default:
    name: "Standard Excel-Import für Organisationen"
    source_type: "excel"
    
    # Header-Zeile (1-basiert)
    header_row: 1
    
    # Daten starten ab Zeile (1-basiert)
    data_start_row: 2
    
    # Spalten-Mapping (Excel-Spaltenbuchstabe → TOM-Feld)
    columns:
      # Pflichtfelder
      name:
        excel_column: "A"  # Oder: "Firmenname" (wenn Header-Name verwendet wird)
        required: true
        validation:
          - type: "not_empty"
          - type: "max_length"
            value: 255
        
      # Optionale Felder
      org_kind:
        excel_column: "B"
        required: false
        default: "customer"
        mapping:
          "GmbH": "customer"
          "AG": "customer"
          "e.K.": "customer"
          "UG": "customer"
          "Lieferant": "supplier"
          "Berater": "consultant"
      
      industry:
        excel_column: "C"
        required: false
      
      website:
        excel_column: "D"
        required: false
        validation:
          - type: "url"
        transformation:
          - type: "normalize_url"  # http:// → https://, etc.
      
      # Adress-Felder
      address_street:
        excel_column: "E"
        required: false
      
      address_postal_code:
        excel_column: "F"
        required: false
        validation:
          - type: "postal_code_de"  # 5-stellig
      
      address_city:
        excel_column: "G"
        required: false
      
      address_country:
        excel_column: "H"
        required: false
        default: "DE"
        mapping:
          "Deutschland": "DE"
          "Germany": "DE"
          "Österreich": "AT"
          "Austria": "AT"
          "Schweiz": "CH"
          "Switzerland": "CH"
      
      # Kontakt-Felder
      phone:
        excel_column: "I"
        required: false
      
      email:
        excel_column: "J"
        required: false
        validation:
          - type: "email"
      
      # Weitere Felder
      employee_count:
        excel_column: "K"
        required: false
        transformation:
          - type: "to_int"
        validation:
          - type: "min"
            value: 0
      
      revenue_range:
        excel_column: "L"
        required: false
        mapping:
          "0-1M": "micro"
          "1-10M": "small"
          "10-50M": "medium"
          "50-250M": "large"
          ">250M": "enterprise"
      
      vat_id:
        excel_column: "M"
        required: false
        validation:
          - type: "vat_id_format"
      
      external_ref:
        excel_column: "N"
        required: false
      
      notes:
        excel_column: "O"
        required: false
    
    # Import-Optionen
    options:
      skip_duplicates: true
      duplicate_check_fields: ["name", "website"]  # Prüfe auf Duplikate
      auto_validate: true
      create_import_validation_task: true
```

---

## Alternative: Header-Namen basiertes Mapping

Wenn die Excel-Datei Header-Namen in der ersten Zeile hat:

```yaml
mappings:
  org_excel_header_based:
    name: "Excel-Import mit Header-Namen"
    source_type: "excel"
    
    # Header-Zeile (1-basiert)
    header_row: 1
    
    # Daten starten ab Zeile
    data_start_row: 2
    
    # Spalten-Mapping (Header-Name → TOM-Feld)
    columns:
      name:
        excel_header: "Firmenname"  # Suche nach diesem Header
        required: true
      
      org_kind:
        excel_header: "Rechtsform"
        required: false
        default: "customer"
      
      website:
        excel_header: "Website"
        required: false
      
      address_street:
        excel_header: "Straße"
        required: false
      
      address_postal_code:
        excel_header: "PLZ"
        required: false
      
      address_city:
        excel_header: "Ort"
        required: false
      
      # ... weitere Felder
```

---

## Import-Service Implementierung

### OrgImportService mit Mapping

```php
class OrgImportService {
    private PDO $db;
    private OrgService $orgService;
    private WorkflowTemplateService $workflowService;
    
    /**
     * Importiert Organisationen aus Excel mit Mapping-Konfiguration
     */
    public function importFromExcel(
        string $filePath, 
        string $mappingKey = 'org_excel_default',
        string $userId = null
    ): array {
        // 1. Lade Mapping-Konfiguration
        $mapping = $this->loadMapping($mappingKey);
        
        // 2. Erstelle Import-Batch
        $batchUuid = $this->createImportBatch('excel', basename($filePath), $userId);
        
        // 3. Lade Excel
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // 4. Lese Header (falls header-basiert)
        $headers = null;
        if (isset($mapping['columns'][array_key_first($mapping['columns'])]['excel_header'])) {
            $headers = $this->readHeaders($worksheet, $mapping['header_row']);
        }
        
        // 5. Verarbeite Zeilen
        $stats = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'errors_detail' => []
        ];
        
        $dataStartRow = $mapping['data_start_row'] ?? 2;
        $highestRow = $worksheet->getHighestRow();
        
        for ($row = $dataStartRow; $row <= $highestRow; $row++) {
            try {
                // Lese Zeile
                $rowData = $this->readRow($worksheet, $row, $mapping, $headers);
                
                // Validiere
                $validation = $this->validateRow($rowData, $mapping);
                if (!$validation['valid']) {
                    $stats['errors']++;
                    $stats['errors_detail'][] = [
                        'row' => $row,
                        'errors' => $validation['errors']
                    ];
                    continue;
                }
                
                // Prüfe Duplikate
                if ($mapping['options']['skip_duplicates'] ?? true) {
                    if ($this->isDuplicate($rowData, $mapping)) {
                        $stats['skipped']++;
                        continue;
                    }
                }
                
                // Mappe zu Org-Daten
                $orgData = $this->mapRowToOrgData($rowData, $mapping);
                
                // Setze Import-Flags
                $orgData['is_imported'] = true;
                $orgData['import_source'] = 'excel';
                $orgData['import_batch_uuid'] = $batchUuid;
                $orgData['imported_at'] = date('Y-m-d H:i:s');
                $orgData['imported_by_user_id'] = $userId;
                $orgData['status'] = 'lead'; // Immer lead für Importe
                
                // Erstelle Org
                $org = $this->orgService->createOrg($orgData, $userId);
                
                // Workflow startet automatisch (in createOrg)
                
                $stats['imported']++;
                
            } catch (\Exception $e) {
                $stats['errors']++;
                $stats['errors_detail'][] = [
                    'row' => $row,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // 6. Batch abschließen
        $this->completeImportBatch($batchUuid, $stats);
        
        return [
            'batch_uuid' => $batchUuid,
            'stats' => $stats
        ];
    }
    
    /**
     * Liest eine Zeile aus Excel basierend auf Mapping
     */
    private function readRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
        int $row,
        array $mapping,
        ?array $headers
    ): array {
        $rowData = [];
        
        foreach ($mapping['columns'] as $field => $config) {
            $value = null;
            
            // Spaltenbuchstabe oder Header-Name?
            if (isset($config['excel_column'])) {
                // Direkte Spaltenbuchstabe
                $col = $config['excel_column'];
                $value = $worksheet->getCell($col . $row)->getFormattedValue();
            } elseif (isset($config['excel_header']) && $headers) {
                // Header-Name basiert
                $col = $this->findColumnByHeader($config['excel_header'], $headers);
                if ($col) {
                    $value = $worksheet->getCell($col . $row)->getFormattedValue();
                }
            }
            
            // Transformationen anwenden
            if ($value !== null && isset($config['transformation'])) {
                $value = $this->applyTransformations($value, $config['transformation']);
            }
            
            // Mapping (z.B. "GmbH" → "customer")
            if ($value !== null && isset($config['mapping'])) {
                $value = $config['mapping'][$value] ?? $value;
            }
            
            // Default-Wert
            if (($value === null || $value === '') && isset($config['default'])) {
                $value = $config['default'];
            }
            
            $rowData[$field] = $value;
        }
        
        return $rowData;
    }
    
    /**
     * Mappt Row-Daten zu Org-Datenstruktur
     */
    private function mapRowToOrgData(array $rowData, array $mapping): array
    {
        $orgData = [];
        
        // Direkte Felder
        $directFields = ['name', 'org_kind', 'industry', 'website', 'employee_count', 
                         'revenue_range', 'external_ref', 'notes'];
        
        foreach ($directFields as $field) {
            if (isset($rowData[$field])) {
                $orgData[$field] = $rowData[$field];
            }
        }
        
        // Adress-Felder (werden später in org_address gespeichert)
        if (isset($rowData['address_street']) || 
            isset($rowData['address_postal_code']) || 
            isset($rowData['address_city'])) {
            $orgData['address'] = [
                'street' => $rowData['address_street'] ?? null,
                'postal_code' => $rowData['address_postal_code'] ?? null,
                'city' => $rowData['address_city'] ?? null,
                'country_code' => $rowData['address_country'] ?? 'DE',
                'address_type' => 'headquarters'
            ];
        }
        
        // Kontakt-Felder (werden später in org_address gespeichert)
        if (isset($rowData['phone']) || isset($rowData['email'])) {
            $orgData['contact'] = [
                'phone' => $rowData['phone'] ?? null,
                'email' => $rowData['email'] ?? null
            ];
        }
        
        // USt-ID (wird später in org_vat_registration gespeichert)
        if (isset($rowData['vat_id'])) {
            $orgData['vat_id'] = $rowData['vat_id'];
        }
        
        return $orgData;
    }
}
```

---

## UI: Mapping-Konfigurator

Für flexible Imports könnte ein Mapping-Konfigurator in der UI hilfreich sein:

```html
<div class="import-mapping-configurator">
    <h3>Spalten-Mapping konfigurieren</h3>
    
    <table>
        <thead>
            <tr>
                <th>Excel-Spalte</th>
                <th>TOM-Feld</th>
                <th>Pflicht</th>
                <th>Transformation</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <select name="excel_column_A">
                        <option value="A">A (Firmenname)</option>
                        <option value="B">B (Rechtsform)</option>
                        <!-- ... -->
                    </select>
                </td>
                <td>
                    <select name="tom_field_A">
                        <option value="name">Name</option>
                        <option value="org_kind">Rechtsform</option>
                        <!-- ... -->
                    </select>
                </td>
                <td>
                    <input type="checkbox" name="required_A">
                </td>
                <td>
                    <select name="transformation_A">
                        <option value="">Keine</option>
                        <option value="normalize_url">URL normalisieren</option>
                        <option value="to_int">Zu Integer</option>
                        <!-- ... -->
                    </select>
                </td>
            </tr>
            <!-- ... weitere Zeilen ... -->
        </tbody>
    </table>
    
    <button onclick="saveMapping()">Mapping speichern</button>
    <button onclick="importWithMapping()">Import starten</button>
</div>
```

---

## Nächste Schritte

1. **Excel-Datei analysieren:** Die tatsächliche Struktur der `wzw-2026-01-02 18-22-33.xlsx` muss noch genauer analysiert werden
2. **Mapping erstellen:** Basierend auf der tatsächlichen Struktur ein konkretes Mapping erstellen
3. **Import-Service implementieren:** `OrgImportService` mit Mapping-Support
4. **UI erstellen:** Import-Interface mit Mapping-Konfigurator

---

## Beispiel-Mapping für typische Excel-Struktur

Falls die Excel-Datei folgende Struktur hat:

| A (Firmenname) | B (Rechtsform) | C (Branche) | D (Website) | E (Straße) | F (PLZ) | G (Ort) | ... |
|---------------|----------------|-------------|-------------|------------|---------|---------|-----|
| Musterfirma GmbH | GmbH | Maschinenbau | www.muster.de | Musterstr. 1 | 12345 | Berlin | ... |

**Mapping:**
```yaml
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
```

