# PersonService Refactoring Plan

## Aktuelle Situation

**PersonService.php:** 539 Zeilen  
**Status:** Noch nicht modularisiert (analog zu OrgService vor dem Refactoring)

## Inhalt der PersonService.php

### 1. **Kern-Person CRUD** (~200 Zeilen)
- `createPerson()` - Erstellen mit Duplikat-Prüfung
- `getPerson()` - Abrufen einer Person
- `updatePerson()` - Aktualisieren
- `listPersons()` - Liste aller Personen
- `searchPersons()` - Volltext-Suche

### 2. **Affiliation Management** (~80 Zeilen)
- `createAffiliation()` - Affiliation hinzufügen
- `getAffiliation()` - Einzelne Affiliation
- `getPersonAffiliations()` - Alle Affiliations einer Person

### 3. **Relationship Management** (~100 Zeilen)
- `createRelationship()` - Relationship hinzufügen
- `getRelationship()` - Einzelne Relationship
- `getPersonRelationships()` - Alle Relationships einer Person
- `deleteRelationship()` - Relationship löschen

### 4. **OrgUnit Management** (~50 Zeilen)
- `getOrgUnits()` - Alle Org Units einer Organisation
- `createOrgUnit()` - Org Unit erstellen
- `getOrgUnit()` - Einzelne Org Unit

### 5. **Access Tracking** (~10 Zeilen)
- `getRecentPersons()` - Zuletzt verwendete Personen

### 6. **Audit Trail** (~10 Zeilen)
- `getAuditTrail()` - Audit-Trail abrufen

## Refactoring-Plan (analog zu OrgService)

### Schritt 1: PersonService auf BaseEntityService umstellen

**Aktuell:**
```php
class PersonService
{
    private PDO $db;
    private EventPublisher $eventPublisher;
    private AuditTrailService $auditTrailService;
    private AccessTrackingService $accessTrackingService;
}
```

**Ziel:**
```php
class PersonService extends BaseEntityService
{
    private AccessTrackingService $accessTrackingService;
    private PersonAffiliationService $affiliationService;
    private PersonRelationshipService $relationshipService;
}
```

### Schritt 2: Services extrahieren

**Zu erstellende Services:**

1. **PersonAffiliationService** (`src/TOM/Service/Person/PersonAffiliationService.php`)
   - `createAffiliation()`
   - `getAffiliation()`
   - `getPersonAffiliations()`
   - `updateAffiliation()` (falls benötigt)
   - `deleteAffiliation()` (falls benötigt)

2. **PersonRelationshipService** (`src/TOM/Service/Person/PersonRelationshipService.php`)
   - `createRelationship()`
   - `getRelationship()`
   - `getPersonRelationships()`
   - `updateRelationship()` (falls benötigt)
   - `deleteRelationship()`

3. **OrgUnitService** (`src/TOM/Service/Org/OrgUnitService.php`)
   - `getOrgUnits()`
   - `createOrgUnit()`
   - `getOrgUnit()`
   - `updateOrgUnit()` (falls benötigt)
   - `deleteOrgUnit()` (falls benötigt)
   
   **Hinweis:** OrgUnit gehört eigentlich zu Organisationen, könnte aber auch in PersonService bleiben oder in OrgService verschoben werden.

### Schritt 3: PersonService anpassen

**Nach Refactoring:**
- PersonService: ~250 Zeilen (vorher: 539)
- Klare Trennung der Verantwortlichkeiten
- Konsistente Architektur mit OrgService

## Vergleich Frontend vs. Backend

| Frontend | Backend |
|----------|---------|
| ✅ Modularisiert | ❌ Noch nicht modularisiert |
| `person-affiliation.js` | ❌ Alles in `PersonService.php` |
| `person-relationship.js` | ❌ Alles in `PersonService.php` |
| Klare Trennung | God Object |

## Empfehlung

**Sofort umsetzen:**
1. PersonService auf BaseEntityService umstellen
2. PersonAffiliationService extrahieren (Frontend bereits modular)
3. PersonRelationshipService extrahieren (Frontend bereits modular)

**Ergebnis:**
- PersonService.php: ~250 Zeilen (Kern-CRUD)
- Konsistente Architektur mit OrgService
- Parallele Entwicklung möglich
- Einfacher zu testen und warten


