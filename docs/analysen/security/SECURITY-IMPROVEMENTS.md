# TOM3 - Security Improvements

## Übersicht

Dieses Dokument beschreibt die umgesetzten Sicherheitsverbesserungen basierend auf dem Code-Review.

## ✅ Umsetzte Verbesserungen (P0 - Kritisch)

### 1. Secrets aus Repository entfernt

**Problem:** Passwörter und Credentials waren direkt im Code (`config/database.php`).

**Lösung:**
- Alle Secrets müssen jetzt über Umgebungsvariablen gesetzt werden
- In Production: Fail-closed (App startet nicht ohne gesetzte ENV-Variablen)
- `.env.example` als Template erstellt
- Bestehende Secrets sollten rotiert werden

**Verwendung:**
```bash
# Lokale Entwicklung
export MYSQL_PASSWORD=dein_passwort
# oder .env Datei erstellen

# Production
# ENV-Variablen müssen über Container/Server gesetzt werden
```

### 2. CORS nur in Development

**Problem:** CORS war komplett offen (`*`) für alle Umgebungen. CSRF-Token Header fehlte in `Access-Control-Allow-Headers`.

**Lösung:**
- CORS nur in `local`/`dev` aktiv (für lokale Entwicklung)
- Production: Nur erlaubte Origins (über `CORS_ALLOWED_ORIGINS` ENV)
- `X-CSRF-Token` Header zu `Access-Control-Allow-Headers` hinzugefügt
- `Access-Control-Allow-Credentials: true` in Production (für Cookies)
- Zentrale Funktion in `api-security.php`

**Konfiguration:**
```bash
# Production
CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
```

**Header:**
```
Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token
```

### 3. Zentraler Auth-Guard

**Problem:** Viele API-Endpunkte hatten keine Auth-Prüfung.

**Lösung:**
- Zentrale Auth-Prüfung im Router (`public/api/index.php`)
- Alle Endpunkte sind standardmäßig geschützt
- Ausnahme: Öffentliche Endpunkte (z.B. `/api/auth/*`)
- Rollen-Checks für sensible Endpunkte (Monitoring, Users)

**Verwendung in Endpunkten:**
```php
// Automatisch durch Router geschützt
// Für spezielle Rollen:
requireAdmin(); // oder requireRole('manager')
```

### 4. Bypass-Schutz für direkte .php-Aufrufe

**Problem:** API-Endpunkte konnten direkt aufgerufen werden und Router umgehen.

**Lösung:**
- `.htaccess` angepasst: Alle `/api/*.php` Dateien werden blockiert (403 Forbidden)
- Alle `/api/*` Requests gehen über Router (`api/index.php`)
- Router setzt `TOM3_API_ROUTER` Define
- Alle API-Skripte prüfen `TOM3_API_ROUTER` Guard (404 wenn direkt aufgerufen)
- Gefährliche "Direktaufruf"-Zweige entfernt (z.B. in `monitoring.php`)

**Implementierung:**
```apache
# .htaccess
RewriteRule ^api/.*\.php$ - [F,L]  # Blockiere direkte PHP-Dateien
RewriteRule ^api/(.*)$ api/index.php [QSA,L]  # Route über Router
```

```php
// In jedem API-Skript:
if (!defined('TOM3_API_ROUTER')) {
    http_response_code(404);
    exit;
}
```

### 5. Error-Handling: Dev vs Production

**Problem:** Stack-Traces, PDO-Fehler und Details wurden in Production ausgegeben.

**Lösung:**
- Dev: Vollständige Fehlerdetails (Message, File, Line, Trace, pdo_error)
- Production: Generische Fehlermeldung + Korrelations-ID
- Details werden nur intern geloggt
- `pdo_error` (DB-Struktur/Constraints) nur im Dev-Mode
- Alle direkten `$e->getMessage()` Ausgaben durch `sendErrorResponse()` ersetzt

**Verwendung:**
```php
// ❌ Falsch (leakt interne Details):
catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

// ✅ Richtig:
catch (Exception $e) {
    require_once __DIR__ . '/api-security.php';
    sendErrorResponse($e);
}
```

### 6. API-Design vereinheitlicht

