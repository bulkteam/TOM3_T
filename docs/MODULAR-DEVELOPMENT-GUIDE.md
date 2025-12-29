# TOM3 - Leitfaden für modulare Entwicklung

## Problem: Große Dateien trotz Modulkonzept

Trotz des Modulkonzepts haben sich wieder große Dateien gebildet:
- `org-detail.js`: 1,322 Zeilen (sollte < 400 sein)
- `OrgService.php`: 1,805 Zeilen (sollte < 500 sein)
- `orgs.php`: 553 Zeilen (sollte < 200 sein)

## Ursachen-Analyse

### Warum werden Dateien groß?

1. **Feature-Creep**: Neue Features werden einfach in bestehende Dateien hinzugefügt
2. **Fehlende Grenzen**: Keine klaren Regeln, wann eine neue Datei erstellt werden muss
3. **Komfort**: "Schnell mal" etwas hinzufügen ist einfacher als Refactoring
4. **Unklare Verantwortlichkeiten**: Eine Klasse/Modul macht zu viel

### Beispiel: `org-detail.js`

Diese Datei hat zu viele Verantwortlichkeiten:
- ✅ Rendering der Detail-Ansicht
- ✅ Edit-Modus
- ✅ Adressen-Management (CRUD)
- ✅ Kommunikationskanäle-Management (CRUD)
- ✅ USt-ID-Management (CRUD)
- ✅ Beziehungen-Management (CRUD)
- ✅ Archivierung
- ✅ Event-Handler für alles

**Lösung**: Aufteilen in spezialisierte Module

---

## Strategien für kleine Codeblöcke

### 1. Single Responsibility Principle (SRP)

**Regel**: Jede Datei/Klasse hat genau **eine** Verantwortlichkeit.

**Beispiel**:
```javascript
// ❌ SCHLECHT: org-detail.js macht alles
class OrgDetailModule {
    renderOrgDetail() { ... }
    editAddress() { ... }
    editChannel() { ... }
    editVat() { ... }
    editRelation() { ... }
}

// ✅ GUT: Aufgeteilt in spezialisierte Module
class OrgDetailModule {
    renderOrgDetail() { ... }
}

class OrgAddressModule {
    editAddress() { ... }
    createAddress() { ... }
}

class OrgChannelModule {
    editChannel() { ... }
    createChannel() { ... }
}
```

### 2. Dateigrößen-Limits

**Regel**: Dateien dürfen bestimmte Größen nicht überschreiten.

| Typ | Maximum | Warnung bei |
|-----|---------|-------------|
| JavaScript Module | 400 Zeilen | 300 Zeilen |
| PHP Service | 500 Zeilen | 400 Zeilen |
| PHP API Endpoint | 200 Zeilen | 150 Zeilen |
| PHP Infrastructure | 300 Zeilen | 250 Zeilen |

**Aktion bei Überschreitung**:
1. ⚠️ Warnung in Code-Review
2. 🔴 Blockierung bei > 150% des Limits
3. 📋 Refactoring-Plan erstellen

### 3. Composition über große Klassen

**Regel**: Verwende Composition statt alles in eine Klasse zu packen.

**Beispiel**:
```javascript
// ❌ SCHLECHT: Alles in einer Klasse
class OrgDetailModule {
    constructor(app) {
        this.app = app;
    }
    // 1,322 Zeilen Code...
}

// ✅ GUT: Composition mit spezialisierten Modulen
class OrgDetailModule {
    constructor(app) {
        this.app = app;
        this.addressModule = new OrgAddressModule(app);
        this.channelModule = new OrgChannelModule(app);
        this.vatModule = new OrgVatModule(app);
        this.relationModule = new OrgRelationModule(app);
    }
    
    async showOrgDetail(orgUuid) {
        // Nur Koordination, keine Details
        const org = await window.API.getOrgDetails(orgUuid);
        this.renderOrgDetail(org);
        this.setupSubModules(orgUuid);
    }
}
```

### 4. Feature-Module statt Monolithen

**Regel**: Jedes Feature bekommt sein eigenes Modul.

