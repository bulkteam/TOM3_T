# TOM3 - Personen-Modul: Konzept-Analyse

## Überblick

Dieses Dokument analysiert das vorgeschlagene Personen-Modul-Konzept im Vergleich zur bestehenden TOM3-Struktur und gibt Empfehlungen für die Umsetzung.

## 1. Bestehende TOM3-Struktur

### 1.1 Aktuelle Tabellen

**`person` Tabelle:**
```sql
CREATE TABLE person (
    person_uuid CHAR(36) PRIMARY KEY,
    display_name TEXT NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(50),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**`person_affiliation` Tabelle:**
```sql
CREATE TABLE person_affiliation (
    person_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    kind VARCHAR(50) NOT NULL COMMENT 'employee | contractor | advisor | other',
    title TEXT,
    since_date DATE DEFAULT '1900-01-01',
    until_date DATE,
    PRIMARY KEY (person_uuid, org_uuid, kind, since_date),
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid) ON DELETE CASCADE,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid) ON DELETE CASCADE
);
```

**Bestehende Features:**
- ✅ UUID-basierte IDs (konsistent mit Neo4j)
- ✅ Event-basierte Synchronisation nach Neo4j
- ✅ Zeitliche Historie (since_date, until_date)
- ✅ Mehrfach-Zugehörigkeiten möglich (via PRIMARY KEY)
- ✅ Verschiedene `kind`-Typen (employee, contractor, advisor, other)

**Limitationen:**
- ❌ Keine Trennung von first_name/last_name
- ❌ Keine Org-Einheiten (Abteilungen)
- ❌ Keine Reporting-Lines
- ❌ Keine Mandate/Rollen (Geschäftsführer, etc.)
- ❌ Keine Beteiligungen/Anteile
- ❌ Keine Person↔Person Beziehungen
- ❌ Keine Soft-Delete (is_active)
- ❌ Keine zusätzlichen Felder (LinkedIn, Notes)

## 2. Vorgeschlagenes Konzept - Analyse

### 2.1 Kernideen - Bewertung

#### A) Person ist unabhängig von Firma ✅ **SEHR GUT**

**Bewertung:** ✅ **Übernehmen**
- Entspricht bereits der bestehenden Struktur
- `person_affiliation` ist bereits getrennt
- Ermöglicht Mehrfach-Zugehörigkeiten

**Anpassung für TOM3:**
- Bestehende Struktur ist bereits korrekt
- `person_affiliation` entspricht dem Konzept

#### B) "Arbeitet bei" als eigene Entität ✅ **SEHR GUT**

**Bewertung:** ✅ **Übernehmen mit Anpassungen**

**Vergleich:**
- **Bestehend:** `person_affiliation` (einfacher, weniger Felder)
- **Vorgeschlagen:** `employments` (detaillierter, mehr Felder)

**Empfehlung:**
- **Option 1 (Empfohlen):** `person_affiliation` erweitern statt ersetzen
  - Vorteil: Keine Breaking Changes
  - Migration einfacher
  - Bestehende Daten bleiben erhalten
  
- **Option 2:** Neue `employment` Tabelle + Migration
  - Vorteil: Sauberer Start
  - Nachteil: Migration komplexer

**Neue Felder, die hinzugefügt werden sollten:**
- `org_unit_id` (neue Tabelle `org_unit` nötig)
- `job_function` (fachliche Funktion)
- `seniority` (intern, junior, mid, senior, lead, head, vp, cxo)
- `is_primary` (Hauptarbeitgeber)

#### C) Beziehungen als generisches "Relationship"-Objekt ✅ **SEHR GUT**

**Bewertung:** ✅ **Neu implementieren**

**Empfehlung:**
- Neue Tabelle `person_relationship` erstellen
- Flexibles System für verschiedene Beziehungstypen
- Wichtig für Graph-Analysen in Neo4j

#### D) "Prozessrollen" kontextabhängig ✅ **SEHR GUT - ABER ERWEITERN**

**Bewertung:** ⚠️ **Bestehend funktioniert, aber vorgeschlagenes Modell ist besser**

**Bestehende Struktur (TOM3):**
- ✅ `project_partner` - verknüpft Organisationen mit Projekten (delivers, advises, participates)
- ✅ `project_stakeholder` - verknüpft Personen mit Projekten (Decider, Influencer, User, etc.)
- ❌ **Problem:** Keine explizite Verknüpfung zwischen Person und Projektpartei

**Vorgeschlagenes Modell (Verbesserung):**
- ✅ `project_parties` - verknüpft Organisationen mit Projekten (mit `party_role` ENUM)
- ✅ `project_people` - verknüpft Personen mit Projekten **UND** explizit mit `project_party_id`
- ✅ **Vorteil:** Explizite Zuordnung Person ↔ Projektpartei (Firma + Rolle)

**Wichtiger Unterschied:**

| Aspekt | Bestehend | Vorgeschlagen | Bewertung |
|--------|-----------|---------------|-----------|
| **Firmen-Verknüpfung** | `project_partner` (relation VARCHAR) | `project_parties` (party_role ENUM) | ⚠️ **Verbessern** (ENUM ist sauberer) |
| **Person-Verknüpfung** | `project_stakeholder` (nur project + person) | `project_people` (project + person + **project_party_id**) | ✅ **WICHTIG: Explizite Zuordnung** |
| **Zuordnung Person ↔ Firma** | Implizit über `person_affiliation` | Explizit über `project_party_id` | ✅ **Viel besser!** |

**Empfehlung: Vorgeschlagenes Modell übernehmen**

**Gründe:**
1. **Explizite Zuordnung:** `project_party_id` macht klar, in welcher Rolle die Firma am Projekt beteiligt ist
2. **Mehrfach-Rollen:** Firma kann als Supplier UND Consultant am gleichen Projekt beteiligt sein (verschiedene `project_parties` Einträge)
3. **Validierung:** Kann prüfen, ob Person aktives Employment bei der Firma hat
4. **Bessere Abfragen:** "Alle Personen, die für Projektpartei X (Firma Y als Consultant) arbeiten"

**Migration-Strategie:**

**Option 1 (Empfohlen):** Neue Tabellen erstellen, bestehende parallel laufen lassen
```sql
-- Neue Tabellen (vorgeschlagenes Modell)
CREATE TABLE project_parties (...);
CREATE TABLE project_people (...);

