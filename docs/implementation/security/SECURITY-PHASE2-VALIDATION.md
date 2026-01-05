# Security Phase 2.2 - Input-Validation vereinheitlichen

## Übersicht

Zentrale Validierung mit `ValidationException` und `InputValidator` für konsistente Fehlerbehandlung.

### Vorher
```php
// Inkonsistent - verschiedene Fehlerbehandlung
if (empty($data['name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Name is required']);
    exit;
}

if (strlen($data['name']) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Name too long']);
    exit;
}
```

### Nachher
```php
// Konsistent - zentrale Validierung
use TOM\Infrastructure\Validation\InputValidator;

InputValidator::validateRequired($data, 'name');
InputValidator::validateLength($data['name'], 1, 255, 'name');
// ValidationException wird automatisch zu 400 Bad Request
```

---

## ValidationException

Wird geworfen wenn Input-Validierung fehlschlägt. Enthält detaillierte Fehlerinformationen.

```php
try {
    InputValidator::validateRequired($data, 'name');
} catch (ValidationException $e) {
    // $e->getMessage() - Hauptfehlermeldung
    // $e->getErrors() - ['field' => 'error message', ...]
    // $e->getError('name') - Fehler für bestimmtes Feld
    // $e->hasError('name') - Prüft ob Feld Fehler hat
}
```

**Automatische Behandlung:**
- `handleApiException()` erkennt `ValidationException`
- Gibt automatisch 400 Bad Request zurück
- JSON-Format: `{'error': 'Validation error', 'message': '...', 'errors': {...}}`

---

## InputValidator Methoden

### validateRequired()
```php
InputValidator::validateRequired($data, 'name');
// Wirft ValidationException wenn Feld fehlt oder leer ist
```

### validateLength()
```php
InputValidator::validateLength($data['name'], 1, 255, 'name');
// Wirft ValidationException wenn Länge nicht im Bereich
```

### validateEmail()
```php
InputValidator::validateEmail($data['email'], 'email');
// Wirft ValidationException wenn E-Mail ungültig ist
```

### validateEnum()
```php
InputValidator::validateEnum($data['status'], ['active', 'inactive'], 'status');
// Wirft ValidationException wenn Wert nicht in erlaubten Werten
```

### validateUuid()
```php
InputValidator::validateUuid($data['org_uuid'], 'org_uuid');
// Wirft ValidationException wenn UUID ungültig ist
```

### validateDate()
```php
InputValidator::validateDate($data['created_at'], 'created_at');
// Wirft ValidationException wenn Datum ungültig ist (YYYY-MM-DD)
```

### validateInteger()
```php
InputValidator::validateInteger($data['age'], 0, 150, 'age');
// Wirft ValidationException wenn Wert kein Integer oder außerhalb Bereich
```

### validateFloat()
```php
InputValidator::validateFloat($data['price'], 0.0, 9999.99, 'price');
// Wirft ValidationException wenn Wert keine Zahl oder außerhalb Bereich
```

### validateBoolean()
```php
$isActive = InputValidator::validateBoolean($data['is_active'], 'is_active');
// Wirft ValidationException wenn Wert kein Boolean ist
// Gibt validierten Boolean zurück
```

### validateArray()
```php
InputValidator::validateArray($data['tags'], 'tags', 1, 10);
// Wirft ValidationException wenn Wert kein Array oder Größe nicht passt
```

### validate() - Mehrere Felder auf einmal
```php
InputValidator::validate($data, [
    'name' => ['required', 'length:1:255'],
    'email' => ['required', 'email', 'length:3:255'],
    'status' => ['enum:active:inactive'],
    'age' => ['integer:0:150']
]);
// Wirft ValidationException wenn Validierung fehlschlägt
```

---

## Verwendung in API-Endpoints

### Beispiel: POST /api/orgs

**Vorher:**
```php
$data = json_decode(file_get_contents('php://input'), true);
if (empty($data['name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Name is required']);
    exit;
}
if (strlen($data['name']) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Name too long']);
    exit;
}
```

**Nachher:**
```php
use TOM\Infrastructure\Validation\InputValidator;

$data = getJsonBody();

try {
    InputValidator::validateRequired($data, 'name');
    InputValidator::validateLength($data['name'], 1, 255, 'name');
    
    if (isset($data['org_kind'])) {
        InputValidator::validateEnum($data['org_kind'], 
            ['customer', 'supplier', 'consultant', 'internal', 'other'], 
            'org_kind');
    }
    
    $result = $orgService->createOrg($data, $currentUserId);
    jsonResponse($result, 201);
} catch (\Exception $e) {
    handleApiException($e, 'Create org');
}
```

**Oder mit validate():**
```php
use TOM\Infrastructure\Validation\InputValidator;

$data = getJsonBody();

try {
    InputValidator::validate($data, [
        'name' => ['required', 'length:1:255'],
        'org_kind' => ['enum:customer:supplier:consultant:internal:other'],
        'status' => ['enum:lead:prospect:customer:inactive']
    ]);
    
    $result = $orgService->createOrg($data, $currentUserId);
    jsonResponse($result, 201);
} catch (\Exception $e) {
    handleApiException($e, 'Create org');
}
```

---

## Fehlerantwort-Format

### ValidationException (400 Bad Request)
```json
{
  "error": "Validation error",
  "message": "Validation failed",
  "errors": {
    "name": "Field 'name' is required",
    "email": "Field 'email' must be a valid email address"
  }
}
```

### InvalidArgumentException (400 Bad Request)
```json
{
  "error": "Invalid request",
  "message": "Organization already exists"
}
```

---

## Migration

### Schrittweise Migration

1. **Neue Endpoints:** Verwenden sofort `InputValidator`
2. **Bestehende Endpoints:** Können schrittweise migriert werden
3. **Alte Validierung:** Bleibt funktionsfähig (kein Breaking Change)

### Beispiel-Migration

**orgs.php - POST /api/orgs:**
```php
// Vorher
$data = json_decode(file_get_contents('php://input'), true);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}
if (empty($data['name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Name is required']);
    exit;
}

// Nachher
use TOM\Infrastructure\Validation\InputValidator;

$data = getJsonBody(); // Bereits in base-api-handler.php

try {
    InputValidator::validateRequired($data, 'name');
    InputValidator::validateLength($data['name'], 1, 255, 'name');
    // ...
} catch (\Exception $e) {
    handleApiException($e, 'Create org');
}
```

---

## Vorteile

1. **Konsistenz:** Einheitliche Fehlerbehandlung
2. **Detailliert:** Feld-spezifische Fehlermeldungen
3. **Wartbarkeit:** Zentrale Validierung
4. **Type-Safety:** Explizite Typ-Validierung
5. **Erweiterbarkeit:** Neue Validatoren einfach hinzufügen

---

## Nächste Schritte

1. ✅ ValidationException erstellt
2. ✅ InputValidator erstellt
3. ✅ Exception-Handler erweitert
4. ⏳ API-Endpoints schrittweise migrieren (optional)

