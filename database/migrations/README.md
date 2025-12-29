# TOM3 - Database Migrations

## Überblick

Dieses Verzeichnis enthält SQL-Migrationen für die TOM3-Datenbank (MySQL/MariaDB).

## Migrationen

### 001_tom_core_schema_mysql.sql
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

### 002_workflow_definitions_mysql.sql
Erstellt Workflow-Definitionen:
- `phase_definition` - Phase-Definitionen
- `phase_requirement_definition` - Checklisten pro Phase
- `engine_definition` - Engine-Definitionen

Enthält auch Default-Daten für Engines und Phasen.

### 003-019: Weitere Migrationen
- `003_org_addresses_and_relations_mysql.sql` - Adressen und Relationen
- `004_org_metadata_mysql.sql` - Metadaten
- `005_org_classification_mysql.sql` - Klassifikation
- `006_org_account_ownership_mysql.sql` - Account Ownership
- `007_org_communication_channels_mysql.sql` - Kommunikationskanäle
- `008_org_industry_hierarchy_mysql.sql` - Branchenhierarchie
- `009_remove_org_industry_redundancy_mysql.sql` - Redundanz entfernen
- `010_industry_subclasses_wz2008_mysql.sql` - WZ2008 Unterklassen
- `011_org_vat_registration_mysql.sql` - USt-IdNr.
- `012_org_vat_registration_simplify_mysql.sql` - USt-IdNr. vereinfachen
- `013_org_audit_trail_mysql.sql` - Audit-Trail
- `014_org_archive_mysql.sql` - Archivierung
- `015_org_address_add_additional_field_mysql.sql` - Adress-Felder
- `016_org_address_add_geodata_mysql.sql` - Geodaten
- `017_org_relations_extended_mysql.sql` - Erweiterte Relationen
- `018_users_and_roles_mysql.sql` - User und Rollen
- `019_workflow_roles_mysql.sql` - Workflow-Rollen

## Setup

### Automatisch (empfohlen)

```bash
php scripts/setup-mysql-database.php
```

### Manuell

1. Erstelle die MySQL-Datenbank:
```sql
CREATE DATABASE tom CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

2. Führe die Migrationen in Reihenfolge aus:
```bash
mysql -u root -p tom < database/migrations/001_tom_core_schema_mysql.sql
mysql -u root -p tom < database/migrations/002_workflow_definitions_mysql.sql
mysql -u root -p tom < database/migrations/003_org_addresses_and_relations_mysql.sql
# ... weitere Migrationen
```

Oder verwende die einzelnen Migrations-Scripts:

```bash
php scripts/run-migration-001.php
php scripts/run-migration-002.php
# ... weitere Migrationen
```

## Konfiguration

Kopiere `config/database.php.example` nach `config/database.php` und passe die Werte an:

```php
return [
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'tom',
        'user' => 'root',
        'password' => 'password',
        'charset' => 'utf8mb4'
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

*Datenbank-Migrationen für TOM3 (MySQL/MariaDB)*
