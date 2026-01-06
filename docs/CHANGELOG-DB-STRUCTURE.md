# TOM3 - Datenbank-Struktur Änderungen

## Übersicht

Dieses Dokument listet wichtige Änderungen in der Datenbank-Struktur auf, die in der Dokumentation berücksichtigt werden müssen.

---

## Entfernte/Ersetzte Tabellen

### 1. `project_partner` → `project_party`
- **Status:** ✅ Ersetzt (Migration 022, 023)
- **Neue Tabelle:** `project_party`
- **Grund:** Explizite Rollen über ENUM (`party_role`) statt VARCHAR
- **Dokumentation:** 
  - ✅ `docs/NAMING_CONVENTIONS.md` - Aktualisiert
  - ✅ `docs/NEO4J-SYNC.md` - Aktualisiert
  - ⚠️ `docs/implementation/PERSON-PROJECT-INTEGRATION.md` - Veraltet, verweist auf V2

### 2. `project_stakeholder` → `project_person`
- **Status:** ✅ Ersetzt (Migration 022, 023)
- **Neue Tabelle:** `project_person`
- **Grund:** Explizite Zuordnung über `project_party_uuid` statt implizit über `person_affiliation`
- **Dokumentation:** 
  - ✅ `docs/NAMING_CONVENTIONS.md` - Aktualisiert
  - ✅ `docs/NEO4J-SYNC.md` - Aktualisiert

### 3. `org_industry` → Direkte Felder in `org`
- **Status:** ✅ Entfernt (Migration 009)
- **Grund:** Redundanz - direkte Felder (`industry_level1_uuid`, `industry_level2_uuid`, `industry_level3_uuid`) sind ausreichend
- **Dokumentation:** 
  - ✅ `docs/NAMING_CONVENTIONS.md` - Bereits dokumentiert als Beispiel für direkte Felder

### 4. `document_versions`
- **Status:** ⚠️ Optional/Auskommentiert (Migration 036)
- **Grund:** Versionierung wird über `documents` Tabelle selbst gehandhabt
- **Dokumentation:** 
  - ⚠️ `docs/implementation/document/DOCUMENT-UPLOAD-SERVICE-CONCEPT.md` - Erwähnt als optional

### 5. `party`
- **Status:** ❌ Existiert nicht (war Fehler im Cleanup-Skript)
- **Grund:** Tabelle wurde nie erstellt, war Fehler in Cleanup-Skript
- **Dokumentation:** Keine Dokumentation betroffen

---

## Tabellen-Namen (Plural vs. Singular)

### Wichtig: Plural-Formen
- ✅ `documents` (nicht `document`)
- ✅ `document_attachments` (nicht `document_attachment`)
- ✅ `duplicate_check_results` (nicht `duplicate_check_result`)

---

## Neue Tabellen

### Import-System
- ✅ `org_import_batch` - Import-Batches
- ✅ `org_import_staging` - Staging-Daten für Review
- ✅ `import_duplicate_candidates` - Duplikat-Kandidaten beim Import
- ✅ `duplicate_check_results` - Ergebnisse der täglichen Duplikaten-Prüfung

### Workflow-System
- ✅ `case_item` - Erweitert für Inside Sales (mit `stage`, `priority_stars`, `next_action_at`)

---

## Aktualisierte Dokumentation

### ✅ Aktualisiert:
- `docs/NAMING_CONVENTIONS.md` - Tabellennamen korrigiert
- `docs/NEO4J-SYNC.md` - Tabellennamen aktualisiert
- `docs/implementation/PERSON-PROJECT-INTEGRATION.md` - Hinweis auf V2 hinzugefügt

### ⚠️ Zu prüfen:
- `docs/implementation/document/DOCUMENT-UPLOAD-SERVICE-CONCEPT.md` - Erwähnt `document_versions` als optional
- `docs/analysen/refactoring/PERSON-MODULE-ANALYSIS.md` - Erwähnt alte Tabellen (historisch, kann bleiben)

---

## Migrationen

Die folgenden Migrationen beschreiben die Änderungen:
- **Migration 009:** Entfernt `org_industry`
- **Migration 022:** Migriert `project_partner` → `project_party`
- **Migration 023:** Löscht `project_partner` und `project_stakeholder`
- **Migration 036:** Erstellt `documents` und `document_attachments` (nicht `document_versions`)

---

## Checkliste für neue Dokumentation

Bei der Erstellung neuer Dokumentation:
- [ ] Verwende `project_party` statt `project_partner`
- [ ] Verwende `project_person` statt `project_stakeholder`
- [ ] Verwende `documents` (Plural) statt `document`
- [ ] Verwende `document_attachments` (Plural) statt `document_attachment`
- [ ] Verwende `duplicate_check_results` (Plural) statt `duplicate_check_result`
- [ ] Erwähne `org_industry` nur als historisches Beispiel (wurde entfernt)

---

*Letzte Aktualisierung: 2026-01-06*

