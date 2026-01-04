# TOM3 - Personen-Projekt-Integration (Verbesserte Version)

## Überblick

Dieses Dokument beschreibt die **verbesserte** Struktur für die Integration von Personen in Projekte, basierend auf dem vorgeschlagenen Modell mit expliziter Zuordnung über `project_party_id`.

## Kernidee

**Zwei Ebenen im Projekt:**

1. **Welche Firmen wirken am Projekt mit?** (Berater, Lieferant, Auditor …)
   - → `project_parties` (Projektparteien)

2. **Welche Personen wirken am Projekt mit – und im Namen welcher Firma?**
   - → `project_people` (Projektpersonen mit expliziter Zuordnung zur Projektpartei)

## Datenmodell (Verbesserte Version)

### 1. Projekte

```sql
CREATE TABLE project (
    project_uuid CHAR(36) PRIMARY KEY,
    owner_org_uuid CHAR(36) NOT NULL COMMENT 'Firma B (Owner)',
    name VARCHAR(255) NOT NULL,
    status ENUM('open','won','lost','closed','on_hold') NOT NULL DEFAULT 'open',
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_org_uuid) REFERENCES org(org_uuid)
);
```

**Hinweis:** `owner_org_uuid` entspricht bereits `sponsor_org_uuid` in bestehender Tabelle.

### 2. Projektparteien (Welche Firmen sind beteiligt)

**Neue Tabelle:** `project_party` (statt `project_partner`)

```sql
CREATE TABLE project_party (
    party_uuid CHAR(36) PRIMARY KEY,
    project_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    
    party_role ENUM('customer','supplier','consultant','auditor','partner','subcontractor') NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Hauptkunde/Lieferant',
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY uq_project_org_role (project_uuid, org_uuid, party_role),
    KEY idx_pp_project (project_uuid),
    KEY idx_pp_org (org_uuid),
    
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid),
    FOREIGN KEY (org_uuid) REFERENCES org(org_uuid)
);
```

**Wichtig:**
- Eine Firma kann **mehrere Rollen** am gleichen Projekt haben
- Beispiel: Firma A als `consultant` UND `subcontractor` (zwei separate Einträge)
- `UNIQUE KEY` verhindert Duplikate (gleiche Firma, gleiche Rolle)

### 3. Projektpersonen (Welche Personen wirken mit, für welche Projektpartei)

**Neue Tabelle:** `project_person` (statt `project_stakeholder`)

```sql
CREATE TABLE project_person (
    project_person_uuid CHAR(36) PRIMARY KEY,
    project_uuid CHAR(36) NOT NULL,
    person_uuid CHAR(36) NOT NULL,
    
    -- WICHTIG: Explizite Zuordnung zur Projektpartei
    project_party_uuid CHAR(36) NULL COMMENT 'Firma + Rolle im Projektkontext',
    
    -- Projektrolle (kontextbezogen, dynamisch)
    project_role ENUM(
        'consultant',
        'lead_consultant',
        'account_contact',
        'delivery_contact',
        'auditor',
        'stakeholder',
        'decision_maker',
        'champion',
        'blocker'
    ) NOT NULL,
    
    start_date DATE NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    KEY idx_prj_person_project (project_uuid),
    KEY idx_prj_person_person (person_uuid),
    KEY idx_prj_person_party (project_party_uuid),
    
    FOREIGN KEY (project_uuid) REFERENCES project(project_uuid),
    FOREIGN KEY (person_uuid) REFERENCES person(person_uuid),
    FOREIGN KEY (project_party_uuid) REFERENCES project_party(party_uuid)
);
```

**Warum `project_party_uuid` (und nicht nur `org_uuid`)?**

1. **Eindeutigkeit:** Klare Zuordnung zur Projektpartei (Firma + Rolle)
   - Beispiel: Firma A als `consultant` → `project_party_uuid = party-1`
   - Person 1 arbeitet für diese Projektpartei → `project_party_uuid = party-1`

2. **Mehrfach-Rollen:** Firma kann mehrere Rollen haben
   - Firma A als `consultant` → `party_uuid = party-1`
   - Firma A als `subcontractor` → `party_uuid = party-2`
   - Person 1 kann für beide Projektparteien arbeiten (zwei Einträge)

3. **Bessere Abfragen:**
   - "Alle Personen, die für Projektpartei X (Firma Y als Consultant) arbeiten"
   - "Alle Personen von Lieferanten im Projekt"

## Vollständiges Beispiel

### Setup: Projekt mit mehreren Partner-Firmen

