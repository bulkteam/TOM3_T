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

**Problem:** CORS war komplett offen (`*`) für alle Umgebungen.

**Lösung:**
- CORS nur in `local`/`dev` aktiv (für lokale Entwicklung)
- Production: Nur erlaubte Origins (über `CORS_ALLOWED_ORIGINS` ENV)
- Zentrale Funktion in `api-security.php`

**Konfiguration:**
```bash
# Production
CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
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
- `.htaccess` angepasst: Alle `/api/*` Aufrufe gehen über Router
- Auch existierende `.php` Dateien werden über Router geleitet

### 5. Error-Handling: Dev vs Production

**Problem:** Stack-Traces und Details wurden in Production ausgegeben.

**Lösung:**
- Dev: Vollständige Fehlerdetails (Message, File, Line, Trace)
- Production: Generische Fehlermeldung + Korrelations-ID
- Details werden nur intern geloggt

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

## 🔒 Spätere Verbesserungen (P2)

- Automatisierte Tests (Smoke/Integration)
- Security-Header (CSP, HSTS, etc.)
- Rate Limiting / Bruteforce-Schutz
- Umfangreiche Permission-Matrix

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

- [x] Secrets aus Repository entfernt
- [x] CORS nur in dev aktiv
- [x] Zentraler Auth-Guard
- [x] Bypass-Schutz
- [x] Error-Handling (dev vs prod)
- [ ] Input-Validation überall eingebaut (Pattern vorhanden)
- [ ] Secrets rotiert
- [ ] Tests geschrieben
- [ ] Security-Header konfiguriert

---

*Letzte Aktualisierung: 2026-01-01*
