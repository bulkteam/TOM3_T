# Security Phase 1 - Testing Guide

## Übersicht

Dieses Dokument beschreibt die Test-Szenarien für Phase 1 der Sicherheitsverbesserungen:
1. Auth-Zwang ohne "default_user" Fallback
2. CSRF-Schutz
3. APP_ENV härten

---

## Test-Umgebungen

### Dev-Mode (APP_ENV=local)
- **Erwartetes Verhalten:**
  - `default_user` Fallback funktioniert (für Kompatibilität)
  - CSRF optional (wird nicht strikt geprüft)
  - APP_ENV Default auf 'local'

### Production (APP_ENV=production)
- **Erwartetes Verhalten:**
  - `default_user` Fallback blockiert (401 Unauthorized)
  - CSRF strikt geprüft (403 bei fehlendem Token)
  - APP_ENV muss explizit gesetzt sein (500 bei fehlendem APP_ENV)

---

## Test-Checkliste

### 1. Auth-Zwang (requireAuth())

#### Test 1.1: GET-Endpoints ohne Auth (Öffentlich)
- [ ] `GET /api/orgs` - Sollte funktionieren
- [ ] `GET /api/orgs/{uuid}` - Sollte funktionieren
- [ ] `GET /api/persons` - Sollte funktionieren
- [ ] `GET /api/cases` - Sollte funktionieren
- [ ] `GET /api/projects` - Sollte funktionieren

**Erwartetes Ergebnis:** Alle GET-Endpoints sollten ohne Auth funktionieren.

#### Test 1.2: POST-Endpoints ohne Auth (Dev-Mode)
- [ ] `POST /api/orgs` ohne Auth - **Dev:** Sollte funktionieren (mit default_user)
- [ ] `POST /api/orgs` ohne Auth - **Prod:** Sollte 401 zurückgeben

**Test-Command:**
```bash
# Dev-Mode
curl -X POST http://localhost/tom3/public/api/orgs \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Org"}'

# Production (sollte 401 zurückgeben)
curl -X POST http://production.example.com/api/orgs \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Org"}'
```

#### Test 1.3: POST-Endpoints mit Auth
- [ ] `POST /api/orgs` mit Auth - Sollte funktionieren
- [ ] `POST /api/persons` mit Auth - Sollte funktionieren
- [ ] `POST /api/cases` mit Auth - Sollte funktionieren

**Test-Command:**
```bash
# Mit Session-Cookie (nach Login)
curl -X POST http://localhost/tom3/public/api/orgs \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=..." \
  -d '{"name":"Test Org"}'
```

---

### 2. CSRF-Schutz

#### Test 2.1: CSRF-Token Endpoint
- [ ] `GET /api/auth/csrf-token` - Sollte Token zurückgeben

**Test-Command:**
```bash
curl http://localhost/tom3/public/api/auth/csrf-token
```

**Erwartetes Ergebnis:**
```json
{
  "token": "abc123..."
}
```

#### Test 2.2: POST ohne CSRF-Token (Dev-Mode)
- [ ] `POST /api/orgs` ohne CSRF-Token - **Dev:** Sollte funktionieren (optional)
- [ ] `POST /api/orgs` ohne CSRF-Token - **Prod:** Sollte 403 zurückgeben

**Test-Command:**
```bash
# Dev-Mode (sollte funktionieren)
curl -X POST http://localhost/tom3/public/api/orgs \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=..." \
  -d '{"name":"Test Org"}'

# Production (sollte 403 zurückgeben)
curl -X POST http://production.example.com/api/orgs \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=..." \
  -d '{"name":"Test Org"}'
```

#### Test 2.3: POST mit CSRF-Token
- [ ] `POST /api/orgs` mit CSRF-Token - Sollte funktionieren
- [ ] `PUT /api/orgs/{uuid}` mit CSRF-Token - Sollte funktionieren
- [ ] `DELETE /api/orgs/{uuid}/addresses/{address_uuid}` mit CSRF-Token - Sollte funktionieren