-- Bestehende Tabellen bleiben (für Migration/Backward Compatibility)
-- Später: Daten migrieren, dann alte Tabellen entfernen
```

**Option 2:** Bestehende Tabellen erweitern
```sql
-- project_partner erweitern (party_role als ENUM)
ALTER TABLE project_partner MODIFY COLUMN relation ENUM(...);

-- project_stakeholder erweitern (project_party_id hinzufügen)
ALTER TABLE project_stakeholder 
    ADD COLUMN project_party_id CHAR(36) NULL,
    ADD FOREIGN KEY (project_party_id) REFERENCES project_partner(...);
```

**Problem bei Option 2:** `project_partner` hat Composite Primary Key, `project_party_id` müsste auf eine eindeutige ID verweisen.

**Empfehlung:** Option 1 (neue Tabellen), da:
- Sauberer Start
- Keine Breaking Changes
- Bestehende Daten bleiben erhalten
- Migration schrittweise möglich

**WICHTIG: Vorgeschlagenes Modell übernehmen!**

Das vorgeschlagene Modell mit `project_party` und `project_person` (mit `project_party_uuid`) ist **deutlich besser** als die bestehende Struktur, weil:

1. **Explizite Zuordnung:** `project_party_uuid` macht klar, in welcher Rolle die Firma am Projekt beteiligt ist
2. **Mehrfach-Rollen:** Firma kann als Supplier UND Consultant am gleichen Projekt beteiligt sein (verschiedene `project_party` Einträge)
3. **Validierung:** Einfache Prüfung, ob Person aktives Employment bei der Firma hat
4. **Bessere Abfragen:** "Alle Personen, die für Projektpartei X (Firma Y als Consultant) arbeiten"
5. **Sauberer:** ENUM statt VARCHAR für Rollen

**Siehe auch:** `docs/PERSON-PROJECT-INTEGRATION-V2.md` für vollständige Dokumentation des verbesserten Modells.

### 2.2 Datenmodell-Vergleich

#### Personen-Tabelle

| Feld | Bestehend | Vorgeschlagen | Empfehlung |
|------|-----------|---------------|------------|
| ID | `person_uuid` (CHAR(36)) | `id` (BIGINT) | ✅ **Bestehend beibehalten** (UUID für Neo4j-Sync) |
| Name | `display_name` (TEXT) | `first_name`, `last_name`, `display_name` (generated) | ⚠️ **Erweitern** (first_name, last_name hinzufügen) |
| Email | `email` (VARCHAR(255)) | `email` (VARCHAR(255)) | ✅ **Bestehend beibehalten** |
| Phone | `phone` (VARCHAR(50)) | `phone` (VARCHAR(64)) | ✅ **Bestehend beibehalten** |
| LinkedIn | ❌ | `linkedin_url` (VARCHAR(512)) | ✅ **Neu hinzufügen** |
| Notes | ❌ | `notes` (TEXT) | ✅ **Neu hinzufügen** |
| is_active | ❌ | `is_active` (TINYINT(1)) | ✅ **Neu hinzufügen** (Soft-Delete) |
| archived_at | ❌ | `archived_at` (DATETIME) | ✅ **Neu hinzufügen** (Soft-Delete Timestamp) |
| salutation | ❌ | `salutation` (VARCHAR(20)) | ✅ **Neu hinzufügen** (Anrede) |
| title | ❌ | `title` (VARCHAR(100)) | ✅ **Neu hinzufügen** (Titel) |
| mobile_phone | ❌ | `mobile_phone` (VARCHAR(50)) | ✅ **Neu hinzufügen** (getrennt von phone) |

**Empfehlung für `person` Tabelle:**
```sql
-- Migration: Erweitern statt ersetzen
ALTER TABLE person 
    ADD COLUMN first_name VARCHAR(120) NULL AFTER person_uuid,
    ADD COLUMN last_name VARCHAR(120) NULL AFTER first_name,
    ADD COLUMN salutation VARCHAR(20) NULL COMMENT 'Herr | Frau | Dr. | Prof. | etc.' AFTER last_name,
    ADD COLUMN title VARCHAR(100) NULL COMMENT 'Dr. | Prof. | etc.' AFTER salutation,
    ADD COLUMN mobile_phone VARCHAR(50) NULL COMMENT 'Mobiltelefon' AFTER phone,
    ADD COLUMN linkedin_url VARCHAR(512) NULL,
    ADD COLUMN notes TEXT NULL,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN archived_at DATETIME NULL,
    MODIFY COLUMN display_name VARCHAR(255) GENERATED ALWAYS AS (
        TRIM(CONCAT(
            COALESCE(salutation, ''), ' ',
            COALESCE(title, ''), ' ',
            COALESCE(first_name, ''), ' ',
            COALESCE(last_name, '')
        ))
    ) STORED;

