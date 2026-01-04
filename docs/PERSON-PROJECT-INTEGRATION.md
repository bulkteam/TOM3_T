# TOM3 - Personen-Projekt-Integration

## Überblick

Dieses Dokument beschreibt, wie Personen aus Partner-Firmen in Projekten verwendet werden können.

## Szenario

**Beispiel:**
- Firma B hat ein Projekt
- Firma A (Ingenieurbüro) wird als Berater beauftragt
- Firma Z wird als Lieferant beauftragt
- Mitarbeiter 1 und 2 von Firma A sollen als Berater im Projekt sichtbar sein
- Mitarbeiter 3 von Firma Z soll als Ansprechpartner im Projekt sichtbar sein
- Mehrere Firmen können beteiligt sein (7 Lieferanten, 2 Berater, 1 Auditor)

## Bestehende Struktur

### 1. Projekt-Partner (`project_partner`)

Verknüpft Organisationen mit Projekten:

```sql
CREATE TABLE project_partner (
    project_uuid CHAR(36) NOT NULL,
    org_uuid CHAR(36) NOT NULL,
    relation VARCHAR(50) NOT NULL COMMENT 'delivers | advises | participates',
    scope TEXT,
    contract_ref TEXT,
    PRIMARY KEY (project_uuid, org_uuid, relation)
);
```

**Beispiel:**
```sql
-- Firma A wird als Berater für Projekt von Firma B eingetragen
INSERT INTO project_partner (project_uuid, org_uuid, relation)
VALUES ('project-b', 'org-firma-a', 'advises');

-- Firma Z wird als Lieferant eingetragen
INSERT INTO project_partner (project_uuid, org_uuid, relation)
VALUES ('project-b', 'org-firma-z', 'delivers');
```

### 2. Projekt-Stakeholder (`project_stakeholder`)

Verknüpft Personen direkt mit Projekten (egal aus welcher Org):

```sql
CREATE TABLE project_stakeholder (
    project_uuid CHAR(36) NOT NULL,
    person_uuid CHAR(36) NOT NULL,
    role VARCHAR(50) NOT NULL COMMENT 'Decider | Influencer | User | advisor | contact_person | etc.',
    influence INT COMMENT '1..5',
    decision_power INT COMMENT '0..100',
    since_date DATE DEFAULT '1900-01-01',
    until_date DATE,
    PRIMARY KEY (project_uuid, person_uuid, role, since_date)
);
```

**Beispiel:**
```sql
-- Mitarbeiter 1 und 2 von Firma A werden als Berater hinzugefügt
INSERT INTO project_stakeholder (project_uuid, person_uuid, role)
VALUES 
    ('project-b', 'person-1', 'advisor'),
    ('project-b', 'person-2', 'advisor');

-- Mitarbeiter 3 von Firma Z wird als Ansprechpartner hinzugefügt
INSERT INTO project_stakeholder (project_uuid, person_uuid, role)
VALUES ('project-b', 'person-3', 'contact_person');
```

## Vollständiges Beispiel

### Setup: Projekt mit mehreren Partner-Firmen

```sql
-- Projekt erstellen
INSERT INTO project (project_uuid, name, sponsor_org_uuid)
VALUES ('project-b', 'Projekt B', 'org-firma-b');

-- Partner-Firmen hinzufügen
INSERT INTO project_partner (project_uuid, org_uuid, relation) VALUES
    -- 7 Lieferanten
    ('project-b', 'org-lieferant-1', 'delivers'),
    ('project-b', 'org-lieferant-2', 'delivers'),
    ('project-b', 'org-lieferant-3', 'delivers'),
    ('project-b', 'org-lieferant-4', 'delivers'),
    ('project-b', 'org-lieferant-5', 'delivers'),
    ('project-b', 'org-lieferant-6', 'delivers'),
    ('project-b', 'org-lieferant-7', 'delivers'),
    -- 2 Berater
    ('project-b', 'org-berater-1', 'advises'),
    ('project-b', 'org-berater-2', 'advises'),
    -- 1 Auditor
    ('project-b', 'org-auditor-1', 'advises');

-- Personen aus Partner-Firmen als Stakeholder hinzufügen
INSERT INTO project_stakeholder (project_uuid, person_uuid, role) VALUES
    -- Personen von Lieferanten
    ('project-b', 'person-l1-1', 'contact_person'),
    ('project-b', 'person-l2-1', 'contact_person'),
    -- ... weitere Personen
    -- Personen von Beratern
    ('project-b', 'person-b1-1', 'advisor'),
    ('project-b', 'person-b1-2', 'advisor'),
    ('project-b', 'person-b2-1', 'advisor'),
    -- Person von Auditor
    ('project-b', 'person-a1-1', 'auditor');
```

## Abfragen

### Alle Stakeholder eines Projekts (mit ihren Firmen)

```sql
SELECT 
    ps.project_uuid,
    ps.person_uuid,
    p.display_name,
    p.email,
    ps.role,
    ps.influence,
    ps.decision_power,
    -- Firma über person_affiliation
    pa.org_uuid as person_org_uuid,
    o.name as person_org_name,
    -- Partner-Org (falls vorhanden)
    pp.org_uuid as partner_org_uuid,
    po.name as partner_org_name,
    pp.relation as partner_relation
FROM project_stakeholder ps
JOIN person p ON p.person_uuid = ps.person_uuid
LEFT JOIN person_affiliation pa ON pa.person_uuid = ps.person_uuid AND pa.until_date IS NULL
LEFT JOIN org o ON o.org_uuid = pa.org_uuid
LEFT JOIN project_partner pp ON pp.project_uuid = ps.project_uuid AND pp.org_uuid = pa.org_uuid
LEFT JOIN org po ON po.org_uuid = pp.org_uuid
WHERE ps.project_uuid = 'project-b'
  AND (ps.until_date IS NULL OR ps.until_date >= CURDATE())
ORDER BY pp.relation, ps.role, p.display_name;
```

