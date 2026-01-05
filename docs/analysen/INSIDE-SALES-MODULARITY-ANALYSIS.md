# Inside Sales Workflow - Modularitäts-Analyse

**Stand:** 2026-01-05  
**Status:** ❌ Entspricht NICHT dem modularen Ansatz

---

## Vergleich: ORG vs. PERS vs. Inside Sales

### ✅ ORG-Modul (modular)

**Backend-Struktur:**
```
src/TOM/Service/
├── OrgService.php                    # Facade (~470 Zeilen)
└── Org/
    ├── Core/
    │   ├── OrgCrudService.php
    │   ├── OrgEnrichmentService.php
    │   ├── OrgAliasService.php
    │   └── OrgCustomerNumberService.php
    ├── Account/
    │   ├── OrgAccountHealthService.php
    │   └── OrgAccountOwnerService.php
    ├── Search/
    │   └── OrgSearchService.php
    ├── Communication/
    │   └── OrgCommunicationService.php
    ├── Management/
    │   └── OrgArchiveService.php
    ├── Audit/
    │   └── OrgAuditHelperService.php
    ├── OrgAddressService.php
    ├── OrgVatService.php
    └── OrgRelationService.php
```

**API-Struktur:**
```
public/api/
└── orgs.php                          # Ein Endpoint (Router)
```

**Merkmale:**
- ✅ Haupt-Service als Facade
- ✅ Sub-Services in `Org/` Verzeichnis
- ✅ Klare Trennung nach Domänen (Core, Account, Search, etc.)
- ✅ Ein API-Endpoint als Router

---

### ✅ PERS-Modul (modular)

**Backend-Struktur:**
```
src/TOM/Service/
├── PersonService.php                 # Facade (~413 Zeilen)
└── Person/
    ├── PersonAffiliationService.php
    └── PersonRelationshipService.php
```

**API-Struktur:**
```
public/api/
└── persons.php                        # Ein Endpoint (Router)
```

**Merkmale:**
- ✅ Haupt-Service als Facade
- ✅ Sub-Services in `Person/` Verzeichnis
- ✅ Ein API-Endpoint als Router

---

### ❌ Inside Sales Workflow (NICHT modular)

**Backend-Struktur:**
```
src/TOM/Service/
├── WorkItemService.php               # ~682 Zeilen (direkt, nicht in Unterordner)
├── WorkItemTimelineService.php       # ~200 Zeilen (direkt, nicht in Unterordner)
└── TelephonyService.php              # ~250 Zeilen (direkt, nicht in Unterordner)
```

**API-Struktur:**
```
public/api/
├── work-items.php                    # Separater Endpoint
├── queues.php                        # Separater Endpoint
└── telephony.php                     # Separater Endpoint
```

**Probleme:**
- ❌ Services nicht in `WorkItem/` oder `InsideSales/` Verzeichnis
- ❌ Kein Haupt-Service als Facade
- ❌ 3 separate API-Endpoints statt einem Router
- ❌ Keine klare Domänen-Trennung

---

## Empfohlene Refactoring-Struktur

### Zielstruktur (analog zu ORG/PERS)

**Backend:**
```
src/TOM/Service/
├── WorkItemService.php               # Facade (~150 Zeilen)
└── WorkItem/
    ├── Core/
    │   └── WorkItemCrudService.php   # CRUD-Operationen (~200 Zeilen)
    ├── Queue/
    │   └── WorkItemQueueService.php  # Queue-Logik (~150 Zeilen)
    ├── Timeline/
    │   └── WorkItemTimelineService.php # Timeline-Management (~200 Zeilen)
    ├── Handoff/
    │   └── WorkItemHandoffService.php # Handoff-Logik (~150 Zeilen)
    └── Telephony/
        └── WorkItemTelephonyService.php # Telephony-Integration (~250 Zeilen)
```

**API:**
```
public/api/
└── work-items.php                    # Ein Router (wie orgs.php)
    ├── Routes zu Core-Service
    ├── Routes zu Queue-Service
    ├── Routes zu Timeline-Service
    ├── Routes zu Handoff-Service
    └── Routes zu Telephony-Service
```

---

## Detaillierte Aufteilung

