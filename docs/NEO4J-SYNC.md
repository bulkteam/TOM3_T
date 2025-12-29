# TOM3 - Neo4j Synchronisation

## Überblick

TOM3 verwendet eine Hybrid-Architektur: MySQL als System of Record (Quelle der Wahrheit) und Neo4j für Relationship Intelligence (Graph-Analysen, Hierarchien, Netzwerke).

## Synchronisierte Daten

### Phase 1 (MVP - Implementiert):
- ✅ **Organisationen (Org)** - Basis-Nodes für Firmenhierarchien
- ✅ **Firmenrelationen (org_relation)** - Mutter/Tochter, Beteiligungen, etc.
- ✅ **Personen (Person)** - Stakeholder-Nodes
- ✅ **Person-Affiliationen (person_affiliation)** - Zugehörigkeiten

### Geplante Phasen:
- ⏳ Projekte (Project)
- ⏳ Projekt-Partner (project_partner)
- ⏳ Projekt-Stakeholder (project_stakeholder)

## Architektur

### Event-basierte Synchronisation

1. **MySQL (System of Record)**
   - Alle Schreibaktionen erfolgen in MySQL
   - Events werden in `outbox_event` Tabelle geschrieben
   - Events enthalten: `aggregate_type`, `aggregate_uuid`, `event_type`, `payload`

2. **Sync-Worker**
   - Liest unverarbeitete Events aus `outbox_event`
   - Verarbeitet Events und erstellt/aktualisiert Nodes/Relationships in Neo4j
   - Markiert Events als verarbeitet (`processed_at`)

3. **Neo4j (Relationship Intelligence)**
   - Read-optimiert
   - Idempotente Synchronisation (MERGE statt CREATE)
   - Liefert Kontexte und Insights, keine operativen Entscheidungen

## Verwendung

### 1. Neo4j Constraints einrichten

```bash
php scripts/setup-neo4j-constraints.php
```

Dies erstellt die notwendigen Constraints für:
- `Org.uuid` (UNIQUE)
- `Person.uuid` (UNIQUE)
- `Project.uuid` (UNIQUE)
- `Case.uuid` (UNIQUE)

### 2. Initial-Sync (erste Synchronisation)

Für den ersten Start oder nach Problemen:

```bash
php scripts/sync-neo4j-initial.php
```

Dies synchronisiert alle bestehenden Daten aus MySQL nach Neo4j:
- Alle Organisationen
- Alle Personen
- Alle Firmenrelationen
- Alle Person-Affiliationen

### 3. Sync-Worker starten

#### Einmalige Verarbeitung:
```bash
php scripts/sync-neo4j-worker.php
```

#### Daemon-Modus (kontinuierlich):
```bash
php scripts/sync-neo4j-worker.php --daemon
```

Der Daemon-Modus:
- Verarbeitet alle 5 Sekunden neue Events
- Läuft kontinuierlich bis manuell beendet (Ctrl+C)
- Ideal für Produktionsumgebungen

## Event-Typen

### Org-Events:
- `OrgCreated` - Neue Organisation erstellt
- `OrgUpdated` - Organisation aktualisiert
- `OrgRelationAdded` - Firmenrelation hinzugefügt
- `OrgRelationUpdated` - Firmenrelation aktualisiert
- `OrgRelationDeleted` - Firmenrelation gelöscht

### Person-Events:
- `PersonCreated` - Neue Person erstellt
- `PersonUpdated` - Person aktualisiert
- `PersonAffiliationAdded` - Person-Affiliation hinzugefügt
- `PersonAffiliationUpdated` - Person-Affiliation aktualisiert
- `PersonAffiliationDeleted` - Person-Affiliation gelöscht

## Neo4j Relationship-Typen

### Firmenrelationen:
- `PART_OF` - Tochter/Holding/Division (subsidiary_of, parent_of, division_of)
- `OWNS` - Beteiligungen (owns_stake_in, owns)
- `MERGED_WITH` - Fusionen
- `ACQUIRED` - Übernahmen
- `SUPPLIES` - Lieferantenbeziehung
- `CUSTOMER_OF` - Kundenbeziehung
- `PARTNER_OF` - Partnerschaften
- `RELATED_TO` - Sonstige Beziehungen

### Person-Beziehungen:
- `AFFILIATED_WITH` - Person gehört zu Organisation

## Monitoring

### Unverarbeitete Events prüfen:

```sql
SELECT COUNT(*) 
FROM outbox_event 
WHERE processed_at IS NULL;
```

### Sync-Status prüfen:

```sql
SELECT 
    aggregate_type,
    event_type,
    COUNT(*) as count,
    MIN(created_at) as oldest,
    MAX(created_at) as newest
FROM outbox_event
WHERE processed_at IS NULL
GROUP BY aggregate_type, event_type;
```

## Troubleshooting

### Neo4j-Verbindungsfehler

- Prüfe `config/database.php` (Neo4j-Credentials)
- Prüfe ob Neo4j läuft
- Prüfe Firewall/Netzwerk

### Events werden nicht verarbeitet

- Prüfe ob Sync-Worker läuft
- Prüfe Neo4j-Verbindung
- Prüfe Logs für Fehlerdetails

### Daten inkonsistent

- Führe Initial-Sync aus: `php scripts/sync-neo4j-initial.php`
- Prüfe ob alle Events verarbeitet wurden
- Prüfe Neo4j-Daten direkt

## Beispiel-Cypher-Queries

### Konzernstruktur einer Organisation:

```cypher
MATCH (o:Org {uuid: 'org-uuid'})-[r:PART_OF*]->(parent:Org)
RETURN o, r, parent
```

### Alle Tochtergesellschaften:

```cypher
MATCH (parent:Org {uuid: 'org-uuid'})-[r:PART_OF]->(child:Org)
RETURN child, r
```

### Stakeholder-Netzwerk:

```cypher
MATCH (p:Person)-[a:AFFILIATED_WITH]->(o:Org)
WHERE o.uuid = 'org-uuid'
RETURN p, a, o
```

---

*Neo4j-Synchronisation für TOM3*


