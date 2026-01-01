# PersonService Refactoring-Vorschlag

## Aktuelle Situation

### PersonService.php
- **Größe**: 539 Zeilen
- **Funktionen**: 20 Methoden
- **Status**: Noch überschaubar, aber wächst

### OrgService.php (zum Vergleich)
- **Größe**: 2186 Zeilen ⚠️
- **Funktionen**: 54 Methoden
- **Status**: Zu groß, sollte aufgeteilt werden

## Problem-Analyse

### PersonService.php Verantwortlichkeiten

1. **Kern-Person-CRUD** (~150 Zeilen)
   - `createPerson()`
   - `getPerson()`
   - `updatePerson()`
   - `listPersons()`
   - `searchPersons()`

2. **Affiliation-Management** (~80 Zeilen)
   - `createAffiliation()`
   - `getAffiliation()`
   - `getPersonAffiliations()`

3. **Relationship-Management** (~120 Zeilen)
   - `createRelationship()`
   - `getRelationship()`
   - `getPersonRelationships()`
   - `deleteRelationship()`

4. **OrgUnit-Management** (~50 Zeilen)
   - `getOrgUnits()`
   - `createOrgUnit()`
   - `getOrgUnit()`

5. **Audit & Access-Tracking** (~30 Zeilen)
   - `resolveFieldValue()`
   - `getAuditTrail()`
   - `trackAccess()`
   - `getRecentPersons()`

## Empfohlene Aufteilung

### Zielstruktur (analog zu ORG-Modularisierung)

```
src/TOM/Service/Person/
  PersonService.php              # Kern-CRUD (~150 Zeilen)
  PersonAffiliationService.php    # Affiliations (~150 Zeilen)
  PersonRelationshipService.php     # Relationships (~150 Zeilen)
  PersonOrgUnitService.php       # OrgUnits (~100 Zeilen)
```

### Vorteile

1. **Konsistenz**: Gleiche Struktur wie geplante ORG-Aufteilung
2. **Wartbarkeit**: Jede Datei < 200 Zeilen
3. **Klare Verantwortlichkeiten**: Jeder Service hat eine Domäne
4. **Testbarkeit**: Services können isoliert getestet werden
5. **Erweiterbarkeit**: Neue Features in passende Services

## Vergleich: Frontend-Struktur

### ORG (sehr modular) ✅
- `org-detail.js` - Koordinator
- `org-detail-view.js` - Rendering
- `org-detail-edit.js` - Bearbeitung
- `org-forms.js` - Formulare
- `org-address.js` - Adressen-Modul
- `org-channel.js` - Channels-Modul
- `org-relation.js` - Relationen-Modul
- `org-vat.js` - USt-ID-Modul
- `org-search.js` - Suche

### PERS (weniger modular) ⚠️
- `person-detail.js` - Koordinator + Logik (~740 Zeilen)
- `person-detail-view.js` - Rendering (~193 Zeilen)
- `person-forms.js` - Formulare (~359 Zeilen)
- `person-search.js` - Suche

**Fehlend**:
- `person-affiliation.js` - Affiliations-Modul (aktuell in person-detail.js)
- `person-relationship.js` - Relationships-Modul (aktuell in person-detail.js)

## Empfehlung

### Phase 1: Frontend-Modularisierung (sofort)

1. **Extrahieren aus `person-detail.js`**:
   - `person-affiliation.js` - Affiliations-Management (~200 Zeilen)
   - `person-relationship.js` - Relationships-Management (~200 Zeilen)
   - `person-detail.js` reduziert auf ~300 Zeilen

### Phase 2: Backend-Modularisierung (später)

1. **PersonService.php aufteilen**:
   - `PersonService.php` - Nur Kern-CRUD
   - `PersonAffiliationService.php` - Affiliations
   - `PersonRelationshipService.php` - Relationships
   - `PersonOrgUnitService.php` - OrgUnits

2. **API-Endpoints aufteilen** (optional):
   - `persons.php` - Kern-CRUD
   - `persons-affiliations.php` - Affiliations
   - `persons-relationships.php` - Relationships

## Aktionsplan

### Sofort (Frontend)
- [ ] `person-affiliation.js` erstellen
- [ ] `person-relationship.js` erstellen
- [ ] Code aus `person-detail.js` extrahieren

### Später (Backend)
- [ ] `PersonAffiliationService.php` erstellen
- [ ] `PersonRelationshipService.php` erstellen
- [ ] `PersonOrgUnitService.php` erstellen
- [ ] `PersonService.php` auf Kern-CRUD reduzieren

## Vergleich mit OrgService.php

**OrgService.php sollte auch aufgeteilt werden** (laut `MODULAR-DEVELOPMENT-GUIDE.md`):

```
src/TOM/Service/Org/
  OrgService.php           # Kern-CRUD (~200 Zeilen)
  OrgAddressService.php    # Adressen (~150 Zeilen)
  OrgChannelService.php    # Channels (~150 Zeilen)
  OrgVatService.php        # USt-IDs (~100 Zeilen)
  OrgRelationService.php   # Beziehungen (~200 Zeilen)
  OrgAuditService.php      # Audit-Trail (~150 Zeilen)
  OrgHealthService.php     # Account Health (~150 Zeilen)
  OrgSearchService.php     # Suche (~200 Zeilen)
```

**Status**: Noch nicht umgesetzt (OrgService.php hat immer noch 2186 Zeilen)

## Fazit

**PersonService.php** ist noch überschaubar (539 Zeilen), sollte aber **jetzt** aufgeteilt werden, bevor es zu groß wird.

**OrgService.php** ist bereits zu groß (2186 Zeilen) und sollte dringend aufgeteilt werden.

**Frontend**: PERS sollte die gleiche modulare Struktur wie ORG bekommen.
