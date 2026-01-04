# USt-ID Verwaltung - Konzept

## Problemstellung

Eine Organisation kann mehrere USt-IDs haben:
- Hauptsitz in DE → DE123456789
- Niederlassung in AT → ATU98765432
- Niederlassung in FR → FRXX123456789

**Die USt-ID gehört an den Standort, nicht an die Organisation!**

## Datenmodell

### 1. Erweiterung `org_address`

Aktuell:
- `address_type`: headquarters | delivery | billing | other

**Erweitern um:**
- `location_type`: HQ | Branch | Subsidiary | SalesOffice | Plant | Warehouse | Other
- `country_code`: ISO 2-stellig (DE, AT, FR, etc.)

**Begründung:**
- Standort-Typ ist wichtig für steuerliche Zuordnung
- Country Code ist wichtig für USt-ID-Validierung

### 2. Neue Tabelle `org_vat_registration`

```sql
CREATE TABLE org_vat_registration (
    vat_registration_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    address_uuid CHAR(36) NOT NULL,  -- Verknüpfung zur Adresse
    vat_id VARCHAR(50) NOT NULL,     -- z.B. "DE123456789", "ATU98765432"
    country_code VARCHAR(2) NOT NULL,  -- ISO 2-stellig
    valid_from DATE NOT NULL,
    valid_to DATE NULL,              -- NULL = aktuell gültig
    is_primary_for_country TINYINT(1) NOT NULL DEFAULT 0,  -- Primär für dieses Land
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE,
    FOREIGN KEY (address_uuid) REFERENCES org_address(address_uuid) ON DELETE CASCADE,
    UNIQUE KEY unique_vat_id_country (vat_id, country_code, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Wichtig:**
- USt-ID ist an `address_uuid` gebunden, nicht direkt an `org_uuid`
- Zeitbezug: `valid_from` / `valid_to` für historische USt-IDs
- `is_primary_for_country`: Primäre USt-ID pro Land (für Default-Auswahl)

### 3. Optional: Default USt-ID auf Organisationsebene

```sql
ALTER TABLE org ADD COLUMN default_vat_id VARCHAR(50) COMMENT 'Fallback USt-ID (nur wenn keine Standort-spezifische vorhanden)';
```

**Nur als Fallback**, nicht als alleinige Wahrheit!

## Logik

### Rechnungsstellung

1. **Rechnungsempfänger = Adresse**
   - User wählt Adresse für Rechnung
   - System sucht USt-ID für diese Adresse

2. **USt-ID-Suche:**
   ```sql
   SELECT vat_id, country_code
   FROM org_vat_registration
   WHERE address_uuid = :address_uuid
     AND (valid_to IS NULL OR valid_to >= CURDATE())
   ORDER BY is_primary_for_country DESC, valid_from DESC
   LIMIT 1;
   ```

3. **Fallback:**
   - Wenn keine USt-ID für Adresse → `org.default_vat_id`
   - Wenn auch das leer → Fehler/Warnung

### UI-Logik

**Organisation - Überblick:**
```
Organisation: Müller Maschinenbau GmbH

USt-Registrierungen:
• DE – DE123456789 (HQ Berlin)
• AT – ATU98765432 (Niederlassung Wien)
```

**Adresse - Detail:**
```
Adresse: Wien, Hauptstraße 1
Typ: Branch
Land: AT
USt-ID: ATU98765432
```

**Rechnung:**
```
Rechnung an:
☑ Hauptsitz DE (DE123456789)
☐ Niederlassung AT (ATU98765432)
```

## Unterschied: Niederlassung vs. Tochtergesellschaft

**Niederlassung:**
- Gleiche juristische Person
- Eigene USt-ID
- Eigene Adresse
- `org_relation` mit `relation_type = 'branch'`

**Tochtergesellschaft:**
- Eigene Organisation
- Eigener `org` Datensatz
- Eigene USt-ID
- `org_relation` mit `relation_type = 'subsidiary'`

## Validierung

### USt-ID Format pro Land

- **DE**: DE + 9 Ziffern (z.B. DE123456789)
- **AT**: ATU + 8 Ziffern (z.B. ATU12345678)
- **FR**: FR + 2 Zeichen + 9 Ziffern (z.B. FRXX123456789)
- etc.

**Implementierung:**
- Validierungsfunktion pro Land
- Warnung bei falschem Format

## Migration

1. Erweitere `org_address` um `location_type` und `country_code`
2. Erstelle `org_vat_registration` Tabelle
3. Optional: `org.default_vat_id` als Fallback
4. Migriere bestehende Daten (falls vorhanden)

## Service-Layer

### OrgService

```php
// USt-ID für Adresse
public function getVatIdForAddress(string $addressUuid): ?array

// Alle USt-IDs einer Organisation
public function getVatRegistrations(string $orgUuid): array

// USt-ID hinzufügen
public function addVatRegistration(string $orgUuid, string $addressUuid, array $data): array

// USt-ID aktualisieren
public function updateVatRegistration(string $vatRegistrationUuid, array $data): array
```

## API

```
GET /api/orgs/{uuid}/vat-registrations
POST /api/orgs/{uuid}/vat-registrations
PUT /api/orgs/{uuid}/vat-registrations/{vat_uuid}
DELETE /api/orgs/{uuid}/vat-registrations/{vat_uuid}
GET /api/orgs/{uuid}/addresses/{address_uuid}/vat-id
```