**Test-Command:**
```bash
# 1. Hole CSRF-Token
TOKEN=$(curl -s -b cookies.txt http://localhost/tom3/public/api/auth/csrf-token | jq -r '.token')

# 2. POST mit Token
curl -X POST http://localhost/tom3/public/api/orgs \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $TOKEN" \
  -H "Cookie: PHPSESSID=..." \
  -d '{"name":"Test Org"}'
```

#### Test 2.4: POST mit ungültigem CSRF-Token
- [ ] `POST /api/orgs` mit ungültigem Token - Sollte 403 zurückgeben

**Test-Command:**
```bash
curl -X POST http://localhost/tom3/public/api/orgs \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: invalid-token" \
  -H "Cookie: PHPSESSID=..." \
  -d '{"name":"Test Org"}'
```

**Erwartetes Ergebnis:** 403 Forbidden

#### Test 2.5: Frontend CSRF-Token
- [ ] CSRF-Token wird beim App-Start geholt
- [ ] CSRF-Token wird bei POST-Requests automatisch mitgesendet
- [ ] CSRF-Token wird bei PUT-Requests automatisch mitgesendet
- [ ] CSRF-Token wird bei DELETE-Requests automatisch mitgesendet

**Test im Browser:**
1. Öffne Browser DevTools → Network Tab
2. Führe eine POST/PUT/DELETE Aktion aus (z.B. Organisation erstellen)
3. Prüfe Request Headers: `X-CSRF-Token` sollte vorhanden sein

---

### 3. APP_ENV härten

#### Test 3.1: APP_ENV nicht gesetzt (Dev-Mode)
- [ ] API-Request ohne APP_ENV - **Dev:** Sollte funktionieren (Default auf 'local')

**Test-Command:**
```bash
# Entferne APP_ENV aus .env oder Umgebung
unset APP_ENV

# Request sollte funktionieren
curl http://localhost/tom3/public/api/orgs
```

#### Test 3.2: APP_ENV nicht gesetzt (Production)
- [ ] API-Request ohne APP_ENV - **Prod:** Sollte 500 zurückgeben

**Test-Command:**
```bash
# In Production-Umgebung ohne APP_ENV
curl http://production.example.com/api/orgs
```

**Erwartetes Ergebnis:**
```json
{
  "error": "Configuration error",
  "message": "Security: APP_ENV must be explicitly set in production..."
}
```

#### Test 3.3: APP_ENV=production
- [ ] API-Request mit APP_ENV=production - Sollte funktionieren
- [ ] `default_user` Fallback sollte blockiert sein
- [ ] CSRF sollte strikt geprüft werden

---

## Integrationstests

### Test 4.1: Vollständiger Workflow (Org erstellen)
1. [ ] Login durchführen
2. [ ] CSRF-Token holen
3. [ ] Organisation erstellen (POST /api/orgs mit Token)
4. [ ] Organisation abrufen (GET /api/orgs/{uuid})
5. [ ] Organisation bearbeiten (PUT /api/orgs/{uuid} mit Token)
6. [ ] Adresse hinzufügen (POST /api/orgs/{uuid}/addresses mit Token)
7. [ ] Adresse löschen (DELETE /api/orgs/{uuid}/addresses/{address_uuid} mit Token)

**Erwartetes Ergebnis:** Alle Schritte sollten erfolgreich sein.

### Test 4.2: Import-Workflow
1. [ ] Import-Datei hochladen (POST /api/import/upload)
2. [ ] Datei analysieren (POST /api/import/analyze)
3. [ ] Mapping speichern (POST /api/import/mapping/{batch_uuid})
4. [ ] In Staging importieren (POST /api/import/staging/{batch_uuid})
5. [ ] Disposition setzen (POST /api/import/staging/{staging_uuid}/disposition)
6. [ ] Korrekturen speichern (POST /api/import/staging/{staging_uuid}/corrections)
7. [ ] Batch committen (POST /api/import/batch/{batch_uuid}/commit)

