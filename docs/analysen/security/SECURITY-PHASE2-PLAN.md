# Security Phase 2 - Implementierungsplan (P1)

## Übersicht

Implementierung der P1-Verbesserungen:
1. Role/Permission Mapping (Hierarchie)
2. Input-Validation vereinheitlichen
3. Transaktionen bei Multi-Step Writes

**Geschätzter Aufwand:** 5-8 Stunden

---

## 1. Role/Permission Mapping (Hierarchie)

### Problem
- `UserPermissionService::userHasPermission()` ist zu "literal"
- `return $userRole === $permission` funktioniert nicht für Hierarchien
- Wird brüchig bei mehr Berechtigungen

### Lösung
- Rollen-Hierarchie als Mapping (admin > manager > user > readonly)
- Permissions als Capabilities (org.write, person.write, export.run)
- Endpoints prüfen Capabilities, nicht "Role-String"

### Implementierung

**Schritt 1: Capability-System**
```php
// Capabilities definieren
const CAPABILITIES = [
    'org.read' => ['admin', 'manager', 'user', 'readonly'],
    'org.write' => ['admin', 'manager', 'user'],
    'org.delete' => ['admin', 'manager'],
    'person.read' => ['admin', 'manager', 'user', 'readonly'],
    'person.write' => ['admin', 'manager', 'user'],
    'export.run' => ['admin', 'manager'],
    // ...
];
```

**Schritt 2: UserPermissionService erweitern**
- `userHasCapability($userId, $capability)` - Prüft Capability
- `getUserCapabilities($userId)` - Gibt alle Capabilities zurück
- Hierarchie-basierte Prüfung

**Schritt 3: API-Endpoints anpassen**
- `requireCapability($capability)` statt `requireRole($role)`
- Konsistente Capability-Prüfung

### Betroffene Dateien
- `src/TOM/Service/User/UserPermissionService.php`
- `public/api/api-security.php` (neue `requireCapability()` Funktion)
- Alle API-Endpoints mit Permission-Checks

---

## 2. Input-Validation vereinheitlichen

### Problem
- Validation-Pattern nicht überall integriert
- Teils "echo + http_response_code" statt zentraler Fehlerbehandlung
- Inkonsistente Fehlermeldungen

### Lösung
- Validatoren werfen Exceptions (`ValidationException`)
- Zentraler Handler in `base-api-handler.php`
- Konsistente JSON-Errors

### Implementierung

**Schritt 1: ValidationException erstellen**
```php
class ValidationException extends \InvalidArgumentException {
    private array $errors;
    
    public function __construct(string $message, array $errors = []) {
        parent::__construct($message);
        $this->errors = $errors;
    }
    
    public function getErrors(): array {
        return $this->errors;
    }
}
```

**Schritt 2: Validator-Service erstellen**
- `InputValidator` Klasse
- Methoden: `validateRequired()`, `validateLength()`, `validateEmail()`, etc.
- Werfen `ValidationException` bei Fehlern

**Schritt 3: Exception-Handler erweitern**
- `handleApiException()` erkennt `ValidationException`
- Gibt konsistente JSON-Errors zurück (400 Bad Request)

**Schritt 4: API-Endpoints anpassen**
- Verwenden `InputValidator` statt direkte Validierung
- Konsistente Fehlerbehandlung

### Betroffene Dateien
- `src/TOM/Infrastructure/Validation/ValidationException.php` (neu)
- `src/TOM/Infrastructure/Validation/InputValidator.php` (neu)
- `public/api/base-api-handler.php` (Exception-Handler erweitern)
- Alle API-Endpoints mit Input-Validation

---

## 3. Transaktionen bei Multi-Step Writes

### Problem
- Entities wie Org ändern mehrere Tabellen
- Keine Transaktionen → Inkonsistenz möglich
- Audit/Activity-Logs werden auch bei Fehlern geschrieben

### Lösung
- Service-Layer Methoden mit Transaktionen
- Audit/Activity nach Commit
- Rollback bei Fehlern

### Implementierung

**Schritt 1: Transaction-Helper erstellen**
```php
class TransactionHelper {
    public static function executeInTransaction(PDO $db, callable $callback) {
        $db->beginTransaction();
        try {
            $result = $callback();
            $db->commit();
            return $result;
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
```

**Schritt 2: OrgService anpassen**
- `createOrg()` - Transaction um alle Schritte
- `updateOrg()` - Transaction um alle Schritte
- Audit-Logs nach Commit

**Schritt 3: Weitere Services anpassen**
- `PersonService::createPerson()`
- `ImportCommitService::commitBatch()` (bereits teilweise vorhanden)
- Andere Multi-Step Operations

### Betroffene Dateien
- `src/TOM/Infrastructure/Database/TransactionHelper.php` (neu)
- `src/TOM/Service/OrgService.php` (bzw. OrgCrudService)
- `src/TOM/Service/PersonService.php`
- `src/TOM/Service/Import/ImportCommitService.php` (prüfen/erweitern)

---

## Implementierungsreihenfolge

### Phase 2.1: Role/Permission Mapping (2-3 Stunden)
1. Capability-System definieren
2. UserPermissionService erweitern
3. `requireCapability()` Funktion erstellen
4. API-Endpoints anpassen (optional, kann schrittweise erfolgen)

### Phase 2.2: Input-Validation (2-3 Stunden)
1. ValidationException erstellen
2. InputValidator erstellen
3. Exception-Handler erweitern
4. API-Endpoints anpassen (schrittweise)

### Phase 2.3: Transaktionen (1-2 Stunden)
1. TransactionHelper erstellen
2. OrgService anpassen
3. PersonService anpassen
4. Weitere Services prüfen

---

## Nächste Schritte

1. **Phase 2.1 starten** - Role/Permission Mapping
2. **Phase 2.2** - Input-Validation
3. **Phase 2.3** - Transaktionen

**Geschätzter Gesamtaufwand:** 5-8 Stunden