**Problem:** Inkonsistentes API-Design - Router vs. "Standalone"-Scripts.

**Lösung:**
- Router als Single Entry Point (Front Controller)
- Alle Handler nutzen Router-Variablen (`$id`, `$action`) statt selbst zu parsen
- Einheitliches Response/Error-Handling
- Security-Fallbacks (`'default_user'`) entfernt
- Einheitliche Auth über Router/`requireAuth()`

**Refactored Dateien:**
- `tasks.php`, `cases.php`, `queues.php`, `work-items.php`, `users.php`
- Alle nutzen jetzt Router-Variablen statt eigenes Parsing

### 7. Document-Download/View gehärtet

**Problem:** Header-Injection Risiko, Content-Sniffing, fehlende Berechtigungsprüfung.

**Lösung:**
- RFC5987 `filename*` für Unicode-Unterstützung
- `sanitizeFilenameForHeader()`: Striktes Filtern (Whitelist)
- `X-Content-Type-Options: nosniff` hinzugefügt
- Content-Security-Policy für PDFs (Sandbox)
- Basis-Berechtigungsprüfung: Dokumente ohne Attachments nur für Admins
- TODO: Vollständige Permission-Prüfung (wenn Permission-System vorhanden)

**Implementierung:**
```php
// RFC5987 Format:
header('Content-Disposition: attachment; filename="..." ; filename*=UTF-8\'\'...');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: sandbox allow-same-origin allow-scripts'); // für PDFs
```

### 8. Undefined Offset Bug behoben

**Problem:** `cases.php` hatte undefined offset Zugriff (`$pathParts[4]` ohne `isset()`).

**Lösung:**
- `isset($pathParts[3], $pathParts[4])` Prüfung hinzugefügt
- Verhindert PHP-Notices/Warnings → 500 Errors

## 📋 Nächste Schritte (P1 - Sehr sinnvoll)

### Input-Validation Pattern

**Status:** Pattern erstellt (`api-validation.php`), noch nicht überall eingebaut.

**Empfehlung:**
- Schrittweise in bestehende Endpunkte einbauen
- Neue Endpunkte sollten Validation von Anfang an nutzen
- Beispiel-Validatoren für Person/Org bereits vorhanden

**Verwendung:**
```php
require_once __DIR__ . '/api-validation.php';

$data = getValidatedJsonBody();
validatePersonCreate($data);
$person = $personService->createPerson($data);
```

### Monitoring-Endpunkt absichern

**Status:** ✅ Bereits umgesetzt - Monitoring erfordert Admin-Rolle.

## ✅ Weitere Verbesserungen (Januar 2026)

### Session/Cookie-Härtung

**Status:** ✅ Implementiert

**Änderungen:**
- SameSite=Strict für Staging/Prod (besserer CSRF-Schutz)
- SameSite=Lax für lokale Entwicklung (Cross-Origin-Tests möglich)
- HttpOnly bereits vorhanden
- Secure-Flag basierend auf APP_ENV und HTTPS

**Implementierung:**
```php
// src/TOM/Infrastructure/Auth/AuthService.php
$sameSite = ($this->appEnv === 'prod' || $this->appEnv === 'staging') ? 'Strict' : 'Lax';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secure,
    'samesite' => $sameSite,
]);
```

**Session-Regeneration:** Bereits implementiert in `login()` (`session_regenerate_id(true)`)

### Rate-Limiting

**Status:** ✅ Implementiert

**Rate-Limits:**
- Login: 5 Versuche pro IP pro Minute
- Telephony/Calls: 20 Calls pro User pro Minute
- Work-Items PATCH: 30 Requests pro User pro Minute

**Implementierung:**
- `src/TOM/Infrastructure/Security/RateLimiter.php` (In-Memory für Staging)
- Für Production: Redis oder Memcached empfohlen

**Verwendung:**
```php
$rateLimiter = new RateLimiter($db);
if (!$rateLimiter->checkIpLimit('auth-login', 5, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}
```

### Audit-Logging für Stage/Owner Änderungen

**Status:** ✅ Implementiert

