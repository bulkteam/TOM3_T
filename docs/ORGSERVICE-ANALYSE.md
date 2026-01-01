# OrgService.php - Analyse und Refactoring-Empfehlungen

## Aktuelle Situation

**Dateigröße:** 2.186 Zeilen  
**Problem:** "God Object" Anti-Pattern - eine Klasse mit zu vielen Verantwortlichkeiten

## Inhalt der OrgService.php

### 1. **Kern-Organisation CRUD** (~200 Zeilen)
- `createOrg()` - Erstellen mit Duplikat-Prüfung
- `getOrg()` - Abrufen einer Organisation
- `updateOrg()` - Aktualisieren
- `archiveOrg()` / `unarchiveOrg()` - Archivierung

### 2. **Account Management** (~150 Zeilen)
- `getAccountHealth()` - Account-Gesundheit berechnen
- `getAccountsByOwner()` - Organisationen nach Account Owner
- `getAvailableAccountOwners()` - Verfügbare Account Owner
- `getAvailableAccountOwnersWithNames()` - Mit Namen
- Private Helper: `getLastContactDate()`, `getStaleOffers()`, `getWaitingProjects()`, `getOpenEscalations()`

### 3. **Alias Management** (~30 Zeilen)
- `addAlias()` - Alias hinzufügen
- `getAliases()` - Alle Aliase abrufen

### 4. **Address Management** (~200 Zeilen)
- `addAddress()` - Adresse hinzufügen
- `getAddress()` - Einzelne Adresse
- `getAddresses()` - Alle Adressen einer Organisation
- `updateAddress()` - Adresse aktualisieren
- `deleteAddress()` - Adresse löschen

### 5. **VAT Registration Management** (~350 Zeilen)
- `addVatRegistration()` - USt-ID hinzufügen
- `getVatRegistration()` - Einzelne USt-ID
- `getVatRegistrations()` - Alle USt-IDs einer Organisation
- `getVatIdForAddress()` - USt-ID für Adresse
- `updateVatRegistration()` - USt-ID aktualisieren
- `deleteVatRegistration()` - USt-ID löschen

### 6. **Relation Management** (~200 Zeilen)
- `addRelation()` - Relation hinzufügen
- `getRelation()` - Einzelne Relation
- `getRelations()` - Alle Relationen einer Organisation
- `updateRelation()` - Relation aktualisieren
- `deleteRelation()` - Relation löschen

### 7. **Communication Channel Management** (~200 Zeilen)
- `addCommunicationChannel()` - Kommunikationskanal hinzufügen
- `getCommunicationChannel()` - Einzelner Kanal
- `getCommunicationChannels()` - Alle Kanäle einer Organisation
- `updateCommunicationChannel()` - Kanal aktualisieren
- `deleteCommunicationChannel()` - Kanal löschen
- `formatPhoneNumber()` - Telefonnummer formatieren

### 8. **Search & Find** (~200 Zeilen)
- `searchOrgs()` - Volltext-Suche mit Filtern
- `findSimilarOrgs()` - Ähnliche Organisationen finden
- `listOrgs()` - Liste (Wrapper um searchOrgs)

### 9. **Access Tracking** (~20 Zeilen)
- `trackAccess()` - Zugriff protokollieren
- `getRecentOrgs()` - Zuletzt verwendete Organisationen
- `getFavoriteOrgs()` - Favoriten

### 10. **Customer Number Management** (~60 Zeilen)
- `getNextCustomerNumber()` - Nächste Kundennummer
- `generateCustomerNumber()` - Kundennummer generieren

### 11. **Audit Trail** (~100 Zeilen)
- `getAuditTrail()` - Audit-Trail abrufen
- `insertAuditEntry()` - Eintrag einfügen (private)
- `resolveFieldValue()` - Feldwert auflösen
- `formatAddressFieldValue()` - Adress-Feld formatieren
- `formatVatFieldValue()` - USt-Feld formatieren
- `formatChannelFieldValue()` - Kanal-Feld formatieren

### 12. **Helper & Utilities** (~100 Zeilen)
- `getOrgWithDetails()` - Organisation mit allen Details
- `getIndustryByUuid()` - Branche nach UUID
- Verschiedene Formatierungs-Helper

## Warum ist die Datei so groß?