-- Index für Suche
CREATE INDEX idx_person_name ON person(last_name, first_name);
CREATE INDEX idx_person_active ON person(is_active);
```

#### Org-Einheiten (Neue Tabelle)

**Bewertung:** ✅ **Neu implementieren**

**Empfehlung:**
- Neue Tabelle `org_unit` (nicht `company_org_units`)
- Konsistent mit TOM3-Naming (`org` statt `company`)
- UUID-basiert für Neo4j-Sync

```sql
CREATE TABLE org_unit (
    org_unit_uuid CHAR(36) PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    parent_org_unit_uuid CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid),
    FOREIGN KEY (parent_org_unit_uuid) REFERENCES org_unit(org_unit_uuid)
);
```

#### Employment / Affiliation

**Vergleich:**

| Aspekt | Bestehend (`person_affiliation`) | Vorgeschlagen (`employments`) | Empfehlung |
|--------|----------------------------------|------------------------------|------------|
| Struktur | Einfach, funktional | Detailliert, umfassend | ⚠️ **Erweitern** |
| Org-Einheit | ❌ | ✅ `org_unit_id` | ✅ **Hinzufügen** |
| Job-Titel | ✅ `title` | ✅ `job_title` | ✅ **Umbenennen** (konsistenter) |
| Funktion | ❌ | ✅ `job_function` | ✅ **Hinzufügen** |
| Seniority | ❌ | ✅ `seniority` | ✅ **Hinzufügen** |
| Primary | ❌ | ✅ `is_primary` | ✅ **Hinzufügen** |

**Empfehlung:**
```sql
-- Migration: person_affiliation erweitern
ALTER TABLE person_affiliation
    ADD COLUMN org_unit_uuid CHAR(36) NULL AFTER org_uuid,
    ADD COLUMN job_function VARCHAR(255) NULL,
    ADD COLUMN seniority ENUM('intern','junior','mid','senior','lead','head','vp','cxo') NULL,
    ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0,
    MODIFY COLUMN title VARCHAR(255) NULL,
    ADD FOREIGN KEY (org_unit_uuid) REFERENCES org_unit(org_unit_uuid);