### 1. WorkItemService (Facade)

**Verantwortlichkeiten:**
- Koordination zwischen Sub-Services
- Delegation an spezialisierte Services
- Zentrale Konfiguration

**Methoden:**
```php
class WorkItemService extends BaseEntityService
{
    private WorkItemCrudService $crudService;
    private WorkItemQueueService $queueService;
    private WorkItemTimelineService $timelineService;
    private WorkItemHandoffService $handoffService;
    private WorkItemTelephonyService $telephonyService;
    
    // Delegation-Methoden
    public function getWorkItem(string $uuid): ?array {
        return $this->crudService->getWorkItem($uuid);
    }
    
    public function listWorkItems(...): array {
        return $this->queueService->listWorkItems(...);
    }
    
    // etc.
}
```

### 2. WorkItemCrudService

**Verantwortlichkeiten:**
- CRUD-Operationen für WorkItems
- WorkItem-Validierung
- Stage-Management

**Methoden:**
- `getWorkItem(string $uuid): ?array`
- `updateWorkItem(string $uuid, array $data, string $userId): array`
- `createWorkItemFromImport(...): string`
- `claimWorkItem(string $uuid, string $userId): array`

### 3. WorkItemQueueService

**Verantwortlichkeiten:**
- Queue-Filterung und Sortierung
- Next-Lead-Logik
- Queue-Statistiken

**Methoden:**
- `listWorkItems(string $type, ?string $tab, ?string $userId): array`
- `getNextLead(string $userId): ?array`
- `getQueueStats(string $type, ?string $userId): array`

### 4. WorkItemTimelineService

**Verantwortlichkeiten:**
- Timeline-Einträge erstellen/abrufen
- Activity-Typen verwalten
- Pinned-Activities

**Methoden:**
- `addUserNote(...): int`
- `addCallActivity(...): int`
- `addSystemMessage(...): int`
- `addHandoffActivity(...): int`
- `getTimeline(string $uuid, ?int $limit): array`

### 5. WorkItemHandoffService

**Verantwortlichkeiten:**
- Handoff-Logik (QUOTE_REQUEST, DATA_CHECK)
- Conversion LEAD → VORGANG
- Return-Logik (Sales Ops → Inside Sales)

**Methoden:**
- `handoff(...): array`
- `convertToVorgang(...): array`
- `returnToInsideSales(...): array`

### 6. WorkItemTelephonyService

**Verantwortlichkeiten:**
- sipgate-Integration
- Call-Management
- Call-Status-Polling

**Methoden:**
- `startCall(...): array`
- `getCallStatus(string $callRef): ?array`
- `finalizeCallActivity(...): void`
- `pollSipgateStatus(...): ?array`

---

## API-Router-Struktur

### Aktuell (3 separate Endpoints)

```php
// public/api/work-items.php
// public/api/queues.php
// public/api/telephony.php
```

### Ziel (ein Router)

```php
// public/api/work-items.php
switch ($resource) {
    case 'work-items':
        // CRUD-Operationen
        require __DIR__ . '/work-items/work-items-core.php';
        break;
    case 'queues':
        // Queue-Operationen
        require __DIR__ . '/work-items/work-items-queue.php';
        break;
    case 'timeline':
        // Timeline-Operationen
        require __DIR__ . '/work-items/work-items-timeline.php';
        break;
    case 'handoff':
        // Handoff-Operationen
        require __DIR__ . '/work-items/work-items-handoff.php';
        break;
    case 'telephony':
        // Telephony-Operationen
        require __DIR__ . '/work-items/work-items-telephony.php';
        break;
}
```

**Oder noch besser (wie orgs.php):**

```php
// public/api/work-items.php
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
    case 'get':
    case 'update':
    case 'claim':
        require __DIR__ . '/work-items-core.php';
        break;
    case 'next':
    case 'queue-stats':
        require __DIR__ . '/work-items-queue.php';
        break;
    case 'timeline':
    case 'add-activity':
        require __DIR__ . '/work-items-timeline.php';
        break;
    case 'handoff':
    case 'convert':
    case 'return':
        require __DIR__ . '/work-items-handoff.php';
        break;
    case 'call':
    case 'call-status':
    case 'finalize-call':
        require __DIR__ . '/work-items-telephony.php';
        break;
}
```

