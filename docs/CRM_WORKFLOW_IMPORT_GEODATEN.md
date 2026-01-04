# CRM Workflow - Geodaten beim Import

## Problemstellung

Beim **manuellen Anlegen** einer Adresse:
1. Benutzer gibt PLZ ein
2. System macht automatisch PLZ-Lookup → Stadt, Bundesland, **Koordinaten**
3. Koordinaten werden in `org_address.latitude` und `org_address.longitude` gespeichert

**Frage:** Wie machen wir das beim **Import**?

---

## Lösung: Automatische Geodaten-Ermittlung beim Import

### Strategie

1. **PLZ aus Excel lesen**
2. **PLZ-Lookup durchführen** (wie beim manuellen Anlegen)
3. **Koordinaten automatisch setzen**
4. **Optional:** Wenn Excel bereits Koordinaten hat, diese verwenden (höhere Priorität)

---

## Implementierung

### 1. Erweiterte `OrgAddressService::addAddress()` Logik

Die bestehende `addAddress()` Methode akzeptiert bereits `latitude` und `longitude`. Wir müssen nur sicherstellen, dass diese beim Import automatisch gesetzt werden, wenn PLZ vorhanden ist.

### 2. Import-Service: Geodaten-Ermittlung

```php
class OrgImportService {
    private PDO $db;
    private OrgService $orgService;
    private OrgAddressService $addressService;
    
    /**
     * Mappt Row-Daten zu Org-Datenstruktur (inkl. Geodaten)
     */
    private function mapRowToOrgData(array $rowData, array $mapping): array
    {
        $orgData = [];
        
        // ... bestehende Mapping-Logik ...
        
        // Adress-Felder (werden später in org_address gespeichert)
        if (isset($rowData['address_street']) || 
            isset($rowData['address_postal_code']) || 
            isset($rowData['address_city'])) {
            
            $addressData = [
                'street' => $rowData['address_street'] ?? null,
                'postal_code' => $rowData['address_postal_code'] ?? null,
                'city' => $rowData['address_city'] ?? null,
                'country_code' => $rowData['address_country'] ?? 'DE',
                'address_type' => 'headquarters'
            ];
            
            // Geodaten-Ermittlung
            $geodata = $this->resolveGeodata($addressData, $rowData);
            $addressData['latitude'] = $geodata['latitude'];
            $addressData['longitude'] = $geodata['longitude'];
            
            // Bundesland/State aus PLZ-Lookup (falls nicht in Excel)
            if (empty($addressData['state']) && !empty($addressData['postal_code'])) {
                $plzInfo = $this->lookupPlz($addressData['postal_code']);
                if ($plzInfo) {
                    $addressData['state'] = $plzInfo['bundesland'] ?? null;
                    // Stadt auch setzen, falls nicht in Excel
                    if (empty($addressData['city'])) {
                        $addressData['city'] = $plzInfo['city'] ?? null;
                    }
                }
            }
            
            $orgData['address'] = $addressData;
        }
        
        return $orgData;
    }
    
    /**
     * Ermittelt Geodaten für eine Adresse
     * 
     * Priorität:
     * 1. Koordinaten aus Excel (falls vorhanden)
     * 2. PLZ-Lookup (automatisch)
     * 3. null (keine Koordinaten)
     */
    private function resolveGeodata(array $addressData, array $rowData): array
    {
        $latitude = null;
        $longitude = null;
        
        // 1. Prüfe, ob Excel bereits Koordinaten hat
        if (isset($rowData['latitude']) && isset($rowData['longitude'])) {
            $latitude = $this->parseCoordinate($rowData['latitude']);
            $longitude = $this->parseCoordinate($rowData['longitude']);
            
            if ($latitude !== null && $longitude !== null) {
                return [
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ];
            }
        }
        
        // 2. PLZ-Lookup (automatisch)
        if (!empty($addressData['postal_code']) && 
            ($addressData['country_code'] === 'DE' || empty($addressData['country_code']))) {
            
            $plzInfo = $this->lookupPlz($addressData['postal_code']);
            if ($plzInfo && isset($plzInfo['latitude']) && isset($plzInfo['longitude'])) {
                return [
                    'latitude' => (float)$plzInfo['latitude'],
                    'longitude' => (float)$plzInfo['longitude']
                ];
            }
        }
        
        // 3. Keine Koordinaten gefunden
        return [
            'latitude' => null,
            'longitude' => null
        ];
    }
    
    /**
     * Führt PLZ-Lookup durch (wie beim manuellen Anlegen)
     */
    private function lookupPlz(string $plz): ?array
    {
        // Verwende die gleiche Funktion wie beim manuellen Anlegen
        require_once __DIR__ . '/../../config/plz_mapping.php';
        
        return mapPlzToBundeslandAndCity($plz);
    }
    
    /**
     * Parst Koordinaten aus Excel (kann String oder Number sein)
     */
    private function parseCoordinate($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        $value = trim((string)$value);
        $float = (float)$value;
        
        // Prüfe auf gültigen Bereich
        if ($float >= -90 && $float <= 90) {
            return $float;
        }
        
        return null;
    }
}
```

### 3. OrgService: Adresse mit Geodaten erstellen

Beim Erstellen der Org wird die Adresse mit Geodaten gespeichert:

```php
// In OrgService::createOrg() oder OrgImportService
if (isset($orgData['address'])) {
    $addressData = $orgData['address'];
    
    // Adresse erstellen (inkl. Geodaten)
    $this->addressService->addAddress($orgUuid, $addressData, $userId);
}
```

