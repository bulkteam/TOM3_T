# Code-Redundanz Analyse - TOM3

## ✅ Bereits zentralisiert

### 1. UUID-Erstellung
**Status**: ✅ **Zentralisiert**

- **Helper**: `TOM\Infrastructure\Utils\UuidHelper`
- **Verwendung**: Alle Services verwenden `UuidHelper::generate($this->db)`
- **Services**: PersonService, OrgService, CaseService, ProjectService, TaskService, WorkflowService

**Beispiel**:
```php
// PersonService, OrgService, etc.
$uuid = UuidHelper::generate($this->db);
```

### 2. Event-Publishing
**Status**: ✅ **Zentralisiert**

- **Helper**: `TOM\Infrastructure\Events\EventPublisher`
- **Verwendung**: Alle Services verwenden `EventPublisher` für Neo4j-Sync
- **Services**: PersonService, OrgService, CaseService, ProjectService, etc.

**Beispiel**:
```php
$this->eventPublisher->publish('person', $personUuid, 'PersonCreated', $person);
```

### 3. User-ID Abfrage
**Status**: ✅ **Zentralisiert**

- **Helper**: `TOM\Infrastructure\Auth\AuthHelper::getCurrentUserId()`
- **Verwendung**: PersonService verwendet bereits `AuthHelper::getCurrentUserId()`
- **OrgService**: Verwendet wahrscheinlich auch AuthHelper (zu prüfen)

**Beispiel**:
```php
// PersonService
private function getCurrentUserId(): string
{
    return AuthHelper::getCurrentUserId();
}
```

## ❌ Noch nicht zentralisiert (Redundanz vorhanden)

### 1. Audit-Trail Logging
**Status**: ❌ **Dupliziert**

**Problem**: 
- `PersonService` hat: `logAuditTrail()`, `insertAuditEntry()`, `getAuditTrail()`, `resolveFieldValue()`
- `OrgService` hat: `logAuditTrail()`, `insertAuditEntry()`, `getAuditTrail()`, `resolveFieldValue()`
- Code ist fast identisch, nur Tabellennamen unterschiedlich

**Lösung**: 
- ✅ **Zentraler `AuditTrailService` erstellt** (`src/TOM/Infrastructure/Audit/AuditTrailService.php`)
- **Nächster Schritt**: PersonService und OrgService refactoren, um AuditTrailService zu verwenden

**Redundanz**: ~150 Zeilen Code pro Service = ~300 Zeilen gespart

### 2. Field Value Resolution
**Status**: ⚠️ **Teilweise redundant**

**Problem**:
- `PersonService::resolveFieldValue()` - einfache Logik
- `OrgService::resolveFieldValue()` - komplexere Logik (Adressen, VAT, etc.)
- `OrgService::formatAddressFieldValue()` - spezifisch für Adressen
- `OrgService::formatVatFieldValue()` - spezifisch für VAT

**Lösung**:
- AuditTrailService unterstützt bereits Callback-basierte Field-Resolver
- Services können ihre eigenen Resolver übergeben
- Gemeinsame Logik könnte in `FieldValueResolver` Klasse ausgelagert werden

**Redundanz**: ~50 Zeilen Code

### 3. Soft-Delete Pattern
**Status**: ⚠️ **Ähnliche Logik**

**Problem**:
- Beide Services haben ähnliche Soft-Delete-Logik (`is_active`, `archived_at`)
- Pattern ist ähnlich, aber nicht identisch

**Lösung**:
- Könnte als Trait ausgelagert werden: `SoftDeleteTrait`
- Oder als Helper-Methode in einem BaseService

**Redundanz**: ~20 Zeilen Code

## 📊 Zusammenfassung

| Bereich | Status | Redundanz | Priorität |
|---------|--------|-----------|-----------|
| UUID-Erstellung | ✅ Zentralisiert | 0 Zeilen | - |
| Event-Publishing | ✅ Zentralisiert | 0 Zeilen | - |
| User-ID Abfrage | ✅ Zentralisiert | 0 Zeilen | - |
| **Audit-Trail** | ❌ Dupliziert | ~300 Zeilen | **Hoch** |
| Field Resolution | ⚠️ Teilweise | ~50 Zeilen | Mittel |
| Soft-Delete | ⚠️ Ähnlich | ~20 Zeilen | Niedrig |

## 🎯 Empfohlene Refactoring-Reihenfolge

1. **Audit-Trail zentralisieren** (größte Redundanz, bereits implementiert)
   - PersonService refactoren
   - OrgService refactoren
   - Tests durchführen

2. **Field Value Resolver zentralisieren** (optional)
   - Gemeinsame Logik in `FieldValueResolver` Klasse
   - Service-spezifische Resolver als Callbacks

3. **Soft-Delete Pattern** (optional, niedrige Priorität)
   - Als Trait oder Helper-Methode

## 💡 Weitere Verbesserungen

### Frontend-Redundanz

**Person-Module vs. Org-Module**:
- `person-detail.js` vs. `org-detail.js` - ähnliche Struktur
- `person-detail-view.js` vs. `org-detail-view.js` - ähnliche Rendering-Logik
- Tabs, Modal-Handling, etc.

**Mögliche Lösung**:
- Base-Klassen für Detail-Module
- Shared Components für Tabs, Forms, etc.

**Aber**: Frontend-Redundanz ist weniger kritisch als Backend-Redundanz, da:
- Frontend-Code ändert sich häufiger
- Unterschiedliche Anforderungen pro Entität
- Wartbarkeit wichtiger als DRY im Frontend
