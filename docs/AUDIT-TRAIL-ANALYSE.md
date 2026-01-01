# Audit-Trail Analyse und Empfehlungen

## Aktuelles System

### Struktur
- **Entity-spezifische Audit-Trails:**
  - `org_audit_trail` - Änderungen an Organisationen
  - `person_audit_trail` - Änderungen an Personen
  - `project_audit_trail` - (vorbereitet für Projekte)

### Tabellen-Struktur
```sql
CREATE TABLE org_audit_trail (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    org_uuid CHAR(36) NOT NULL,
    user_id VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,  -- 'create' | 'update' | 'delete'
    field_name VARCHAR(100),
    old_value TEXT,
    new_value TEXT,
    change_type VARCHAR(50),
    metadata JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_org_audit_org (org_uuid),
    INDEX idx_org_audit_user (user_id),
    INDEX idx_org_audit_created (created_at),
    INDEX idx_org_audit_action (action)
)
```

### Vorteile des aktuellen Systems
1. **Schnelle Abfragen:** Nur relevante Entität wird geladen
2. **Klare Struktur:** Jede Entität hat ihren eigenen Trail
3. **Gute Performance:** Indizes auf häufig genutzten Feldern
4. **Skalierbarkeit:** Tabellen können unabhängig wachsen

### Nachteile
1. **Kein zentrales Log:** Keine Übersicht über alle User-Aktionen
2. **Fehlende Aktionen:** Login, Datei-Uploads, Exporte werden nicht geloggt
3. **Keine Cross-Entity-Suche:** Schwer zu finden, was ein User insgesamt gemacht hat

## Fragen und Antworten

### a) Generelles Log für alle User-Aktionen?

**Antwort: Ja, aber selektiv**

**Empfehlung:** Hybrides System
- **Entity-spezifische Audit-Trails** (aktuell) für detaillierte Änderungen
- **Zentrales Activity-Log** für User-Aktionen (Login, Export, Upload, etc.)

**Begründung:**
- ✅ Compliance: Vollständige Nachvollziehbarkeit
- ✅ Security: Erkennung von verdächtigen Aktivitäten
- ✅ Debugging: Einfacheres Troubleshooting
- ⚠️ Performance: Muss gut strukturiert sein

### b) Audit-Trail im Generallog aufgehen lassen?

**Antwort: Nein, aber verknüpfen**

**Empfehlung:** Zwei-Ebenen-System
1. **Activity-Log** (zentral): High-Level-Aktionen
   - Login, Logout
   - Datei-Upload, Datei-Download
   - Export, Import
   - Zuweisungen (Account Owner, etc.)
   - System-Aktionen

2. **Audit-Trail** (entity-spezifisch): Detaillierte Änderungen
   - Feld-Änderungen
   - Sub-Entity-Änderungen (Address, VAT, etc.)
   - Relation-Änderungen

**Verknüpfung:**
- Activity-Log hat Referenz auf Entity + Audit-Trail-ID
- Audit-Trail kann auf Activity-Log-ID verweisen (optional)

### c) Performance bei großen Log-Dateien?

**Antwort: Ja, aber lösbar**

**Risiken:**
- ❌ Große Tabellen werden langsam
- ❌ Indizes werden groß
- ❌ Queries werden langsamer
- ❌ INSERTs können langsamer werden

**Lösungen:**

#### 1. **Partitionierung** (Empfohlen)
```sql
-- Partitionierung nach Monat
ALTER TABLE activity_log 
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202401 VALUES LESS THAN (202402),
    PARTITION p202402 VALUES LESS THAN (202403),
    -- ...
);
```

**Vorteile:**
- Alte Daten können einfach archiviert werden
- Queries werden schneller (nur relevante Partition)
- Indizes bleiben klein

#### 2. **Archivierung**
- Daten älter als X Monate in Archiv-Tabelle verschieben
- Nur aktuelle Daten in Haupttabelle
- Archiv-Daten bei Bedarf laden

#### 3. **Indizierung**
```sql
-- Composite Index für häufige Queries
CREATE INDEX idx_activity_user_date ON activity_log(user_id, created_at DESC);
CREATE INDEX idx_activity_entity ON activity_log(entity_type, entity_uuid);
CREATE INDEX idx_activity_action ON activity_log(action, created_at DESC);
```

#### 4. **Asynchrones Logging** (Optional)
- Logs in Queue schreiben
- Background-Worker schreibt in DB
- Verhindert Blocking bei hoher Last