```sql
-- 1. Projekt erstellen
INSERT INTO project (project_uuid, name, owner_org_uuid, status)
VALUES ('project-b', 'Projekt B', 'org-firma-b', 'open');

-- 2. Projektparteien hinzufügen (Firmen + Rollen)
INSERT INTO project_party (party_uuid, project_uuid, org_uuid, party_role) VALUES
    -- 7 Lieferanten
    (UUID(), 'project-b', 'org-lieferant-1', 'supplier'),
    (UUID(), 'project-b', 'org-lieferant-2', 'supplier'),
    (UUID(), 'project-b', 'org-lieferant-3', 'supplier'),
    (UUID(), 'project-b', 'org-lieferant-4', 'supplier'),
    (UUID(), 'project-b', 'org-lieferant-5', 'supplier'),
    (UUID(), 'project-b', 'org-lieferant-6', 'supplier'),
    (UUID(), 'project-b', 'org-lieferant-7', 'supplier'),
    -- 2 Berater
    (UUID(), 'project-b', 'org-berater-1', 'consultant'),
    (UUID(), 'project-b', 'org-berater-2', 'consultant'),
    -- 1 Auditor
    (UUID(), 'project-b', 'org-auditor-1', 'auditor');

-- 3. Personen aus Projektparteien hinzufügen
-- Mitarbeiter 1 und 2 von Firma A (Berater) als Berater
INSERT INTO project_person (project_person_uuid, project_uuid, person_uuid, project_party_uuid, project_role)
SELECT 
    UUID(),
    'project-b',
    pa.person_uuid,
    pp.party_uuid,
    'consultant'
FROM person_affiliation pa
JOIN project_party pp ON pp.org_uuid = pa.org_uuid AND pp.party_role = 'consultant'
WHERE pa.org_uuid = 'org-firma-a'
  AND pa.person_uuid IN ('person-1', 'person-2')
  AND pa.until_date IS NULL
  AND pp.project_uuid = 'project-b';

-- Mitarbeiter 3 von Firma Z (Lieferant) als Ansprechpartner
INSERT INTO project_person (project_person_uuid, project_uuid, person_uuid, project_party_uuid, project_role)
SELECT 
    UUID(),
    'project-b',
    'person-3',
    pp.party_uuid,
    'account_contact'
FROM project_party pp
WHERE pp.project_uuid = 'project-b'
  AND pp.org_uuid = 'org-firma-z'
  AND pp.party_role = 'supplier';
```

## Abfragen

### Alle Personen eines Projekts (mit Projektpartei)

```sql
SELECT 
    pp.project_uuid,
    pp.project_person_uuid,
    p.display_name,
    p.email,
    pp.project_role,
    -- Projektpartei (Firma + Rolle)
    pt.party_uuid,
    pt.org_uuid as party_org_uuid,
    o.name as party_org_name,
    pt.party_role as party_role_in_project
FROM project_person pp
JOIN person p ON p.person_uuid = pp.person_uuid
LEFT JOIN project_party pt ON pt.party_uuid = pp.project_party_uuid
LEFT JOIN org o ON o.org_uuid = pt.org_uuid
WHERE pp.project_uuid = 'project-b'
  AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
ORDER BY pt.party_role, pp.project_role, p.display_name;
```

### Alle Personen einer Projektpartei

```sql
SELECT 
    p.display_name,
    p.email,
    pp.project_role,
    pt.party_role as party_role_in_project
FROM project_person pp
JOIN person p ON p.person_uuid = pp.person_uuid
JOIN project_party pt ON pt.party_uuid = pp.project_party_uuid
WHERE pp.project_uuid = 'project-b'
  AND pt.party_uuid = 'party-firma-a-consultant'
  AND (pp.end_date IS NULL OR pp.end_date >= CURDATE());
```

### Alle Berater im Projekt (unabhängig von Firma)

```sql
SELECT 
    p.display_name,
    o.name as firm_name,
    pp.project_role
FROM project_person pp
JOIN person p ON p.person_uuid = pp.person_uuid
JOIN project_party pt ON pt.party_uuid = pp.project_party_uuid
JOIN org o ON o.org_uuid = pt.org_uuid
WHERE pp.project_uuid = 'project-b'
  AND pt.party_role = 'consultant'
  AND (pp.end_date IS NULL OR pp.end_date >= CURDATE());
```

## Validierung

### Prüfung beim Hinzufügen einer Person

**Optional, aber sinnvoll:**

```sql
-- Prüfe, ob Person aktives Employment bei der Firma hat
SELECT 
    pa.person_uuid,
    pa.org_uuid,
    pa.until_date
FROM person_affiliation pa
WHERE pa.person_uuid = 'person-1'
  AND pa.org_uuid = 'org-firma-a'
  AND (pa.until_date IS NULL OR pa.until_date >= CURDATE());
```

**Verhalten:**
- ✅ **Person hat aktives Employment:** Alles ok, Person kann hinzugefügt werden
- ⚠️ **Person hat kein aktives Employment:** Kann trotzdem erlaubt werden (Externe/Interim), aber markieren in `notes` oder `confidence` Feld

