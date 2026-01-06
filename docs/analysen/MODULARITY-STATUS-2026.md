# Modularitäts-Status Analyse - Januar 2026

**Stand:** 2026-01-10  
**Status:** ⚠️ **KRITISCH** - Viele Dateien überschreiten die Limits deutlich

---

## Zusammenfassung

Das Modularitätskonzept wird **teilweise** angewendet, aber es gibt **erhebliche Abweichungen**:

### ✅ Positive Beispiele (modular)

1. **ORG-Modul** - Gut strukturiert:
   - `OrgService.php`: 469 Zeilen (OK, < 500)
   - Sub-Services in `Org/` Verzeichnis
   - Klare Domänen-Trennung (Core, Account, Search, Communication, etc.)

2. **PERS-Modul** - Gut strukturiert:
   - `PersonService.php`: 398 Zeilen (OK, < 500)
   - Sub-Services in `Person/` Verzeichnis
   - `PersonAffiliationService.php`: 310 Zeilen (OK)
   - `PersonRelationshipService.php`: 336 Zeilen (OK)

3. **WorkItem Backend** - Teilweise modular:
   - `WorkItemTimelineService.php`: 377 Zeilen (OK)
   - `WorkItemQueueService.php`: 305 Zeilen (OK)
   - Aber: `WorkItemService.php` nur 108 Zeilen (sehr gut!)

---

## ❌ Kritische Probleme

### JavaScript-Module (Limit: 400 Zeilen)

| Datei | Zeilen | % des Limits | Status |
|-------|--------|--------------|--------|
| `import.js` | **3.300** | **825%** | 🔴 KRITISCH |
| `inside-sales.js` | **2.019** | **505%** | 🔴 KRITISCH |
| ~~`import-old.js`~~ | ~~1.518~~ | ~~380%~~ | ✅ **GELÖSCHT** |
| `person-affiliation.js` | 656 | 164% | ⚠️ Warnung |
| `audit-trail.js` | 619 | 155% | ⚠️ Warnung |
| `person-relationship.js` | 612 | 153% | ⚠️ Warnung |
| `utils.js` | 600 | 150% | ⚠️ Warnung |
| `org-address.js` | 514 | 129% | ⚠️ Warnung |
| `document-search.js` | 487 | 122% | ⚠️ Warnung |
| `person-forms.js` | 433 | 108% | ⚠️ Warnung |
| `org-relation.js` | 421 | 105% | ⚠️ Warnung |

**Empfehlung:** Die beiden größten Dateien (`import.js`, `inside-sales.js`) sollten **dringend** aufgeteilt werden.

---

### PHP Services (Limit: 500 Zeilen)

| Datei | Zeilen | % des Limits | Status |
|-------|--------|--------------|--------|
| `DocumentService.php` | **1.407** | **281%** | 🔴 KRITISCH |
| `ImportTemplateService.php` | 744 | 149% | ⚠️ Warnung |
| `OrgImportService.php` | 731 | 146% | ⚠️ Warnung |
| `ImportStagingService.php` | 621 | 124% | ⚠️ Warnung |
| `ImportCommitService.php` | 569 | 114% | ⚠️ Warnung |
| `OrgVatService.php` | 477 | 95% | ✅ OK |
| `OrgService.php` | 469 | 94% | ✅ OK |
| `UserService.php` | 462 | 92% | ✅ OK |

**Empfehlung:** `DocumentService.php` sollte in mehrere Sub-Services aufgeteilt werden:
- `DocumentCrudService.php` (CRUD-Operationen)
- `DocumentVersionService.php` (Versionierung)
- `DocumentBlobService.php` (bereits vorhanden: 333 Zeilen)
- `DocumentAttachmentService.php` (Attachments)

---

### PHP API Endpoints (Limit: 200 Zeilen)

| Datei | Zeilen | % des Limits | Status |
|-------|--------|--------------|--------|
| `monitoring.php` | **1.577** | **789%** | 🔴 KRITISCH |
| `import.php` | **907** | **454%** | 🔴 KRITISCH |
| `orgs.php` | **600** | **300%** | 🔴 KRITISCH |
| `documents.php` | 567 | 284% | 🔴 KRITISCH |
| `api-security.php` | 274 | 137% | ⚠️ Warnung |
| `industries.php` | 267 | 134% | ⚠️ Warnung |
| `persons.php` | 248 | 124% | ⚠️ Warnung |

**Empfehlung:** 
- `monitoring.php` sollte in Sub-Dateien aufgeteilt werden
- `import.php` sollte als Router fungieren und Sub-Dateien einbinden
- `orgs.php` sollte als Router fungieren (analog zu geplantem `work-items.php`)

---

## Vergleich: ORG vs. PERS vs. Import vs. Inside Sales

### ✅ ORG-Modul (modular)

```
src/TOM/Service/
├── OrgService.php (469 Zeilen) ✅
└── Org/
    ├── Core/ (4 Services, alle < 400 Zeilen)
    ├── Account/ (2 Services)
    ├── Search/ (1 Service)
    ├── Communication/ (1 Service)
    └── Management/ (1 Service)

public/api/
└── orgs.php (600 Zeilen) ⚠️ Sollte Router sein
```

**Status:** ✅ Gut strukturiert, aber `orgs.php` sollte aufgeteilt werden

---

### ✅ PERS-Modul (modular)

```
src/TOM/Service/
├── PersonService.php (398 Zeilen) ✅
└── Person/
    ├── PersonAffiliationService.php (310 Zeilen) ✅
    └── PersonRelationshipService.php (336 Zeilen) ✅

public/api/
└── persons.php (248 Zeilen) ⚠️ Leicht über Limit
```