---

## Mapping-Konfiguration: Geodaten-Felder

### Option 1: Koordinaten aus Excel (optional)

```yaml
mappings:
  org_excel_with_geodata:
    columns:
      # ... Standard-Felder ...
      
      # Optional: Koordinaten direkt aus Excel
      latitude:
        excel_column: "P"  # Oder: excel_header: "Breitengrad"
        required: false
        transformation:
          - type: "to_float"
        validation:
          - type: "range"
            min: -90
            max: 90
      
      longitude:
        excel_column: "Q"  # Oder: excel_header: "Längengrad"
        required: false
        transformation:
          - type: "to_float"
        validation:
          - type: "range"
            min: -180
            max: 180
```

### Option 2: Automatisch aus PLZ (Standard)

```yaml
mappings:
  org_excel_auto_geodata:
    columns:
      # ... Standard-Felder ...
      
      address_postal_code:
        excel_column: "F"
        required: false
        # Geodaten werden automatisch aus PLZ ermittelt
        # (keine explizite Konfiguration nötig)
```

---

## Workflow: Geodaten beim Import

```
Excel-Zeile lesen
        │
        ▼
┌──────────────────────┐
│ Adress-Daten extrahieren│
│ - PLZ                 │
│ - Stadt               │
│ - Straße              │
│ - Land                │
└──────────────────────┘
        │
        ▼
┌──────────────────────┐
│ Geodaten ermitteln   │
│                      │
│ 1. Excel hat         │
│    Koordinaten?      │
│    → Verwende diese   │
│                      │
│ 2. PLZ vorhanden?    │
│    → PLZ-Lookup      │
│    → Koordinaten     │
│                      │
│ 3. Keine Koordinaten │
│    → null            │
└──────────────────────┘
        │
        ▼
┌──────────────────────┐
│ Org erstellen        │
│ + Adresse mit        │
│   Geodaten           │
└──────────────────────┘
```

---

## Beispiel: Import mit Geodaten

### Excel-Struktur

| A (Name) | ... | F (PLZ) | G (Stadt) | ... | P (Lat) | Q (Lon) |
|----------|-----|---------|-----------|-----|---------|---------|
| Musterfirma | ... | 12345 | Berlin | ... | 52.5200 | 13.4050 |

### Mapping

```yaml
columns:
  name:
    excel_column: "A"
  
  address_postal_code:
    excel_column: "F"
  
  address_city:
    excel_column: "G"
  
  # Optional: Koordinaten aus Excel
  latitude:
    excel_column: "P"
    required: false
  
  longitude:
    excel_column: "Q"
    required: false
```

### Ergebnis

**Szenario 1: Excel hat Koordinaten**
- PLZ: 12345
- Excel Lat: 52.5200
- Excel Lon: 13.4050
- **→ Verwende Excel-Koordinaten** (höhere Priorität)

**Szenario 2: Excel hat keine Koordinaten, aber PLZ**
- PLZ: 12345
- Excel Lat: (leer)
- Excel Lon: (leer)
- **→ PLZ-Lookup → Koordinaten automatisch ermittelt**

**Szenario 3: Keine PLZ, keine Koordinaten**
- PLZ: (leer)
- **→ Keine Koordinaten** (null)

---

## Code-Integration

### OrgImportService erweitern

```php
// In OrgImportService::importFromExcel()
foreach ($rowData as $row) {
    // ... Mapping ...
    
    $orgData = $this->mapRowToOrgData($rowData, $mapping);
    
    // Geodaten werden automatisch in mapRowToOrgData() ermittelt
    // (siehe Code oben)
    
    $org = $this->orgService->createOrg($orgData, $userId);
    
    // Adresse mit Geodaten wird in createOrg() erstellt
    // (wenn $orgData['address'] vorhanden)
}
```

### OrgService erweitern

```php
// In OrgService::createOrg()
public function createOrg(array $data, ?string $userId = null): array
{
    // ... bestehender Code ...
    
    $org = $this->getOrg($uuid);
    
    // Adresse erstellen (falls vorhanden)
    if (isset($data['address']) && is_array($data['address'])) {
        $addressData = $data['address'];
        $addressData['org_uuid'] = $uuid;
        
        // Geodaten sind bereits in $addressData enthalten
        // (wurden im Import-Service ermittelt)
        $this->addressService->addAddress($uuid, $addressData, $userId);
    }
    
    // ... Event-Publishing ...
    
    return $org;
}
```

---

## Zusammenfassung

### ✅ Lösung: Automatische Geodaten-Ermittlung

1. **Priorität:**
   - Excel-Koordinaten (falls vorhanden) → höchste Priorität
   - PLZ-Lookup (automatisch) → Standard
   - null (keine Koordinaten) → Fallback

2. **Implementierung:**
   - `OrgImportService::resolveGeodata()` ermittelt Koordinaten
   - Verwendet `mapPlzToBundeslandAndCity()` (wie beim manuellen Anlegen)
   - Koordinaten werden in `org_address` gespeichert

3. **Mapping:**
   - Optional: `latitude` und `longitude` Spalten in Excel
   - Standard: Automatisch aus PLZ

**Vorteile:**
- ✅ Konsistent mit manuellem Anlegen
- ✅ Automatisch, keine manuelle Eingabe nötig
- ✅ Flexibel: Excel-Koordinaten haben Priorität
- ✅ Keine zusätzliche API nötig (verwendet bestehende PLZ-Lookup-Funktion)