**Struktur**:
```
modules/
  org-detail/
    index.js          # Hauptmodul (Koordination)
    org-detail-view.js    # Rendering
    org-detail-edit.js    # Edit-Modus
    org-address.js        # Adressen
    org-channel.js        # Kommunikationskanäle
    org-vat.js           # USt-IDs
    org-relation.js       # Beziehungen
```

### 5. Service-Layer-Aufteilung

**Regel**: Services nach Domänen aufteilen, nicht nach Entitäten.

**Beispiel PHP**:
```php
// ❌ SCHLECHT: OrgService macht alles
class OrgService {
    // CRUD für Org
    // CRUD für Addresses
    // CRUD für Channels
    // CRUD für VAT
    // CRUD für Relations
    // Audit-Trail
    // Account Health
    // Suche
    // ... 1,805 Zeilen
}

// ✅ GUT: Aufgeteilt nach Domänen
class OrgService {
    // Nur Kern-Org-CRUD (~200 Zeilen)
}

class OrgAddressService {
    // Nur Adressen-Logik (~150 Zeilen)
}

class OrgRelationService {
    // Nur Beziehungen-Logik (~150 Zeilen)
}

class OrgAuditService {
    // Nur Audit-Trail-Logik (~100 Zeilen)
}
```

### 6. API-Endpoint-Aufteilung

**Regel**: Ein Endpoint pro Ressource, nicht alles in einem.

**Beispiel**:
```
// ❌ SCHLECHT: orgs.php macht alles
orgs.php (553 Zeilen)
  - GET /orgs/{uuid}
  - PUT /orgs/{uuid}
  - GET /orgs/{uuid}/addresses
  - POST /orgs/{uuid}/addresses
  - GET /orgs/{uuid}/channels
  - POST /orgs/{uuid}/channels
  - ... etc.

// ✅ GUT: Aufgeteilt nach Ressourcen
orgs.php (~150 Zeilen)
  - GET /orgs/{uuid}
  - PUT /orgs/{uuid}
  - DELETE /orgs/{uuid}

orgs-addresses.php (~100 Zeilen)
  - GET /orgs/{uuid}/addresses
  - POST /orgs/{uuid}/addresses
  - PUT /orgs/{uuid}/addresses/{id}
  - DELETE /orgs/{uuid}/addresses/{id}

orgs-channels.php (~100 Zeilen)
  - GET /orgs/{uuid}/channels
  - POST /orgs/{uuid}/channels
  - ...
```

---

## Checkliste vor dem Hinzufügen von Code

### ✅ Muss ich eine neue Datei erstellen?

1. **Überschreitet die Datei bereits das Limit?**
   - ✅ Ja → Neue Datei erstellen
   - ❌ Nein → Weiter mit Frage 2

2. **Ist die neue Funktionalität eine andere Verantwortlichkeit?**
   - ✅ Ja → Neue Datei erstellen
   - ❌ Nein → Weiter mit Frage 3

3. **Kann die Funktionalität isoliert getestet werden?**
   - ✅ Ja → Neue Datei erstellen
   - ❌ Nein → Weiter mit Frage 4

4. **Wird die Funktionalität auch woanders benötigt?**
   - ✅ Ja → Neue Datei erstellen (Wiederverwendbarkeit)
   - ❌ Nein → In bestehende Datei (aber prüfe Größe!)

### ✅ Code-Review-Fragen

1. **Ist die Datei > 80% des Limits?**
   - → Warnung: Bald Refactoring nötig

2. **Hat die Klasse/Datei mehr als 5 öffentliche Methoden?**
   - → Prüfen: Kann aufgeteilt werden?

3. **Werden mehrere "und"-Wörter im Kommentar benötigt?**
   - Beispiel: "Diese Klasse rendert Organisationen **und** verwaltet Adressen **und** Channels"
   - → Aufteilen!

4. **Gibt es mehrere `if (type === 'address')` / `if (type === 'channel')` Checks?**
   - → Strategy Pattern oder separate Module verwenden

---

## Refactoring-Plan für bestehende große Dateien

### Phase 1: `org-detail.js` (1,322 Zeilen → ~300 Zeilen)