1. **Fehlende Modularisierung im Backend**
   - Im Frontend wurde bereits modularisiert (`org-address.js`, `org-relation.js`)
   - Im Backend ist alles noch in einer Klasse

2. **Viele Sub-Entities**
   - Organisationen haben viele zugehörige Entitäten (Addresses, VAT IDs, Relations, Channels)
   - Jede Sub-Entity hat CRUD-Operationen

3. **Geschäftslogik**
   - Account Health Berechnung
   - Duplikat-Prüfung
   - Kundennummer-Generierung
   - Formatierungs-Logik

4. **Audit Trail Integration**
   - Jede Änderung wird protokolliert
   - Viele Formatierungs-Helper für verschiedene Feldtypen

## Refactoring-Empfehlungen

### Option 1: Service-Module extrahieren (Empfohlen)

```
src/TOM/Service/Org/
├── OrgService.php              (~300 Zeilen) - Kern-CRUD
├── OrgAddressService.php       (~200 Zeilen) - Address Management
├── OrgVatService.php           (~350 Zeilen) - VAT Registration
├── OrgRelationService.php     (~200 Zeilen) - Relation Management
├── OrgChannelService.php       (~200 Zeilen) - Communication Channels
├── OrgAccountService.php       (~150 Zeilen) - Account Management
├── OrgSearchService.php        (~200 Zeilen) - Search & Find
└── OrgAliasService.php          (~30 Zeilen) - Alias Management
```

**Vorteile:**
- Klare Trennung der Verantwortlichkeiten
- Einfacher zu testen
- Einfacher zu warten
- Parallele Entwicklung möglich

**Nachteile:**
- Mehr Dateien
- Dependency Management zwischen Services

### Option 2: Repository Pattern

```
src/TOM/Repository/
├── OrgRepository.php           - Datenbank-Zugriff
├── OrgAddressRepository.php
├── OrgVatRepository.php
└── ...

src/TOM/Service/
├── OrgService.php              - Geschäftslogik
├── OrgAddressService.php
└── ...
```

**Vorteile:**
- Klare Trennung Datenbank/Logik
- Einfacher zu testen (Mock Repositories)

**Nachteile:**
- Mehr Abstraktionsebenen
- Mehr Code insgesamt

### Option 3: Hybrid-Ansatz (Pragmatisch)

1. **Sofort extrahieren:**
   - `OrgAddressService` (bereits im Frontend modular)
   - `OrgVatService` (komplex, eigenständig)
   - `OrgRelationService` (bereits im Frontend modular)

2. **Später extrahieren:**
   - `OrgChannelService`
   - `OrgAccountService`
   - `OrgSearchService`

3. **In OrgService behalten:**
   - Kern-CRUD (create, get, update, archive)
   - Customer Number Generation
   - Access Tracking (delegiert bereits an AccessTrackingService)

## Migration-Strategie

### Schritt 1: Neue Services erstellen
- Neue Service-Klassen anlegen
- Methoden aus `OrgService` kopieren
- Dependency Injection für `$db` und andere Services

### Schritt 2: OrgService anpassen
- Methoden durch Delegation ersetzen
- Alte Methoden als Deprecated markieren
- API-Endpunkte anpassen

### Schritt 3: API-Endpunkte refactoren
- Neue Endpunkte für Sub-Entities
- Beispiel: `/api/orgs/{uuid}/addresses` → `OrgAddressService`

### Schritt 4: Tests
- Unit Tests für neue Services
- Integration Tests für API-Endpunkte

## Vergleich Frontend vs. Backend

| Frontend | Backend |
|----------|---------|
| ✅ Modularisiert | ❌ Monolithisch |
| `org-address.js` | ❌ Alles in `OrgService.php` |
| `org-relation.js` | ❌ Alles in `OrgService.php` |
| Klare Trennung | God Object |

## Empfehlung

**Sofort umsetzen:**
1. `OrgAddressService` extrahieren (Frontend bereits modular)
2. `OrgVatService` extrahieren (komplex, eigenständig)
3. `OrgRelationService` extrahieren (Frontend bereits modular)

**Danach:**
- `OrgChannelService` extrahieren
- `OrgAccountService` extrahieren
- `OrgSearchService` extrahieren

**Ergebnis:**
- `OrgService.php`: ~300 Zeilen (Kern-CRUD)
- Klare Verantwortlichkeiten
- Parallele Entwicklung möglich
- Einfacher zu testen und warten