```

#### Reporting Lines (Neue Tabelle)

**Bewertung:** ✅ **Neu implementieren**

**Empfehlung:**
- Neue Tabelle `person_affiliation_reporting`
- Verknüpft `person_affiliation` (nicht direkt Person)
- Ermöglicht Historie von Reporting-Changes

```sql
CREATE TABLE person_affiliation_reporting (
    reporting_uuid CHAR(36) PRIMARY KEY,
    affiliation_uuid CHAR(36) NOT NULL, -- Verweis auf person_affiliation
    manager_affiliation_uuid CHAR(36) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (affiliation_uuid) REFERENCES person_affiliation(...),
    FOREIGN KEY (manager_affiliation_uuid) REFERENCES person_affiliation(...)
);
```

**Problem:** `person_affiliation` hat Composite Primary Key. Lösung: UUID für Reporting hinzufügen oder Composite Key verwenden.

#### Mandate / Organfunktionen (Neue Tabelle)

**Bewertung:** ✅ **Neu implementieren**

**Empfehlung:**
- Neue Tabelle `person_org_role`
- Getrennt von `person_affiliation` (kann parallel existieren)
- UUID-basiert

```sql
CREATE TABLE person_org_role (
    role_uuid CHAR(36) PRIMARY KEY,
    person_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    role_type ENUM('ceo','cfo','cto','managing_director','board_member','authorized_signatory','advisor','owner_rep') NOT NULL,
    role_title VARCHAR(255) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid),
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid)
);
```

#### Beteiligungen / Anteile (Neue Tabelle)

**Bewertung:** ✅ **Neu implementieren**

**Empfehlung:**
- Neue Tabelle `person_org_shareholding`
- UUID-basiert

```sql
CREATE TABLE person_org_shareholding (
    shareholding_uuid CHAR(36) PRIMARY KEY,
    person_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    percent DECIMAL(6,3) NULL,
    shares_count BIGINT NULL,
    voting_percent DECIMAL(6,3) NULL,
    is_direct TINYINT(1) NOT NULL DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    source VARCHAR(512) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid),
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid)
);
```

#### Person↔Person Beziehungen (Neue Tabelle)

**Bewertung:** ✅ **Neu implementieren**

**Empfehlung:**
- Neue Tabelle `person_relationship`
- UUID-basiert
- Wichtig für Neo4j Graph-Analysen

```sql
CREATE TABLE person_relationship (
    relationship_uuid CHAR(36) PRIMARY KEY,
    person_a_uuid CHAR(36) NOT NULL,
    person_b_uuid CHAR(36) NOT NULL,
    relation_type ENUM('knows','friendly','adversarial','advisor_of','mentor_of','former_colleague','influences','gatekeeper_for') NOT NULL,
    direction ENUM('a_to_b','b_to_a','bidirectional') NOT NULL DEFAULT 'bidirectional',
    strength TINYINT NULL, -- 1..10
    confidence TINYINT NULL, -- 1..10
    context_org_uuid CHAR(36) NULL,
    context_project_uuid CHAR(36) NULL, -- später
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_a_uuid) REFERENCES person(person_uuid),
    FOREIGN KEY (person_b_uuid) REFERENCES person(person_uuid),
    FOREIGN KEY (context_org_uuid) REFERENCES org(org_uuid)
);
```

## 3. Anpassungen für TOM3

### 3.1 Naming Conventions

**Vorgeschlagen:** `companies`, `people`, `employments`  
**TOM3:** `org`, `person`, `person_affiliation`

**Empfehlung:**
- ✅ **TOM3-Naming beibehalten** (konsistent mit bestehender Struktur)
- ✅ **UUID-basiert** (für Neo4j-Sync)
- ✅ **Plural für Tabellen** (person, nicht people)

### 3.2 ID-Strategie

**Vorgeschlagen:** `BIGINT AUTO_INCREMENT`  
**TOM3:** `CHAR(36) UUID`

**Empfehlung:**
- ✅ **UUID beibehalten** (bereits implementiert, Neo4j-Sync funktioniert)
- ✅ **Konsistenz** mit bestehenden Tabellen

### 3.3 Migration-Strategie

**Phase 1: Erweiterung (Keine Breaking Changes)**
1. `person` Tabelle erweitern (first_name, last_name, linkedin_url, notes, is_active)
2. `person_affiliation` erweitern (org_unit_uuid, job_function, seniority, is_primary)
3. Neue Tabellen: `org_unit`, `person_org_role`, `person_org_shareholding`, `person_relationship`

**Phase 2: Reporting Lines**
- `person_affiliation_reporting` (nach Phase 1, da abhängig von erweitertem `person_affiliation`)

**Phase 3: Neo4j-Sync erweitern**
- Neue Relationship-Typen in Neo4j
- Reporting-Lines als Graph
- Person↔Person Beziehungen

### 3.4 Neo4j-Sync Anpassungen

**Bestehend:**
- ✅ Person → Neo4j Node
- ✅ PersonAffiliation → AFFILIATED_WITH Relationship

**Neu hinzufügen:**
- `org_unit` → Neo4j Node
- `person_org_role` → HAS_ROLE Relationship
- `person_org_shareholding` → OWNS Relationship
- `person_relationship` → RELATES Relationship
- `person_affiliation_reporting` → REPORTS_TO Relationship

## 4. Empfehlungen

### 4.1 Was übernehmen?

✅ **Übernehmen:**
1. **Kernideen** (Person unabhängig, Employment als Entität, etc.)
2. **Org-Einheiten** (neue Tabelle `org_unit`)
3. **Reporting Lines** (neue Tabelle)
4. **Mandate/Rollen** (neue Tabelle `person_org_role`)
5. **Beteiligungen** (neue Tabelle `person_org_shareholding`)
6. **Person↔Person Beziehungen** (neue Tabelle `person_relationship`)
7. **Erweiterte Felder** (first_name, last_name, linkedin_url, notes, is_active)
8. **Seniority, Job-Function, is_primary** in `person_affiliation`

### 4.2 Was anpassen?

⚠️ **Anpassen:**
1. **Naming:** `companies` → `org`, `people` → `person`, `employments` → `person_affiliation`
2. **IDs:** `BIGINT` → `CHAR(36) UUID`
3. **Migration:** Erweitern statt ersetzen (keine Breaking Changes)
4. **Composite Keys:** `person_affiliation` hat bereits Composite Key, Reporting muss angepasst werden

### 4.3 Was anders/besser lösen?

🔧 **Verbesserungen:**

1. **Reporting Lines:**
   - **Problem:** `person_affiliation` hat Composite Primary Key
   - **Lösung:** UUID für Reporting hinzufügen oder Composite Key in Reporting-Tabelle verwenden
   - **Alternative:** `person_affiliation` um `affiliation_uuid` erweitern (neuer Primary Key)

2. **Display Name:**
   - **Vorgeschlagen:** Generated Column
   - **Bestehend:** Manuell gepflegt
   - **Empfehlung:** Generated Column nur wenn first_name/last_name vorhanden, sonst Fallback auf display_name

3. **Soft Delete:**
   - **Vorgeschlagen:** `is_active`
   - **Bestehend:** Kein Soft-Delete
   - **Empfehlung:** `is_active` hinzufügen, Standard = 1

4. **Fulltext-Suche:**
   - **Vorgeschlagen:** FULLTEXT Index
   - **Bestehend:** Kein Fulltext
   - **Empfehlung:** Für später (wenn Performance-Probleme auftreten)

5. **Projekt-Rollen:**
   - **Vorgeschlagen:** Später in `project_person_role`
   - **Bestehend:** ✅ `project_stakeholder` existiert bereits mit `role`, `influence`, `decision_power`
   - **Empfehlung:** Bestehende `project_stakeholder` ist bereits gut - keine Änderung nötig
   - **Hinweis:** Konzept passt - Rollen sind kontextabhängig (pro Projekt)

## 5. Implementierungsreihenfolge

### Phase 1: Grundlagen (MVP)
1. ✅ `person` Tabelle erweitern
2. ✅ `org_unit` Tabelle erstellen
3. ✅ `person_affiliation` erweitern
4. ✅ UI für Person-Suche/Liste
5. ✅ UI für Person-Detail mit Affiliations

### Phase 2: Erweiterte Features
1. ✅ `person_org_role` (Mandate)
2. ✅ `person_org_shareholding` (Beteiligungen)
3. ✅ UI für Rollen und Beteiligungen

### Phase 3: Beziehungen
1. ✅ `person_relationship` (Person↔Person)
2. ✅ UI für Beziehungen
3. ✅ Neo4j-Sync für Beziehungen

### Phase 4: Reporting
1. ✅ `person_affiliation_reporting`
2. ✅ UI für Team-Hierarchie
3. ✅ Neo4j-Sync für Reporting-Lines

## 6. Wichtige Design-Entscheidungen

### 6.1 Soft-Delete Strategie

**Frage:** Wird eine Person gelöscht oder nur deaktiviert?

**Antwort:** ✅ **Nur deaktiviert (Soft-Delete)**

**Begründung:**
- Keine Datenverluste
- Historie bleibt erhalten
- Beziehungen bleiben erhalten
- Reporting bleibt möglich

**Implementierung:**
```sql
ALTER TABLE person 
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN archived_at DATETIME NULL;