**Erwartetes Ergebnis:** Alle Schritte sollten erfolgreich sein, CSRF-Token wird automatisch mitgesendet.

---

## Browser-Tests

### Test 5.1: Frontend CSRF-Token
1. Öffne Browser DevTools → Console
2. Prüfe: `window.csrfTokenService.getToken()` sollte Token zurückgeben
3. Führe eine POST-Aktion aus (z.B. Organisation erstellen)
4. Prüfe Network Tab: Request sollte `X-CSRF-Token` Header enthalten

### Test 5.2: CSRF-Token nach Reload
1. Lade Seite neu
2. Prüfe: CSRF-Token sollte automatisch geholt werden
3. Führe POST-Aktion aus - sollte funktionieren

### Test 5.3: CSRF-Token nach Logout
1. Logout durchführen
2. Prüfe: `window.csrfTokenService.getToken()` sollte `null` zurückgeben
3. Login durchführen
4. Prüfe: CSRF-Token sollte neu geholt werden

---

## Fehlerbehandlung

### Test 6.1: 401 Unauthorized
- [ ] POST ohne Auth sollte 401 zurückgeben
- [ ] Frontend sollte zu Login weiterleiten

### Test 6.2: 403 Forbidden (CSRF)
- [ ] POST mit ungültigem CSRF-Token sollte 403 zurückgeben
- [ ] Fehlermeldung sollte angezeigt werden

### Test 6.3: 500 Configuration Error
- [ ] API-Request ohne APP_ENV in Production sollte 500 zurückgeben
- [ ] Fehlermeldung sollte angezeigt werden

---

## Performance-Tests

### Test 7.1: CSRF-Token Caching
- [ ] CSRF-Token wird nur einmal pro Session geholt
- [ ] Mehrere POST-Requests verwenden denselben Token
- [ ] Token wird nicht bei jedem Request neu geholt

---

## Sicherheits-Tests

### Test 8.1: CSRF-Angriff Simulation
1. Erstelle böswillige HTML-Seite:
```html
<form action="http://production.example.com/api/orgs" method="POST">
  <input type="hidden" name="name" value="Hacked Org">
  <input type="submit" value="Submit">
</form>
```

2. Versuche von externer Domain zu submitten
3. **Erwartetes Ergebnis:** 403 Forbidden (CSRF-Schutz funktioniert)

### Test 8.2: Session-Hijacking
- [ ] CSRF-Token ist session-spezifisch
- [ ] Token von anderer Session funktioniert nicht

---

## Test-Ergebnisse

### Dev-Mode (APP_ENV=local)
- [ ] Alle Tests bestanden
- [ ] `default_user` Fallback funktioniert
- [ ] CSRF optional (keine Fehler)

### Production (APP_ENV=production)
- [ ] Alle Tests bestanden
- [ ] `default_user` Fallback blockiert
- [ ] CSRF strikt geprüft
- [ ] APP_ENV-Fehler bei fehlendem APP_ENV

---

## Bekannte Probleme

### Problem 1: CSRF-Token in FormData
- **Status:** Gelöst
- **Lösung:** Token wird als Header gesendet, nicht in FormData

### Problem 2: CSRF-Token bei GET-Requests
- **Status:** Erwartetes Verhalten
- **Hinweis:** GET-Requests benötigen kein CSRF-Token

---

## Nächste Schritte nach Testing

1. **Fehler beheben:** Falls Tests fehlschlagen, Fehler beheben
2. **Dokumentation aktualisieren:** Test-Ergebnisse dokumentieren
3. **Production Deployment:** Nach erfolgreichen Tests in Production deployen
4. **Monitoring:** CSRF-Fehler in Production überwachen

---

## Test-Skripte

Siehe `scripts/test-security-phase1.sh` für automatisierte Tests (wird erstellt).