**Protokolliert:**
- Stage-Änderungen: "Stage geändert: X → Y"
- Owner-Änderungen: "Owner geändert: X → Y"
- Metadaten: `old_stage`, `new_stage`, `changed_by`

**Implementierung:**
- System-Activity in `work_item_timeline` Tabelle
- Activity-Type: `STAGE_CHANGE`, `OWNER_CHANGE`

### Input Validation für Work-Items

**Status:** ✅ Implementiert

**Validierung:**
- Stage-Enum: Nur gültige Stages erlaubt
- Datumsformat: ISO 8601 für `next_action_at`
- priority_stars: Range 0-5

**Gültige Stages:**
`NEW`, `IN_PROGRESS`, `SNOOZED`, `QUALIFIED`, `DATA_CHECK`, `DISQUALIFIED`, `DUPLICATE`, `CLOSED`

## 🔒 Spätere Verbesserungen (P2)

- Automatisierte Tests (Smoke/Integration)
- Security-Header (CSP, HSTS, etc.)
- Umfangreiche Permission-Matrix
- Rate-Limiting für Production (Redis/Memcached)

## Migration Guide

### Für bestehende Entwickler

1. **ENV-Variablen setzen:**
   ```bash
   # Kopiere .env.example nach .env
   cp .env.example .env
   # Bearbeite .env mit deinen lokalen Werten
   ```

2. **Secrets rotieren:**
   - MariaDB Passwort ändern
   - Neo4j Credentials ändern (falls verwendet)

3. **Auth prüfen:**
   - Alle API-Calls sollten jetzt Auth erfordern
   - `/api/auth/*` Endpunkte sind weiterhin öffentlich

### Für Production-Deployment

1. **ENV-Variablen setzen:**
   - `APP_ENV=prod`
   - `AUTH_MODE=session` (oder andere Auth-Methode)
   - Alle Secrets müssen gesetzt sein

2. **CORS konfigurieren:**
   - `CORS_ALLOWED_ORIGINS` mit erlaubten Domains setzen

3. **Error-Logging prüfen:**
   - Korrelations-IDs werden in Error-Logs geschrieben
   - Logs sollten überwacht werden

## Best Practices

### Neue API-Endpunkte erstellen

1. **Auth ist automatisch aktiv** - keine manuelle Prüfung nötig
2. **Validation einbauen:**
   ```php
   require_once __DIR__ . '/api-validation.php';
   $data = getValidatedJsonBody();
   validatePersonCreate($data); // oder eigene Validator-Funktion
   ```
3. **Error-Handling:**
   - Exceptions werden automatisch korrekt behandelt
   - Keine manuellen `getMessage()` Aufrufe nötig

### Öffentliche Endpunkte

Falls ein Endpunkt öffentlich sein soll:
1. In `api-security.php` → `isPublicEndpoint()` hinzufügen
2. Oder: Explizit in Router prüfen (vor `requireAuth()`)

## Sicherheits-Checkliste

- [x] Secrets aus Repository entfernt (keine Default-Passwörter mehr)
- [x] CORS nur in dev aktiv + X-CSRF-Token Header
- [x] Zentraler Auth-Guard
- [x] Bypass-Schutz (.htaccess + TOM3_API_ROUTER Guards)
- [x] Error-Handling (dev vs prod, pdo_error nur im Dev)
- [x] API-Design vereinheitlicht (Router-Variablen, keine Fallbacks)
- [x] Document-Download/View gehärtet (RFC5987, CSP, Berechtigungsprüfung)
- [x] Undefined Offset Bug behoben
- [x] Session/Cookie-Härtung (SameSite=Strict für Staging/Prod)
- [x] Rate-Limiting implementiert (Login, Telephony, Work-Items)
- [x] Audit-Logging für Stage/Owner Änderungen
- [x] Input Validation für Work-Items (Stage-Enum, Datumsformat, priority_stars)
- [ ] Input-Validation überall eingebaut (Pattern vorhanden, teilweise umgesetzt)
- [ ] Secrets rotiert
- [ ] Tests geschrieben
- [ ] Vollständige Permission-Prüfung für Dokumente (wenn Permission-System vorhanden)

---

*Letzte Aktualisierung: 2026-01-04*