-- Standard: is_active = 1 (aktiv)
-- Deaktiviert: is_active = 0, archived_at = NOW()
```

**Verhalten:**
- Deaktivierte Personen werden in Standard-Listen ausgeblendet
- In Suchfunktionen optional einblendbar ("Auch inaktive anzeigen")
- Alle Beziehungen, Affiliations, Rollen bleiben erhalten
- Historie bleibt vollständig

### 6.2 Personen, die aus Unternehmen ausscheiden

**Frage:** Was passiert mit Personen, die aus Unternehmen ausscheiden?

**Antwort:** ✅ **Historie beibehalten, Status aktualisieren**

**Strategie:**

1. **`person_affiliation` bleibt erhalten:**
   - `until_date` wird gesetzt (Austrittsdatum)
   - `is_active` in Affiliation kann optional hinzugefügt werden
   - Historie bleibt vollständig

2. **Person selbst bleibt aktiv:**
   - Person kann weiterhin bei anderen Unternehmen tätig sein
   - Person kann später wieder beim gleichen Unternehmen arbeiten
   - Nur wenn Person komplett aus System ausscheidet → `is_active = 0`

3. **Beziehungen bleiben erhalten:**
   - Person↔Person Beziehungen bleiben
   - Rollen/Mandate können end_date bekommen
   - Beteiligungen können end_date bekommen

**Beispiel:**
```sql
-- Person scheidet aus Firma aus
UPDATE person_affiliation 
SET until_date = '2025-12-31'
WHERE person_uuid = '...' AND org_uuid = '...' AND until_date IS NULL;

-- Person bleibt aktiv (kann bei anderen Firmen tätig sein)
-- Person kann später wieder bei gleicher Firma arbeiten (neue Affiliation)
```

**UI-Verhalten:**
- Aktuelle Affiliations: `until_date IS NULL`
- Historische Affiliations: `until_date IS NOT NULL`
- Beide können angezeigt werden (Tabs: "Aktuell" / "Historie")

### 6.3 Mindest-Felder für Person

**Vorgeschlagene Grunddaten:**
- ✅ Name (last_name)
- ✅ Vorname (first_name)
- ✅ Anrede (salutation) - **NEU**
- ✅ Titel (title) - **NEU**
- ✅ Funktion (job_function) - in `person_affiliation`
- ✅ eMail (email)
- ✅ Mobil (mobile_phone) - **NEU, getrennt von phone**
- ✅ Telefon mit Durchwahl (phone)
- ✅ Status (is_active)

**Erweiterte Felder:**
- LinkedIn URL
- Notizen
- Weitere Kontaktdaten (optional)

**Empfehlung für `person` Tabelle:**
```sql
CREATE TABLE person (
    person_uuid CHAR(36) PRIMARY KEY,
    
    -- Name
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    salutation VARCHAR(20) NULL COMMENT 'Herr | Frau | Dr. | Prof. | etc.',
    title VARCHAR(100) NULL COMMENT 'Dr. | Prof. | etc.',
    display_name VARCHAR(255) GENERATED ALWAYS AS (
        TRIM(CONCAT(
            COALESCE(salutation, ''), ' ',
            COALESCE(title, ''), ' ',
            COALESCE(first_name, ''), ' ',
            COALESCE(last_name, '')
        ))
    ) STORED,
    
    -- Kontakt
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL COMMENT 'Telefon mit Durchwahl',
    mobile_phone VARCHAR(50) NULL COMMENT 'Mobiltelefon',
    
    -- Zusätzlich
    linkedin_url VARCHAR(512) NULL,
    notes TEXT NULL,
    
    -- Status
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    
    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_person_email (email),
    KEY idx_person_name (last_name, first_name),
    KEY idx_person_active (is_active)
);
```

**Empfehlung für `person_affiliation` (Funktion):**
```sql
-- Funktion gehört zu Affiliation, nicht zu Person
ALTER TABLE person_affiliation
    ADD COLUMN job_function VARCHAR(255) NULL COMMENT 'Einkauf | Technik | etc.';