## Vergleich: Bestehend vs. Vorgeschlagen

| Aspekt | Bestehend (`project_partner` + `project_stakeholder`) | Vorgeschlagen (`project_party` + `project_person`) | Empfehlung |
|--------|------------------------------------------------------|-----------------------------------------------------|------------|
| **Firmen-Verknüpfung** | `relation VARCHAR(50)` | `party_role ENUM` | ✅ **ENUM ist sauberer** |
| **Person-Verknüpfung** | Nur `project_uuid` + `person_uuid` | `project_uuid` + `person_uuid` + **`project_party_uuid`** | ✅ **Explizite Zuordnung wichtig!** |
| **Zuordnung Person ↔ Firma** | Implizit über `person_affiliation` | Explizit über `project_party_uuid` | ✅ **Viel besser!** |
| **Mehrfach-Rollen Firma** | Möglich (verschiedene `relation`) | Möglich (verschiedene `party_role`) | ✅ **Beide möglich** |
| **Validierung** | Manuell (über JOIN) | Einfach (direkte FK) | ✅ **Einfacher** |

## Migration-Strategie

### Option 1: Neue Tabellen (Empfohlen)

**Vorteile:**
- ✅ Sauberer Start
- ✅ Keine Breaking Changes
- ✅ Bestehende Daten bleiben erhalten
- ✅ Migration schrittweise möglich

**Schritte:**
1. Neue Tabellen `project_party` und `project_person` erstellen
2. Daten aus `project_partner` → `project_party` migrieren
3. Daten aus `project_stakeholder` → `project_person` migrieren (mit `project_party_uuid` Zuordnung)
4. UI auf neue Tabellen umstellen
5. Alte Tabellen später entfernen (nach vollständiger Migration)

### Option 2: Bestehende Tabellen erweitern

**Problem:**
- `project_partner` hat Composite Primary Key
- `project_stakeholder` hat Composite Primary Key
- `project_party_uuid` müsste auf eindeutige ID verweisen

**Lösung:**
- `project_partner` um `partner_uuid` erweitern (neuer Primary Key)
- `project_stakeholder` um `project_party_uuid` erweitern

**Nachteil:**
- Komplexere Migration
- Breaking Changes möglich

**Empfehlung:** Option 1 (neue Tabellen)

## UI-Überlegungen

### Projekt-Detail-Ansicht

**Tab "Projektparteien":**
- Liste aller Projektparteien (aus `project_party`)
- Gruppiert nach `party_role` (supplier, consultant, auditor, etc.)
- Für jede Projektpartei:
  - Firma
  - Rolle im Projekt
  - Anzahl zugeordneter Personen
  - Button: "Personen hinzufügen"

**Tab "Projektpersonen":**
- Liste aller Projektpersonen (aus `project_person`)
- Gruppiert nach `project_party_uuid` (Projektpartei)
- Zeigt für jede Person:
  - Name, E-Mail, Telefon
  - Projektpartei (Firma + Rolle)
  - Projektrolle (consultant, account_contact, etc.)
  - Zeitraum (start_date, end_date)

### Person-Auswahl für Projekt

**Beim Hinzufügen einer Person:**
1. **Projektpartei auswählen** (aus `project_party`)
   - Zeigt: Firma + Rolle im Projekt
2. **Personen aus dieser Firma anzeigen** (über `person_affiliation`)
   - Optional: Validierung (hat Person aktives Employment?)
3. **Person auswählen**
4. **Projektrolle wählen** (consultant, account_contact, etc.)
5. **Optional:** Zeitraum, Notizen

**Oder:**
1. Person direkt suchen (aus allen Personen)
2. System zeigt automatisch Projektpartei (falls Person bei Partner-Firma arbeitet)
3. Projektrolle wählen

## Zusammenfassung

### ✅ Das vorgeschlagene Modell ist besser!

**Vorteile:**
1. ✅ **Explizite Zuordnung:** `project_party_uuid` macht klar, in welcher Rolle die Firma am Projekt beteiligt ist
2. ✅ **Mehrfach-Rollen:** Firma kann als Supplier UND Consultant am gleichen Projekt beteiligt sein
3. ✅ **Validierung:** Einfache Prüfung, ob Person aktives Employment bei der Firma hat
4. ✅ **Bessere Abfragen:** "Alle Personen, die für Projektpartei X arbeiten"
5. ✅ **Sauberer:** ENUM statt VARCHAR für Rollen

**Empfehlung:**
- ✅ Neue Tabellen `project_party` und `project_person` erstellen
- ✅ Bestehende Tabellen parallel laufen lassen (Migration schrittweise)
- ✅ UI auf neue Tabellen umstellen
- ✅ Später: Alte Tabellen entfernen

---

*Personen-Projekt-Integration V2 für TOM3*


