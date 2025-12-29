# TOM3 - Modul-Übersicht

Übersicht aller Module mit Dateigrößen und Zeilenzahlen.

## JavaScript Module (Frontend)

| Modul | Zeilen | Größe | Beschreibung |
|-------|--------|-------|-------------|
| `admin.js` | 389 | 18,08 KB | Admin-Bereich (Benutzer- und Rollenverwaltung) |
| `audit-trail.js` | 228 | 8,67 KB | Audit-Trail-Anzeige für Organisationen |
| `auth.js` | 64 | 2,26 KB | Authentifizierung |
| `org-detail.js` | 1,322 | 66,6 KB | Organisationsdetail-Ansicht (größtes Modul) |
| `org-forms.js` | 181 | 6,66 KB | Formulare für Organisationen |
| `org-search.js` | 253 | 11,6 KB | Organisationssuche |
| `utils.js` | 135 | 3,98 KB | Utility-Funktionen |

**Gesamt JavaScript:** 2,572 Zeilen | 117,84 KB

### Hinweise
- `org-detail.js` ist mit 1,322 Zeilen das größte Frontend-Modul und könnte weiter aufgeteilt werden
- Alle anderen Module sind unter 400 Zeilen (gut handhabbar)

---

## PHP Service Module (Backend)

| Modul | Zeilen | Größe | Beschreibung |
|-------|--------|-------|-------------|
| `CaseService.php` | 148 | 5,15 KB | Case-Management |
| `OrgService.php` | 1,805 | 67,25 KB | Organisations-Management (größtes Modul) |
| `PersonService.php` | 83 | 2,59 KB | Personen-Management |
| `ProjectService.php` | 69 | 2,4 KB | Projekt-Management |
| `TaskService.php` | 62 | 2,02 KB | Task-Management |
| `UserService.php` | 571 | 18,24 KB | Benutzer-Management |
| `WorkflowService.php` | 96 | 3,37 KB | Workflow-Management |

**Gesamt PHP Services:** 2,834 Zeilen | 101,02 KB

### Hinweise
- `OrgService.php` ist mit 1,805 Zeilen das größte Backend-Modul
- Enthält umfangreiche Logik für Organisationen, Adressen, Beziehungen, Audit-Trail, etc.
- Könnte in mehrere spezialisierte Services aufgeteilt werden (z.B. `OrgAddressService`, `OrgRelationService`)

---

## PHP Infrastructure Module

| Modul | Zeilen | Größe | Beschreibung |
|-------|--------|-------|-------------|
| `Auth/AuthHelper.php` | 64 | 1,75 KB | Authentifizierungs-Helper |
| `Auth/AuthService.php` | 269 | 7,72 KB | Authentifizierungs-Service |
| `Database/DatabaseConnection.php` | 45 | 1,54 KB | Datenbankverbindung |
| `Events/EventPublisher.php` | 34 | 1,08 KB | Event-System |
| `Neo4j/Neo4jService.php` | 102 | 2,9 KB | Neo4j-Integration |
| `Sync/Neo4jSyncService.php` | 392 | 12,09 KB | Neo4j-Synchronisation |
| `Utils/UrlHelper.php` | 95 | 2,79 KB | URL-Helper |
| `Utils/UuidHelper.php` | 77 | 2,17 KB | UUID-Helper |

**Gesamt PHP Infrastructure:** 1,078 Zeilen | 32,04 KB

---

## PHP API Endpoints

| Endpoint | Zeilen | Größe | Beschreibung |
|----------|--------|-------|-------------|
| `accounts.php` | 45 | 1,41 KB | Account-Endpunkte |
| `address-types.php` | 23 | 0,69 KB | Adresstypen |
| `auth.php` | 163 | 6,05 KB | Authentifizierung |
| `cases.php` | 128 | 4,93 KB | Cases |
| `index.php` | 106 | 3,38 KB | Router |
| `industries.php` | 48 | 1,68 KB | Branchen |
| `monitoring.php` | 318 | 8,8 KB | Monitoring |
| `orgs-recent.php` | 40 | 1,12 KB | Zuletzt verwendete Organisationen |
| `orgs-search.php` | 47 | 1,78 KB | Organisationssuche |
| `orgs-track.php` | 40 | 1,2 KB | Organisations-Tracking |
| `orgs.php` | 553 | 23,33 KB | Organisationen (größter Endpoint) |
| `persons.php` | 50 | 1,43 KB | Personen |
| `plz-lookup.php` | 32 | 0,88 KB | PLZ-Suche |
| `projects.php` | 91 | 3,22 KB | Projekte |
| `tasks.php` | 51 | 1,5 KB | Tasks |
| `users.php` | 144 | 5,92 KB | Benutzer |
| `workflow.php` | 66 | 2,17 KB | Workflows |

**Gesamt PHP API:** 1,945 Zeilen | 69,48 KB

### Hinweise
- `orgs.php` ist mit 553 Zeilen der größte API-Endpoint
- Enthält alle Organisations-Endpunkte (CRUD, Adressen, Beziehungen, Channels, etc.)
- Könnte in mehrere Endpoints aufgeteilt werden (z.B. `orgs-addresses.php`, `orgs-relations.php`)

---

## Gesamtübersicht

| Kategorie | Zeilen | Größe |
|-----------|--------|-------|
| **JavaScript Module** | 2,572 | 117,84 KB |
| **PHP Services** | 2,834 | 101,02 KB |
| **PHP Infrastructure** | 1,078 | 32,04 KB |
| **PHP API** | 1,945 | 69,48 KB |
| **GESAMT** | **8,429** | **320,38 KB** |

---

## Empfehlungen für Refactoring

### Priorität 1: Große Module aufteilen

1. **`org-detail.js`** (1,322 Zeilen)
   - Aufteilen in: `org-detail-view.js`, `org-detail-edit.js`, `org-detail-addresses.js`, `org-detail-relations.js`
   - Ziel: Module unter 400 Zeilen

2. **`OrgService.php`** (1,805 Zeilen)
   - Aufteilen in: `OrgService.php` (Kern), `OrgAddressService.php`, `OrgRelationService.php`, `OrgChannelService.php`
   - Ziel: Services unter 500 Zeilen

3. **`orgs.php`** (553 Zeilen)
   - Aufteilen in: `orgs.php` (Kern), `orgs-addresses.php`, `orgs-relations.php`, `orgs-channels.php`
   - Ziel: Endpoints unter 200 Zeilen

### Priorität 2: Weitere Optimierungen

- `UserService.php` (571 Zeilen) könnte in `UserService.php` und `UserRoleService.php` aufgeteilt werden
- `monitoring.php` (318 Zeilen) könnte in mehrere spezialisierte Endpoints aufgeteilt werden

---

*Erstellt am: $(Get-Date -Format "yyyy-MM-dd")*
*Letzte Aktualisierung: Automatisch generiert*

