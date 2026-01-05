# Security Phase 1 - Implementierung abgeschlossen

## ✅ Implementierte Verbesserungen

### 1. Auth-Zwang ohne "default_user" Fallback

**Status:** ✅ Implementiert

**Änderungen:**
- `AuthHelper::getCurrentUserId()` akzeptiert jetzt `$allowFallback` Parameter
- Fallback auf `'default_user'` nur in Dev-Mode erlaubt
- In Production: Exception wenn kein User eingeloggt

**Dateien:**
- `src/TOM/Infrastructure/Auth/AuthHelper.php` ✅
- `src/TOM/Service/BaseEntityService.php` ✅

**Verwendung:**
```php
// Strikte Prüfung (empfohlen)
$user = requireAuth();
$userId = (string)$user['user_id'];

// Mit Fallback nur in Dev
$userId = AuthHelper::getCurrentUserId(true);
```

---

### 2. CSRF-Schutz

**Status:** ✅ Implementiert

**Neue Services:**
- `CsrfTokenService` - Zentrale CSRF-Token-Verwaltung
- `generateCsrfToken()` - Token-Generierung
- `validateCsrfToken($method)` - Token-Validierung

**Dateien:**
- `src/TOM/Infrastructure/Security/CsrfTokenService.php` ✅
- `public/api/api-security.php` ✅ (erweitert)
- `public/api/auth.php` ✅ (CSRF-Token Endpoint hinzugefügt)

**Endpoints:**
- `GET /api/auth/csrf-token` - Generiert CSRF-Token für Frontend

**Verhalten:**
- **Dev-Mode:** CSRF optional (für einfacheres Testen)
- **Production:** Strikte CSRF-Prüfung (403 bei fehlendem/ungültigem Token)

---

### 3. APP_ENV härten

**Status:** ✅ Implementiert

**Neue Services:**
- `SecurityHelper` - Zentrale APP_ENV-Verwaltung
- `requireAppEnv()` - Prüft APP_ENV, failt in Production wenn nicht gesetzt
- `isDevMode()` / `isProduction()` - Helper-Methoden

**Dateien:**
- `src/TOM/Infrastructure/Security/SecurityHelper.php` ✅
- `public/api/api-security.php` ✅ (angepasst)
- `public/api/base-api-handler.php` ✅ (angepasst)
- `src/TOM/Infrastructure/Auth/AuthService.php` ✅ (angepasst)
- `public/api/auth.php` ✅ (angepasst)

**Verhalten:**
- **Dev:** Default auf `'local'` wenn nicht gesetzt
- **Production:** Fail sofort (500) wenn APP_ENV nicht gesetzt

---

## 📋 Nächste Schritte (Phase 1.4-1.6)

### Phase 1.4: API-Endpoints migrieren

**Betroffene Dateien (38+ Stellen):**
- `public/api/orgs.php`
- `public/api/persons.php`
- `public/api/import.php`
- `public/api/documents.php`
- `public/api/cases.php`
- `public/api/projects.php`
- `public/api/accounts.php`
- Alle anderen API-Endpoints

**Migration:**
1. `requireAuth()` statt `getCurrentUserId()` verwenden
2. `validateCsrfToken($method)` für POST/PUT/DELETE hinzufügen
3. `'default_user'` Fallbacks entfernen

### Phase 1.5: Frontend anpassen

**Änderungen:**
1. CSRF-Token beim Laden der Seite holen
2. Token bei allen POST/PUT/DELETE Requests mitsenden (Header: `X-CSRF-Token`)

**Beispiel:**
```javascript
// Beim App-Start
const csrfToken = await fetch('/api/auth/csrf-token').then(r => r.json());

// Bei Requests
fetch('/api/orgs', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken.token
    },
    body: JSON.stringify(data)
});
```

### Phase 1.6: Testing

**Checkliste:**
- [ ] Dev-Mode: `default_user` Fallback funktioniert
- [ ] Dev-Mode: CSRF optional
- [ ] Production: `default_user` Fallback blockiert (401)
- [ ] Production: CSRF strikt geprüft (403)
- [ ] Production: APP_ENV-Fehler bei fehlendem APP_ENV (500)

---

## 🔒 Sicherheitsverbesserungen

### Vorher:
- ❌ `default_user` Fallback erlaubt anonyme Writes
- ❌ Kein CSRF-Schutz
- ❌ APP_ENV fällt auf `'local'` zurück (auch in Production)

### Nachher:
- ✅ `default_user` nur in Dev-Mode
- ✅ CSRF-Schutz für state-changing Requests
- ✅ APP_ENV failt in Production wenn nicht gesetzt

---

## 📚 Dokumentation

- `docs/SECURITY-REVIEW-PRIORITIES.md` - Priorisierte Verbesserungen
- `docs/SECURITY-MIGRATION-GUIDE.md` - Migrationsanleitung
- `docs/SECURITY-PHASE1-IMPLEMENTATION.md` - Diese Datei

---

## ⚠️ Wichtige Hinweise

1. **Backward Compatibility:** 
   - Bestehende API-Endpoints funktionieren weiterhin (mit `default_user` in Dev)
   - Migration kann schrittweise erfolgen

2. **Production Deployment:**
   - **WICHTIG:** `APP_ENV=production` muss explizit gesetzt sein
   - CSRF-Token müssen vom Frontend mitgesendet werden
   - `default_user` Fallback ist in Production blockiert

3. **Testing:**
   - Teste zuerst in Dev-Mode
   - Dann mit `APP_ENV=production` testen
   - Prüfe alle POST/PUT/DELETE Endpoints

