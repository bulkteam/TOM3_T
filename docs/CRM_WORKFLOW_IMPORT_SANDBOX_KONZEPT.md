# CRM Workflow - Import Sandbox/Review-Konzept

## Problemstellung

**Risiko bei direktem Import:**
- Falsches Mapping → Müll in der DB
- Ungeprüfte Daten → Qualitätsprobleme
- Keine Rückgängigmachung → Datenbereinigung nötig
- Fehlerhafte Duplikate → Doppelarbeit

**Lösung:** Sandbox/Staging-Bereich mit Review-Prozess

---

## Vorschlag: 3-Phasen-Import-Prozess

### Phase 1: Mapping-Konfiguration (vor Import)
### Phase 2: Staging/Sandbox (Import-Vorschau)
### Phase 3: Review & Freigabe (durch Sales Ops)

---

## Phase 1: Mapping-Konfiguration

### Ziel
Sales Ops sieht Excel-Struktur und kann Mapping bestätigen/anpassen, **bevor** Daten importiert werden.

### Ablauf

```
1. Excel-Datei hochladen
        ↓
2. System analysiert Excel:
   - Header-Zeile erkennen
   - Spalten identifizieren
   - Daten-Typen erkennen (String, Number, Date)
        ↓
3. Automatisches Mapping-Vorschlag:
   - "Firmenname" → name (Wahrscheinlichkeit: 95%)
   - "PLZ" → address_postal_code (Wahrscheinlichkeit: 90%)
   - "Website" → website (Wahrscheinlichkeit: 85%)
   - ... (unklar) → (kein Mapping)
        ↓
4. Sales Ops sieht Vorschau:
   - Excel-Spalten + Header
   - Vorgeschlagenes Mapping
   - Konfidenz-Score
        ↓
5. Sales Ops kann:
   - ✅ Mapping bestätigen
   - ✏️ Mapping anpassen
   - ➕ Transformationen hinzufügen
   - ❌ Spalten ignorieren
        ↓
6. Mapping speichern (als Template)
```

### UI: Mapping-Konfigurator

```
┌─────────────────────────────────────────────────────┐
│  Import-Mapping konfigurieren                      │
├─────────────────────────────────────────────────────┤
│                                                     │
│  Excel-Spalten:                                    │
│  ┌─────────┬──────────────┬──────────────────┐   │
│  │ Spalte  │ Header       │ Vorschlag        │   │
│  ├─────────┼──────────────┼──────────────────┤   │
│  │ A       │ Firmenname   │ name ✅ (95%)    │   │
│  │ B       │ Rechtsform   │ org_kind ✅ (90%)│   │
│  │ C       │ PLZ          │ postal_code ✅   │   │
│  │ D       │ ???          │ (kein Mapping)   │   │
│  │ E       │ Website      │ website ✅ (85%) │   │
│  └─────────┴──────────────┴──────────────────┘   │
│                                                     │
│  [Mapping anpassen] [Weiter → Staging]            │
└─────────────────────────────────────────────────────┘
```

### Vorteile
- ✅ Fehler werden **vor** dem Import erkannt
- ✅ Sales Ops hat Kontrolle über Mapping
- ✅ Mapping kann als Template gespeichert werden
- ✅ Konfidenz-Score zeigt unsichere Mappings

---

## Phase 2: Staging/Sandbox (Import-Vorschau)

### Ziel
Daten werden in einen **Staging-Bereich** importiert, nicht direkt in die Produktions-DB.

### Datenmodell

```sql
-- Staging-Tabelle für Import-Daten
CREATE TABLE org_import_staging (
    staging_uuid CHAR(36) PRIMARY KEY,
    import_batch_uuid CHAR(36) NOT NULL,
    row_number INT NOT NULL,
    
    -- Rohdaten (JSON)
    raw_data JSON COMMENT 'Original Excel-Zeile als JSON',
    
    -- Gemappte Daten (JSON)
    mapped_data JSON COMMENT 'Gemappte Org-Daten als JSON',
    
    -- Validierung
    validation_status VARCHAR(50) COMMENT 'valid | invalid | warning',
    validation_errors JSON COMMENT 'Liste von Validierungsfehlern',
    
    -- Review-Status
    review_status VARCHAR(50) COMMENT 'pending | approved | rejected | corrected',
    reviewed_by_user_id VARCHAR(255),
    reviewed_at DATETIME,
    review_notes TEXT,
    
    -- Import-Status
    import_status VARCHAR(50) COMMENT 'pending | imported | skipped',
    imported_org_uuid CHAR(36) COMMENT 'Verknüpfung zur finalen Org',
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (import_batch_uuid) REFERENCES org_import_batch(batch_uuid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_staging_batch ON org_import_staging(import_batch_uuid);
CREATE INDEX idx_staging_review ON org_import_staging(review_status);
CREATE INDEX idx_staging_import ON org_import_staging(import_status);
```