```

## 7. Offene Fragen

1. **Reporting-Lines Composite Key:**
   - Wie mit Composite Primary Key in `person_affiliation` umgehen?
   - Option A: UUID für `person_affiliation` hinzufügen
   - Option B: Composite Key in Reporting-Tabelle verwenden

2. **Org-Unit Hierarchie:**
   - Soll `org_unit` rekursiv sein (parent_org_unit_uuid)?
   - Wie tief darf die Hierarchie sein?

3. **Person-Suche:**
   - Soll Fulltext-Suche sofort implementiert werden?
   - Oder erst bei Performance-Problemen?

4. **Migration bestehender Daten:**
   - Wie `display_name` in `first_name`/`last_name` aufteilen?
   - Automatisch (Parsing) oder manuell?

5. **Anrede (salutation):**
   - Soll es ein ENUM sein oder VARCHAR?
   - Welche Werte: 'Herr', 'Frau', 'Dr.', 'Prof.', 'Prof. Dr.'?
   - Oder freies Feld?

6. **Titel vs. Anrede:**
   - Soll `title` akademische Titel enthalten (Dr., Prof.)?
   - Oder nur `salutation`?
   - Oder beides getrennt?

## 7. Antworten auf wichtige Fragen

### 7.1 Soft-Delete: Person wird nicht gelöscht, nur deaktiviert

**✅ RICHTIG:** Personen werden **nur deaktiviert**, nicht gelöscht.

**Implementierung:**
- `is_active = 0` (Person deaktiviert)
- `archived_at = NOW()` (Zeitstempel der Deaktivierung)
- Alle Daten bleiben erhalten:
  - Affiliations (historisch)
  - Beziehungen
  - Rollen/Mandate
  - Beteiligungen
  - Reporting-Lines

**Vorteile:**
- ✅ Keine Datenverluste
- ✅ Vollständige Historie
- ✅ Reporting bleibt möglich
- ✅ Beziehungen bleiben erhalten

**UI-Verhalten:**
- Standard-Listen zeigen nur aktive Personen (`is_active = 1`)
- Option "Auch inaktive anzeigen" für Suche
- Detailansicht zeigt auch inaktive Personen (mit Hinweis)

### 7.2 Personen, die aus Unternehmen ausscheiden

**Was passiert:**
1. **`person_affiliation` wird aktualisiert:**
   - `until_date` wird gesetzt (Austrittsdatum)
   - Affiliation bleibt in Datenbank (Historie)
   - Person kann später wieder bei gleicher Firma arbeiten (neue Affiliation)

2. **Person selbst bleibt aktiv:**
   - `is_active = 1` (Person ist weiterhin aktiv)
   - Person kann bei anderen Unternehmen tätig sein
   - Nur wenn Person komplett aus System ausscheidet → `is_active = 0`

3. **Beziehungen bleiben erhalten:**
   - Person↔Person Beziehungen bleiben
   - Rollen/Mandate können `end_date` bekommen
   - Beteiligungen können `end_date` bekommen

**Beispiel:**
```sql
-- Person scheidet am 31.12.2025 aus Firma aus
UPDATE person_affiliation 
SET until_date = '2025-12-31'
WHERE person_uuid = '...' 
  AND org_uuid = '...' 
  AND until_date IS NULL;

