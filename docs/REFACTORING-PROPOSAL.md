# Refactoring-Vorschlag: Modulare JavaScript-Struktur

## Problem
- `app.js` hat über 2000 Zeilen
- Ein Fehler kann das gesamte System lahmlegen
- Schwer zu warten und zu testen
- Hohes Risiko bei Änderungen

## Empfehlung: ES6 Module-basierte Struktur

### Neue Struktur:
```
public/js/
├── app.js                    (~100 Zeilen - nur Initialisierung)
├── modules/
│   ├── auth.js              (~150 Zeilen - Login, Session, User-Info)
│   ├── admin.js             (~200 Zeilen - Admin-Bereich, User-Verwaltung)
│   ├── org-search.js        (~200 Zeilen - Organisationen-Suche)
│   ├── org-detail.js        (~400 Zeilen - Organisations-Details, Rendering)
│   ├── org-forms.js         (~200 Zeilen - Create/Edit Organisation)
│   ├── org-address.js       (~150 Zeilen - Adressen-Management)
│   ├── org-channel.js       (~150 Zeilen - Kommunikationskanäle)
│   ├── org-vat.js           (~150 Zeilen - USt-ID-Verwaltung)
│   ├── org-relation.js      (~200 Zeilen - Organisations-Relationen)
│   └── utils.js             (~50 Zeilen - Helper-Funktionen)
└── api.js                    (bleibt wie ist)
```

### Vorteile:
1. **Isolation**: Fehler in einem Modul betreffen nicht andere
2. **Wartbarkeit**: Jedes Modul < 400 Zeilen, klare Verantwortlichkeiten
3. **Testbarkeit**: Module können einzeln getestet werden
4. **Parallele Entwicklung**: Mehrere Entwickler können gleichzeitig arbeiten
5. **Einfacheres Debugging**: Fehler sind leichter zu lokalisieren

### Nachteile:
1. **Initialer Aufwand**: Refactoring benötigt 2-3 Stunden
2. **Mehr Dateien**: Mehr Dateien zu verwalten (aber übersichtlicher)
3. **Module-Loading**: Browser lädt mehrere Dateien (mit HTTP/2 kein Problem)

## Implementierung: ES6 Modules

### Warum ES6 Modules?
- ✅ Moderne Browser unterstützen `type="module"` nativ
- ✅ Saubere Imports/Exports
- ✅ Kein Build-System nötig
- ✅ Tree-shaking möglich (später mit Build-Tool)

### Beispiel-Struktur:

#### app.js (Hauptklasse - nur ~100 Zeilen)
```javascript
import { AuthModule } from './modules/auth.js';
import { AdminModule } from './modules/admin.js';
import { OrgSearchModule } from './modules/org-search.js';
import { OrgDetailModule } from './modules/org-detail.js';
import { OrgFormsModule } from './modules/org-forms.js';
import { OrgAddressModule } from './modules/org-address.js';
import { OrgChannelModule } from './modules/org-channel.js';
import { OrgVatModule } from './modules/org-vat.js';
import { OrgRelationModule } from './modules/org-relation.js';
import { Utils } from './modules/utils.js';

class TOM3App {
    constructor() {
        this.currentPage = localStorage.getItem('currentPage') || 'dashboard';
        
        // Initialisiere Module
        this.auth = new AuthModule(this);
        this.admin = new AdminModule(this);
        this.orgSearch = new OrgSearchModule(this);
        this.orgDetail = new OrgDetailModule(this);
        this.orgForms = new OrgFormsModule(this);
        this.orgAddress = new OrgAddressModule(this);
        this.orgChannel = new OrgChannelModule(this);
        this.orgVat = new OrgVatModule(this);
        this.orgRelation = new OrgRelationModule(this);
        this.utils = Utils; // Statische Helper
        
        this.init();
    }
    
    async init() {
        await this.auth.loadCurrentUser();
        this.setupEventListeners();
        this.setupNavigation();
        this.navigateTo(this.currentPage, false);
    }
    
    setupEventListeners() {
        // Nur Basis-Event-Listener
    }
    
    setupNavigation() {
        // Navigation-Logik
    }
    
    navigateTo(page, storePage = true) {
        // Navigation-Logik, delegiert an Module
        switch(page) {
            case 'orgs':
                this.orgSearch.init();
                break;
            case 'admin':
                this.admin.load();
                break;
            // ...
        }
    }
    
    // Gemeinsame Methoden
    showError(message) {
        console.error(message);
        alert(message);
    }
    
    showSuccess(message) {
        console.log(message);
    }
}

// Initialize app
const app = new TOM3App();
window.app = app;
```