### Ablauf

```
1. Mapping bestätigt
        ↓
2. Excel-Datei wird Zeile für Zeile verarbeitet:
   - Rohdaten in raw_data (JSON)
   - Mapping anwenden → mapped_data (JSON)
   - Validierung durchführen
   - In org_import_staging speichern
        ↓
3. Vorschau generieren:
   - Anzahl Firmen
   - Anzahl Personen (falls vorhanden)
   - Validierungsfehler
   - Duplikate (gegen bestehende DB)
        ↓
4. Sales Ops sieht Vorschau
```

### UI: Staging-Vorschau

```
┌─────────────────────────────────────────────────────┐
│  Import-Vorschau (Staging)                         │
├─────────────────────────────────────────────────────┤
│                                                     │
│  📊 Statistiken:                                   │
│  • Firmen: 150                                     │
│  • Personen: 45                                    │
│  • Valid: 140                                      │
│  • Warnings: 8                                     │
│  • Errors: 2                                       │
│  • Duplikate: 12                                   │
│                                                     │
│  ┌─────────────────────────────────────────────┐ │
│  │ Tab: Firmen | Personen | Fehler | Duplikate  │ │
│  ├─────────────────────────────────────────────┤ │
│  │ Name          | PLZ | Stadt | Status        │ │
│  ├─────────────────────────────────────────────┤ │
│  │ Musterfirma   | 12345 | Berlin | ✅ Valid  │ │
│  │ Test GmbH     | -     | -      | ⚠️ Warning│ │
│  │ Fehler AG     | abc   | -      | ❌ Error  │ │
│  └─────────────────────────────────────────────┘ │
│                                                     │
│  [Alle freigeben] [Selektiert freigeben] [Abbrechen]│
└─────────────────────────────────────────────────────┘
```

### Vorteile
- ✅ Daten sind **noch nicht** in Produktions-DB
- ✅ Vorschau zeigt, was importiert wird
- ✅ Validierungsfehler werden angezeigt
- ✅ Duplikate werden erkannt
- ✅ Korrektur möglich

---

## Phase 3: Review & Freigabe

### Ziel
Sales Ops prüft Staging-Daten und gibt sie frei (oder korrigiert sie).

### Ablauf

```
1. Sales Ops sieht Staging-Vorschau
        ↓
2. Prüfung:
   - Daten korrekt?
   - Mapping passt?
   - Duplikate OK?
   - Validierungsfehler beheben?
        ↓
3. Aktionen:
   a) ✅ Alle freigeben → Import in Produktion
   b) ✅ Selektiert freigeben → Nur ausgewählte
   c) ✏️ Manuell korrigieren → Staging-Daten bearbeiten
   d) 🔄 Mapping anpassen → Zurück zu Phase 1
   e) ❌ Abbrechen → Staging löschen
        ↓
4. Bei Freigabe:
   - Staging-Daten → Produktions-DB
   - Org erstellen
   - Adressen erstellen
   - Personen erstellen
   - Workflow starten
```

### UI: Review-Interface

```
┌─────────────────────────────────────────────────────┐
│  Import-Review                                      │
├─────────────────────────────────────────────────────┤
│                                                     │
│  Zeile 1: Musterfirma GmbH                          │
│  ┌─────────────────────────────────────────────┐ │
│  │ ✅ Valid                                      │ │
│  │ Name: Musterfirma GmbH                        │ │
│  │ PLZ: 12345 → Berlin                           │ │
│  │ Website: www.muster.de                        │ │
│  │ [Freigeben] [Korrigieren] [Ablehnen]          │ │
│  └─────────────────────────────────────────────┘ │
│                                                     │
│  Zeile 2: Test GmbH                                │
│  ┌─────────────────────────────────────────────┐ │
│  │ ⚠️ Warning: PLZ fehlt                         │ │
│  │ Name: Test GmbH                              │ │
│  │ PLZ: (leer)                                  │ │
│  │ [Freigeben] [Korrigieren: PLZ=12345] [Ablehnen]│
│  └─────────────────────────────────────────────┘ │
│                                                     │
│  [Alle validen freigeben] [Abbrechen]              │
└─────────────────────────────────────────────────────┘
```

