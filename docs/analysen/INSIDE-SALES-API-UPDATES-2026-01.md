# Inside Sales API Updates - Januar 2026

**Datum:** 2026-01-04  
**Status:** ✅ Implementiert

## Zusammenfassung der Änderungen

### 1. Handover-Endpoint implementiert

**Problem:** Frontend postete auf `/api/work-items/{uuid}/handover`, aber Endpoint existierte nicht.

**Lösung:**
- ✅ `POST /api/work-items/{uuid}/handover` implementiert
- ✅ Unterstützt `QUOTE_REQUEST` und `DATA_CHECK` Handoff-Typen
- ✅ Setzt automatisch Stage (`QUALIFIED` oder `DATA_CHECK`) und Owner-Role (`ops`)
- ✅ Erstellt Timeline-Eintrag mit vollständigen Metadaten

**API-Dokumentation:**
```http
POST /api/work-items/{uuid}/handover
Content-Type: application/json
X-CSRF-Token: <token>

Body (QUOTE_REQUEST):
{
  "handoff_type": "QUOTE_REQUEST",
  "need_summary": "Kurze Beschreibung des Bedarfs",
  "contact_hint": "Ansprechpartner/Abteilung",
  "next_step": "Nächster Schritt"
}

Body (DATA_CHECK):
{
  "handoff_type": "DATA_CHECK",
  "issue": "Was ist unklar?",
  "request": "Was soll Sales Ops klären?",
  "contact_hint": "Ansprechpartner (optional)",
  "next_step": "Nächster Schritt (optional)",
  "links": ["URL1", "URL2"]
}

Response:
{
  "timeline_id": 123,
  "stage": "QUALIFIED",
  "owner_role": "ops"
}
```

### 2. IN_PROGRESS-Bug behoben

**Problem:** Leads wurden unbeabsichtigt auf `IN_PROGRESS` gesetzt beim Sterne-Setzen.

**Lösung:**
- ✅ Automatisches `IN_PROGRESS` aus `setStars()` entfernt
- ✅ `IN_PROGRESS` wird nur noch bei echten Aktionen gesetzt:
  - Call starten (`startCallWithNumber()`)
  - Disposition speichern (`saveDisposition()`)
- ✅ Event-Listener-Problem behoben (kein Stacking mehr)

**Verhalten:**
- Sterne setzen = Priorisierung, **kein** automatischer Stage-Wechsel
- Stage-Wechsel nur bei aktiven Aktionen (Call, Disposition)

### 3. API-Client konsolidiert

**Problem:** Gemischter Einsatz von `window.API.request()` und manuellem `fetch()`.

**Lösung:**
- ✅ CSRF-Token werden automatisch in `window.API.request()` hinzugefügt
- ✅ Alle `fetch()`-Aufrufe durch `window.API.request()` ersetzt
- ✅ `getApiUrl()` kann entfernt werden (nicht mehr benötigt)

**Verwendung:**
```javascript
// Vorher:
const token = await window.csrfTokenService?.fetchToken();
await fetch(this.getApiUrl(`/work-items/${uuid}`), {
    method: 'PATCH',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token || ''
    },
    body: JSON.stringify(data)
});

// Nachher:
await window.API.request(`/work-items/${uuid}`, {
    method: 'PATCH',
    body: data
});
```

### 4. Zentrale Stage-Transitions

**Problem:** Stage-Übergänge passierten an mehreren Stellen im Code.

**Lösung:**
- ✅ Zentrale Methode `applyStageTransition()` in `InsideSalesDialerModule`
- ✅ Verhindert, dass Stage-Übergänge versehentlich an mehreren Stellen passieren

**Verwendung:**
```javascript
// Stage-Transition zentral
await this.dialerModule.applyStageTransition('IN_PROGRESS');
```

### 5. Polling verbessert

**Problem:** Polling-Loops konnten parallel laufen, keine saubere Beendigung.

**Lösung:**
- ✅ AbortController für sauberes Abbrechen
- ✅ Polling wird automatisch bei Leadwechsel gestoppt
- ✅ Backoff-Strategie beibehalten (1s → 2s → 5s)

### 6. Sicherheitsverbesserungen

**Implementiert:**
- ✅ Session-Cookies: SameSite=Strict (Staging/Prod)
- ✅ Rate-Limits für kritische Endpunkte:
  - Login: 5 Versuche/IP/Minute
  - Telephony/Calls: 20 Calls/User/Minute
  - Work-Items PATCH: 30 Requests/User/Minute
- ✅ Audit-Logging für Stage/Owner Änderungen
- ✅ Input Validation für PATCH work-items:
  - Stage-Enum-Validierung
  - Datumsformat-Validierung
  - priority_stars Range-Validierung

**Gültige Stages:**
- `NEW`, `IN_PROGRESS`, `SNOOZED`, `QUALIFIED`, `DATA_CHECK`, `DISQUALIFIED`, `DUPLICATE`, `CLOSED`

---

## Dokumentation, die aktualisiert werden sollte

### 1. API-Dokumentation
- [ ] Handover-Endpoint dokumentieren
- [ ] Stage-Transitions dokumentieren
- [ ] Rate-Limits dokumentieren

### 2. Entwickler-Dokumentation
- [ ] API-Client-Konsolidierung dokumentieren
- [ ] Stage-Transition-Pattern dokumentieren
- [ ] Polling-Pattern dokumentieren

### 3. Security-Dokumentation
- [ ] `docs/analysen/security/SECURITY-IMPROVEMENTS.md` aktualisieren
- [ ] Rate-Limits dokumentieren
- [ ] Audit-Logging dokumentieren

### 4. Refactoring-Plan
- [ ] `docs/analysen/refactoring/INSIDE-SALES-REFACTORING-PLAN.md` aktualisieren
- [ ] Status der Implementierung aktualisieren

---

## Betroffene Dateien

### Backend
- `public/api/work-items.php` - Handover-Endpoint, Input Validation, Audit
- `public/api/auth.php` - Rate-Limits
- `public/api/telephony.php` - Rate-Limits
- `src/TOM/Infrastructure/Auth/AuthService.php` - Session-Cookie-Härtung
- `src/TOM/Infrastructure/Security/RateLimiter.php` - Neu

### Frontend
- `public/js/api.js` - CSRF-Token automatisch
- `public/js/modules/inside-sales-dialer.js` - Stage-Transitions, Polling, API-Client
- `public/js/modules/inside-sales-disposition.js` - API-Client, Stage-Transitions

---

## Nächste Schritte

1. ✅ Code-Änderungen implementiert
2. ⏳ API-Dokumentation aktualisieren
3. ⏳ Entwickler-Dokumentation aktualisieren
4. ⏳ Security-Dokumentation aktualisieren

