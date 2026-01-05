# .htaccess Änderungen - Analyse der Auswirkungen

**Stand:** 2026-01-05  
**Problem:** 404-Fehler bei `/api/work-items`  
**Lösung:** Frontend-URLs anpassen statt `.htaccess` zu ändern

---

## Ursprüngliche .htaccess (KORREKT)

```apache
RewriteEngine On
RewriteBase /TOM3/public/

# API Routes
RewriteCond %{REQUEST_URI} ^/TOM3/public/api/ [NC]
RewriteCond %{REQUEST_URI} !^/TOM3/public/api/index\.php [NC]
RewriteRule ^api/(.*)$ api/index.php [QSA,L]
```

**Funktioniert für:**
- ✅ `/TOM3/public/api/orgs` → `api/index.php` (resource=orgs)
- ✅ `/TOM3/public/api/persons` → `api/index.php` (resource=persons)
- ✅ `/TOM3/public/api/work-items` → `api/index.php` (resource=work-items)
- ✅ Alle anderen API-Routen

**Wie es funktioniert:**
1. `RewriteBase /TOM3/public/` setzt den Base-Path
2. `RewriteCond %{REQUEST_URI} ^/TOM3/public/api/` prüft, ob die URL mit `/TOM3/public/api/` beginnt
3. `RewriteRule ^api/(.*)$ api/index.php` matched `api/...` und leitet zu `api/index.php` weiter
4. Da `RewriteBase /TOM3/public/` gesetzt ist, wird `api/index.php` zu `/TOM3/public/api/index.php` aufgelöst

---

## Vorgeschlagene Änderung (PROBLEMATISCH)

```apache
RewriteCond %{REQUEST_URI} ^(/TOM3/public)?/api/ [NC]
RewriteCond %{REQUEST_URI} !^/TOM3/public/api/index\.php [NC]
RewriteCond %{REQUEST_URI} !^/api/index\.php [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^api/(.*)$ api/index.php [QSA,L]
```

**Problem:**
- ⚠️ `RewriteBase /TOM3/public/` ist gesetzt
- ⚠️ `RewriteRule ^api/(.*)$` wird relativ zu `/TOM3/public/` ausgewertet
- ⚠️ Wenn jemand `/api/work-items` aufruft (ohne `/TOM3/public/`):
  - Die Bedingung `^(/TOM3/public)?/api/` matched ✅
  - Aber `RewriteRule ^api/(.*)$` sucht nach `api/...` relativ zu `/TOM3/public/`
  - Das bedeutet: Es sucht nach `/TOM3/public/api/...`, nicht nach `/api/...`
  - **Resultat:** Die Regel matched nicht korrekt!

**Auswirkungen auf andere Routen:**
- ❌ `/TOM3/public/api/orgs` könnte nicht mehr funktionieren (wenn die Bedingung zu restriktiv ist)
- ❌ Inkonsistente URL-Behandlung
- ❌ Unvorhersehbare Fehler

---

## Korrekte Lösung: Frontend anpassen

**Statt `.htaccess` zu ändern, sollte das Frontend die korrekten URLs verwenden:**

### Problem im Frontend

`inside-sales.js` und `sales-ops.js` verwenden:
```javascript
fetch('/api/work-items?type=LEAD&tab=new')
```

Das wird zu `http://localhost/api/work-items` aufgelöst (ohne `/TOM3/public/`).

### Lösung: Helper-Funktion

```javascript
this.getApiUrl = (path) => {
    const basePath = window.location.pathname
        .replace(/\/index\.html$/, '')
        .replace(/\/login\.php$/, '')
        .replace(/\/monitoring\.html$/, '')
        .replace(/\/$/, '') || '';
    return `${basePath}/api${path.startsWith('/') ? path : '/' + path}`;
};
```

**Beispiel:**
- Wenn `window.location.pathname = '/TOM3/public/index.html'`
- Dann `basePath = '/TOM3/public'`
- Dann `getApiUrl('/work-items')` → `/TOM3/public/api/work-items` ✅

---

## Vergleich: Andere Module

### Module die `window.API` verwenden (KORREKT)

```javascript
// org-forms.js, person-forms.js, etc.
const org = await window.API.createOrg(data);
```

**Funktioniert, weil:**
- `window.API.baseUrl` wird von `getBasePath()` ermittelt
- `getBasePath()` extrahiert den Base-Path aus `window.location.pathname`
- Automatisch korrekt: `/TOM3/public/api/...`

### Module die direkten `fetch()` verwenden (PROBLEMATISCH)

```javascript
// inside-sales.js (VORHER)
const response = await fetch('/api/work-items?type=LEAD&tab=new');
```

**Problem:**
- Absolute URL `/api/...` wird zu `http://localhost/api/...` aufgelöst
- Fehlt `/TOM3/public/` → 404

**Lösung:**
- Helper-Funktion `getApiUrl()` verwenden (wie oben)
- Oder `window.API.request()` verwenden

---

## Empfehlung

### ✅ Option 1: Helper-Funktion (aktuell implementiert)

**Vorteile:**
- `.htaccess` bleibt unverändert
- Andere Routen funktionieren weiterhin
- Konsistent mit bestehender Struktur

**Nachteile:**
- Zwei verschiedene Ansätze im Frontend (window.API vs. getApiUrl)

### ✅ Option 2: window.API verwenden (besser, aber mehr Arbeit)

**Vorteile:**
- Konsistent mit anderen Modulen
- Zentrale URL-Verwaltung
- Einheitlicher Ansatz

**Nachteile:**
- Mehr Refactoring nötig
- Alle fetch-Aufrufe müssen angepasst werden

---

## Zusammenfassung

**Aktuelle Lösung (Option 1):**
- ✅ `.htaccess` bleibt unverändert
- ✅ Helper-Funktion `getApiUrl()` in `inside-sales.js` und `sales-ops.js`
- ✅ Andere Routen funktionieren weiterhin
- ✅ Keine Breaking Changes

**Alternative (Option 2):**
- `.htaccess` bleibt unverändert
- Alle fetch-Aufrufe auf `window.API.request()` umstellen
- Konsistenter, aber mehr Arbeit

**NICHT empfohlen:**
- ❌ `.htaccess` ändern (kann andere Routen beeinträchtigen)
- ❌ Absolute URLs `/api/...` verwenden (funktioniert nicht mit `/TOM3/public/`)

---

## Test-Checkliste

Nach der Änderung sollten folgende Routen weiterhin funktionieren:

- [ ] `/TOM3/public/api/orgs` → OrgService
- [ ] `/TOM3/public/api/persons` → PersonService
- [ ] `/TOM3/public/api/cases` → CaseService
- [ ] `/TOM3/public/api/import` → ImportService
- [ ] `/TOM3/public/api/documents` → DocumentService
- [ ] `/TOM3/public/api/work-items` → WorkItemService ✅ (neu)
- [ ] `/TOM3/public/api/queues` → QueueService ✅ (neu)
- [ ] `/TOM3/public/api/telephony` → TelephonyService ✅ (neu)