**Status:** ✅ Gut strukturiert, `persons.php` könnte optimiert werden

---

### ❌ Import-Modul (NICHT modular)

```
Frontend:
├── import.js (3.300 Zeilen) 🔴 KRITISCH
~~├── import-old.js (1.518 Zeilen)~~ ✅ **GELÖSCHT**  
~~└── import-new.js (880 Zeilen)~~ ✅ **GELÖSCHT**

Backend:
├── ImportTemplateService.php (744 Zeilen) ⚠️
├── OrgImportService.php (731 Zeilen) ⚠️
├── ImportStagingService.php (621 Zeilen) ⚠️
└── ImportCommitService.php (569 Zeilen) ⚠️

API:
└── import.php (907 Zeilen) 🔴 KRITISCH
```

**Status:** ❌ **Dringend Refactoring nötig**

**Empfehlung:**
- `import.js` aufteilen in:
  - `import-upload.js` (Upload & Mapping)
  - `import-industry-check.js` (Branchen-Prüfung)
  - `import-review.js` (Review & Commit)
  - `import-overview.js` (Übersicht)
- `import.php` als Router mit Sub-Dateien

---

### ❌ Inside Sales (teilweise modular)

```
Frontend:
└── inside-sales.js (2.019 Zeilen) 🔴 KRITISCH

Backend:
├── WorkItemService.php (108 Zeilen) ✅
└── WorkItem/
    ├── Core/WorkItemCrudService.php (105 Zeilen) ✅
    ├── Queue/WorkItemQueueService.php (305 Zeilen) ✅
    └── Timeline/WorkItemTimelineService.php (377 Zeilen) ✅

API:
├── work-items.php (179 Zeilen) ✅
├── queues.php (74 Zeilen) ✅
└── telephony.php (157 Zeilen) ✅
```

**Status:** ⚠️ Backend ist gut, Frontend ist zu groß

**Empfehlung:**
- `inside-sales.js` aufteilen in:
  - `inside-sales-queue.js` (Queue-Übersicht)
  - `inside-sales-dialer.js` (Dialer/Player)
  - `inside-sales-timeline.js` (Timeline-Management)
  - `inside-sales-disposition.js` (Disposition-Formulare)

---

## Empfohlene Refactoring-Prioritäten

### 🔴 Priorität 1 (KRITISCH - sofort)

1. **`import.js` (3.300 Zeilen)**
   - Aufteilen in 4-5 Module
   - Geschätzter Aufwand: 6-8 Stunden

2. **`inside-sales.js` (2.019 Zeilen)**
   - Aufteilen in 4 Module
   - Geschätzter Aufwand: 4-6 Stunden

3. **`monitoring.php` (1.577 Zeilen)**
   - Aufteilen in Sub-Dateien
   - Geschätzter Aufwand: 2-3 Stunden

4. **`DocumentService.php` (1.407 Zeilen)**
   - Aufteilen in Sub-Services
   - Geschätzter Aufwand: 4-6 Stunden

### ⚠️ Priorität 2 (Warnung - bald)

5. **`import.php` (907 Zeilen)**
   - Als Router umbauen
   - Geschätzter Aufwand: 2-3 Stunden

6. **`orgs.php` (600 Zeilen)**
   - Als Router umbauen
   - Geschätzter Aufwand: 2-3 Stunden

7. ~~**`import-old.js` (1.518 Zeilen)**~~ ✅ **GELÖSCHT**

### ✅ Priorität 3 (Optimierung - später)

8. **`person-affiliation.js` (656 Zeilen)**
9. **`audit-trail.js` (619 Zeilen)**
10. **`person-relationship.js` (612 Zeilen)**
11. **`utils.js` (600 Zeilen)**
12. **`org-address.js` (514 Zeilen)**
13. **`document-search.js` (487 Zeilen)**

---

## Best Practices (aus MODULAR-DEVELOPMENT-GUIDE.md)

### Dateigrößen-Limits

| Typ | Maximum | Warnung bei | Blockierung bei |
|-----|---------|-------------|-----------------|
| JavaScript Module | 400 Zeilen | 300 Zeilen | 600 Zeilen |
| PHP Service | 500 Zeilen | 400 Zeilen | 750 Zeilen |
| PHP API Endpoint | 200 Zeilen | 150 Zeilen | 300 Zeilen |
| PHP Infrastructure | 300 Zeilen | 250 Zeilen | 450 Zeilen |

### Aktion bei Überschreitung

1. ⚠️ **Warnung** bei Überschreitung des Limits
2. 🔴 **Blockierung** bei > 150% des Limits
3. 📋 **Refactoring-Plan** erstellen

---

## Fazit

**Das Modularitätskonzept wird teilweise angewendet**, aber es gibt **erhebliche Abweichungen**:

### ✅ Was gut funktioniert:
- ORG-Modul (Backend)
- PERS-Modul (Backend)
- WorkItem-Modul (Backend)

### ❌ Was dringend verbessert werden muss:
- Import-Modul (Frontend & Backend)
- Inside Sales (Frontend)
- Monitoring API
- Document Service

### 📋 Empfehlung:

1. **Sofort:** Die 4 kritischsten Dateien aufteilen
2. **Bald:** API-Endpoints als Router umbauen
3. **Später:** Kleinere Optimierungen

**Geschätzter Gesamtaufwand:** 20-30 Stunden für alle kritischen Refactorings

---

## Nächste Schritte

1. ✅ Diese Analyse erstellen
2. ⏳ Entscheidung: Welche Refactorings sollen zuerst angegangen werden?
3. ⏳ Detaillierte Refactoring-Pläne für Priorität 1 erstellen
4. ⏳ Schrittweise Umsetzung