### Alle Personen einer Partner-Firma im Projekt

```sql
SELECT 
    p.display_name,
    p.email,
    ps.role,
    ps.influence
FROM project_stakeholder ps
JOIN person p ON p.person_uuid = ps.person_uuid
JOIN person_affiliation pa ON pa.person_uuid = ps.person_uuid AND pa.until_date IS NULL
JOIN project_partner pp ON pp.project_uuid = ps.project_uuid AND pp.org_uuid = pa.org_uuid
WHERE ps.project_uuid = 'project-b'
  AND pp.org_uuid = 'org-firma-a'
  AND (ps.until_date IS NULL OR ps.until_date >= CURDATE());
```

## Optional: Verbesserung für bessere Nachvollziehbarkeit

### Problem

Aktuell gibt es keine direkte Verknüpfung zwischen `project_stakeholder` und `project_partner`. Die Zuordnung funktioniert implizit über `person_affiliation`:

1. Person hat `person_affiliation` zu Org X
2. Org X ist `project_partner` für Projekt Y
3. Person ist `project_stakeholder` für Projekt Y
4. → Person ist über Org X am Projekt beteiligt

### Lösung: Optionales Feld `partner_org_uuid`

**Vorschlag:**
```sql
ALTER TABLE project_stakeholder
    ADD COLUMN partner_org_uuid CHAR(36) NULL COMMENT 'Optional: Org über die Person am Projekt beteiligt ist',
    ADD FOREIGN KEY (partner_org_uuid) REFERENCES org(org_uuid),
    ADD INDEX idx_stakeholder_partner (project_uuid, partner_org_uuid);
```

**Vorteile:**
- ✅ Klare Zuordnung: "Person X ist über Partner-Org Y am Projekt beteiligt"
- ✅ Einfache Abfrage: "Alle Personen, die über Partner-Org Z am Projekt beteiligt sind"
- ✅ Bessere Nachvollziehbarkeit
- ✅ Optionales Feld (keine Breaking Changes)

**Nachteile:**
- ⚠️ Zusätzliches Feld (aber optional)
- ⚠️ Muss bei Erstellung gepflegt werden (kann aber auch automatisch aus `person_affiliation` abgeleitet werden)

**Empfehlung:** Optionales Feld hinzufügen, aber nicht zwingend erforderlich (funktioniert auch ohne über `person_affiliation`).

## UI-Überlegungen

### Projekt-Detail-Ansicht

**Tab "Partner":**
- Liste aller Partner-Firmen (aus `project_partner`)
- Gruppiert nach `relation` (delivers, advises, participates)
- Für jede Partner-Firma: Liste der zugeordneten Personen (aus `project_stakeholder`)

**Tab "Stakeholder":**
- Liste aller Stakeholder (aus `project_stakeholder`)
- Gruppiert nach `role` (Decider, Influencer, Advisor, Contact Person, etc.)
- Zeigt für jede Person:
  - Name, E-Mail, Telefon
  - Firma (über `person_affiliation`)
  - Partner-Org (falls `partner_org_uuid` vorhanden)
  - Rolle im Projekt
  - Einfluss/Entscheidungsmacht

### Person-Auswahl für Projekt

**Beim Hinzufügen eines Stakeholders:**
1. Partner-Firma auswählen (aus `project_partner`)
2. Personen aus dieser Firma anzeigen (über `person_affiliation`)
3. Person auswählen
4. Rolle im Projekt wählen (Decider, Influencer, Advisor, Contact Person, etc.)
5. Optional: Einfluss/Entscheidungsmacht setzen

**Oder:**
1. Person direkt suchen (aus allen Personen)
2. System zeigt automatisch Partner-Firma (falls Person bei Partner-Firma arbeitet)
3. Rolle im Projekt wählen

## Zusammenfassung

### ✅ Das Konzept unterstützt das bereits!

**Bestehende Struktur:**
- ✅ `project_partner` verknüpft Organisationen mit Projekten
- ✅ `project_stakeholder` verknüpft Personen mit Projekten
- ✅ Personen können aus beliebigen Organisationen kommen
- ✅ Mehrere Partner-Firmen pro Projekt möglich
- ✅ Mehrere Personen pro Partner-Firma möglich

**Funktioniert:**
- Firma B beauftragt Firma A (Ingenieurbüro) → Mitarbeiter 1,2 von Firma A als Berater
- Firma Z als Lieferant → Mitarbeiter 3 als Ansprechpartner
- Mehrere Firmen (7 Lieferanten, 2 Berater, 1 Auditor) → alle Personen auswählbar

**Optional: Verbesserung**
- Optionales Feld `partner_org_uuid` in `project_stakeholder` für bessere Nachvollziehbarkeit
- Aber nicht zwingend erforderlich (funktioniert auch ohne)

---

*Personen-Projekt-Integration für TOM3*


