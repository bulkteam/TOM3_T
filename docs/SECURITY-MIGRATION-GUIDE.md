# Security Migration Guide - Phase 1

## Übersicht

Diese Anleitung beschreibt die Änderungen für Phase 1 der Sicherheitsverbesserungen:
1. ✅ Auth-Zwang ohne "default_user" Fallback
2. ✅ CSRF-Schutz
3. ✅ APP_ENV härten

---

## 1. Auth-Zwang ohne Fallback

### Was wurde geändert:

**Vorher:**
```php
$userId = AuthHelper::getCurrentUserId(); // Gibt 'default_user' zurück wenn nicht eingeloggt
```

**Nachher:**
```php
// Option 1: Strikte Prüfung (empfohlen)
$user = requireAuth(); // Wirft 401 wenn nicht eingeloggt
$userId = (string)$user['user_id'];

// Option 2: Mit Fallback nur in Dev
$userId = AuthHelper::getCurrentUserId(true); // Erlaubt 'default_user' nur in Dev-Mode
```

### Migration in API-Endpoints:

**Vorher:**
```php
$userId = AuthHelper::getCurrentUserId();
// oder
$userId = $_GET['user_id'] ?? 'default_user';
```

**Nachher:**
```php
require_once __DIR__ . '/api-security.php';

// Für geschützte Endpoints:
$user = requireAuth();
$userId = (string)$user['user_id'];

// Für öffentliche Endpoints (z.B. auth.php):
// Keine Änderung nötig
```

### Betroffene Dateien:

Die folgenden Dateien müssen angepasst werden:
- `public/api/orgs.php`
- `public/api/persons.php`
- `public/api/import.php`
- `public/api/documents.php`
- `public/api/cases.php`
- `public/api/projects.php`
- `public/api/accounts.php`
- Alle anderen API-Endpoints mit `getCurrentUserId()` oder `'default_user'`

---

## 2. CSRF-Schutz

### Was wurde geändert:

**Neue Funktionen:**
- `generateCsrfToken()` - Generiert CSRF-Token
- `validateCsrfToken($method)` - Validiert CSRF-Token für state-changing Requests

### Verwendung in API-Endpoints:

**Für POST/PUT/DELETE Requests:**
```php
require_once __DIR__ . '/api-security.php';

$method = $_SERVER['REQUEST_METHOD'];
validateCsrfToken($method); // Prüft automatisch für POST/PUT/DELETE

// Weiter mit Request-Verarbeitung...
```

**Frontend:**
```javascript
// Token beim Laden der Seite holen
const csrfToken = await fetch('/api/auth/csrf-token').then(r => r.json());

// Bei POST/PUT/DELETE Requests mitsenden
fetch('/api/orgs', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken.token
    },
    body: JSON.stringify(data)
});
```

### CSRF-Token Endpoint erstellen:

Erstelle `public/api/auth.php` Endpoint für Token-Generierung:
```php
// GET /api/auth/csrf-token
if ($method === 'GET' && $action === 'csrf-token') {
    $token = generateCsrfToken();
    jsonResponse(['token' => $token]);
}
```

### Dev vs Production:

- **Dev-Mode:** CSRF optional (für einfacheres Testen)
- **Production:** Strikte CSRF-Prüfung (403 bei fehlendem/ungültigem Token)

---

## 3. APP_ENV härten

### Was wurde geändert:

**Vorher:**
```php
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local'; // ❌ Immer Fallback
```

**Nachher:**
```php
use TOM\Infrastructure\Security\SecurityHelper;

$appEnv = SecurityHelper::requireAppEnv(); // ✅ Fail in Production wenn nicht gesetzt
```

### Migration:

**Betroffene Dateien:**
- `public/api/base-api-handler.php` ✅ (bereits angepasst)
- `public/api/auth.php`
- `public/api/api-security.php` ✅ (bereits angepasst)
- `src/TOM/Infrastructure/Auth/AuthService.php` ✅ (bereits angepasst)

**Ersetze:**
```php
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
```

**Durch:**
```php
$appEnv = \TOM\Infrastructure\Security\SecurityHelper::requireAppEnv();
```

---

## 4. Neue Services

### SecurityHelper

**Location:** `src/TOM/Infrastructure/Security/SecurityHelper.php`

**Methoden:**
- `requireAppEnv(): string` - Prüft APP_ENV, failt in Production
- `isDevMode(): bool` - Prüft ob Dev-Mode
- `isProduction(): bool` - Prüft ob Production

### CsrfTokenService

**Location:** `src/TOM/Infrastructure/Security/CsrfTokenService.php`

**Methoden:**
- `generateToken(): string` - Generiert CSRF-Token
- `getToken(): ?string` - Holt Token aus Session
- `validateToken(string $token): bool` - Validiert Token
- `requireValidToken(string $method, ?string $token): bool` - Prüft für state-changing Requests

---

## 5. Beispiel: Migration eines API-Endpoints

**Vorher (`public/api/orgs.php`):**
```php
$currentUserId = AuthHelper::getCurrentUserId(); // Fallback auf 'default_user'

if ($method === 'POST') {
    $data = getJsonBody();
    $org = $orgService->createOrg($data, $currentUserId);
    jsonResponse($org);
}
```

**Nachher:**
```php
require_once __DIR__ . '/api-security.php';

// Auth prüfen
$user = requireAuth();
$currentUserId = (string)$user['user_id'];

if ($method === 'POST') {
    // CSRF prüfen
    validateCsrfToken($method);
    
    $data = getJsonBody();
    $org = $orgService->createOrg($data, $currentUserId);
    jsonResponse($org);
}
```

---

## 6. Testing

### Dev-Mode (APP_ENV=local):
- ✅ `default_user` Fallback funktioniert
- ✅ CSRF optional (wird nicht strikt geprüft)
- ✅ APP_ENV Default auf 'local'

### Production (APP_ENV=production):
- ❌ `default_user` Fallback blockiert (401)
- ✅ CSRF strikt geprüft (403 bei fehlendem Token)
- ❌ APP_ENV muss explizit gesetzt sein (500 bei fehlendem APP_ENV)

---

## 7. Nächste Schritte

1. ✅ **Grundfunktionen implementiert** (Phase 1.1-1.3)
2. ⏳ **API-Endpoints migrieren** (Phase 1.4)
   - Alle Endpoints auf `requireAuth()` umstellen
   - CSRF-Validierung für POST/PUT/DELETE hinzufügen
   - `'default_user'` Fallbacks entfernen
3. ⏳ **Frontend anpassen** (Phase 1.5)
   - CSRF-Token beim Laden holen
   - Token bei allen POST/PUT/DELETE Requests mitsenden
4. ⏳ **Testing** (Phase 1.6)
   - Dev-Mode testen
   - Production-Mode testen (mit APP_ENV=production)

---

## 8. Rollback-Plan

Falls Probleme auftreten:

1. **Temporärer Rollback:**
   ```php
   // In AuthHelper.php
   return 'default_user'; // Temporär wieder aktivieren
   ```

2. **CSRF deaktivieren:**
   ```php
   // In api-security.php
   function validateCsrfToken($method) {
       return; // Temporär deaktivieren
   }
   ```

3. **APP_ENV Fallback:**
   ```php
   // In SecurityHelper.php
   return 'local'; // Temporär wieder Fallback
   ```

**WICHTIG:** Rollback nur für kurzfristige Fehlerbehebung, nicht für Production!

