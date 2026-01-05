# CRM Import - Mehrere Personen pro Organisation

## Problemstellung

In der Excel-Datei gibt es pro Organisation mehrere Personen (GF1, GF2, GF3, etc.):
- **GF1**: Geschäftsführer 1 (Anrede, Vorname, Name)
- **GF2**: Geschäftsführer 2 (Anrede, Vorname, Name)
- **GF3-6**: Weitere Geschäftsführer

**Aktueller Stand:** Personen werden noch nicht extrahiert und in separate Staging-Tabellen importiert.

---

## Konzept: 1:N-Beziehung (Org : Personen)

### Datenmodell

```
org_import_staging (1 Zeile = 1 Organisation)
    ↓
person_import_staging (N Zeilen = N Personen)
    ↓
employment_import_staging (Join: Org ↔ Person)
```

### Beispiel

**Excel-Zeile 2:**
- Organisation: "Francia Mozzarella GmbH"
- GF1: Herr, Valter, Francia
- GF2: Herr, Herbert, Deniffel
- GF3: Herr, Jacob, Wolters

**Ergebnis im Staging:**
- `org_import_staging`: 1 Zeile (Francia Mozzarella GmbH)
- `person_import_staging`: 3 Zeilen (Valter Francia, Herbert Deniffel, Jacob Wolters)
- `employment_import_staging`: 3 Zeilen (Verknüpfungen)

---

## Implementierung

### Phase 1: Staging-Import (aktuell fehlend)

**In `OrgImportService::importToStaging()`:**

```php
// Nach saveStagingRow() für Organisation:
$orgStagingUuid = $this->saveStagingRow(...);

// Extrahiere Personen aus rowData
$persons = $this->extractPersonsFromRow($rowData, $mappingConfig);

// Für jede Person:
foreach ($persons as $personData) {
    // 1. Person in person_import_staging speichern
    $personStagingUuid = $this->savePersonStagingRow(
        $batchUuid,
        $rowNumber,
        $personData,
        $orgStagingUuid
    );
    
    // 2. Employment in employment_import_staging speichern
    $this->saveEmploymentStagingRow(
        $batchUuid,
        $orgStagingUuid,
        $personStagingUuid,
        $personData
    );
}
```

### Phase 2: Person-Extraktion

**Neue Methode: `extractPersonsFromRow()`**

```php
private function extractPersonsFromRow(array $rowData, array $mappingConfig): array
{
    $persons = [];
    
    // Suche alle Person-Felder im Mapping
    // Pattern: person_salutation, person_first_name, person_last_name
    // Mit Präfix: GF1, GF2, GF3, etc.
    
    $personGroups = [];
    
    // Gruppiere nach GF-Nummer (1-6)
    foreach ($rowData as $field => $value) {
        if (preg_match('/^person_(salutation|first_name|last_name)$/', $field, $matches)) {
            // Extrahiere GF-Nummer aus Spalten-Header
            // z.B. "GF1Anrede" → GF1, "GF2Vorname" → GF2
            // Oder aus Mapping-Config
        }
    }
    
    // Für jede GF-Gruppe (1-6):
    for ($gfNum = 1; $gfNum <= 6; $gfNum++) {
        $salutation = $rowData["person_salutation_GF{$gfNum}"] ?? null;
        $firstName = $rowData["person_first_name_GF{$gfNum}"] ?? null;
        $lastName = $rowData["person_last_name_GF{$gfNum}"] ?? null;
        
        // Nur wenn mindestens Name vorhanden
        if ($firstName || $lastName) {
            $persons[] = [
                'gf_number' => $gfNum,
                'salutation' => $salutation,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'job_title' => 'Geschäftsführer', // Standard oder aus Mapping
                'job_function' => 'GF'
            ];
        }
    }
    
    return $persons;
}
```

### Phase 3: Mapping-Anpassung

**Problem:** Aktuell werden alle GF-Felder auf dieselben TOM-Felder gemappt:
- GF1Anrede, GF2Anrede → `person_salutation`
- GF1Vorname, GF2Vorname → `person_first_name`

**Lösung:** Mapping muss GF-Nummer erhalten:

```php
// Mapping-Config erweitern:
$mappingConfig = [
    'columns' => [
        'person_salutation_GF1' => ['excel_column' => 'N', 'gf_number' => 1],
        'person_first_name_GF1' => ['excel_column' => 'P', 'gf_number' => 1],
        'person_last_name_GF1' => ['excel_column' => 'Q', 'gf_number' => 1],
        'person_salutation_GF2' => ['excel_column' => 'R', 'gf_number' => 2],
        // ...
    ]
];
```

**Oder:** Mapping-Service erkennt automatisch GF-Nummer aus Spalten-Header.

---

## Workflow

### 1. Staging-Import

```
Excel-Zeile 2
    ↓
Org-Staging (1 Zeile)
    ↓
Person-Extraktion
    ↓
Person-Staging (N Zeilen)
    ↓
Employment-Staging (N Zeilen)
```

### 2. Review

**UI zeigt:**
- Organisation + zugehörige Personen
- Getrennte Ansicht: "Organisationen" und "Personen"
- Personen können einzeln freigegeben/abgelehnt werden

### 3. Finaler Import

```
Org-Staging (approve_new)
    ↓
Org erstellen
    ↓
Person-Staging (approve_new, org_staging_uuid = ...)
    ↓
Person erstellen
    ↓
Employment-Staging (org_staging_uuid + person_staging_uuid)
    ↓
person_affiliation erstellen
```

---

## Offene Fragen

1. **Deduplikation:** Wie werden Personen-Duplikate erkannt?
   - Gleicher Name + gleiche Organisation?
   - Oder nur Name (Person kann bei mehreren Orgs arbeiten)?

2. **Validierung:** Was ist Pflicht für Person?
   - Mindestens Vor- oder Nachname?
   - Oder beides?

3. **Job-Title:** Standard "Geschäftsführer" oder aus Mapping?

4. **GF-Nummer:** Soll die GF-Nummer (1-6) gespeichert werden?
   - Als `job_title`? ("Geschäftsführer 1", "Geschäftsführer 2")
   - Oder als Metadaten?

---

## Nächste Schritte

1. ✅ Staging-Tabellen existieren bereits
2. ⚠️ Person-Extraktion implementieren
3. ⚠️ Mapping-Service erweitern (GF-Nummer erkennen)
4. ⚠️ Review-UI erweitern (Personen anzeigen)
5. ⚠️ Finaler Import (Personen + Employment)

