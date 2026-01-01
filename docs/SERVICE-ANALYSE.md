# Service-Analyse - Welche Services sollten modularisiert werden?

## Aktuelle Situation

### Bereits modularisiert:
- ✅ **OrgService**: 1.428 Zeilen (vorher: 2.186) - **-34,7%**
  - OrgAddressService
  - OrgVatService
  - OrgRelationService

- ✅ **PersonService**: 391 Zeilen (vorher: 539) - **-27,5%**
  - PersonAffiliationService
  - PersonRelationshipService

### Noch nicht modularisiert:

| Service | Zeilen | Status | Empfehlung |
|---------|--------|--------|------------|
| **UserService** | 571 | ⚠️ Groß | **Sollte modularisiert werden** |
| CaseService | 148 | ✅ OK | Keine Modularisierung nötig |
| ProjectService | 69 | ✅ OK | Keine Modularisierung nötig |
| TaskService | 62 | ✅ OK | Keine Modularisierung nötig |
| WorkflowService | 96 | ✅ OK | Keine Modularisierung nötig |

## UserService - Detaillierte Analyse

**Zeilen:** 571  
**Problem:** Potenzielles "God Object" mit vielen Verantwortlichkeiten

### Inhalt der UserService.php

#### 1. **Kern-User CRUD** (~200 Zeilen)
- `getAllUsers()` - Alle User abrufen
- `getUser()` - Einzelner User
- `createUser()` - User erstellen
- `updateUser()` - User aktualisieren
- `activateUser()` - User aktivieren
- `deactivateUser()` - User deaktivieren

#### 2. **Role Management** (~150 Zeilen)
- `getUserPermissionRole()` - Permission-Rolle eines Users
- `getAvailablePermissionRoles()` - Verfügbare Permission-Rollen
- `userHasPermission()` - Prüft Berechtigung
- `canUserBeAccountOwner()` - Prüft ob User Account Owner sein kann

#### 3. **Workflow Role Management** (~100 Zeilen)
- `getUserWorkflowRoles()` - Workflow-Rollen eines Users
- `userHasWorkflowRole()` - Prüft Workflow-Rolle
- `getAvailableWorkflowRoles()` - Verfügbare Workflow-Rollen
- `getUsersByWorkflowRole()` - User nach Workflow-Rolle

#### 4. **Account Team Role Management** (~50 Zeilen)
- `getAvailableAccountTeamRoles()` - Verfügbare Account Team Rollen

#### 5. **Helper & Utilities** (~70 Zeilen)
- Verschiedene Helper-Methoden

## Empfehlung: UserService modularisieren

### Option 1: Nach Verantwortlichkeiten trennen (Empfohlen)

```
src/TOM/Service/User/
├── UserRoleService.php          (~150 Zeilen) - Permission & Workflow Roles
├── UserPermissionService.php    (~100 Zeilen) - Permission-Prüfungen
└── UserService.php              (~320 Zeilen) - Kern-CRUD
```

**Vorteile:**
- Klare Trennung: User-Verwaltung vs. Rollen-Verwaltung
- Einfacher zu testen
- Konsistente Architektur mit OrgService/PersonService

**Nachteile:**
- Mehr Dateien
- Dependency Management

### Option 2: Nur Role Management extrahieren

```
src/TOM/Service/User/
└── UserRoleService.php          (~200 Zeilen) - Alle Rollen-Funktionen

UserService.php                  (~370 Zeilen) - Kern-CRUD + Helper
```

**Vorteile:**
- Weniger Änderungen
- Schneller umzusetzen

**Nachteile:**
- UserRoleService könnte noch groß werden

## Vergleich mit anderen Services

### CaseService (148 Zeilen)
- **Status:** ✅ OK
- **Grund:** Relativ klein, klare Verantwortlichkeit
- **Sub-Entities:** Notes, Blockers, Requirements (aber alle Case-spezifisch)
- **Empfehlung:** Keine Modularisierung nötig

### ProjectService (69 Zeilen)
- **Status:** ✅ OK
- **Grund:** Sehr klein, einfache CRUD-Operationen
- **Empfehlung:** Keine Modularisierung nötig

### TaskService (62 Zeilen)
- **Status:** ✅ OK
- **Grund:** Sehr klein, einfache CRUD-Operationen
- **Empfehlung:** Keine Modularisierung nötig

### WorkflowService (96 Zeilen)
- **Status:** ✅ OK
- **Grund:** Klein, klare Verantwortlichkeit (Workflow-Operationen)
- **Empfehlung:** Keine Modularisierung nötig

## Zusammenfassung

### Sollte modularisiert werden:
1. **UserService** (571 Zeilen)
   - Rollen-Management extrahieren
   - Permission-Management extrahieren
   - Ergebnis: ~320 Zeilen (Kern-CRUD)

### Muss nicht modularisiert werden:
- CaseService (148 Zeilen) - OK
- ProjectService (69 Zeilen) - OK
- TaskService (62 Zeilen) - OK
- WorkflowService (96 Zeilen) - OK

## Empfehlung

**Sofort umsetzen:**
- UserService modularisieren (analog zu OrgService/PersonService)

**Ergebnis:**
- Konsistente Architektur über alle großen Services
- UserService: ~320 Zeilen (Kern-CRUD)
- UserRoleService: ~200 Zeilen (Rollen-Management)
- UserPermissionService: ~50 Zeilen (Permission-Prüfungen)
