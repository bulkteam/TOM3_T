# Code-Refactoring: Audit-Trail Zentralisierung

## Problem

Der Audit-Trail-Code ist aktuell in beiden Services (`PersonService` und `OrgService`) dupliziert:

- `logAuditTrail()` - fast identisch
- `insertAuditEntry()` - fast identisch  
- `getAuditTrail()` - fast identisch
- `resolveFieldValue()` - ähnlich, aber unterschiedlich

## Lösung: Zentraler AuditTrailService

### Vorteile

1. **DRY (Don't Repeat Yourself)**: Code wird nur einmal geschrieben
2. **Konsistenz**: Einheitliche Logik für alle Entitäten
3. **Wartbarkeit**: Änderungen nur an einer Stelle
4. **Erweiterbarkeit**: Neue Entitäten können einfach Audit-Trail nutzen

### Implementierung

```php
// src/TOM/Infrastructure/Audit/AuditTrailService.php

class AuditTrailService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::getInstance();
    }
    
    /**
     * Protokolliert Änderungen im Audit-Trail
     * 
     * @param string $entityType 'org' | 'person' | 'project' | ...
     * @param string $entityUuid UUID der Entität
     * @param string $userId User-ID des Bearbeiters
     * @param string $action 'create' | 'update' | 'delete'
     * @param array|null $oldData Alte Daten
     * @param array|null $newData Neue Daten
     * @param array|null $allowedFields Felder, die protokolliert werden sollen
     * @param array|null $changedFields Geänderte Felder (optional)
     * @param callable|null $fieldResolver Callback für Feldwert-Formatierung
     */
    public function logAuditTrail(
        string $entityType,
        string $entityUuid,
        string $userId,
        string $action,
        ?array $oldData,
        ?array $newData,
        ?array $allowedFields = null,
        ?array $changedFields = null,
        ?callable $fieldResolver = null
    ): void {
        // Implementierung...
    }
    
    /**
     * Holt das Audit-Trail für eine Entität
     */
    public function getAuditTrail(
        string $entityType,
        string $entityUuid,
        int $limit = 100
    ): array {
        // Implementierung...
    }
}
```

### Verwendung

```php
// In PersonService:
$auditTrailService = new AuditTrailService($this->db);
$auditTrailService->logAuditTrail(
    'person',
    $personUuid,
    AuthHelper::getCurrentUserId(),
    'update',
    $oldData,
    $newData,
    ['first_name', 'last_name', 'email', ...],
    $changedFields,
    [$this, 'resolveFieldValue']
);

// In OrgService:
$auditTrailService->logAuditTrail(
    'org',
    $orgUuid,
    AuthHelper::getCurrentUserId(),
    'update',
    $oldData,
    $newData,
    ['name', 'org_kind', 'status', ...],
    $changedFields,
    [$this, 'resolveFieldValue']
);
```

## Weitere Refactoring-Möglichkeiten

### 1. Field Value Resolver

Aktuell hat jeder Service seine eigene `resolveFieldValue()` Methode. Diese könnten in eine zentrale `FieldValueResolver` Klasse ausgelagert werden:

```php
class FieldValueResolver
{
    public static function resolve(string $entityType, string $field, $value, PDO $db): string
    {
        // Zentrale Logik für alle Entitäten
    }
}
```

### 2. Soft-Delete Pattern

Beide Services haben ähnliche Soft-Delete-Logik (`is_active`, `archived_at`). Könnte in ein Trait ausgelagert werden:

```php
trait SoftDeleteTrait
{
    protected function applySoftDelete(array $data, array $oldData, array &$updates): void
    {
        // Gemeinsame Soft-Delete-Logik
    }
}
```

### 3. Event-Publishing Pattern

Wird bereits zentral über `EventPublisher` gemacht ✅

### 4. UUID-Generierung

Wird bereits zentral über `UuidHelper` gemacht ✅

## Empfehlung

**Priorität 1**: Audit-Trail zentralisieren (größte Redundanz)
**Priorität 2**: Field Value Resolver zentralisieren
**Priorität 3**: Soft-Delete Pattern als Trait