-- Person bleibt aktiv (is_active = 1)
-- Person kann später wieder bei gleicher Firma arbeiten (neue Affiliation mit neuem since_date)
```

**UI-Verhalten:**
- **Aktuelle Affiliations:** `until_date IS NULL` (Tab "Aktuell")
- **Historische Affiliations:** `until_date IS NOT NULL` (Tab "Historie")
- Beide Tabs können angezeigt werden

### 7.3 Rollen, Mandate und Beteiligungen

**Frage 1:** Kann eine Person verschiedene Rollen bei verschiedenen Firmen haben?

**✅ JA - Mehrfach-Rollen sind möglich und explizit vorgesehen:**

Eine Person kann **gleichzeitig**:

1. **Bei mehreren Firmen arbeiten** (via `person_affiliation`)
   - Beispiel: Person arbeitet bei Firma A als Einkäufer UND bei Firma B als Berater

2. **Geschäftsführer mehrerer Firmen sein** (via `person_org_role`)
   - Beispiel: Person ist Geschäftsführer von Firma A UND Firma B
   - `role_type = 'managing_director'` für beide Firmen

3. **Gründer/Inhaber mehrerer Firmen sein** (via `person_org_role` + `person_org_shareholding`)
   - Beispiel: Person ist Gründer von Firma A (100% Anteile) UND Inhaber von Firma B (50% Anteile)
   - `role_type = 'owner_rep'` + `shareholding` mit `percent = 100` bzw. `50`

4. **Beteiligungen an mehreren Firmen haben** (via `person_org_shareholding`)
   - Beispiel: Person hält 25% an Firma A, 10% an Firma B, 5% an Firma C

5. **Kombinationen:**
   - Person kann bei Firma A arbeiten UND Geschäftsführer von Firma B sein
   - Person kann Inhaber von Firma A sein UND bei Firma B arbeiten
   - Person kann Geschäftsführer von Firma A sein UND Beteiligung an Firma B haben

**Implementierung:**

```sql
-- Beispiel: Person ist Geschäftsführer von 2 Firmen
INSERT INTO person_org_role (role_uuid, person_uuid, org_uuid, role_type, role_title)
VALUES 
    (UUID(), 'person-123', 'org-firma-a', 'managing_director', 'Geschäftsführer'),
    (UUID(), 'person-123', 'org-firma-b', 'managing_director', 'Geschäftsführer');

-- Beispiel: Person ist Inhaber/Gründer von 2 Firmen
INSERT INTO person_org_shareholding (shareholding_uuid, person_uuid, org_uuid, percent, is_direct)
VALUES 
    (UUID(), 'person-123', 'org-firma-a', 100.000, 1), -- 100% Inhaber
    (UUID(), 'person-123', 'org-firma-b', 50.000, 1);  -- 50% Inhaber

-- Beispiel: Person arbeitet bei Firma A UND ist Geschäftsführer von Firma B
INSERT INTO person_affiliation (person_uuid, org_uuid, kind, title)
VALUES ('person-123', 'org-firma-a', 'employee', 'Einkäufer');

INSERT INTO person_org_role (role_uuid, person_uuid, org_uuid, role_type, role_title)
VALUES (UUID(), 'person-123', 'org-firma-b', 'managing_director', 'Geschäftsführer');
```

**UI-Verhalten:**
- Person-Detail zeigt alle Rollen, Affiliations und Beteiligungen
- Gruppiert nach Firma
- Zeitliche Historie für alle (start_date, end_date)

### 7.4 Person↔Person Beziehungen

**Frage 2:** Wie definiert sich das Verhältnis zwischen Personen (gleiche Firma oder außerhalb)?

**✅ Flexibles Beziehungssystem:**

Beziehungen werden über `person_relationship` modelliert und können:

1. **Innerhalb gleicher Firma** (via `context_org_uuid`)
   - Beispiel: "Person A kennt Person B bei Firma X"
   - `context_org_uuid = 'org-firma-x'`

2. **Außerhalb / Allgemein** (ohne Kontext)
   - Beispiel: "Person A kennt Person B" (allgemein)
   - `context_org_uuid = NULL`

3. **Projekt-bezogen** (später via `context_project_uuid`)
   - Beispiel: "Person A arbeitet mit Person B in Projekt Y zusammen"
   - `context_project_uuid = 'project-y'` (später)

**Beziehungstypen:**

| Typ | Beschreibung | Beispiel |
|-----|--------------|----------|
| `knows` | Kennt die Person | "Habe auf Konferenz kennengelernt" |
| `friendly` | Freundliche Beziehung | "Gute Zusammenarbeit" |
| `adversarial` | Gegnerische Beziehung | "Konflikt in der Vergangenheit" |
| `advisor_of` | Berät die Person | "Ist Berater für Person B" |
| `mentor_of` | Mentor | "Ist Mentor von Person B" |
| `former_colleague` | Ehemaliger Kollege | "War früher Kollege bei Firma X" |
| `influences` | Beeinflusst | "Beeinflusst Entscheidungen von Person B" |
| `gatekeeper_for` | Türöffner | "Ist Türöffner zu Person B" |

**Richtung:**

| Richtung | Beschreibung | Beispiel |
|----------|--------------|----------|
| `a_to_b` | Einseitig: A → B | "Person A kennt Person B" (aber B kennt A nicht) |
| `b_to_a` | Einseitig: B → A | "Person B kennt Person A" (aber A kennt B nicht) |
| `bidirectional` | Gegenseitig | "Person A und B kennen sich" |

**Stärke und Vertrauen:**

- `strength` (1-10): Wie stark ist die Beziehung?
- `confidence` (1-10): Wie sicher sind wir uns über diese Beziehung?

**Beispiele:**

```sql
-- Person A kennt Person B bei Firma X (Kollegen)
INSERT INTO person_relationship (
    relationship_uuid, person_a_uuid, person_b_uuid, 
    relation_type, direction, context_org_uuid, strength, confidence
)
VALUES (
    UUID(), 'person-a', 'person-b',
    'knows', 'bidirectional', 'org-firma-x', 8, 9
);

