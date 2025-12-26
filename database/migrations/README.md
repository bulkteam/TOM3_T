# TOM3 - Database Migrations

## Überblick

Dieses Verzeichnis enthält SQL-Migrationen für die TOM3-Datenbank.

## Migrationen

### 001_tom_core_schema.sql
Erstellt die Kern-Tabellen:
- `org` - Organisationen
- `person` - Personen/Kontakte
- `person_affiliation` - Zugehörigkeiten
- `project` - Projekte
- `project_partner` - Projektpartner
- `project_stakeholder` - Projekt-Stakeholder
- `case_item` - Vorgänge
- `project_case` - Projekt-Vorgang-Verknüpfungen
- `task` - Aufgaben
- `case_note` - Notizen
- `case_requirement` - Pflichtoutputs/Blocker
- `case_handover` - Übergaben
- `case_return` - Rückläufer
- `outbox_event` - Event-Outbox

Enthält auch die Funktion `calculate_case_status()` zur Status-Berechnung.

### 002_workflow_definitions.sql
Erstellt Workflow-Definitionen:
- `phase_definition` - Phase-Definitionen
- `phase_requirement_definition` - Checklisten pro Phase
- `engine_definition` - Engine-Definitionen

Enthält auch Default-Daten für Engines und Phasen.

## Setup

### Automatisch
```bash
php scripts/setup-database.php
```

### Manuell
1. Erstelle die PostgreSQL-Datenbank:
```sql
CREATE DATABASE tom3;
```

2. Führe die Migrationen in Reihenfolge aus:
```bash
psql -d tom3 -f database/migrations/001_tom_core_schema.sql
psql -d tom3 -f database/migrations/002_workflow_definitions.sql
```

## Konfiguration

Kopiere `config/database.php.example` nach `config/database.php` und passe die Werte an:

```php
return [
    'postgresql' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => 'tom3',
        'user' => 'postgres',
        'password' => 'password'
    ],
    'neo4j' => [
        'uri' => 'bolt://localhost:7687',
        'user' => 'neo4j',
        'password' => 'password'
    ]
];
```

## Neo4j Setup

Nach dem SQL-Setup sollten auch die Neo4j-Constraints und Indexes erstellt werden:

```bash
# Constraints
cypher-shell -u neo4j -p password < database/neo4j/constraints.cypher

# Indexes
cypher-shell -u neo4j -p password < database/neo4j/indexes.cypher
```

---

*Datenbank-Migrationen für TOM3*


