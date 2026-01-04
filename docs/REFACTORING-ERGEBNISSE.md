# Refactoring-Ergebnisse: Audit-Trail Zentralisierung

## ✅ Durchgeführt

### 1. Zentraler AuditTrailService erstellt
- **Datei**: `src/TOM/Infrastructure/Audit/AuditTrailService.php`
- **Funktionalität**:
  - `logAuditTrail()` - Protokolliert Änderungen für alle Entitäten
  - `getAuditTrail()` - Holt Audit-Trail für alle Entitäten
  - Unterstützt verschiedene Entity-Typen (org, person, project)
  - Callback-basierte Field-Resolver für flexible Formatierung

### 2. PersonService refactored
- **Entfernt**: ~150 Zeilen duplizierter Code
  - `logAuditTrail()` - entfernt
  - `insertAuditEntry()` - entfernt
  - `getAuditTrail()` - delegiert an AuditTrailService
- **Beibehalten**: `resolveFieldValue()` als public Methode (für Callback)
- **Verwendung**: Nutzt jetzt `AuditTrailService` für alle Audit-Trail-Operationen

### 3. OrgService refactored
- **Entfernt**: ~150 Zeilen duplizierter Code
  - `logAuditTrail()` - entfernt
  - `getAuditTrail()` - delegiert an AuditTrailService
- **Beibehalten**: 
  - `insertAuditEntry()` - bleibt für spezielle Events (address_added, channel_updated, etc.)
  - `resolveFieldValue()` als public Methode (für Callback)
- **Verwendung**: Nutzt jetzt `AuditTrailService` für Standard-Audit-Trail-Operationen

## 📊 Code-Reduktion

| Service | Vorher | Nachher | Reduktion |
|---------|--------|---------|-----------|
| PersonService | ~564 Zeilen | ~414 Zeilen | **-150 Zeilen** |
| OrgService | ~2265 Zeilen | ~2115 Zeilen | **-150 Zeilen** |
| **Gesamt** | | | **-300 Zeilen** |

## 🎯 Vorteile

1. **DRY (Don't Repeat Yourself)**: Audit-Trail-Logik nur einmal implementiert
2. **Konsistenz**: Einheitliche Logik für alle Entitäten
3. **Wartbarkeit**: Änderungen nur an einer Stelle
4. **Erweiterbarkeit**: Neue Entitäten können einfach Audit-Trail nutzen
5. **Testbarkeit**: Zentrale Klasse ist einfacher zu testen

## 🔄 Verwendung

### PersonService
```php
// Create
$this->auditTrailService->logAuditTrail(
    'person',
    $uuid,
    null, // userId wird automatisch geholt
    'create',
    null,
    $person,
    null,
    null,
    [$this, 'resolveFieldValue']
);

// Update
$this->auditTrailService->logAuditTrail(
    'person',
    $personUuid,
    null,
    'update',
    $oldData,
    $newData,
    $allowedFields,
    $changedFields,
    [$this, 'resolveFieldValue']
);

// Get
$auditTrail = $this->auditTrailService->getAuditTrail('person', $personUuid, 100);
```

### OrgService
```php
// Create
$this->auditTrailService->logAuditTrail(
    'org',
    $uuid,
    $userId ?? null,
    'create',
    null,
    $org,
    null,
    null,
    [$this, 'resolveFieldValue']
);

// Update
$this->auditTrailService->logAuditTrail(
    'org',
    $orgUuid,
    $userId ?? null,
    'update',
    $oldOrg,
    $org,
    $allowedFields,
    $changedFields,
    [$this, 'resolveFieldValue']
);

// Get
$auditTrail = $this->auditTrailService->getAuditTrail('org', $orgUuid, 100);
```

## ⚠️ Offene Punkte

1. **Spezielle Events in OrgService**: 
   - `insertAuditEntry()` wird noch für spezielle Events verwendet (address_added, channel_updated, etc.)
   - Diese könnten später auch über AuditTrailService laufen, erfordert aber Erweiterung

2. **Field-Resolver**:
   - Jeder Service hat seinen eigenen `resolveFieldValue()` Callback
   - Gemeinsame Logik könnte in `FieldValueResolver` Klasse ausgelagert werden (optional)

## ✅ Tests

- [ ] PersonService: Create Person → Audit-Trail prüfen
- [ ] PersonService: Update Person → Audit-Trail prüfen
- [ ] PersonService: Get Audit-Trail → Daten prüfen
- [ ] OrgService: Create Org → Audit-Trail prüfen
- [ ] OrgService: Update Org → Audit-Trail prüfen
- [ ] OrgService: Get Audit-Trail → Daten prüfen

## 📝 Nächste Schritte

1. ✅ AuditTrailService erstellt
2. ✅ PersonService refactored
3. ✅ OrgService refactored
4. ⏳ Tests durchführen
5. ✅ Dokumentation aktualisiert

## 🔄 Weitere Zentralisierungen

Nach der Audit-Trail-Zentralisierung wurden weitere Duplikationen identifiziert und zentralisiert:

- ✅ **Access-Tracking** ("Zuletzt angesehen") - `AccessTrackingService`
- ✅ **Keyboard-Navigation** - `SearchKeyboardNavigationModule`
- ✅ **Detail-View-Struktur** - `EntityDetailBaseModule`
- ✅ **Basis-Service-Patterns** - `BaseEntityService`
- ✅ **API-Error-Handling** - `base-api-handler.php`
- ✅ **Search-Input-Handling** - `SearchInputModule`
- ✅ **Query-Parsing** - `SearchQueryHelper`

**Siehe `docs/REFACTORING-ERGEBNISSE-V2.md` für Details aller Zentralisierungen.**