-- Person A ist Mentor von Person B (allgemein, nicht firmenbezogen)
INSERT INTO person_relationship (
    relationship_uuid, person_a_uuid, person_b_uuid,
    relation_type, direction, strength, confidence
)
VALUES (
    UUID(), 'person-a', 'person-b',
    'mentor_of', 'a_to_b', 9, 10
);

-- Person A war früher Kollege von Person B bei Firma Y
INSERT INTO person_relationship (
    relationship_uuid, person_a_uuid, person_b_uuid,
    relation_type, direction, context_org_uuid, strength, confidence
)
VALUES (
    UUID(), 'person-a', 'person-b',
    'former_colleague', 'bidirectional', 'org-firma-y', 7, 10
);
```

**UI-Verhalten:**
- Beziehungen gruppiert nach Kontext (Firma X, Allgemein, Projekt Y)
- Filter: "Nur Beziehungen bei Firma X", "Alle Beziehungen"
- Visualisierung: Graph-Ansicht (später mit Neo4j)

**Neo4j-Sync:**
- Beziehungen werden als `(:Person)-[:RELATES {type, strength, confidence, contextOrgUuid}]->(:Person)` synchronisiert
- Ermöglicht Graph-Queries: "Wer kennt wen über 2-3 Ecken?"

### 7.5 Mindest-Felder für Person

**Grunddaten (Pflicht/Empfohlen):**

| Feld | Typ | Tabelle | Beschreibung |
|------|-----|---------|--------------|
| **Name** | `last_name` VARCHAR(120) | `person` | Nachname |
| **Vorname** | `first_name` VARCHAR(120) | `person` | Vorname |
| **Anrede** | `salutation` VARCHAR(20) | `person` | Herr, Frau, Dr., Prof. |
| **Titel** | `title` VARCHAR(100) | `person` | Dr., Prof., etc. |
| **Funktion** | `job_function` VARCHAR(255) | `person_affiliation` | Einkauf, Technik, etc. |
| **eMail** | `email` VARCHAR(255) | `person` | E-Mail-Adresse |
| **Mobil** | `mobile_phone` VARCHAR(50) | `person` | Mobiltelefon |
| **Telefon** | `phone` VARCHAR(50) | `person` | Telefon mit Durchwahl |
| **Status** | `is_active` TINYINT(1) | `person` | Aktiv/Inaktiv |

**Zusätzliche Felder:**
- `display_name` (generated) - Vollständiger Name
- `linkedin_url` - LinkedIn-Profil
- `notes` - Notizen
- `archived_at` - Zeitstempel der Deaktivierung

**Vollständige Tabellen-Struktur:**

```sql
-- Person (Grunddaten)
CREATE TABLE person (
    person_uuid CHAR(36) PRIMARY KEY,
    
    -- Name (Pflicht)
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    salutation VARCHAR(20) NULL COMMENT 'Herr | Frau | Dr. | Prof. | etc.',
    title VARCHAR(100) NULL COMMENT 'Dr. | Prof. | etc.',
    display_name VARCHAR(255) GENERATED ALWAYS AS (
        TRIM(CONCAT(
            COALESCE(salutation, ''), ' ',
            COALESCE(title, ''), ' ',
            COALESCE(first_name, ''), ' ',
            COALESCE(last_name, '')
        ))
    ) STORED,
    
    -- Kontakt (Pflicht)
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL COMMENT 'Telefon mit Durchwahl',
    mobile_phone VARCHAR(50) NULL COMMENT 'Mobiltelefon',
    
    -- Zusätzlich
    linkedin_url VARCHAR(512) NULL,
    notes TEXT NULL,
    
    -- Status (Pflicht)
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    
    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_person_email (email),
    KEY idx_person_name (last_name, first_name),
    KEY idx_person_active (is_active)
);

-- Person Affiliation (Funktion gehört hierher)
ALTER TABLE person_affiliation
    ADD COLUMN job_function VARCHAR(255) NULL COMMENT 'Einkauf | Technik | etc.';
```

**Hinweis:** 
- **Funktion** (`job_function`) gehört zu `person_affiliation`, nicht zu `person`
- Grund: Eine Person kann verschiedene Funktionen bei verschiedenen Firmen haben
- Funktion ist kontextabhängig (Firma + Zeitraum)

## 8. Zusammenfassung

### ✅ Übernehmen
- Alle Kernideen
- Alle neuen Tabellen (angepasst an TOM3-Naming)
- Erweiterte Felder

### ⚠️ Anpassen
- Naming (org statt company, person statt people)
- UUID statt BIGINT
- Migration-Strategie (erweitern statt ersetzen)

### 🔧 Verbessern
- Reporting-Lines mit Composite Key umgehen
- Display Name als Generated Column (mit Fallback)
- Soft-Delete mit is_active

---

*Analyse erstellt: 2025-12-31*
