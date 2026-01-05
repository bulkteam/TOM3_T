# Security - Offene ToDos

## Status-Übersicht

**P0 (Kritisch):** ✅ Alle umgesetzt  
**P1 (Wichtig):** ⚠️ Teilweise umgesetzt (4/6)  
**P2 (Nice-to-have):** ❌ Noch offen (0/2)

---

## P1 - Noch offen

### 7. Search/Listing: Pagination + Indizes

**Problem:**
- Keine Pagination
- Fehlende Indizes für Performance

**Empfehlung:**
- Pagination (limit/offset oder cursor)
- FULLTEXT-Indizes für Name/Notizen/Email
- Indizes für "recent"-Listen

**Betroffene Dateien:**
- `src/TOM/Service/Org/Search/OrgSearchService.php`
- `src/TOM/Service/PersonService.php`
- Alle `list*()` Methoden

**Geschätzter Aufwand:** 2-3 Stunden

**Priorität:** Mittel (Performance-Optimierung)

---

### 8. Neo4j-Integration: Deprecation-Suppression ersetzen

**Problem:**
- Deprecation-Verhalten unterdrückt
- Echte Fehler können untergehen

**Empfehlung:**
- Library-Versionen pinnen
- Upgrade-Pfad definieren
- Deprecation gezielt fixen

**Betroffene Dateien:**
- `composer.json`
- Neo4j-Integration Code

**Geschätzter Aufwand:** 1 Stunde

**Priorität:** Niedrig (Wartbarkeit)

---

## P2 - Nice-to-have

### 9. Reproduzierbarkeit / Build

**Ziele:**
- `composer.json` + `composer.lock` vollständig
- `phpstan/psalm` für statische Analyse
- `php-cs-fixer` für Code-Style
- CI-Checks (GitHub Actions, GitLab CI, etc.)

**Geschätzter Aufwand:** 3-4 Stunden

**Priorität:** Niedrig (Quality of Life)

**Dateien:**
- `composer.json` (prüfen/ergänzen)
- `.github/workflows/ci.yml` (neu)
- `phpstan.neon` (neu)
- `.php-cs-fixer.php` (neu)

---

### 10. API-Kontrakte dokumentieren

**Ziele:**
- OpenAPI/Swagger Spezifikation
- Request/Response Beispiele
- Endpoint-Dokumentation

**Geschätzter Aufwand:** Ongoing (kontinuierlich)

**Priorität:** Niedrig (Dokumentation)

**Dateien:**
- `docs/api/openapi.yaml` (neu)
- `docs/api/endpoints.md` (neu)

---

## Implementierungsreihenfolge (Empfehlung)

### Kurzfristig (wenn Performance-Probleme auftreten)
1. **Pagination + Indizes** (P1.7)
   - Schneller ROI bei vielen Datensätzen
   - Verbessert User Experience

### Mittelfristig (Wartbarkeit)
2. **Neo4j Deprecation** (P1.8)
   - Verhindert zukünftige Probleme
   - Einfach umzusetzen

### Langfristig (Quality of Life)
3. **Reproduzierbarkeit / Build** (P2.9)
   - Verbessert Entwickler-Experience
   - Erleichtert Onboarding

4. **API-Dokumentation** (P2.10)
   - Kontinuierlich während Entwicklung
   - Hilft bei Integration

---

## Abgeschlossene Punkte

### P0 - Kritisch ✅
- ✅ **1. Auth-Zwang ohne "default_user" Fallback** (Phase 1)
- ✅ **2. CSRF-Schutz für Cookie-Session-Auth** (Phase 1)
- ✅ **3. APP_ENV Default "local" härten** (Phase 1)
- ✅ **4. Rollen-/Rechteprüfung: Hierarchie** (Phase 2.1)

### P1 - Sehr sinnvoll ✅
- ✅ **5. Input-Validation vereinheitlichen** (Phase 2.2)
- ✅ **6. Transaktionen bei Multi-Step Writes** (Phase 2.3)

---

## Dokumentation

- `docs/SECURITY-REVIEW-PRIORITIES.md` - Original Review
- `docs/SECURITY-PHASE1-COMPLETE.md` - Phase 1 Zusammenfassung
- `docs/SECURITY-PHASE2-COMPLETE.md` - Phase 2 Zusammenfassung
- `docs/SECURITY-TODOS.md` - Diese Datei (offene ToDos)

