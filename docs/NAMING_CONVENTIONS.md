# TOM3 - Tabellen-Namenskonventionen

## Übersicht

Die Tabellennamen in TOM3 folgen einem klaren Schema, das die Beziehung zwischen Tabellen widerspiegelt.

## Kategorien

### 1. **Master-/Referenzdaten** (ohne Präfix)
**Zweck:** Wiederverwendbare, unabhängige Daten, die von mehreren Entitäten referenziert werden können.

**Beispiele:**
- `industry` - Branchen-Masterdaten (WZ 2008)
- `market_segment` - Marktsegment-Masterdaten
- `location` - Standort-Masterdaten (mit Geokoordinaten)
- `customer_tier` - Tier-Masterdaten (A/B/C)

**Regel:** Kein Präfix, da diese Daten **nicht** an eine spezifische Entität gebunden sind.

---

### 2. **Org-spezifische Daten** (mit `org_` Präfix)
**Zweck:** Daten, die direkt zu einer Organisation gehören und nicht wiederverwendbar sind.

**Beispiele:**
- `org_address` - Adressen einer Organisation
- `org_alias` - Aliase einer Organisation (frühere Namen, Handelsnamen)
- `org_communication_channel` - Kommunikationskanäle (E-Mail, Telefon, Fax)
- `org_metrics` - Metriken (Umsatz, Mitarbeiter) einer Organisation
- `org_relation` - Relationen zwischen Organisationen (Mutter, Tochter, Partner)
- `org_strategic_flag` - Strategic Flag einer Organisation
- `org_account_team` - Account Team einer Organisation

**Regel:** `org_` Präfix, da diese Daten **nur** zu einer Organisation gehören.

---

### 3. **Verknüpfungstabellen** (mit Präfix der Hauptentität)
**Zweck:** M:N-Beziehungen zwischen Entitäten.

**Beispiele:**
- `org_location` - Verknüpft `org` ↔ `location` (mehrere Standorte pro Organisation)
- `org_market_segment` - Verknüpft `org` ↔ `market_segment` (mehrere Segmente pro Organisation)
- `org_customer_tier` - Verknüpft `org` ↔ `customer_tier` (zeitbezogen)
- `person_affiliation` - Verknüpft `person` ↔ `org` (zeitbezogen)
- `project_partner` - Verknüpft `project` ↔ `org`
- `project_stakeholder` - Verknüpft `project` ↔ `person`
- `project_case` - Verknüpft `project` ↔ `case_item`
- `user_org_access` - Verknüpft `user` ↔ `org` (Zugriffs-Tracking)

**Regel:** Präfix der **Hauptentität** (z.B. `org_`, `person_`, `project_`), gefolgt vom Namen der referenzierten Tabelle.

---

### 4. **Case-spezifische Daten** (mit `case_` Präfix)
**Zweck:** Daten, die direkt zu einem Case/Vorgang gehören.

**Beispiele:**
- `case_note` - Notizen zu einem Case
- `case_requirement` - Pflichtoutputs/Blocker eines Cases
- `case_handover` - Übergaben eines Cases
- `case_return` - Rückläufer eines Cases

**Regel:** `case_` Präfix, analog zu `org_` für Org-spezifische Daten.

---

### 5. **Workflow-Definitionen** (mit `_definition` Suffix oder Präfix)
**Zweck:** Konfigurationsdaten für Workflows.

**Beispiele:**
- `phase_definition` - Phase-Definitionen
- `phase_requirement_definition` - Checklisten pro Phase
- `engine_definition` - Engine-Definitionen

**Regel:** `_definition` Suffix oder Präfix, um zu zeigen, dass es sich um Konfigurationsdaten handelt.

---

### 6. **Kern-Entitäten** (ohne Präfix)
**Zweck:** Hauptentitäten des Systems.

**Beispiele:**
- `org` - Organisationen
- `person` - Personen/Kontakte
- `project` - Projekte
- `case_item` - Vorgänge/Cases
- `task` - Aufgaben

**Regel:** Kein Präfix, da es sich um die Hauptentitäten handelt.

---

### 7. **System-Tabellen** (ohne Präfix)
**Zweck:** System-interne Tabellen.

**Beispiele:**
- `outbox_event` - Event-Outbox für Synchronisation

**Regel:** Kein Präfix, da es sich um System-Tabellen handelt.

---

## Zusammenfassung

| Kategorie | Präfix | Beispiel | Begründung |
|-----------|--------|----------|------------|
| Master-/Referenzdaten | Kein Präfix | `industry`, `location` | Wiederverwendbar, unabhängig |
| Org-spezifische Daten | `org_` | `org_address`, `org_metrics` | Gehören nur zu einer Organisation |
| Verknüpfungstabellen | Präfix der Hauptentität | `org_location`, `person_affiliation` | M:N-Beziehung, Präfix zeigt Hauptentität |
| Case-spezifische Daten | `case_` | `case_note`, `case_requirement` | Gehören nur zu einem Case |
| Workflow-Definitionen | `_definition` | `phase_definition` | Konfigurationsdaten |
| Kern-Entitäten | Kein Präfix | `org`, `person`, `project` | Hauptentitäten |
| System-Tabellen | Kein Präfix | `outbox_event` | System-interne Tabellen |

---

## Besondere Fälle

### Direkte Felder in `org` statt Verknüpfungstabelle

**Beispiel:** `org.industry_main_uuid` und `org.industry_sub_uuid` statt `org_industry` Tabelle.

**Begründung:** 
- Eine Organisation hat **genau eine** Hauptklasse und **optional eine** Unterklasse
- Keine M:N-Beziehung nötig
- Reduziert Redundanz und Komplexität
- Direkte Felder sind performanter für 1:1-Beziehungen

**Regel:** Wenn eine Beziehung **1:1** oder **1:0..1** ist, verwende direkte Felder. Wenn eine Beziehung **M:N** ist, verwende eine Verknüpfungstabelle.

---

## Konsistenz-Checkliste

Bei der Erstellung neuer Tabellen:

- [ ] Ist es Master-/Referenzdaten? → **Kein Präfix**
- [ ] Gehört es nur zu einer Organisation? → **`org_` Präfix**
- [ ] Gehört es nur zu einem Case? → **`case_` Präfix**
- [ ] Ist es eine M:N-Verknüpfung? → **Präfix der Hauptentität** (z.B. `org_`, `person_`, `project_`)
- [ ] Ist es eine 1:1-Beziehung? → **Direktes Feld** in der Hauptentität (z.B. `org.industry_main_uuid`)
- [ ] Ist es eine Workflow-Definition? → **`_definition` Suffix**

---

## Beispiele für Inkonsistenzen (zu vermeiden)

❌ **Falsch:**
- `org_industry` (wurde entfernt, da redundant mit direkten Feldern)
- `industry_location` (sollte `location` sein, da es Masterdaten sind)
- `org_industry_master` (sollte `industry` sein, da es Masterdaten sind)

✅ **Richtig:**
- `industry` (Masterdaten, kein Präfix)
- `org_location` (Verknüpfungstabelle, `org_` Präfix)
- `org.industry_main_uuid` (direktes Feld für 1:1-Beziehung)





