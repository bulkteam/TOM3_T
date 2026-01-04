# OrgService Refactoring Plan

## Aktuelle Situation
- **OrgService.php**: 1535 Zeilen (58 KB) - zu groß!
- **Unterordner-Services**: Gut strukturiert (OrgAddressService, OrgVatService, OrgRelationService)

## Analyse der Verantwortlichkeiten

### 1. Core CRUD Operations (~200 Zeilen)
- `createOrg()` - Erstellt Organisation mit Duplikat-Prüfung
- `getOrg()` - Holt einzelne Organisation
- `updateOrg()` - Aktualisiert Organisation

### 2. Account Health & Monitoring (~150 Zeilen)
- `getAccountHealth()` - Berechnet Account-Gesundheit
- `getLastContactDate()` - Letzter Kontakt
- `getStaleOffers()` - Stagnierende Angebote
- `getWaitingProjects()` - Wartende Projekte
- `getOpenEscalations()` - Offene Eskalationen

### 3. Account Owner Management (~80 Zeilen)
- `getAccountsByOwner()` - Organisationen eines Owners
- `getAvailableAccountOwners()` - Verfügbare Owners
- `getAvailableAccountOwnersWithNames()` - Owners mit Namen

### 4. Alias Management (~30 Zeilen)
- `addAlias()` - Fügt Alias hinzu
- `getAliases()` - Holt Aliases

### 5. Access Tracking (~25 Zeilen)
- `trackAccess()` - Delegiert an AccessTrackingService
- `getRecentOrgs()` - Delegiert an AccessTrackingService
- `getFavoriteOrgs()` - Holt Favoriten

### 6. Search & Find (~250 Zeilen)
- `searchOrgs()` - Volltextsuche mit Filtern
- `findSimilarOrgs()` - Ähnliche Organisationen
- `listOrgs()` - Delegiert an searchOrgs()

### 7. Communication Channels (~300 Zeilen)
- `addCommunicationChannel()` - Erstellt Kommunikationskanal
- `getCommunicationChannel()` - Holt Kanal
- `getCommunicationChannels()` - Holt alle Kanäle
- `updateCommunicationChannel()` - Aktualisiert Kanal
- `deleteCommunicationChannel()` - Löscht Kanal
- `formatPhoneNumber()` - Formatiert Telefonnummer

### 8. Audit Trail & Field Resolution (~200 Zeilen)
- `resolveFieldValue()` - Resolviert Feldwerte (UUID → Name)
- `formatChannelFieldValue()` - Formatiert Kanal-Feldwerte
- `insertAuditEntry()` - Fügt Audit-Eintrag ein
- `getAuditTrail()` - Delegiert an AuditTrailService

### 9. Archive Management (~100 Zeilen)
- `archiveOrg()` - Archiviert Organisation
- `unarchiveOrg()` - Reaktiviert Organisation

### 10. Customer Number Generation (~50 Zeilen)
- `getNextCustomerNumber()` - Nächste Kundennummer
- `generateCustomerNumber()` - Generiert Kundennummer

### 11. Enriched Data (~50 Zeilen)
- `getOrgWithDetails()` - Holt Organisation mit Details
- `getIndustryByUuid()` - Holt Branche

### 12. Delegation Methods (~100 Zeilen)
- Address Management (delegiert an OrgAddressService)
- VAT Registration (delegiert an OrgVatService)
- Relation Management (delegiert an OrgRelationService)

## Vorschlag: Modulare Struktur

```
src/TOM/Service/Org/
├── OrgService.php                    # Haupt-Service (Facade, ~200 Zeilen)
│   └── Delegiert an alle Sub-Services
│
├── Core/
│   ├── OrgCrudService.php           # CRUD-Operationen (~200 Zeilen)
│   └── OrgEnrichmentService.php     # Enriched Data (~50 Zeilen)
│
├── Account/
│   ├── OrgAccountHealthService.php  # Account Health (~150 Zeilen)
│   └── OrgAccountOwnerService.php   # Account Owner Management (~80 Zeilen)
│
├── Search/
│   └── OrgSearchService.php         # Search & Find (~250 Zeilen)
│
├── Communication/
│   └── OrgCommunicationService.php  # Communication Channels (~300 Zeilen)
│
├── Management/
│   ├── OrgAliasService.php          # Alias Management (~30 Zeilen)
│   ├── OrgArchiveService.php        # Archive Management (~100 Zeilen)
│   └── OrgCustomerNumberService.php # Customer Number Generation (~50 Zeilen)
│
├── Audit/
│   └── OrgAuditHelperService.php    # Audit Trail Helpers (~200 Zeilen)
│
└── [Bereits vorhanden]
    ├── OrgAddressService.php
    ├── OrgVatService.php
    └── OrgRelationService.php
```

## Refactoring-Strategie

### Phase 1: Neue Services erstellen
1. **OrgCrudService** - CRUD-Operationen extrahieren
2. **OrgCommunicationService** - Communication Channels extrahieren
3. **OrgSearchService** - Search-Funktionalität extrahieren

### Phase 2: Weitere Services
4. **OrgAccountHealthService** - Account Health extrahieren
5. **OrgAccountOwnerService** - Account Owner Management extrahieren
6. **OrgArchiveService** - Archive Management extrahieren

### Phase 3: Helper Services
7. **OrgAuditHelperService** - Audit Trail Helpers extrahieren
8. **OrgAliasService** - Alias Management extrahieren
9. **OrgCustomerNumberService** - Customer Number Generation extrahieren
10. **OrgEnrichmentService** - Enriched Data extrahieren

### Phase 4: OrgService als Facade
- OrgService wird zu einer Facade, die alle Sub-Services verwendet
- Alle öffentlichen Methoden bleiben erhalten (Rückwärtskompatibilität)
- Interne Implementierung delegiert an Sub-Services

## Vorteile

1. **Kleinere Dateien**: Jede Datei < 350 Zeilen
2. **Klare Verantwortlichkeiten**: Jeder Service hat einen Fokus
3. **Bessere Wartbarkeit**: Änderungen isoliert
4. **Testbarkeit**: Services können einzeln getestet werden
5. **Rückwärtskompatibilität**: OrgService bleibt als Facade erhalten

## Migration

- Schrittweise Migration möglich
- OrgService delegiert schrittweise an neue Services
- Keine Breaking Changes für bestehenden Code