### Vorteile
- ✅ Kontrolle durch Sales Ops
- ✅ Manuelle Korrektur möglich
- ✅ Selektive Freigabe
- ✅ Keine Müll-Daten in DB

---

## Architektur-Optionen

### Option A: Separate Staging-Tabelle (empfohlen)

**Vorteile:**
- ✅ Klare Trennung: Staging vs. Produktion
- ✅ Einfaches Löschen (keine Produktions-Daten betroffen)
- ✅ Vollständige Historie (was wurde importiert?)
- ✅ Rollback möglich

**Nachteile:**
- ⚠️ Zusätzliche Tabelle
- ⚠️ Daten-Duplikation (temporär)

### Option B: Flag-basiert (einfacher)

```sql
-- In org Tabelle
ALTER TABLE org ADD COLUMN is_staging TINYINT(1) DEFAULT 0;
ALTER TABLE org ADD COLUMN staging_batch_uuid CHAR(36);
```

**Vorteile:**
- ✅ Keine zusätzliche Tabelle
- ✅ Einfacher

**Nachteile:**
- ⚠️ Produktions-DB wird "verschmutzt"
- ⚠️ Löschen schwieriger (Cascades, etc.)
- ⚠️ Keine klare Trennung

**Empfehlung:** Option A (Separate Staging-Tabelle)

---

## Workflow: Kompletter Import-Prozess

```
┌─────────────────────────────────────────────────────┐
│  PHASE 1: Mapping-Konfiguration                    │
├─────────────────────────────────────────────────────┤
│  1. Excel hochladen                                 │
│  2. Header/Spalten erkennen                         │
│  3. Mapping-Vorschlag generieren                    │
│  4. Sales Ops bestätigt/anpasst Mapping             │
│  5. Mapping speichern (optional als Template)       │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│  PHASE 2: Staging-Import                           │
├─────────────────────────────────────────────────────┤
│  1. Excel verarbeiten (Zeile für Zeile)            │
│  2. Mapping anwenden                               │
│  3. Validierung durchführen                        │
│  4. Duplikate prüfen                               │
│  5. In org_import_staging speichern                 │
│  6. Vorschau generieren                             │
└─────────────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────────────┐
│  PHASE 3: Review & Freigabe                        │
├─────────────────────────────────────────────────────┤
│  1. Sales Ops sieht Vorschau                        │
│  2. Prüft Daten (Firmen + Personen getrennt)       │
│  3. Korrigiert Fehler (optional)                   │
│  4. Gibt frei (alle oder selektiv)                 │
│  5. Import in Produktion                            │
│  6. Workflow starten                                │
└─────────────────────────────────────────────────────┘
```

---

## UI: Kompletter Import-Wizard

### Schritt 1: Datei hochladen
```
┌─────────────────────────────────────┐
│  Import: Schritt 1/3                │
│  Excel-Datei hochladen              │
│  [Datei auswählen] [Weiter →]       │
└─────────────────────────────────────┘
```

### Schritt 2: Mapping konfigurieren
```
┌─────────────────────────────────────┐
│  Import: Schritt 2/3                │
│  Mapping konfigurieren              │
│  [Spalten-Mapping] [Weiter →]       │
└─────────────────────────────────────┘
```

### Schritt 3: Review & Freigabe
```
┌─────────────────────────────────────┐
│  Import: Schritt 3/3                │
│  Review & Freigabe                  │
│  [Vorschau] [Freigeben]              │
└─────────────────────────────────────┘
```

---

## Erweiterte Features

### 1. Duplikat-Erkennung (gegen bestehende DB)

```php
// In Staging-Phase
foreach ($stagingRows as $row) {
    $duplicates = $this->findDuplicates($row['mapped_data']);
    if (!empty($duplicates)) {
        $row['duplicates'] = $duplicates;
        $row['validation_status'] = 'warning';
    }
}
```

