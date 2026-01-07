# Import-Modul Refactoring Plan

**Ziel:** `import.js` (3.300 Zeilen) in 5 Module aufteilen

## Zielstruktur

```
public/js/modules/
├── import.js                    # Koordinator (~200 Zeilen)
├── import-overview.js           # Übersichtsseite (~300 Zeilen)
├── import-upload.js             # Upload-Schritt (~400 Zeilen)
├── import-mapping.js            # Mapping-Schritt (~600 Zeilen)
├── import-industry-check.js     # Branchen-Prüfung (~800 Zeilen)
└── import-review.js             # Review & Commit (~1000 Zeilen)
```

## Aufteilung der Methoden

### import.js (Koordinator)
- `constructor()`
- `init()`
- `fetchWithToken()` (Helper)
- `goToStep()`
- `loadExistingBatch()`
- Delegation an Sub-Module

### import-overview.js
- `renderOverviewPage()`
- `loadBatchesList()`
- `renderBatchesList()`
- `startNewImport()`
- `openBatch()`
- `deleteBatch()`
- `showOverview()`
- `showImportSummary()`

### import-upload.js
- `renderImportPage()` (nur Step 1)
- `handleUpload()`
- `setupEventHandlers()` (nur Upload-bezogen)

### import-mapping.js
- `renderMappingStep()`
- `renderMappingUI()`
- `saveMapping()`
- `reloadAnalysisForBatch()`
- `convertMappingConfigToSuggestion()`
- `getHeaderNameForColumn()`
- `getFieldLabel()`
- `importToStaging()`

### import-industry-check.js
- `renderIndustryCheckStep()`
- `extractIndustryCombinations()`
- `renderIndustryWarnings()`
- `renderIndustryCombination()`
- `checkAllIndustriesConfirmed()`
- `updateConfirmIndustriesButton()`
- `confirmAllIndustries()`
- `confirmLevel1()`
- `confirmLevel2()`
- `onLevel1Selected()`
- `onLevel2Selected()`
- `onLevel3Selected()`
- `loadLevel2Options()`
- `loadLevel3Options()`
- `loadAllLevel1Options()`
- `activateLevel1()`
- `activateLevel2()`
- `activateLevel3()`
- `resetLevel1()`
- `resetLevel2()`
- `resetLevel3()`
- `addLevel3FromCombo()`
- `updateDropdownOptions()`

### import-review.js
- `renderReviewStep()`
- `renderReviewUI()`
- `loadStagingRows()`
- `saveCorrections()`
- `updateRowInTable()`
- `commitBatch()`
- `getFilePathForBatch()`

## Abhängigkeiten

- Alle Module importieren `Utils` aus `utils.js`
- Koordinator importiert alle Sub-Module
- Sub-Module haben Zugriff auf `this.app` und `this.currentBatch` über Koordinator

## State-Management

- `currentBatch`: Im Koordinator
- `currentStep`: Im Koordinator
- `stagingRows`: Im Koordinator (für Review)
- `stagingImported`: Im Koordinator
- `analysis`: Im Mapping-Modul
- `industryCombinations`: Im Industry-Check-Modul

## Migration-Strategie

1. ✅ Plan erstellen
2. ⏳ import-overview.js erstellen
3. ⏳ import-upload.js erstellen
4. ⏳ import-mapping.js erstellen
5. ⏳ import-industry-check.js erstellen
6. ⏳ import-review.js erstellen
7. ⏳ import.js als Koordinator umbauen
8. ⏳ Testen