**Zielstruktur**:
```
modules/org-detail/
  index.js              # Hauptmodul (~150 Zeilen)
  org-detail-view.js    # Rendering (~200 Zeilen)
  org-detail-edit.js    # Edit-Modus (~150 Zeilen)
  org-address.js        # Adressen (~200 Zeilen)
  org-channel.js         # Channels (~200 Zeilen)
  org-vat.js            # USt-IDs (~150 Zeilen)
  org-relation.js        # Beziehungen (~200 Zeilen)
```

**Schritte**:
1. ✅ Neue Ordnerstruktur erstellen
2. ✅ `org-address.js` extrahieren (Adressen-Logik)
3. ✅ `org-channel.js` extrahieren (Channel-Logik)
4. ✅ `org-vat.js` extrahieren (VAT-Logik)
5. ✅ `org-relation.js` extrahieren (Relation-Logik)
6. ✅ `org-detail-view.js` extrahieren (Rendering)
7. ✅ `org-detail-edit.js` extrahieren (Edit-Modus)
8. ✅ `index.js` als Koordinator

### Phase 2: `OrgService.php` (1,805 Zeilen → ~200 Zeilen)

**Zielstruktur**:
```
src/TOM/Service/Org/
  OrgService.php           # Kern-CRUD (~200 Zeilen)
  OrgAddressService.php    # Adressen (~150 Zeilen)
  OrgChannelService.php    # Channels (~150 Zeilen)
  OrgVatService.php        # USt-IDs (~100 Zeilen)
  OrgRelationService.php    # Beziehungen (~200 Zeilen)
  OrgAuditService.php      # Audit-Trail (~150 Zeilen)
  OrgHealthService.php     # Account Health (~150 Zeilen)
  OrgSearchService.php     # Suche (~200 Zeilen)
```

**Schritte**:
1. ✅ Neue Namespace-Struktur erstellen
2. ✅ `OrgAddressService` extrahieren
3. ✅ `OrgChannelService` extrahieren
4. ✅ `OrgVatService` extrahieren
5. ✅ `OrgRelationService` extrahieren
6. ✅ `OrgAuditService` extrahieren
7. ✅ `OrgHealthService` extrahieren
8. ✅ `OrgSearchService` extrahieren
9. ✅ `OrgService` als Facade/Coordinator

### Phase 3: `orgs.php` (553 Zeilen → ~150 Zeilen)

**Zielstruktur**:
```
public/api/orgs/
  index.php              # Router (~100 Zeilen)
  orgs-core.php          # Kern-CRUD (~150 Zeilen)
  orgs-addresses.php     # Adressen-Endpoints (~100 Zeilen)
  orgs-channels.php      # Channels-Endpoints (~100 Zeilen)
  orgs-vat.php           # VAT-Endpoints (~80 Zeilen)
  orgs-relations.php     # Relations-Endpoints (~100 Zeilen)
```

---

## Best Practices

### 1. Immer mit kleinstem Modul starten

**Regel**: Beginne mit der kleinstmöglichen Einheit.

```javascript
// ✅ GUT: Starte klein
class OrgAddressForm {
    render() { ... }
    submit() { ... }
}

// ❌ SCHLECHT: Alles auf einmal
class OrgDetailModule {
    // 1,322 Zeilen...
}
```

### 2. Dependency Injection für Module

**Regel**: Module sollten über Konstruktor injiziert werden.

```javascript
// ✅ GUT: Dependency Injection
class OrgDetailModule {
    constructor(app, addressModule, channelModule) {
        this.app = app;
        this.addressModule = addressModule;
        this.channelModule = channelModule;
    }
}

// ❌ SCHLECHT: Direkte Instanziierung
class OrgDetailModule {
    constructor(app) {
        this.addressModule = new OrgAddressModule(app);
        this.channelModule = new OrgChannelModule(app);
    }
}
```

### 3. Interface/Contract-Definitionen

**Regel**: Definiere klare Interfaces zwischen Modulen.

```javascript
// ✅ GUT: Interface definiert
/**
 * @interface OrgAddressModule
 * @method renderAddressForm(orgUuid, addressUuid?)
 * @method submitAddressForm(data)
 * @method deleteAddress(orgUuid, addressUuid)
 */
```