**UI:** Zeigt Duplikate an, Sales Ops kann entscheiden:
- ✅ Importieren (neue Org)
- 🔗 Verknüpfen (bestehende Org)
- ❌ Überspringen

### 2. Batch-Operationen

- ✅ "Alle validen freigeben"
- ✅ "Alle mit Warnings freigeben"
- ✅ "Alle ablehnen"
- ✅ "Selektiert freigeben" (Checkboxen)

### 3. Mapping-Templates

- Mapping speichern als Template
- Wiederverwendung bei ähnlichen Imports
- Beispiel: "Wer-zu-Wem Standard-Mapping"

### 4. Import-Historie

- Welche Imports wurden durchgeführt?
- Wer hat freigegeben?
- Wann wurde importiert?
- Welche Daten wurden importiert?

---

## Vergleich: Mit vs. Ohne Sandbox

| Aspekt | Ohne Sandbox | Mit Sandbox |
|--------|--------------|-------------|
| **Geschwindigkeit** | ✅ Schnell | ⚠️ Langsamer (2 Schritte) |
| **Datenqualität** | ⚠️ Risiko | ✅ Kontrolliert |
| **Fehlerkorrektur** | ❌ Schwer | ✅ Einfach |
| **Rückgängigmachung** | ❌ Schwer | ✅ Einfach |
| **Duplikate** | ⚠️ Spät erkannt | ✅ Vor Import |
| **Kontrolle** | ❌ Keine | ✅ Sales Ops |

**Empfehlung:** Sandbox für kritische Imports (große Mengen, externe Quellen)

---

## Offene Fragen / Entscheidungen

### 1. Staging-Daten löschen?

**Option A:** Automatisch nach Import (7 Tage)
- ✅ DB bleibt sauber
- ⚠️ Keine Historie

**Option B:** Manuell löschen
- ✅ Historie bleibt
- ⚠️ DB wächst

**Option C:** Archivieren (nicht löschen)
- ✅ Vollständige Historie
- ⚠️ DB wächst

**Empfehlung:** Option C (Archivieren, nicht löschen)

### 2. Validierungsregeln

Welche Validierungen sollen durchgeführt werden?
- ✅ Pflichtfelder (Name)
- ✅ Format (PLZ, E-Mail, URL)
- ✅ Duplikate
- ✅ Referenzen (Branche existiert?)

### 3. Personen-Import

Wie werden Personen gehandhabt?
- Separate Staging-Tabelle: `person_import_staging`?
- Oder in `org_import_staging` als JSON?

**Empfehlung:** Separate Tabelle für Personen

### 4. Performance bei großen Imports

- 1000+ Zeilen: Wie schnell?
- Pagination in UI?
- Batch-Processing im Hintergrund?

---

## Zusammenfassung

### ✅ Vorteile des Sandbox-Ansatzes

1. **Datenqualität:** Fehler werden vor Import erkannt
2. **Kontrolle:** Sales Ops hat volle Kontrolle
3. **Korrektur:** Manuelle Nachbesserung möglich
4. **Duplikate:** Werden vor Import erkannt
5. **Rückgängigmachung:** Einfach (Staging löschen)

### ⚠️ Nachteile

1. **Geschwindigkeit:** Zwei Schritte (Staging → Produktion)
2. **Komplexität:** Zusätzliche Tabelle + UI
3. **Workflow:** Längerer Prozess

### 🎯 Empfehlung

**Ja, Sandbox-Ansatz implementieren!**

**Begründung:**
- Datenqualität ist wichtiger als Geschwindigkeit
- Einmal falscher Import = viel Aufräumarbeit
- Sales Ops hat Kontrolle
- Flexibel (Mapping anpassen, korrigieren)

**Aber:** Optional "Direkt-Import" für vertrauenswürdige Quellen (mit Bestätigung)

---

## Nächste Schritte (Diskussion)

1. **Staging-Tabelle:** Separate Tabelle oder Flag-basiert?
2. **Mapping-Konfigurator:** Automatisch oder manuell?
3. **Validierungsregeln:** Welche sind Pflicht?
4. **Personen-Import:** Separate Tabelle oder integriert?
5. **Performance:** Wie mit großen Imports umgehen?
6. **Direkt-Import:** Soll es eine Option geben (ohne Staging)?