---

## Vorteile der Modularisierung

### 1. Konsistenz
- ✅ Gleiche Struktur wie ORG und PERS
- ✅ Einheitliche Code-Organisation
- ✅ Vorhersagbare Dateistruktur

### 2. Wartbarkeit
- ✅ Jeder Service < 300 Zeilen
- ✅ Klare Verantwortlichkeiten
- ✅ Einfacher zu testen

### 3. Erweiterbarkeit
- ✅ Neue Features in passende Services
- ✅ Parallele Entwicklung möglich
- ✅ Weniger Merge-Konflikte

### 4. API-Konsistenz
- ✅ Ein Endpoint wie bei ORG/PERS
- ✅ Klare Route-Struktur
- ✅ Einheitliche Fehlerbehandlung

---

## Migrationsplan

### Phase 1: Backend-Refactoring

1. **Verzeichnisstruktur erstellen:**
   ```
   src/TOM/Service/WorkItem/
   ├── Core/
   ├── Queue/
   ├── Timeline/
   ├── Handoff/
   └── Telephony/
   ```

2. **Services extrahieren:**
   - `WorkItemCrudService` aus `WorkItemService`
   - `WorkItemQueueService` aus `WorkItemService`
   - `WorkItemTimelineService` (bereits vorhanden, verschieben)
   - `WorkItemHandoffService` aus `WorkItemService`
   - `WorkItemTelephonyService` aus `TelephonyService`

3. **WorkItemService als Facade:**
   - Delegation an Sub-Services
   - Kompatibilität mit bestehenden API-Calls

### Phase 2: API-Konsolidierung

1. **Router erstellen:**
   - `public/api/work-items.php` als Router
   - Sub-Dateien für verschiedene Actions

2. **Alte Endpoints migrieren:**
   - `queues.php` → `work-items.php?action=queue`
   - `telephony.php` → `work-items.php?action=telephony`

3. **Frontend anpassen:**
   - API-Calls auf neue Struktur umstellen
   - Rückwärtskompatibilität während Migration

### Phase 3: Testing & Cleanup

1. **Tests schreiben:**
   - Unit-Tests für jeden Service
   - Integration-Tests für API

2. **Alte Dateien entfernen:**
   - `queues.php` löschen
   - `telephony.php` löschen (oder als Redirect)

---

## Vergleich: Vorher vs. Nachher

### Vorher (aktuell)

```
WorkItemService.php         682 Zeilen
WorkItemTimelineService.php 200 Zeilen
TelephonyService.php        250 Zeilen
─────────────────────────────────────
Gesamt:                     1.132 Zeilen

API-Endpoints: 3 separate Dateien
```

### Nachher (refactored)

```
WorkItemService.php         150 Zeilen (Facade)
WorkItemCrudService.php     200 Zeilen
WorkItemQueueService.php    150 Zeilen
WorkItemTimelineService.php 200 Zeilen
WorkItemHandoffService.php  150 Zeilen
WorkItemTelephonyService.php 250 Zeilen
─────────────────────────────────────
Gesamt:                     1.100 Zeilen (ähnlich, aber modular)

API-Endpoints: 1 Router + Sub-Dateien
```

---

## Empfehlung

**Status:** ⚠️ Refactoring empfohlen

**Priorität:** Mittel (funktioniert aktuell, aber nicht konsistent)

**Aufwand:** ~4-6 Stunden

**Vorteile:**
- ✅ Konsistenz mit ORG/PERS
- ✅ Bessere Wartbarkeit
- ✅ Einheitliche API-Struktur
- ✅ Vorbereitung für zukünftige Features

**Risiken:**
- ⚠️ Frontend muss angepasst werden
- ⚠️ API-Calls ändern sich
- ⚠️ Rückwärtskompatibilität während Migration

---

## Nächste Schritte

1. **Entscheidung:** Soll refactored werden?
2. **Planung:** Detaillierter Migrationsplan
3. **Umsetzung:** Phase 1 (Backend) → Phase 2 (API) → Phase 3 (Testing)
4. **Dokumentation:** Update `INSIDE-SALES-WORKFLOW.md`

