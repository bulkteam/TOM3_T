# Mehrere Personen pro Organisation - Zusammenfassung

## Aktueller Stand

**❌ Noch nicht implementiert:** Personen werden aktuell nicht aus Excel-Zeilen extrahiert.

**✅ Vorbereitet:**
- Staging-Tabellen existieren: `person_import_staging`, `employment_import_staging`
- Mapping erkennt GF1-6 Felder (Anrede, Vorname, Name)

---

## Problem

**Excel-Struktur:**
```
Zeile 2:
- Organisation: "Francia Mozzarella GmbH"
- GF1Anrede: "Herr"
- GF1Vorname: "Valter"
- GF1Name: "Francia"
- GF2Anrede: "Herr"
- GF2Vorname: "Herbert"
- GF2Name: "Deniffel"
- GF3Anrede: "Herr"
- GF3Vorname: "Jacob"
- GF3Name: "Wolters"
```

**Aktuell:** Nur Organisation wird in `org_import_staging` gespeichert.

**Soll:** 
- 1 Zeile in `org_import_staging` (Organisation)
- 3 Zeilen in `person_import_staging` (3 Personen)
- 3 Zeilen in `employment_import_staging` (3 Verknüpfungen)

---

## Lösung: Person-Extraktion

### Schritt 1: Mapping erweitern

**Aktuell:**
```json
{
  "person_salutation": {"excel_column": "N"},  // GF1Anrede
  "person_first_name": {"excel_column": "P"},  // GF1Vorname
  "person_last_name": {"excel_column": "Q"}    // GF1Name
}
```

**Erweitert:**
```json
{
  "person_salutation_GF1": {"excel_column": "N", "gf_number": 1},
  "person_first_name_GF1": {"excel_column": "P", "gf_number": 1},
  "person_last_name_GF1": {"excel_column": "Q", "gf_number": 1},
  "person_salutation_GF2": {"excel_column": "R", "gf_number": 2},
  "person_first_name_GF2": {"excel_column": "T", "gf_number": 2},
  "person_last_name_GF2": {"excel_column": "U", "gf_number": 2},
  // ... GF3-6
}
```

### Schritt 2: Person-Extraktion in `importToStaging()`

```php
// Nach saveStagingRow() für Organisation:
$orgStagingUuid = $this->saveStagingRow(...);

// Extrahiere Personen
$persons = $this->extractPersonsFromRow($rowData, $mappingConfig);

foreach ($persons as $personData) {
    // Person-Staging
    $personStagingUuid = $this->savePersonStagingRow(
        $batchUuid,
        $rowNumber,
        $personData,
        $orgStagingUuid
    );
    
    // Employment-Staging
    $this->saveEmploymentStagingRow(
        $batchUuid,
        $orgStagingUuid,
        $personStagingUuid,
        $personData
    );
}
```

### Schritt 3: Review-UI

**Zeigt:**
- Organisation + zugehörige Personen
- Getrennte Tabs: "Organisationen" | "Personen"
- Personen können einzeln freigegeben werden

---

## Beispiel-Output

**Für Excel-Zeile 2:**

```
org_import_staging:
  - staging_uuid: abc-123
    name: "Francia Mozzarella GmbH"
    row_number: 2

person_import_staging:
  - staging_uuid: def-456
    org_staging_uuid: abc-123
    first_name: "Valter"
    last_name: "Francia"
    row_number: 2-1  (Zeile 2, Person 1)
  
  - staging_uuid: ghi-789
    org_staging_uuid: abc-123
    first_name: "Herbert"
    last_name: "Deniffel"
    row_number: 2-2  (Zeile 2, Person 2)
  
  - staging_uuid: jkl-012
    org_staging_uuid: abc-123
    first_name: "Jacob"
    last_name: "Wolters"
    row_number: 2-3  (Zeile 2, Person 3)

employment_import_staging:
  - org_staging_uuid: abc-123
    person_staging_uuid: def-456
    job_title: "Geschäftsführer"
  
  - org_staging_uuid: abc-123
    person_staging_uuid: ghi-789
    job_title: "Geschäftsführer"
  
  - org_staging_uuid: abc-123
    person_staging_uuid: jkl-012
    job_title: "Geschäftsführer"
```

---

## Status

- ✅ Staging-Tabellen vorhanden
- ✅ Mapping erkennt GF-Felder
- ❌ Person-Extraktion fehlt
- ❌ Review-UI für Personen fehlt
- ❌ Finaler Import für Personen fehlt
