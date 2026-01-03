# Strategie: Kompletter Neuaufbau (ohne Rückwärtskompatibilität)

## Entscheidung: Alten Code verwerfen

**Vorteile:**
- ✅ Sauberer Code ohne Legacy-Ballast
- ✅ Keine Code-Duplikation
- ✅ Einfacher zu warten
- ✅ Schnellere Umsetzung
- ✅ Konsistente Struktur von Anfang an

**Nachteile:**
- ⚠️ Bestehende Test-Imports gehen verloren (aber das ist OK, da Stage)
- ⚠️ Alte Code-Pfade müssen komplett ersetzt werden

---

## Neue Strategie: Clean Slate Approach

### Phase 1: DB-Migrationen (Foundation)
**Unverändert** - DB-Struktur muss erweitert werden

### Phase 2: Neue Services erstellen (Parallel zum alten Code)
**Neu:** Services komplett neu schreiben, alte Services bleiben temporär

### Phase 3: Alten Code ersetzen (Big Bang)
**Neu:** Alte Services durch neue ersetzen, alte Dateien löschen

### Phase 4: Frontend komplett neu
**Neu:** Frontend nutzt nur neue APIs, alte Logik entfernen

---

## Detaillierte Umsetzung

### Phase 1: DB-Migrationen ✅

**Unverändert:**
1. Migration 048: `industry_resolution`
2. Migration 049: Optionale Felder
3. Migration 050: `industry_alias`

**Ausführen und testen.**

---

### Phase 2: Neue Services (Parallel)

**Strategie:** Neue Services erstellen, alte bleiben temporär (für Vergleich/Testing)

#### 2.1 IndustryNormalizer
```php
// src/TOM/Service/Import/IndustryNormalizer.php
// NEU erstellen
```

#### 2.2 IndustryRepository (oder erweitere industries.php)
```php
// Option: Erweitere public/api/industries.php
// Oder: src/TOM/Repository/IndustryRepository.php (neu)
```

#### 2.3 IndustryResolver
```php
// src/TOM/Service/Import/IndustryResolver.php
// NEU erstellen (nicht refactored, komplett neu)
// Nutzt IndustryNormalizer, IndustryRepository
```

#### 2.4 IndustryDecisionService
```php
// src/TOM/Service/Import/IndustryDecisionService.php
// NEU erstellen
// State-Engine, Guards, Dropdown-Optionen
```

#### 2.5 ImportStagingService (neu)
```php
// src/TOM/Service/Import/ImportStagingService.php
// NEU erstellen
// Ersetzt OrgImportService::importToStaging()
// Nutzt IndustryResolver für buildIndustryResolution()
```

#### 2.6 ImportCommitService
```php
// src/TOM/Service/Import/ImportCommitService.php
// NEU erstellen
```

**Wichtig:** Alte Services (`OrgImportService`, `ImportIndustryValidationService`) bleiben temporär, werden aber nicht mehr erweitert.

---

### Phase 3: Alten Code ersetzen

#### 3.1 OrgImportService refactoren
**Strategie:** Schrittweise ersetzen

**Alt:**
```php
// OrgImportService::importToStaging()
// Nutzt alte Logik
```

**Neu:**
```php
// OrgImportService::importToStaging()
public function importToStaging(string $batchUuid, string $filePath): array
{
    // Delegiere an neuen ImportStagingService
    $stagingService = new ImportStagingService($this->db);
    return $stagingService->stageBatch($batchUuid, $filePath);
}
```

**Oder:** `OrgImportService` komplett durch neue Services ersetzen.

#### 3.2 ImportIndustryValidationService
**Strategie:** Komplett ersetzen durch `IndustryResolver`

**Alt:**
```php
// ImportIndustryValidationService::validateIndustries()
// Alte Matching-Logik
```

**Neu:**
```php
// ImportIndustryValidationService::validateIndustries()
public function validateIndustries(array $sampleRows, array $mappingConfig): array
{
    // Nutze IndustryResolver
    $resolver = new IndustryResolver($this->db);
    // ... neue Logik
}
```

**Oder:** `ImportIndustryValidationService` komplett löschen, Logik in `IndustryResolver`.

#### 3.3 Alte Dateien löschen/bereinigen
- ❌ Alte, nicht mehr genutzte Methoden entfernen
- ❌ Code-Duplikation entfernen
- ✅ Nur neue, saubere Struktur behalten

---

### Phase 4: API-Endpoints neu

#### 4.1 public/api/import.php komplett neu schreiben
**Strategie:** Alte Handler entfernen, neue implementieren

**Alt:**
```php
// handleImportToStaging() - alte Logik
```

**Neu:**
```php
// handleImportToStaging() - nutzt ImportStagingService
function handleImportToStaging($stagingService, $batchUuid, $userId) {
    $filePath = ...; // Hole aus BlobService
    $result = $stagingService->stageBatch($batchUuid, $filePath);
    return $result;
}

// NEU: handleGetStagingRow()
function handleGetStagingRow($stagingService, $stagingUuid) {
    $row = $stagingService->getStagingRow($stagingUuid);
    return formatStagingRowResponse($row);
}

// NEU: handleIndustryDecision()
function handleIndustryDecision($decisionService, $stagingUuid, $request, $userId) {
    $result = $decisionService->applyDecision($stagingUuid, $request, $userId);
    return $result;
}

// NEU: handleCommitBatch()
function handleCommitBatch($commitService, $batchUuid, $request, $userId) {
    $result = $commitService->commitBatch($batchUuid, $userId);
    return $result;
}
```