### 4. Regelmäßige Code-Reviews

**Checkliste**:
- [ ] Dateigröße unter Limit?
- [ ] Nur eine Verantwortlichkeit?
- [ ] Klare Interfaces?
- [ ] Testbar isoliert?
- [ ] Wiederverwendbar?

### 5. Automatisierte Checks

**Ideal**: Pre-commit Hook oder CI/CD Check

```bash
# Beispiel: Pre-commit Hook
#!/bin/bash
MAX_LINES=400
FILE="public/js/modules/org-detail.js"
LINES=$(wc -l < "$FILE")

if [ $LINES -gt $MAX_LINES ]; then
    echo "❌ $FILE hat $LINES Zeilen (Limit: $MAX_LINES)"
    echo "Bitte refactoren oder aufteilen!"
    exit 1
fi
```

---

## Sofortmaßnahmen

### 1. Dateigrößen-Monitoring

Erstelle ein Script, das regelmäßig die Dateigrößen prüft:

```powershell
# check-file-sizes.ps1
$limits = @{
    'public/js/modules/*.js' = 400
    'src/TOM/Service/*.php' = 500
    'public/api/*.php' = 200
}

foreach ($pattern in $limits.Keys) {
    $limit = $limits[$pattern]
    Get-ChildItem -Path $pattern | ForEach-Object {
        $lines = (Get-Content $_.FullName | Measure-Object -Line).Lines
        if ($lines -gt $limit) {
            Write-Host "⚠️  $($_.Name): $lines Zeilen (Limit: $limit)" -ForegroundColor Yellow
        }
    }
}
```

### 2. Code-Review-Template

Erstelle ein Template für Code-Reviews:

```markdown
## Code-Review Checkliste

### Dateigröße
- [ ] Datei unter Limit? (JS: 400, PHP Service: 500, API: 200)
- [ ] Warnung bei > 80% des Limits?

### Verantwortlichkeiten
- [ ] Nur eine klare Verantwortlichkeit?
- [ ] Keine "und"-Beschreibungen nötig?

### Modularität
- [ ] Kann isoliert getestet werden?
- [ ] Wiederverwendbar?
- [ ] Klare Interfaces?

### Refactoring-Bedarf
- [ ] Muss aufgeteilt werden?
- [ ] Refactoring-Plan vorhanden?
```

### 3. Entwickler-Guidelines

Erstelle eine kurze Checkliste für Entwickler:

```markdown
## Bevor du Code hinzufügst:

1. **Prüfe Dateigröße**: Ist die Datei bereits > 80% des Limits?
2. **Prüfe Verantwortlichkeit**: Passt die neue Funktion zur bestehenden?
3. **Prüfe Wiederverwendbarkeit**: Wird das auch woanders benötigt?
4. **Prüfe Testbarkeit**: Kann es isoliert getestet werden?

Wenn 2+ Fragen mit "Nein" beantwortet werden → Neue Datei erstellen!
```

---

## Zusammenfassung

### ✅ DO's

- ✅ **Kleine, fokussierte Module** (< 400 Zeilen)
- ✅ **Eine Verantwortlichkeit** pro Datei
- ✅ **Composition** über große Klassen
- ✅ **Regelmäßige Refactorings** (nicht erst bei 1,000+ Zeilen)
- ✅ **Code-Reviews** mit Größen-Checks

### ❌ DON'Ts

- ❌ **"Schnell mal" Code hinzufügen** ohne Prüfung
- ❌ **Alles in eine Datei** packen
- ❌ **Auf "später refactoren"** vertrösten
- ❌ **Keine Grenzen** definieren
- ❌ **Monolithen** akzeptieren

### 🎯 Ziele

- **JavaScript Module**: < 400 Zeilen
- **PHP Services**: < 500 Zeilen
- **PHP API Endpoints**: < 200 Zeilen
- **PHP Infrastructure**: < 300 Zeilen

---

*Erstellt: 2025-01-28*
*Nächste Überprüfung: Nach jedem größeren Feature*

