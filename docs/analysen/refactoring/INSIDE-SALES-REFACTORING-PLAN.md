# Inside Sales Refactoring Plan

**Ziel:** `inside-sales.js` (2.019 Zeilen) in 4 Module aufteilen

## Zielstruktur

```
public/js/modules/
├── inside-sales.js              # Koordinator (~200 Zeilen)
├── inside-sales-queue.js        # Queue-Übersicht (~400 Zeilen)
├── inside-sales-dialer.js       # Dialer/Lead-Player (~800 Zeilen)
├── inside-sales-timeline.js    # Timeline-Management (~300 Zeilen)
└── inside-sales-disposition.js  # Disposition & Handover (~400 Zeilen)
```

## Aufteilung der Methoden

### inside-sales.js (Koordinator)
- `constructor()`
- `init()`
- `getApiUrl()` (Helper)
- `loadSpecificLead()`
- Delegation an Sub-Module

### inside-sales-queue.js
- `initQueue()`
- `loadQueue()`
- `setupSortHandlers()`
- `updateSortIndicator()`
- `renderEmptyDialer()` (wird von Dialer verwendet)

### inside-sales-dialer.js
- `initDialer()`
- `setupDialerEvents()`
- `loadDialerQueue()`
- `loadNextLead()`
- `renderLeadCard()`
- `setStars()`
- `loadPersonsList()`
- `openCompanyEdit()`
- `openAddPerson()`
- `refreshCurrentLead()`
- `setupOrgEditCloseListener()`
- `setupPersonFormCloseListener()`

### inside-sales-timeline.js
- `loadTimeline()`
- (Timeline-spezifische Rendering-Logik)

### inside-sales-disposition.js
- `openDisposition()`
- `closeDisposition()`
- `setSnooze()`
- `saveDisposition()`
- `openHandoverForm()`
- `closeHandoverForm()`
- `submitHandover()`
- `startCallWithNumber()`
- `startCallPolling()`
- `stopCallPolling()`
- `endCall()`
- `getCallStatusText()`
- `markLeadAsInProgress()`

## Abhängigkeiten

- Alle Module importieren `Utils` aus `utils.js`
- Koordinator importiert alle Sub-Module
- Sub-Module haben Zugriff auf `this.app` und `this.currentWorkItem` über Koordinator

## State-Management

- `currentWorkItem`: Im Koordinator
- `queue`: Im Queue-Modul
- `timeline`: Im Timeline-Modul
- `currentCall`: Im Disposition-Modul
- `isDispositionOpen`: Im Disposition-Modul
- `currentMode`: Im Koordinator
- `currentSort`: Im Queue-Modul
- `currentTab`: Im Koordinator

## Migration-Strategie

1. ✅ Plan erstellen
2. ⏳ inside-sales-queue.js erstellen
3. ⏳ inside-sales-dialer.js erstellen
4. ⏳ inside-sales-timeline.js erstellen
5. ⏳ inside-sales-disposition.js erstellen
6. ⏳ inside-sales.js als Koordinator umbauen
7. ⏳ Testen