---

### Phase 5: Frontend komplett neu

#### 5.1 import.js komplett neu schreiben
**Strategie:** Alte Logik entfernen, neue implementieren

**Entfernen:**
- ❌ Doppelte Funktionen
- ❌ Alte `industryDecisions` Logik (nur Browser)
- ❌ Alte Mapping-Logik

**Neu:**
- ✅ Nutzt nur neue APIs
- ✅ State-Engine serverseitig
- ✅ Dropdowns server-driven
- ✅ Saubere Struktur

**Beispiel:**
```javascript
// Alt:
this.industryDecisions[excelValue] = {...};  // Nur Browser

// Neu:
async saveIndustryDecision(stagingUuid, decision) {
    const response = await fetch(`/api/import/staging/${stagingUuid}/industry-decision`, {
        method: 'POST',
        body: JSON.stringify(decision)
    });
    // Update UI mit response.dropdown_options, response.guards
}
```

---

## Konkrete Umsetzungsreihenfolge

### Schritt 1: Migrationen ausführen
```bash
php scripts/run-migration-048.php
php scripts/run-migration-049.php
php scripts/run-migration-050.php
```

### Schritt 2: Neue Services erstellen (parallel)
1. `IndustryNormalizer.php` - NEU
2. `IndustryResolver.php` - NEU (nicht refactored)
3. `IndustryDecisionService.php` - NEU
4. `ImportStagingService.php` - NEU
5. `ImportCommitService.php` - NEU

### Schritt 3: API-Endpoints neu schreiben
- `public/api/import.php` komplett neu
- Nutzt nur neue Services

### Schritt 4: Frontend neu schreiben
- `public/js/modules/import.js` komplett neu
- Nutzt nur neue APIs

### Schritt 5: Alten Code entfernen
- Alte Methoden aus `OrgImportService` entfernen
- `ImportIndustryValidationService` durch `IndustryResolver` ersetzen
- Code-Duplikation entfernen

---

## Vorteile dieser Strategie

### 1. Sauberer Code
- ✅ Keine Legacy-Ballast
- ✅ Konsistente Struktur
- ✅ Einfacher zu verstehen

### 2. Schnellere Umsetzung
- ✅ Keine Rückwärtskompatibilität nötig
- ✅ Keine Migration alter Daten
- ✅ Direkt neue Struktur

### 3. Weniger Fehler
- ✅ Keine Inkonsistenzen zwischen alt/neu
- ✅ Keine Code-Duplikation
- ✅ Klare Verantwortlichkeiten

---

## Risiken & Mitigation

### Risiko 1: Alte Funktionalität geht verloren
**Mitigation:**
- ✅ Checkliste: Alle Features aus altem Code identifizieren
- ✅ Neue Services müssen alle Features abdecken
- ✅ Testing vor Löschen des alten Codes

### Risiko 2: Großer Big-Bang-Change
**Mitigation:**
- ✅ Services parallel entwickeln (alt bleibt)
- ✅ Schrittweise ersetzen (nicht alles auf einmal)
- ✅ Feature-Branch für gesamten Change

### Risiko 3: Unerwartete Abhängigkeiten
**Mitigation:**
- ✅ Code-Analyse: Welche Dateien nutzen alte Services?
- ✅ Alle Abhängigkeiten identifizieren
- ✅ Schrittweise Migration

---

## Empfohlene Vorgehensweise

### Option A: Komplett neu (empfohlen)
1. ✅ Neue Services erstellen (parallel)
2. ✅ Neue APIs erstellen (nutzen neue Services)
3. ✅ Frontend neu schreiben (nutzt neue APIs)
4. ✅ Alten Code löschen
5. ✅ Testing

**Vorteil:** Sauber, schnell, konsistent

### Option B: Schrittweise Migration
1. ✅ Neue Services erstellen
2. ✅ Alte Services nutzen neue Services intern
3. ✅ Schrittweise alte Logik ersetzen
4. ✅ Alten Code entfernen

**Vorteil:** Weniger Risiko, aber mehr Aufwand

---

## Checkliste vor Löschen des alten Codes

### Funktionen prüfen:
- [ ] Upload funktioniert
- [ ] Excel-Analyse funktioniert
- [ ] Mapping funktioniert
- [ ] Staging-Import funktioniert
- [ ] Industry-Validierung funktioniert
- [ ] Industry-Entscheidungen funktionieren
- [ ] Commit funktioniert

### Abhängigkeiten prüfen:
- [ ] Keine anderen Dateien nutzen alte Services direkt
- [ ] Alle API-Endpoints nutzen neue Services
- [ ] Frontend nutzt nur neue APIs

---

## Fazit

**Ja, kompletter Neuaufbau ist besser!**

**Empfehlung:**
1. ✅ Neue Services komplett neu schreiben
2. ✅ APIs komplett neu schreiben
3. ✅ Frontend komplett neu schreiben
4. ✅ Alten Code löschen

**Vorteile überwiegen deutlich** - sauberer, schneller, wartbarer.

**Soll ich mit dieser Strategie starten?** Das würde bedeuten:
- Phase 1: Migrationen ausführen
- Phase 2: Neue Services komplett neu erstellen
- Phase 3: APIs neu schreiben
- Phase 4: Frontend neu schreiben
- Phase 5: Alten Code entfernen
