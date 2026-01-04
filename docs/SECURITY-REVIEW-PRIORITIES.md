# Security Review - Priorisierte Verbesserungen

## Zusammenfassung der Code-Review Anmerkungen

**Status:** ✅ Analyse abgeschlossen  
**Datum:** 2026-01-04  
**Kategorien:** P0 (kritisch), P1 (wichtig), P2 (nice-to-have)

---

## P0 - Kritisch (Sofort umsetzen)

### 1. ✅ Auth-Zwang ohne "default_user" Fallback

**Problem:**
- `AuthHelper::getCurrentUserId()` fällt auf `'default_user'` zurück
- Ermöglicht anonyme/ungewollte Writes
- Verfälscht Audit-Trail

**Aktueller Code:**
```php
// src/TOM/Infrastructure/Auth/AuthHelper.php:43
public static function getCurrentUserId(): string
{
    $user = self::getCurrentUser();
    if ($user) {
        return (string)$user['user_id'];
    }
    return 'default_user'; // ❌ Sicherheitsrisiko
}
```

**Empfehlung:**
- Fallback nur in `APP_ENV=dev` erlauben
- In Production: 401/403 konsequent
- `requireAuth()` / `requireRole()` einheitlich verwenden

**Betroffene Dateien:**
- `src/TOM/Infrastructure/Auth/AuthHelper.php`
- `src/TOM/Service/BaseEntityService.php`
- Alle API-Endpoints (38+ Stellen)

---

### 2. ❌ CSRF-Schutz für Cookie-Session-Auth

**Problem:**
- Kein CSRF-Schutz für POST/PUT/DELETE
- Session-Cookies sind anfällig für CSRF-Angriffe

**Empfehlung:**
- CSRF-Token (Double Submit Cookie Pattern)
- Header-Token (`X-CSRF-Token`) + serverseitige Prüfung
- SameSite=Strict für Cookies
- Re-Auth für kritische Aktionen

**Betroffene Dateien:**
- Alle API-Endpoints mit POST/PUT/DELETE
- Session-Konfiguration

---

### 3. ⚠️ APP_ENV Default "local" härten

**Problem:**
- `APP_ENV` fällt auf `'local'` zurück
- In Production gefährlich (CORS/Debug-Verhalten)

**Aktueller Code:**
```php
// public/api/base-api-handler.php:125
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local'; // ❌
```

**Empfehlung:**
- In Production: Startup-Fail wenn `APP_ENV` fehlt
- Konfig zentral über ENV
- Keine Pfad-Ratespiele

**Betroffene Dateien:**
- `public/api/base-api-handler.php`
- `public/api/auth.php`
- `public/api/api-security.php`
- `src/TOM/Infrastructure/Auth/AuthService.php`

---

### 4. ⚠️ Rollen-/Rechteprüfung: Hierarchie statt "Role == Permission"

**Problem:**
- `UserPermissionService::userHasPermission()` sehr "literal"
- Wird brüchig bei mehr Berechtigungen

**Aktueller Code:**
```php
// src/TOM/Service/User/UserPermissionService.php:76
public function userHasPermission($userId, string $permission, ?array $userRoles = null): bool
{
    $userRole = $this->getUserPermissionRole($userId, $userRoles);
    if ($userRole === 'admin') {
        return true; // ✅ Gut
    }
    return $userRole === $permission; // ⚠️ Zu literal
}
```

**Empfehlung:**
- Rollen-Hierarchie als Mapping (admin > manager > user > readonly)
- Permissions als Capabilities (org.write, person.write, export.run)
- Endpoints prüfen Capabilities, nicht "Role-String"

**Betroffene Dateien:**
- `src/TOM/Service/User/UserPermissionService.php`
- Alle API-Endpoints mit Permission-Checks

---

## P1 - Sehr sinnvoll (Stabilität, Konsistenz)

### 5. Input-Validation vereinheitlichen

**Problem:**
- Validation-Pattern nicht überall integriert
- Teils "echo + http_response_code" statt zentraler Fehlerbehandlung

**Empfehlung:**
- Validatoren werfen Exceptions (`ValidationException`)
- Zentraler Handler in `base-api-handler.php`
- Konsistente JSON-Errors

---

### 6. Transaktionen bei Multi-Step Writes

**Problem:**
- Entities wie Org ändern mehrere Tabellen
- Keine Transaktionen → Inkonsistenz möglich

**Empfehlung:**
- Service-Layer Methoden mit Transaktionen
- Audit/Activity nach Commit

---

### 7. Search/Listing: Pagination + Indizes

**Problem:**
- Keine Pagination
- Fehlende Indizes für Performance

**Empfehlung:**
- Pagination (limit/offset oder cursor)
- FULLTEXT-Indizes für Name/Notizen/Email
- Indizes für "recent"-Listen

---

### 8. Neo4j-Integration: Deprecation-Suppression ersetzen

**Problem:**
- Deprecation-Verhalten unterdrückt
- Echte Fehler können untergehen

**Empfehlung:**
- Library-Versionen pinnen
- Upgrade-Pfad definieren
- Deprecation gezielt fixen

---

## P2 - Quality of Life

### 9. Reproduzierbarkeit / Build
- composer.json + composer.lock
- phpstan/psalm, php-cs-fixer
- CI-Checks

### 10. API-Kontrakte dokumentieren
- OpenAPI/Swagger
- Request/Response Beispiele

---

## Quick-Wins (Heute umsetzbar)

1. ✅ **default_user Fallback eliminieren** → Verhindert unautorisierte Writes
2. ❌ **CSRF für POST/PUT/DELETE** → Großer Sicherheitshebel
3. ⚠️ **Role/Permission Mapping** → Spart später Endpoint-Flickwerk

---

## Empfehlung: Implementierungsreihenfolge

### Phase 1: Sicherheit (P0)
1. Auth-Zwang ohne Fallback (1-2 Stunden)
2. CSRF-Schutz (2-3 Stunden)
3. APP_ENV härten (30 Minuten)

### Phase 2: Stabilität (P1)
4. Input-Validation vereinheitlichen (2-3 Stunden)
5. Transaktionen (1-2 Stunden)
6. Pagination + Indizes (2-3 Stunden)

### Phase 3: Wartbarkeit (P1/P2)
7. Role/Permission Mapping (2-3 Stunden)
8. Neo4j Deprecation (1 Stunde)
9. API-Dokumentation (ongoing)

**Geschätzter Aufwand:** ~15-20 Stunden für P0+P1

---

## Nächste Schritte

1. ✅ **Priorisierung bestätigen** (diese Datei)
2. ⏳ **Phase 1 starten** (Auth-Zwang)
3. ⏳ **Phase 2 planen** (nach Phase 1)