#### modules/org-detail.js (Beispiel)
```javascript
import { Utils } from './utils.js';

export class OrgDetailModule {
    constructor(app) {
        this.app = app; // Referenz zur Hauptklasse
    }
    
    async showOrgDetail(orgUuid) {
        try {
            const org = await window.API.getOrgDetails(orgUuid);
            if (!org) {
                this.app.showError('Organisation nicht gefunden');
                return;
            }
            
            const modalBody = document.getElementById('modal-org-body');
            if (modalBody) {
                modalBody.innerHTML = this.renderOrgDetail(org);
                await this.loadAccountOwnersForEdit(orgUuid, org.account_owner_user_id);
            }
            
            document.getElementById('modal-org-detail')?.classList.add('active');
        } catch (error) {
            console.error('Error loading org detail:', error);
            this.app.showError('Fehler beim Laden der Organisationsdetails');
        }
    }
    
    renderOrgDetail(org) {
        // Rendering-Logik
    }
    
    async loadAccountOwnersForEdit(orgUuid, currentOwnerId) {
        // Account Owner Loading
    }
    
    // ... weitere Methoden
}
```

#### modules/utils.js
```javascript
export const Utils = {
    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    },
    
    closeModal() {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
    },
    
    // ... weitere Helper
};
```

## Migration-Strategie (Schrittweise)

### Phase 1: Vorbereitung (30 Min)
1. `modules/` Verzeichnis erstellen
2. `utils.js` extrahieren (Helper-Funktionen)
3. Testen ob alles noch funktioniert

### Phase 2: Erste Module (1-2 Stunden)
1. `org-detail.js` extrahieren (größtes Risiko)
2. `org-forms.js` extrahieren
3. Testen

### Phase 3: Weitere Module (1-2 Stunden)
1. `org-address.js`, `org-channel.js`, `org-vat.js`, `org-relation.js`
2. `org-search.js`
3. `admin.js`
4. `auth.js`

### Phase 4: Cleanup (30 Min)
1. Alte `app.js` aufräumen
2. Alle Tests durchführen
3. Dokumentation aktualisieren

## HTML-Anpassung

```html
<!-- Vorher -->
<script src="js/api.js"></script>
<script src="js/app.js"></script>

<!-- Nachher -->
<script src="js/api.js"></script>
<script type="module" src="js/app.js"></script>
```

## Risiko-Minimierung

1. **Git-Branch**: Refactoring in separatem Branch
2. **Schrittweise**: Ein Modul nach dem anderen
3. **Tests**: Nach jedem Modul testen
4. **Rollback**: Alte `app.js` als Backup behalten

## Empfehlung

**JA, definitiv refactoren!**

**Gründe:**
- Aktuelles Risiko ist zu hoch (wie gerade erlebt)
- Modulare Struktur ist Standard in modernen Apps
- Wartbarkeit wird deutlich besser
- Initialer Aufwand lohnt sich langfristig

**Zeitaufwand:** 3-4 Stunden für vollständiges Refactoring

**Alternative (wenn Zeit knapp):**
- Mindestens `org-detail.js` extrahieren (größtes Risiko)
- Rest später nachziehen