#### 5. **Retention-Policy**
- Automatische Löschung/Archivierung alter Daten
- Konfigurierbar (z.B. 2 Jahre aktiv, danach Archiv)

## Empfohlene Architektur

### Struktur

```
activity_log (zentral)
├── activity_id (PK)
├── user_id
├── action_type (login, logout, export, upload, entity_change, etc.)
├── entity_type (org, person, project, system, etc.)
├── entity_uuid (optional, NULL für system-Aktionen)
├── audit_trail_id (optional, Verknüpfung zu entity-spezifischem Audit-Trail)
├── details (JSON - zusätzliche Informationen)
├── ip_address
├── user_agent
└── created_at

org_audit_trail (entity-spezifisch, wie bisher)
person_audit_trail (entity-spezifisch, wie bisher)
```

### Beispiel-Daten

**Activity-Log:**
```json
{
  "activity_id": 12345,
  "user_id": "1",
  "action_type": "entity_change",
  "entity_type": "org",
  "entity_uuid": "abc-123",
  "audit_trail_id": 67890,  // Verknüpfung zu org_audit_trail
  "details": {
    "summary": "Organisation 'Firma XY' aktualisiert",
    "changed_fields": ["name", "status"]
  },
  "ip_address": "192.168.1.1",
  "created_at": "2024-01-15 10:30:00"
}
```

**Org-Audit-Trail:**
```json
{
  "audit_id": 67890,
  "org_uuid": "abc-123",
  "user_id": "1",
  "action": "update",
  "field_name": "name",
  "old_value": "Firma XY Alt",
  "new_value": "Firma XY Neu",
  "change_type": "field_change",
  "created_at": "2024-01-15 10:30:00"
}
```

## Implementierungs-Empfehlung

### Phase 1: Activity-Log einführen (ohne Breaking Changes)
1. Neue Tabelle `activity_log` erstellen
2. Activity-Log-Service erstellen
3. Wichtige Aktionen loggen (Login, Export, Upload)
4. Entity-Änderungen als "entity_change" loggen mit Verknüpfung

### Phase 2: Verknüpfung verbessern
1. Audit-Trail-Einträge mit Activity-Log-ID verknüpfen
2. UI: Activity-Log zeigt Details aus Audit-Trail

### Phase 3: Performance-Optimierung
1. Partitionierung implementieren
2. Archivierung für alte Daten
3. Indizes optimieren

## Performance-Schätzungen

### Annahmen:
- 100 User
- 1.000 Aktionen pro Tag
- 30.000 Aktionen pro Monat
- 360.000 Aktionen pro Jahr

### Tabellengröße (geschätzt):
- Pro Eintrag: ~500 Bytes
- Pro Jahr: ~180 MB
- Nach 5 Jahren: ~900 MB

### Performance:
- ✅ Mit Indizes: Queries < 100ms (auch bei 1M Einträgen)
- ✅ Mit Partitionierung: Queries < 50ms
- ⚠️ Ohne Indizes: Queries können > 1s werden

## Best Practices

1. **Nur wichtige Aktionen loggen**
   - Nicht: Jeder Klick, jeder API-Call
   - Ja: Login, Logout, Export, Upload, Entity-Änderungen

2. **Strukturierte Daten**
   - JSON für Details (flexibel, durchsuchbar)
   - Klare Action-Types (nicht zu viele)

3. **Retention-Policy**
   - Aktiv: 2 Jahre
   - Archiv: 5 Jahre
   - Löschung: Nach 7 Jahren (falls erlaubt)

4. **Monitoring**
   - Tabellengröße überwachen
   - Query-Performance überwachen
   - Automatische Alerts bei Problemen

## Fazit

**Empfehlung:**
- ✅ **Hybrides System:** Activity-Log + Entity-spezifische Audit-Trails
- ✅ **Partitionierung:** Für Performance bei großen Datenmengen
- ✅ **Archivierung:** Alte Daten auslagern
- ✅ **Indizierung:** Composite-Indizes für häufige Queries
- ⚠️ **Selektiv loggen:** Nicht alles, nur wichtige Aktionen

**Nicht empfohlen:**
- ❌ Alles in eine Tabelle (Performance-Problem)
- ❌ Audit-Trail komplett ersetzen (verliert Detailtiefe)
- ❌ Keine Retention-Policy (Tabellen werden zu groß)
