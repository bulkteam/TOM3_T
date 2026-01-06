/**
 * TOM3 - Inside Sales Module
 * Lead-Player (Telefonmodus) für Inside Sales Workflow
 */

import { Utils } from './utils.js';

export class InsideSalesModule {
    constructor(app) {
        this.app = app;
        
        // Helper: Ermittelt Base-Path für API-Calls
        this.getApiUrl = (path) => {
            const basePath = window.location.pathname
                .replace(/\/index\.html$/, '')
                .replace(/\/login\.php$/, '')
                .replace(/\/monitoring\.html$/, '')
                .replace(/\/$/, '') || '';
            return `${basePath}/api${path.startsWith('/') ? path : '/' + path}`;
        };
        this.currentWorkItem = null;
        this.queue = [];
        this.timeline = [];
        this.isDispositionOpen = false;
        this.currentCall = null;
        this.callPollingInterval = null;
        this.currentActivityId = null;
        this.isInitializing = false;
        this.currentMode = null; // 'queue' oder 'dialer'
        this.currentSort = { field: 'stars', direction: 'desc' }; // Standard-Sortierung: Priorität absteigend
        this.currentTab = 'new'; // Aktueller Tab (new, due, in_progress, snoozed, qualified)
    }
    
    /**
     * Initialisiert Inside Sales Seite
     */
    async init() {
        const page = document.getElementById('page-inside-sales');
        if (!page) {
            console.error('[InsideSales] page-inside-sales nicht gefunden');
            return;
        }
        
        // Prüfe, ob Dialer-Modus
        const hash = window.location.hash;
        
        // Extrahiere Parameter aus Hash (z.B. #inside-sales/dialer?lead=uuid&tab=new)
        let leadUuid = null;
        let tabParam = null;
        let sortFieldParam = null;
        let sortOrderParam = null;
        
        if (hash.includes('?')) {
            const hashParts = hash.split('?');
            const hashParams = new URLSearchParams(hashParts[1]);
            leadUuid = hashParams.get('lead');
            tabParam = hashParams.get('tab');
            sortFieldParam = hashParams.get('sort');
            sortOrderParam = hashParams.get('order');
        }
        
        // Fallback: Prüfe auch window.location.search (für direkte URLs)
        if (!leadUuid) {
            const urlParams = new URLSearchParams(window.location.search);
            leadUuid = urlParams.get('lead');
            if (!tabParam) {
                tabParam = urlParams.get('tab');
            }
            if (!sortFieldParam) {
                sortFieldParam = urlParams.get('sort');
            }
            if (!sortOrderParam) {
                sortOrderParam = urlParams.get('order');
            }
        }
        
        const isDialerMode = hash.includes('dialer') || hash.includes('inside-sales/dialer');
        
        // Setze aktuellen Tab (aus URL oder aus gespeichertem State)
        if (tabParam) {
            // Tab explizit im Hash vorhanden -> verwende diesen
            this.currentTab = tabParam;
        } else {
            // Kein Tab im Hash
            if (isDialerMode) {
                // Dialer-Modus: Behalte currentTab (wird vom Dialer gesetzt oder aus vorherigem State)
                // Wenn currentTab noch nicht gesetzt ist (z.B. beim ersten Öffnen), verwende 'new'
                if (!this.currentTab) {
                    this.currentTab = 'new';
                }
            } else {
                // Queue-Modus: Kein Tab im Hash -> verwende Default 'new'
                // WICHTIG: Nur wenn wir vom Schließen-Button kommen, ist der Tab im Hash
                // Bei Menü-Wechsel oder Hard Reload ist kein Tab im Hash -> immer 'new'
                this.currentTab = 'new';
            }
        }
        
        // Setze Sortierung aus Hash (wenn vorhanden)
        if (sortFieldParam && sortOrderParam) {
            this.currentSort = { field: sortFieldParam, direction: sortOrderParam };
        } else if (!this.currentSort) {
            // Fallback: Standard-Sortierung, falls nicht gesetzt
            this.currentSort = { field: 'stars', direction: 'desc' };
        }
        const targetMode = isDialerMode ? 'dialer' : 'queue';
        
        // Prüfe, ob Mode sich ändert (wichtig für Lock-Mechanismus)
        const modeChanged = this.currentMode !== targetMode;
        
        // Verhindere parallele Initialisierung, AUSSER wenn Mode sich ändert
        if (this.isInitializing && !modeChanged) {
            return;
        }
        
        this.isInitializing = true;
        
        try {
            
            // Prüfe, ob bereits die richtige Ansicht geladen ist
            // WICHTIG: Nur überspringen, wenn Mode gleich ist UND (für Dialer) der gleiche Lead geladen ist
            // Wenn Mode sich ändert (z.B. dialer → queue), immer neu laden
            
            if (isDialerMode) {
                // Dialer-Modus
                if (this.currentMode === 'dialer') {
                    // Dialer ist bereits geladen
                    if (leadUuid && this.currentWorkItem && this.currentWorkItem.case_uuid === leadUuid) {
                        return;
                    } else if (leadUuid && (!this.currentWorkItem || this.currentWorkItem.case_uuid !== leadUuid)) {
                        await this.loadSpecificLead(leadUuid);
                        return;
                    }
                    // Wenn kein leadUuid, lade Dialer neu
                }
                // Mode ändert sich von queue → dialer, oder Dialer muss neu geladen werden
                this.currentMode = 'dialer';
                await this.initDialer(page);
                // Wenn Lead-UUID in URL, lade diesen Lead
                if (leadUuid) {
                    await this.loadSpecificLead(leadUuid);
                }
            } else {
                // Queue-Modus
                // Wenn Mode sich ändert (dialer → queue), immer neu laden
                // Wenn Mode gleich ist (queue → queue), auch neu laden (Menü-Klick)
                this.currentMode = 'queue';
                await this.initQueue(page);
            }
        } finally {
            this.isInitializing = false;
        }
    }
    
    /**
     * Lädt spezifischen Lead (aus URL-Parameter)
     */
    async loadSpecificLead(workItemUuid) {
        try {
            // Warte, bis DOM-Elemente verfügbar sind
            let retries = 10;
            while (retries > 0 && !document.getElementById('lead-company-name')) {
                await new Promise(resolve => setTimeout(resolve, 100));
                retries--;
            }
            
            if (!document.getElementById('lead-company-name')) {
                console.error('DOM-Elemente für Lead-Karte nicht gefunden nach Wartezeit');
                Utils.showError('Fehler: Dialer-Elemente nicht gefunden');
                return;
            }
            
            let workItem = await window.API.request(`/work-items/${workItemUuid}`);
            
            if (workItem) {
                this.currentWorkItem = workItem;
                
                // Status wird NICHT automatisch geändert beim Öffnen
                // Der Lead bleibt in seinem ursprünglichen Stage (NEW, IN_PROGRESS, etc.)
                // Status ändert sich nur bei tatsächlichen Änderungen (Sterne setzen, Daten ändern, etc.)
                
                await this.renderLeadCard(workItem);
                await this.loadTimeline(workItem.case_uuid);
                
                // Aktualisiere Mini-Queue (markiere aktiven Lead) - verwende aktuellen Tab
                await this.loadDialerQueue(this.currentTab);
            }
        } catch (error) {
            console.error('Error loading specific lead:', error);
            Utils.showError('Fehler beim Laden des Leads');
        }
    }
    
    /**
     * Initialisiert Queue-Übersicht
     */
    async initQueue(container) {
        if (!container) {
            console.error('[InsideSales] initQueue: Container ist null oder undefined');
            return;
        }
        
        // Stelle sicher, dass der Container leer ist
        container.innerHTML = '';
        
        // Prüfe Hash-Parameter für Tab und Sortierung (als Fallback, falls init() sie nicht gesetzt hat)
        const hash = window.location.hash;
        let tabFromHash = null;
        let sortFromHash = null;
        
        if (hash.includes('?')) {
            const hashParts = hash.split('?');
            const hashParams = new URLSearchParams(hashParts[1]);
            tabFromHash = hashParams.get('tab');
            const sortField = hashParams.get('sort');
            const sortOrder = hashParams.get('order');
            if (sortField && sortOrder) {
                sortFromHash = { field: sortField, direction: sortOrder };
            }
        }
        
        // Setze Tab aus Hash (Hash hat Vorrang, da er die aktuelle Navigation widerspiegelt)
        if (tabFromHash) {
            this.currentTab = tabFromHash;
        }
        
        // Setze Sortierung aus Hash (Hash hat Vorrang)
        if (sortFromHash) {
            this.currentSort = sortFromHash;
        }
        
        container.innerHTML = `
            <div class="page-header">
                <h2>📞 Inside Sales</h2>
                <p class="page-description">Verwalten Sie Ihre Leads und arbeiten Sie sie ab</p>
            </div>
            
            <div style="margin-bottom: 24px;">
                <a href="#inside-sales/dialer" class="btn btn-primary">
                    🎯 Lead-Player starten
                </a>
            </div>
            
            <div id="inside-sales-queue-tabs" class="tabs">
                <button class="tab-btn active" data-tab="new">Neu (<span id="count-new">0</span>)</button>
                <button class="tab-btn" data-tab="due">Fällig (<span id="count-due">0</span>)</button>
                <button class="tab-btn" data-tab="in_progress">In Arbeit (<span id="count-in_progress">0</span>)</button>
                <button class="tab-btn" data-tab="snoozed">Wiedervorlage (<span id="count-snoozed">0</span>)</button>
                <button class="tab-btn" data-tab="qualified">Qualifiziert (<span id="count-qualified">0</span>)</button>
            </div>
            
            <div class="work-item-table-container">
                <div class="work-item-table-header">
                    <div class="work-item-header-cell sortable" data-sort="name">
                        <span>Firma</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="city">
                        <span>Ort</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="stars">
                        <span>Priorität</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="next_action">
                        <span>Wiedervorlage</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="last_touch">
                        <span>Letzter Touch</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell">
                        <span>Aktionen</span>
                    </div>
                </div>
                <div id="inside-sales-queue-list" class="work-item-list">
                    <!-- Wird dynamisch geladen -->
                </div>
            </div>
        `;
        
        // Setze aktiven Tab-Button basierend auf currentTab (aus Hash oder Default)
        setTimeout(() => {
            document.querySelectorAll('#inside-sales-queue-tabs .tab-btn').forEach(btn => {
                if (btn.dataset.tab === this.currentTab) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }, 50);
        
        // Tab-Event-Listener
        document.querySelectorAll('#inside-sales-queue-tabs .tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const tab = btn.dataset.tab;
                this.currentTab = tab; // Speichere aktuellen Tab
                this.loadQueue(tab);
                
                // Update "Lead-Player starten" Button mit Tab-Parameter und Sortierung
                const startButton = document.querySelector('a[href="#inside-sales/dialer"], a[href*="inside-sales/dialer"]');
                if (startButton) {
                    const sortParam = `sort=${this.currentSort.field}&order=${this.currentSort.direction}`;
                    startButton.href = `#inside-sales/dialer?tab=${tab}&${sortParam}`;
                }
            });
        });
        
        // Update "Lead-Player starten" Button mit initialem Tab und Sortierung
        const startButton = document.querySelector('a[href*="inside-sales/dialer"]');
        if (startButton) {
            const sortParam = `sort=${this.currentSort.field}&order=${this.currentSort.direction}`;
            startButton.href = `#inside-sales/dialer?tab=${this.currentTab}&${sortParam}`;
        }
        
        // Sortier-Event-Listener (wird nach DOM-Rendering gesetzt)
        setTimeout(() => {
            this.setupSortHandlers();
        }, 100);
        
        // Setze initiale Sortier-Anzeige
        setTimeout(() => {
            this.updateSortIndicator();
        }, 150);
        
        // Lade initial mit aktuellem Tab (aus Hash oder Default)
        const initialTab = this.currentTab || 'new';
        try {
            await this.loadQueue(initialTab);
        } catch (error) {
            console.error('[InsideSales] Fehler beim Laden der Queue:', error);
            Utils.showError('Fehler beim Laden der Queue: ' + error.message);
        }
    }
    
    /**
     * Initialisiert Lead-Player (Dialer)
     */
    async initDialer(container) {
        if (!container) {
            console.error('initDialer: Container ist null oder undefined');
            return;
        }
        
        // Stelle sicher, dass der Container leer ist, bevor wir die Dialer-HTML einfügen
        // (könnte noch Queue-HTML enthalten)
        container.innerHTML = '';
        
        container.innerHTML = `
            <div class="dialer-container">
                <!-- Zeile 1: Button-Leiste (volle Breite) -->
                <div class="dialer-actions-bar">
                    <div class="dialer-nav-buttons">
                        <button id="btn-next-lead" class="btn btn-primary">Nächster</button>
                        <button id="btn-close-dialer" class="btn btn-secondary">Schließen</button>
                    </div>
                    <div class="dialer-separator"></div>
                    <div class="dialer-outcome-buttons">
                        <button class="outcome-btn" data-outcome="erreicht">✅ Erreicht</button>
                        <button class="outcome-btn" data-outcome="nicht_erreicht">❌ Nicht erreicht</button>
                        <button class="outcome-btn" data-outcome="rueckruf">📞 Rückruf</button>
                        <button class="outcome-btn" data-outcome="falsche_nummer">⚠️ Falsche Nummer</button>
                        <button class="outcome-btn" data-outcome="kein_bedarf">🚫 Kein Bedarf</button>
                        <button class="outcome-btn" data-outcome="qualifiziert">⭐ Qualifiziert</button>
                    </div>
                </div>
                
                <!-- Zeile 2: Drei Spalten nebeneinander -->
                <div class="dialer-content-row">
                    <!-- Linke Spalte: Mini-Queue -->
                    <div class="dialer-queue">
                        <div class="dialer-queue-header">
                            <h3>Aktuelle Queue</h3>
                        </div>
                        <div id="dialer-queue-list" class="dialer-queue-list">
                            <!-- Wird dynamisch geladen -->
                        </div>
                    </div>
                    
                    <!-- Mitte: Lead-Karte -->
                    <div class="dialer-main">
                        <div id="dialer-lead-card" class="dialer-lead-card">
                            <!-- Firmenansicht -->
                            <div class="lead-company-view">
                                <div class="lead-card-header">
                                    <h2>
                                        <span id="lead-company-name">-</span>
                                        <span id="lead-company-edit" class="lead-company-edit" style="cursor: pointer; margin-left: 8px; opacity: 0.6;" title="Firma bearbeiten">✏️</span>
                                    </h2>
                                    <div id="lead-stars" class="lead-stars">
                                        <!-- Wird dynamisch geladen -->
                                    </div>
                                </div>
                                
                                <div class="lead-card-info">
                                    <div class="info-row">
                                        <span class="info-label">📍 Ort:</span>
                                        <span id="lead-city">-</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">📞 Telefon:</span>
                                        <a id="lead-company-phone" class="phone-link" href="#" title="Anrufen">-</a>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">🌐 Website:</span>
                                        <a id="lead-website" href="#" target="_blank">-</a>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">📅 Letzter Touch:</span>
                                        <span id="lead-last-touch">-</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Personenansicht -->
                            <div class="lead-persons-view">
                                <div class="lead-persons-header-inline">
                                    <span class="lead-persons-label">👥 Personen</span>
                                    <button id="btn-add-person" class="btn-add-person btn-add-person-white" title="Neue Person hinzufügen">+</button>
                                </div>
                                
                                <!-- Call Status (versteckt) -->
                                <div id="call-status" class="call-status" style="display: none;">
                                    <div class="call-status-info">
                                        <span id="call-status-text">-</span>
                                        <span id="call-timer">00:00</span>
                                    </div>
                                    <button id="btn-end-call" class="btn btn-sm btn-danger">Beenden</button>
                                </div>
                                
                                <div id="lead-persons-list" class="lead-persons-list">
                                    <!-- Wird dynamisch geladen -->
                                </div>
                            </div>
                        
                        <!-- Disposition Sheet (dauerhaft sichtbar) -->
                        <div id="disposition-sheet" class="disposition-sheet">
                            <h3>Disposition</h3>
                            
                            <div class="form-group">
                                <label>Notiz:</label>
                                <textarea id="disposition-notes" rows="3" placeholder="Notiz eingeben..."></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Wiedervorlage:</label>
                                <div class="snooze-buttons">
                                    <button class="snooze-btn" data-offset="today-16">Heute 16:00</button>
                                    <button class="snooze-btn" data-offset="tomorrow-10">Morgen 10:00</button>
                                    <button class="snooze-btn" data-offset="+3d">+3 Tage</button>
                                    <button class="snooze-btn" data-offset="+1w">+1 Woche</button>
                                    <input type="datetime-local" id="snooze-custom" style="margin-top: 8px;">
                                </div>
                            </div>
                            
                            <div class="disposition-actions">
                                <button id="btn-save-next" class="btn btn-primary">💾 Save & Next</button>
                                <button id="btn-qualify" class="btn btn-success">⭐ Qualifiziert → Angebot</button>
                                <button id="btn-data-check" class="btn btn-warning">🔍 Unklare Daten → Data Check</button>
                                <button id="btn-cancel-disposition" class="btn btn-secondary">Abbrechen</button>
                            </div>
                        </div>
                        
                        <!-- Handover Form - Quote Request (versteckt) -->
                        <div id="handover-form-quote" class="handover-form" style="display: none;">
                            <h3>Qualifiziert → Übergabe an Sales Ops (Angebot)</h3>
                            
                            <div class="form-group">
                                <label>Bedarf in 1 Satz <span class="required">*</span>:</label>
                                <textarea id="handover-need-summary" rows="2" placeholder="Kurze Beschreibung des Bedarfs..." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Ansprechpartner/Abteilung <span class="required">*</span>:</label>
                                <input type="text" id="handover-contact-hint" placeholder="z.B. 'Max Mustermann, Einkauf' oder 'unbekannt'" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Nächster Schritt <span class="required">*</span>:</label>
                                <input type="text" id="handover-next-step" placeholder="z.B. 'Angebot erstellen'" required>
                            </div>
                            
                            <div class="handover-actions">
                                <button id="btn-submit-handover-quote" class="btn btn-success">✅ Übergabe & Next</button>
                                <button id="btn-cancel-handover" class="btn btn-secondary">Abbrechen</button>
                            </div>
                        </div>
                        
                        <!-- Handover Form - Data Check (versteckt) -->
                        <div id="handover-form-data-check" class="handover-form" style="display: none;">
                            <h3>Unklare Daten → Data Check</h3>
                            
                            <div class="form-group">
                                <label>Was ist unklar? <span class="required">*</span>:</label>
                                <textarea id="data-check-issue" rows="2" placeholder="z.B. 'Telefonnummer fehlt', 'Dublette vermutet', 'Firmensitz unklar'..." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Was soll Sales Ops klären? <span class="required">*</span>:</label>
                                <textarea id="data-check-request" rows="2" placeholder="Konkrete To-dos für Sales Ops..." required></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Ansprechpartner/Abteilung:</label>
                                <input type="text" id="data-check-contact-hint" placeholder="z.B. 'unbekannt' oder 'Max Mustermann'">
                            </div>
                            
                            <div class="form-group">
                                <label>Nächster Schritt:</label>
                                <input type="text" id="data-check-next-step" placeholder="z.B. 'Daten klären und zurückgeben'">
                            </div>
                            
                            <div class="form-group">
                                <label>Links/Quellen (optional):</label>
                                <textarea id="data-check-links" rows="2" placeholder="Website, Handelsregister, LinkedIn, etc. (eine pro Zeile)"></textarea>
                            </div>
                            
                            <div class="handover-actions">
                                <button id="btn-submit-handover-data-check" class="btn btn-warning">✅ Data Check & Next</button>
                                <button id="btn-cancel-handover-data-check" class="btn btn-secondary">Abbrechen</button>
                            </div>
                        </div>
                    </div>
                    </div>
                    
                    <!-- Rechte Spalte: Timeline -->
                    <div class="dialer-timeline">
                        <div class="timeline-header">
                            <h3>Timeline</h3>
                        </div>
                        <div id="dialer-timeline-content" class="timeline-content">
                            <!-- Wird dynamisch geladen -->
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Warte, bis DOM-Elemente wirklich verfügbar sind
        // Verwende requestAnimationFrame für zuverlässige DOM-Verfügbarkeit
        await new Promise(resolve => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    resolve();
                });
            });
        });
        
        // Prüfe, ob Elemente im Container existieren (nicht nur im gesamten Dokument)
        let companyNameEl = container.querySelector('#lead-company-name');
        if (!companyNameEl) {
            // Fallback: Suche im gesamten Dokument
            companyNameEl = document.getElementById('lead-company-name');
        }
        
        if (!companyNameEl) {
            console.error('Dialer-Elemente konnten nicht erstellt werden');
            return;
        }
        
        // Event-Listener
        this.setupDialerEvents();
        
        // Lade Mini-Queue mit aktuellem Tab (wichtig: muss vor loadNextLead sein)
        const tab = this.currentTab || 'new';
        await this.loadDialerQueue(tab);
        
        // Lade ersten Lead automatisch, wenn kein Lead in URL angegeben
        const hash = window.location.hash;
        const hasLeadParam = hash.includes('?lead=');
        if (!hasLeadParam) {
            // Kein Lead in URL - lade automatisch den nächsten Lead aus dem aktuellen Tab
            await this.loadNextLead(tab, false); // false = nicht automatisch auf IN_PROGRESS setzen
        }
    }
    
    /**
     * Setup Event-Listener für Dialer
     */
    setupDialerEvents() {
        // Outcome-Buttons
        document.querySelectorAll('.outcome-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                // Entferne active-Klasse von allen Outcome-Buttons
                document.querySelectorAll('.outcome-btn').forEach(b => b.classList.remove('active'));
                // Markiere geklickten Button als active
                btn.classList.add('active');
                const outcome = btn.dataset.outcome;
                this.openDisposition(outcome);
            });
        });
        
        // Snooze-Buttons
        document.querySelectorAll('.snooze-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const offset = btn.dataset.offset;
                this.setSnooze(offset);
            });
        });
        
        // Save & Next
        document.getElementById('btn-save-next')?.addEventListener('click', () => {
            this.saveDisposition();
        });
        
        // Cancel
        document.getElementById('btn-cancel-disposition')?.addEventListener('click', () => {
            this.closeDisposition();
        });
        
        // Qualify Button
        document.getElementById('btn-qualify')?.addEventListener('click', () => {
            this.openHandoverForm('quote');
        });
        
        // Data Check Button
        document.getElementById('btn-data-check')?.addEventListener('click', () => {
            this.openHandoverForm('data_check');
        });
        
        // Handover Form - Quote
        document.getElementById('btn-submit-handover-quote')?.addEventListener('click', () => {
            this.submitHandover('QUOTE_REQUEST');
        });
        
        document.getElementById('btn-cancel-handover')?.addEventListener('click', () => {
            this.closeHandoverForm();
        });
        
        // Handover Form - Data Check
        document.getElementById('btn-submit-handover-data-check')?.addEventListener('click', () => {
            this.submitHandover('DATA_CHECK');
        });
        
        document.getElementById('btn-cancel-handover-data-check')?.addEventListener('click', () => {
            this.closeHandoverForm();
        });
        
        // Next Lead Button - verwende setTimeout, um sicherzustellen, dass Button im DOM ist
        setTimeout(() => {
            const btnNext = document.getElementById('btn-next-lead');
            if (btnNext) {
                // Entferne alle alten Event-Listener, indem wir den Button ersetzen
                const newBtn = btnNext.cloneNode(true);
                btnNext.parentNode?.replaceChild(newBtn, btnNext);
                newBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    // Nächster Lead laden - Status wird NICHT automatisch geändert
                    // Status ändert sich nur bei tatsächlichen Änderungen (Sterne, Daten, etc.)
                    await this.loadNextLead(null, false);
                });
            } else {
                console.error('Button btn-next-lead nicht gefunden');
            }
        }, 100);
        
        // Close Dialer Button
        document.getElementById('btn-close-dialer')?.addEventListener('click', () => {
            // Navigiere zurück zur Queue-Ansicht (ohne Status-Änderung)
            // Hole Tab und Sortierung aus dem Hash (falls vorhanden), sonst verwende currentTab/currentSort
            const hash = window.location.hash;
            let tabToUse = this.currentTab || 'new';
            let sortField = this.currentSort?.field || 'stars';
            let sortOrder = this.currentSort?.direction || 'desc';
            
            // Prüfe ob Tab im Hash vorhanden (vom Öffnen-Link)
            if (hash.includes('?')) {
                const hashParts = hash.split('?');
                const hashParams = new URLSearchParams(hashParts[1]);
                const tabFromHash = hashParams.get('tab');
                const sortFieldFromHash = hashParams.get('sort');
                const sortOrderFromHash = hashParams.get('order');
                
                if (tabFromHash) {
                    tabToUse = tabFromHash;
                }
                if (sortFieldFromHash && sortOrderFromHash) {
                    sortField = sortFieldFromHash;
                    sortOrder = sortOrderFromHash;
                }
            }
            
            const sortParam = `sort=${sortField}&order=${sortOrder}`;
            window.location.hash = `inside-sales?tab=${tabToUse}&${sortParam}`;
        });
        
        // Phone Links - Event Delegation für alle Telefonnummern-Links
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('phone-link') || e.target.closest('.phone-link')) {
                e.preventDefault();
                const link = e.target.classList.contains('phone-link') ? e.target : e.target.closest('.phone-link');
                // Verwende data-phone Attribut falls vorhanden, sonst Text-Content
                const phoneNumber = link.dataset.phone || link.textContent.trim().replace(/📞|📱/g, '').trim();
                if (phoneNumber && phoneNumber !== '-') {
                    this.startCallWithNumber(phoneNumber);
                }
            }
        });
        
        // End Call Button
        document.getElementById('btn-end-call')?.addEventListener('click', () => {
            this.endCall();
        });
        
        // Company Edit Button (Stiftsymbol)
        document.getElementById('lead-company-edit')?.addEventListener('click', () => {
            this.openCompanyEdit();
        });
        
        // Add Person Button
        document.getElementById('btn-add-person')?.addEventListener('click', () => {
            this.openAddPerson();
        });
        
        // Hotkeys
        document.addEventListener('keydown', (e) => {
            if (this.isDispositionOpen) return; // Ignore hotkeys when disposition is open
            
            // Sterne: 1-5
            if (e.key >= '1' && e.key <= '5') {
                e.preventDefault();
                this.setStars(parseInt(e.key));
            }
            
            // Enter: Save & Next
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey) {
                e.preventDefault();
                if (this.currentWorkItem) {
                    this.openDisposition('note');
                }
            }
            
            // S: Snooze
            if (e.key === 's' || e.key === 'S') {
                e.preventDefault();
                this.openDisposition('snooze');
            }
            
            // N: Notiz
            if (e.key === 'n' || e.key === 'N') {
                e.preventDefault();
                this.openDisposition('note');
            }
        });
    }
    
    /**
     * Setup Sortier-Handler
     */
    setupSortHandlers() {
        document.querySelectorAll('.work-item-header-cell.sortable').forEach(header => {
            header.addEventListener('click', () => {
                const sortField = header.dataset.sort;
                
                // Toggle Sortier-Richtung wenn gleiches Feld
                if (this.currentSort.field === sortField) {
                    this.currentSort.direction = this.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    this.currentSort.field = sortField;
                    this.currentSort.direction = 'asc';
                }
                
                // Update UI
                this.updateSortIndicator();
                
                // Lade Queue neu mit Sortierung
                const activeTab = document.querySelector('.tab-btn.active')?.dataset.tab || 'new';
                this.loadQueue(activeTab);
                
                // Update "Lead-Player starten" Button mit aktuellem Tab und Sortierung
                const startButton = document.querySelector('a[href*="inside-sales/dialer"]');
                if (startButton) {
                    const sortParam = `sort=${this.currentSort.field}&order=${this.currentSort.direction}`;
                    startButton.href = `#inside-sales/dialer?tab=${activeTab}&${sortParam}`;
                }
            });
        });
    }
    
    /**
     * Aktualisiert Sortier-Indikatoren in der Tabelle
     */
    updateSortIndicator() {
        document.querySelectorAll('.work-item-header-cell').forEach(h => {
            h.classList.remove('sort-asc', 'sort-desc');
        });
        
        const activeHeader = document.querySelector(`.work-item-header-cell[data-sort="${this.currentSort.field}"]`);
        if (activeHeader) {
            activeHeader.classList.add(`sort-${this.currentSort.direction}`);
        }
    }
    
    /**
     * Lädt Queue für Tab
     */
    async loadQueue(tab) {
        try {
            // Baue Sortier-Parameter
            const sortParam = `sort=${this.currentSort.field}&order=${this.currentSort.direction}`;
            const apiUrl = this.getApiUrl(`/work-items?type=LEAD&tab=${tab}&${sortParam}`);
            const response = await fetch(apiUrl);
            if (!response.ok) {
                const errorText = await response.text();
                console.error('[InsideSales] API Error Response:', errorText);
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();
            
            // Update Counts
            if (data.counts) {
                Object.keys(data.counts).forEach(key => {
                    const el = document.getElementById(`count-${key}`);
                    if (el) el.textContent = data.counts[key];
                });
            }
            
            // Render List
            const listContainer = document.getElementById('inside-sales-queue-list');
            if (listContainer) {
                if (data.items && data.items.length > 0) {
                    listContainer.innerHTML = data.items.map(item => `
                        <div class="work-item-row" data-uuid="${item.case_uuid}">
                            <div class="work-item-name">${Utils.escapeHtml(item.company_name || '-')}</div>
                            <div class="work-item-location">${Utils.escapeHtml(item.company_city || '-')}</div>
                            <div class="work-item-stars">${'⭐'.repeat(item.priority_stars || 0)}</div>
                            <div class="work-item-next">${item.next_action_at ? new Date(item.next_action_at).toLocaleString('de-DE', { 
                                year: 'numeric', 
                                month: '2-digit', 
                                day: '2-digit', 
                                hour: '2-digit', 
                                minute: '2-digit' 
                            }) : '-'}</div>
                            <div class="work-item-last-touch">${item.last_touch_at ? new Date(item.last_touch_at).toLocaleDateString('de-DE') : 'Nie'}</div>
                            <div class="work-item-actions">
                                <a href="#inside-sales/dialer?lead=${item.case_uuid}&tab=${this.currentTab}&sort=${this.currentSort.field}&order=${this.currentSort.direction}" class="btn btn-sm">Öffnen</a>
                            </div>
                        </div>
                    `).join('');
                } else {
                    listContainer.innerHTML = '<div class="empty-state">Keine Leads gefunden</div>';
                }
            }
            
            // Aktualisiere Sortier-Indikatoren nach dem Rendern
            this.updateSortIndicator();
        } catch (error) {
            console.error('Error loading queue:', error);
            Utils.showError('Fehler beim Laden der Queue');
        }
    }
    
    /**
     * Rendert leeren Dialer (ohne Lead)
     */
    renderEmptyDialer() {
        const companyNameEl = document.getElementById('lead-company-name');
        if (companyNameEl) {
            companyNameEl.textContent = 'Kein Lead geladen';
        }
        
        const cityEl = document.getElementById('lead-city');
        if (cityEl) cityEl.textContent = '-';
        
        const websiteEl = document.getElementById('lead-website');
        if (websiteEl) {
            websiteEl.href = '#';
            websiteEl.textContent = '-';
        }
        
        const lastTouchEl = document.getElementById('lead-last-touch');
        if (lastTouchEl) {
            lastTouchEl.textContent = '-';
        }
        
        const starsEl = document.getElementById('lead-stars');
        if (starsEl) {
            starsEl.innerHTML = '';
        }
        
        this.currentWorkItem = null;
    }
    
    /**
     * Lädt nächsten Lead (markiert als IN_PROGRESS)
     */
    /**
     * Lädt nächsten Lead
     * @param {string} tab - Tab aus dem geladen werden soll (new, due, in_progress, snoozed, qualified)
     * @param {boolean} markAsInProgress - Ob der Lead auf IN_PROGRESS gesetzt werden soll (standardmäßig false - nur bei tatsächlichen Änderungen)
     */
    async loadNextLead(tab = null, markAsInProgress = false) {
        try {
            // Verwende aktuellen Tab, falls nicht angegeben
            const targetTab = tab || this.currentTab || 'new';
            
            // Sortierung wird in getNextLead nicht direkt unterstützt
            // Stattdessen verwenden wir listWorkItems und nehmen den ersten Lead
            const sortField = this.currentSort?.field || 'stars';
            const sortOrder = this.currentSort?.direction || 'desc';
            const sortParam = `sort=${sortField}&order=${sortOrder}`;
            
            // Lade alle Leads aus dem Tab mit Sortierung
            const data = await window.API.request(`/work-items?type=LEAD&tab=${targetTab}&${sortParam}`);
            
            if (!data || !data.items || data.items.length === 0) {
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
                return;
            }
            
            // Finde den aktuellen Lead in der Liste
            const currentIndex = this.currentWorkItem 
                ? data.items.findIndex(item => item.case_uuid === this.currentWorkItem.case_uuid)
                : -1;
            
            // Nimm den nächsten Lead (aktueller Index + 1, oder ersten, wenn kein aktueller)
            let nextIndex = currentIndex >= 0 ? currentIndex + 1 : 0;
            
            // Wenn wir am Ende der Liste sind, nimm den ersten Lead (zirkulär)
            if (nextIndex >= data.items.length) {
                nextIndex = 0;
            }
            
            let response = data.items[nextIndex];
            
            // Wenn kein Lead verfügbar, zeige leeren Dialer
            if (!response || !response.case_uuid) {
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
                return;
            }
            
            // Wenn keine Telefonnummer vorhanden, lade Lead neu über getWorkItem (wie bei "Öffnen")
            if (!response.company_phone || response.company_phone === '-' || (typeof response.company_phone === 'string' && response.company_phone.trim() === '')) {
                try {
                    const fullLead = await window.API.request(`/work-items/${response.case_uuid}`);
                    if (fullLead && fullLead.company_phone) {
                        response = fullLead;
                    }
                } catch (error) {
                    // Fehler beim Laden ignorieren, verwende ursprünglichen Response
                }
            }
            
            this.currentWorkItem = response;
            
            // Status wird NICHT automatisch geändert beim Laden
            // Status ändert sich nur bei tatsächlichen Änderungen (Sterne setzen, Daten ändern, etc.)
            
            await this.renderLeadCard(response);
            await this.loadTimeline(response.case_uuid);
            
            // Aktualisiere Mini-Queue nach dem Laden (mit gleichem Tab)
            await this.loadDialerQueue(targetTab);
        } catch (error) {
            console.error('Error loading next lead:', error);
            // Wenn 404 (kein Lead verfügbar), zeige Info statt Fehler
            if (error.message && (error.message.includes('404') || error.message.includes('No leads available'))) {
                const targetTab = tab || this.currentTab || 'new';
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
            } else {
                Utils.showError('Fehler beim Laden des nächsten Leads');
            }
        }
    }
    
    /**
     * Lädt Mini-Queue für Dialer (nächste 10 Leads)
     * @param {string} tab - Tab aus dem geladen werden soll (new, due, in_progress, snoozed, qualified)
     */
    async loadDialerQueue(tab = null) {
        const container = document.getElementById('dialer-queue-list');
        if (!container) return;
        
        try {
            // Verwende aktuellen Tab, falls nicht angegeben
            const targetTab = tab || this.currentTab || 'new';
            
            // Lade Leads aus dem aktuellen Tab mit gleicher Sortierung wie Hauptliste
            // Stelle sicher, dass currentSort gesetzt ist
            const sortField = this.currentSort?.field || 'stars';
            const sortOrder = this.currentSort?.direction || 'desc';
            const sortParam = `sort=${sortField}&order=${sortOrder}`;
            const data = await window.API.request(`/work-items?type=LEAD&tab=${targetTab}&${sortParam}`);
            
            if (data && data.items && data.items.length > 0) {
                // Zeige alle Leads in der Mini-Queue (kein Limit mehr)
                // Die Mini-Queue zeigt alle verfügbaren Leads aus dem aktuellen Tab
                const items = data.items;
                
                container.innerHTML = items.map(item => {
                    const isActive = this.currentWorkItem && this.currentWorkItem.case_uuid === item.case_uuid;
                    const activeClass = isActive ? 'active' : '';
                    return `
                        <div class="dialer-queue-item ${activeClass}" data-uuid="${item.case_uuid}">
                            <div class="dialer-queue-item-name">${Utils.escapeHtml(item.company_name || '-')}</div>
                            <div class="dialer-queue-item-info">
                                <span class="dialer-queue-item-city">${Utils.escapeHtml(item.company_city || '-')}</span>
                                <span class="dialer-queue-item-stars">${'⭐'.repeat(item.priority_stars || 0)}</span>
                            </div>
                        </div>
                    `;
                }).join('');
                
                // Event-Listener für Klicks
                container.querySelectorAll('.dialer-queue-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const uuid = item.dataset.uuid;
                        if (uuid) {
                            // Navigiere zu diesem Lead
                            window.location.hash = `inside-sales/dialer?lead=${uuid}`;
                        }
                    });
                });
            } else {
                container.innerHTML = '<div class="dialer-queue-empty">Keine Leads verfügbar</div>';
            }
        } catch (error) {
            console.error('Error loading dialer queue:', error);
            container.innerHTML = '<div class="dialer-queue-empty">Fehler beim Laden</div>';
        }
    }
    
    /**
     * Rendert Lead-Karte
     */
    async renderLeadCard(workItem) {
        // Prüfe, ob Dialer-Elemente existieren (könnte in Queue-Ansicht fehlen)
        const companyNameEl = document.getElementById('lead-company-name');
        if (!companyNameEl) {
            console.warn('Dialer-Elemente nicht gefunden. Stelle sicher, dass initDialer() aufgerufen wurde.');
            return;
        }
        
        companyNameEl.textContent = workItem.company_name || '-';
        
        // Setze org_uuid für Edit-Button
        const editBtn = document.getElementById('lead-company-edit');
        if (editBtn && workItem.org_uuid) {
            editBtn.dataset.orgUuid = workItem.org_uuid;
        }
        
        const cityEl = document.getElementById('lead-city');
        if (cityEl) {
            cityEl.textContent = workItem.company_city || '-';
        }
        
        // Firmen-Telefonnummer in Firmenansicht
        const companyPhoneEl = document.getElementById('lead-company-phone');
        if (companyPhoneEl) {
            // Prüfe explizit auf null, undefined, leerem String und trim
            let phoneNumber = workItem.company_phone;
            if (!phoneNumber || phoneNumber === '' || (typeof phoneNumber === 'string' && phoneNumber.trim() === '')) {
                phoneNumber = '-';
            } else if (typeof phoneNumber === 'string') {
                phoneNumber = phoneNumber.trim();
            }
            
            if (phoneNumber !== '-') {
                // Setze data-phone Attribut für Event-Delegation
                companyPhoneEl.setAttribute('data-phone', phoneNumber);
                companyPhoneEl.textContent = phoneNumber;
                companyPhoneEl.classList.add('phone-link');
                companyPhoneEl.href = '#';
            } else {
                companyPhoneEl.textContent = '-';
                companyPhoneEl.removeAttribute('data-phone');
                companyPhoneEl.classList.remove('phone-link');
            }
        }
        
        // Reset Call Status
        const callStatusEl = document.getElementById('call-status');
        if (callStatusEl) callStatusEl.style.display = 'none';
        
        this.currentCall = null;
        this.currentActivityId = null;
        this.stopCallPolling();
        
        const websiteEl = document.getElementById('lead-website');
        if (websiteEl) {
            if (workItem.company_website) {
                websiteEl.href = workItem.company_website;
                websiteEl.textContent = workItem.company_website;
            } else {
                websiteEl.href = '#';
                websiteEl.textContent = '-';
            }
        }
        
        const lastTouchEl = document.getElementById('lead-last-touch');
        if (lastTouchEl) {
            const lastTouch = workItem.last_touch_at 
                ? new Date(workItem.last_touch_at).toLocaleString('de-DE')
                : 'Nie';
            lastTouchEl.textContent = lastTouch;
        }
        
        // Sterne
        const starsEl = document.getElementById('lead-stars');
        if (starsEl) {
            starsEl.innerHTML = '';
            // Stelle sicher, dass priority_stars als Zahl behandelt wird
            // Prüfe explizit auf null, undefined, '', NaN und setze auf 0
            let priorityStars = 0;
            if (workItem.priority_stars !== null && workItem.priority_stars !== undefined && workItem.priority_stars !== '') {
                priorityStars = parseInt(workItem.priority_stars, 10);
                if (isNaN(priorityStars)) {
                    priorityStars = 0;
                }
            }
            for (let i = 1; i <= 5; i++) {
                const star = document.createElement('span');
                const isActive = i <= priorityStars;
                star.className = isActive ? 'star active' : 'star';
                // Verwende SVG-Stern statt Emoji, damit CSS color funktioniert
                star.innerHTML = isActive 
                    ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>'
                    : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
                star.dataset.stars = i;
                star.addEventListener('click', () => this.setStars(i));
                starsEl.appendChild(star);
            }
        }
        
        // Lade Personenliste
        if (workItem.org_uuid) {
            await this.loadPersonsList(workItem.org_uuid);
        }
    }
    
    /**
     * Setzt Sterne
     * Wenn Lead noch NEW ist, wird er automatisch auf IN_PROGRESS gesetzt
     */
    async setStars(stars) {
        if (!this.currentWorkItem) return;
        
        try {
            const token = await window.csrfTokenService?.fetchToken();
            
            // Wenn Lead noch NEW ist, setze auf IN_PROGRESS und aktualisiere last_touch_at
            const updateData = { priority_stars: stars };
            if (this.currentWorkItem.stage === 'NEW') {
                updateData.stage = 'IN_PROGRESS';
            }
            
            const response = await fetch(this.getApiUrl(`/work-items/${this.currentWorkItem.case_uuid}`), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify(updateData)
            });
            
            if (response.ok) {
                const updatedWorkItem = await response.json();
                const oldStars = this.currentWorkItem.priority_stars || 0;
                this.currentWorkItem = updatedWorkItem;
                
                // Erstelle Timeline-Eintrag für Prioritätsänderung
                if (oldStars !== stars) {
                    try {
                        const timelineToken = await window.csrfTokenService?.fetchToken();
                        await fetch(this.getApiUrl(`/work-items/${this.currentWorkItem.case_uuid}/activities`), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': timelineToken || ''
                            },
                            body: JSON.stringify({
                                activity_type: 'PRIORITY_CHANGED',
                                notes: `Priorität von ${oldStars} auf ${stars} Sterne geändert`
                            })
                        });
                    } catch (timelineError) {
                        console.error('Fehler beim Erstellen des Timeline-Eintrags:', timelineError);
                        // Timeline-Fehler nicht anzeigen, da die Hauptaktion erfolgreich war
                    }
                }
                
                await this.renderLeadCard(updatedWorkItem);
                
                // Aktualisiere Timeline, um den neuen Eintrag anzuzeigen
                await this.loadTimeline(this.currentWorkItem.case_uuid);
                
                // Aktualisiere Mini-Queue, damit der Lead aus "Fällig" verschwindet
                await this.loadDialerQueue();
            } else {
                const error = await response.json();
                Utils.showError('Fehler beim Setzen der Sterne: ' + (error.error || 'Unbekannter Fehler'));
            }
        } catch (error) {
            console.error('Error setting stars:', error);
            Utils.showError('Fehler beim Setzen der Sterne: ' + error.message);
        }
    }
    
    /**
     * Öffnet Disposition Sheet (fokussiert Notiz-Feld)
     */
    openDisposition(outcome) {
        this.isDispositionOpen = true;
        const sheet = document.getElementById('disposition-sheet');
        if (sheet) {
            // Disposition ist jetzt immer sichtbar, nur Fokus setzen
            document.getElementById('disposition-notes')?.focus();
            
            // Wenn Call beendet, markiere Outcome-Button
            if (outcome === 'call_ended') {
                // Setze default Outcome auf "erreicht" wenn Call erfolgreich war
                const reachedBtn = document.querySelector('.outcome-btn[data-outcome="erreicht"]');
                if (reachedBtn) {
                    // Entferne active von allen Buttons
                    document.querySelectorAll('.outcome-btn').forEach(b => b.classList.remove('active'));
                    reachedBtn.classList.add('active');
                }
            }
            
            // Wenn ein Outcome-Button geklickt wurde, aktualisiere Outcome-Text in der Notiz
            if (outcome && outcome !== 'call_ended') {
                const notesField = document.getElementById('disposition-notes');
                if (notesField) {
                    const outcomeTexts = {
                        'erreicht': 'Erreicht',
                        'nicht_erreicht': 'Nicht erreicht',
                        'rueckruf': 'Rückruf vereinbart',
                        'falsche_nummer': 'Falsche Nummer',
                        'kein_bedarf': 'Kein Bedarf',
                        'qualifiziert': 'Qualifiziert'
                    };
                    const newText = outcomeTexts[outcome] || outcome;
                    const currentText = notesField.value;
                    
                    // Wenn Notiz leer ist, setze neuen Text
                    if (!currentText.trim()) {
                        notesField.value = newText;
                    } else {
                        // Prüfe, ob der Text exakt einem bekannten Outcome-Text entspricht
                        const exactMatch = Object.values(outcomeTexts).find(text => currentText.trim() === text);
                        if (exactMatch) {
                            // Exakter Match: Ersetze komplett
                            notesField.value = newText;
                        } else {
                            // Prüfe, ob der Text mit einem bekannten Outcome-Text beginnt
                            let foundOutcomeText = null;
                            let remainingText = '';
                            
                            for (const outcomeText of Object.values(outcomeTexts)) {
                                // Prüfe auf Outcome-Text am Anfang (case-insensitive)
                                // Mit optionalem Trennzeichen danach (-, –, —, oder Leerzeichen)
                                const escapedText = outcomeText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                const regex = new RegExp(`^${escapedText}(\\s*[-–—]\\s*|\\s+)(.*)$`, 'i');
                                const match = currentText.match(regex);
                                
                                if (match) {
                                    foundOutcomeText = outcomeText;
                                    // Extrahiere den Rest des Textes nach dem Outcome-Text und Trennzeichen
                                    remainingText = match[2] ? match[2].trim() : '';
                                    break;
                                }
                                
                                // Fallback: Prüfe auch auf exakten Match am Anfang (ohne Trennzeichen)
                                if (currentText.trim().toLowerCase().startsWith(outcomeText.toLowerCase())) {
                                    const afterText = currentText.substring(outcomeText.length).trim();
                                    // Wenn danach ein Trennzeichen oder Leerzeichen kommt, ist es wahrscheinlich ein Outcome-Text
                                    if (afterText.startsWith('-') || afterText.startsWith('–') || afterText.startsWith('—') || afterText.startsWith(' ')) {
                                        foundOutcomeText = outcomeText;
                                        remainingText = afterText.replace(/^[-–—\s]+/, '').trim();
                                        break;
                                    }
                                }
                            }
                            
                            if (foundOutcomeText) {
                                // Outcome-Text am Anfang gefunden: Ersetze nur diesen Teil
                                if (remainingText) {
                                    notesField.value = `${newText} - ${remainingText}`;
                                } else {
                                    notesField.value = newText;
                                }
                            } else {
                                // Kein Outcome-Text gefunden: Füge neuen Outcome-Text am Anfang hinzu
                                notesField.value = `${newText} - ${currentText.trim()}`;
                            }
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Schließt Disposition Sheet (leert nur die Felder, versteckt nicht mehr)
     */
    closeDisposition() {
        this.isDispositionOpen = false;
        // Disposition bleibt sichtbar, nur Felder leeren
        const notesField = document.getElementById('disposition-notes');
        if (notesField) {
            notesField.value = '';
        }
        const snoozeField = document.getElementById('snooze-custom');
        if (snoozeField) {
            snoozeField.value = '';
        }
        // Entferne aktive Klassen von Snooze-Buttons
        document.querySelectorAll('.snooze-btn.active').forEach(btn => {
            btn.classList.remove('active');
        });
        // Entferne aktive Klassen von Outcome-Buttons
        document.querySelectorAll('.outcome-btn.active').forEach(btn => {
            btn.classList.remove('active');
        });
    }
    
    /**
     * Setzt Snooze
     */
    setSnooze(offset) {
        const input = document.getElementById('snooze-custom');
        let date;
        
        if (offset === 'today-16') {
            date = new Date();
            date.setHours(16, 0, 0, 0);
        } else if (offset === 'tomorrow-10') {
            date = new Date();
            date.setDate(date.getDate() + 1);
            date.setHours(10, 0, 0, 0);
        } else if (offset === '+3d') {
            date = new Date();
            date.setDate(date.getDate() + 3);
        } else if (offset === '+1w') {
            date = new Date();
            date.setDate(date.getDate() + 7);
        } else {
            return;
        }
        
        // Format für datetime-local
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        input.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    /**
     * Öffnet Handover Form
     */
    openHandoverForm(type) {
        this.closeDisposition();
        
        if (type === 'quote') {
            const form = document.getElementById('handover-form-quote');
            if (form) {
                form.style.display = 'block';
                document.getElementById('handover-need-summary').focus();
            }
        } else if (type === 'data_check') {
            const form = document.getElementById('handover-form-data-check');
            if (form) {
                form.style.display = 'block';
                document.getElementById('data-check-issue').focus();
            }
        }
    }
    
    /**
     * Schließt Handover Form
     */
    closeHandoverForm() {
        const formQuote = document.getElementById('handover-form-quote');
        const formDataCheck = document.getElementById('handover-form-data-check');
        
        if (formQuote) {
            formQuote.style.display = 'none';
            document.getElementById('handover-need-summary').value = '';
            document.getElementById('handover-contact-hint').value = '';
            document.getElementById('handover-next-step').value = '';
        }
        
        if (formDataCheck) {
            formDataCheck.style.display = 'none';
            document.getElementById('data-check-issue').value = '';
            document.getElementById('data-check-request').value = '';
            document.getElementById('data-check-contact-hint').value = '';
            document.getElementById('data-check-next-step').value = '';
            document.getElementById('data-check-links').value = '';
        }
    }
    
    /**
     * Submit Handover (gemeinsam für QUOTE_REQUEST und DATA_CHECK)
     */
    async submitHandover(handoffType) {
        if (!this.currentWorkItem) return;
        
        try {
            const token = await window.csrfTokenService?.fetchToken();
            
            let requestBody;
            
            if (handoffType === 'QUOTE_REQUEST') {
                const needSummary = document.getElementById('handover-need-summary').value.trim();
                const contactHint = document.getElementById('handover-contact-hint').value.trim();
                const nextStep = document.getElementById('handover-next-step').value.trim();
                
                if (!needSummary || !contactHint || !nextStep) {
                    Utils.showError('Bitte füllen Sie alle Pflichtfelder aus');
                    return;
                }
                
                requestBody = {
                    handoffType: 'QUOTE_REQUEST',
                    summary: {
                        needSummary: needSummary,
                        contactHint: contactHint,
                        nextStep: nextStep
                    },
                    stars: this.currentWorkItem.priority_stars || null
                };
            } else {
                // DATA_CHECK
                const issue = document.getElementById('data-check-issue').value.trim();
                const request = document.getElementById('data-check-request').value.trim();
                const contactHint = document.getElementById('data-check-contact-hint').value.trim() || 'unbekannt';
                const nextStep = document.getElementById('data-check-next-step').value.trim() || 'Daten klären';
                const linksText = document.getElementById('data-check-links').value.trim();
                
                if (!issue || !request) {
                    Utils.showError('Bitte füllen Sie "Was ist unklar?" und "Was soll Sales Ops klären?" aus');
                    return;
                }
                
                const links = linksText ? linksText.split('\n').filter(l => l.trim()).map(l => l.trim()) : [];
                
                requestBody = {
                    handoffType: 'DATA_CHECK',
                    summary: {
                        needSummary: issue, // Issue als needSummary
                        contactHint: contactHint,
                        nextStep: nextStep
                    },
                    dataCheck: {
                        issue: issue,
                        request: request,
                        links: links,
                        suspectedDuplicateCompanyId: null // Optional später erweitern
                    },
                    stars: this.currentWorkItem.priority_stars || null
                };
            }
            
            const response = await fetch(this.getApiUrl(`/work-items/${this.currentWorkItem.case_uuid}/handoff`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify(requestBody)
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler bei der Übergabe');
            }
            
            const result = await response.json();
            
            // Close Handover Form
            this.closeHandoverForm();
            
            // Show Success
            const message = handoffType === 'QUOTE_REQUEST' 
                ? 'Lead erfolgreich qualifiziert und an Sales Ops übergeben'
                : 'Lead zur Datenklärung an Sales Ops übergeben';
            Utils.showSuccess(message);
            
            // Load Next Lead - Status wird NICHT automatisch geändert
            // Status ändert sich nur bei tatsächlichen Änderungen (Sterne, Daten, etc.)
            await this.loadNextLead(null, false);
            
        } catch (error) {
            console.error('Error submitting handover:', error);
            Utils.showError(error.message || 'Fehler bei der Übergabe');
        }
    }
    
    /**
     * Startet Call mit gegebener Telefonnummer
     */
    async startCallWithNumber(phoneNumber) {
        if (!this.currentWorkItem) {
            Utils.showError('Kein Lead ausgewählt');
            return;
        }
        
        if (!phoneNumber || phoneNumber === '-') {
            Utils.showError('Keine Telefonnummer verfügbar');
            return;
        }
        
        // Bereinige Telefonnummer (entferne Leerzeichen, Bindestriche, etc.)
        const cleanPhoneNumber = phoneNumber.replace(/[\s\-\(\)]/g, '');
        
        if (!cleanPhoneNumber) {
            Utils.showError('Ungültige Telefonnummer');
            return;
        }
        
        try {
            const token = await window.csrfTokenService?.fetchToken();
            
            const response = await fetch(this.getApiUrl('/telephony/calls'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    work_item_uuid: this.currentWorkItem.case_uuid,
                    phone_number: cleanPhoneNumber
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler beim Starten des Calls');
            }
            
            const result = await response.json();
            this.currentCall = {
                call_ref: result.call_ref,
                phone_number: cleanPhoneNumber,
                activity_id: result.activity_id
            };
            this.currentActivityId = result.activity_id;
            
            // Zeige Call Status
            document.getElementById('call-status').style.display = 'block';
            
            // Starte Polling
            this.startCallPolling();
            
        } catch (error) {
            console.error('Error starting call:', error);
            Utils.showError(error.message || 'Fehler beim Starten des Calls');
        }
    }
    
    /**
     * Startet Call Polling
     */
    startCallPolling() {
        if (!this.currentCall) return;
        
        let pollInterval = 1000; // Start mit 1s
        let callStartTime = null;
        let connectedTime = null;
        
        const poll = async () => {
            try {
                const response = await fetch(this.getApiUrl(`/telephony/calls/${this.currentCall.call_ref}`));
                if (!response.ok) {
                    throw new Error('Polling failed');
                }
                
                const call = await response.json();
                
                // Update Status UI
                const statusText = this.getCallStatusText(call.state);
                document.getElementById('call-status-text').textContent = statusText;
                
                // Timer
                if (call.state === 'connected' || call.state === 'ringing') {
                    if (!callStartTime) {
                        callStartTime = new Date(call.initiated_at || Date.now());
                    }
                    if (call.state === 'connected' && !connectedTime) {
                        connectedTime = new Date(call.connected_at || Date.now());
                    }
                    
                    const now = new Date();
                    const elapsed = call.state === 'connected' && connectedTime
                        ? Math.floor((now - connectedTime) / 1000)
                        : Math.floor((now - callStartTime) / 1000);
                    
                    const minutes = Math.floor(elapsed / 60);
                    const seconds = elapsed % 60;
                    document.getElementById('call-timer').textContent = 
                        `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                }
                
                // State Transitions
                if (call.state === 'ended' || call.state === 'failed') {
                    this.stopCallPolling();
                    
                    // Update Activity mit Duration
                    if (call.duration) {
                        // Activity wird bereits vom Backend aktualisiert
                    }
                    
                    // Auto-Open Disposition
                    if (call.state === 'ended') {
                        this.openDisposition('call_ended');
                    } else {
                        Utils.showError('Call fehlgeschlagen');
                    }
                } else {
                    // Erhöhe Polling-Intervall nach 5s auf 2s, nach 30s auf 5s
                    if (pollInterval === 1000 && Date.now() - callStartTime.getTime() > 5000) {
                        pollInterval = 2000;
                    } else if (pollInterval === 2000 && Date.now() - callStartTime.getTime() > 30000) {
                        pollInterval = 5000;
                    }
                }
                
            } catch (error) {
                console.error('Error polling call status:', error);
            }
        };
        
        // Starte sofort
        poll();
        
        // Setze Interval
        this.callPollingInterval = setInterval(poll, pollInterval);
    }
    
    /**
     * Stoppt Call Polling
     */
    stopCallPolling() {
        if (this.callPollingInterval) {
            clearInterval(this.callPollingInterval);
            this.callPollingInterval = null;
        }
    }
    
    /**
     * Beendet Call (manuell)
     */
    async endCall() {
        // sipgate Call wird serverseitig beendet, wir stoppen nur Polling
        this.stopCallPolling();
        const callStatusEl = document.getElementById('call-status');
        if (callStatusEl) {
            callStatusEl.style.display = 'none';
        }
        this.currentCall = null;
    }
    
    /**
     * Gibt Call-Status-Text zurück
     */
    getCallStatusText(state) {
        const texts = {
            'initiated': 'Wird gestartet...',
            'ringing': 'Klingelt...',
            'connected': 'Verbunden',
            'ended': 'Beendet',
            'failed': 'Fehlgeschlagen'
        };
        return texts[state] || state;
    }
    
    /**
     * Speichert Disposition
     */
    async saveDisposition() {
        if (!this.currentWorkItem) return;
        
        const notes = document.getElementById('disposition-notes').value;
        const snoozeValue = document.getElementById('snooze-custom').value;
        const outcome = document.querySelector('.outcome-btn.active')?.dataset.outcome;
        
        try {
            const token = await window.csrfTokenService?.fetchToken();
            
            // Wenn Call Activity vorhanden, finalisiere diese
            if (this.currentActivityId) {
                await fetch(this.getApiUrl(`/telephony/activities/${this.currentActivityId}/finalize`), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token || ''
                    },
                    body: JSON.stringify({
                        outcome: outcome,
                        notes: notes,
                        next_action_at: snoozeValue || null,
                        next_action_type: snoozeValue ? 'CALL' : null
                    })
                });
                
                this.currentActivityId = null;
            } else {
                // Normale Activity
                const activityData = {
                    activity_type: 'NOTE',
                    notes: notes,
                    outcome: outcome
                };
                
                if (snoozeValue) {
                    activityData.next_action_at = snoozeValue;
                    activityData.next_action_type = 'CALL';
                }
                
                await fetch(this.getApiUrl(`/work-items/${this.currentWorkItem.case_uuid}/activities`), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token || ''
                    },
                    body: JSON.stringify(activityData)
                });
            }
            
            // Update Timeline
            await this.loadTimeline(this.currentWorkItem.case_uuid);
            
            // Close Disposition
            this.closeDisposition();
            
            // Reset Call Status
            if (this.currentCall) {
                this.endCall();
            }
            
            // Aktualisiere Mini-Queue
            await this.loadDialerQueue();
            
            // Load Next Lead - Status wird NICHT automatisch geändert
            // Status ändert sich nur bei tatsächlichen Änderungen (Sterne, Daten, etc.)
            await this.loadNextLead(null, false);
            
            Utils.showSuccess('Disposition gespeichert');
        } catch (error) {
            console.error('Error saving disposition:', error);
            Utils.showError('Fehler beim Speichern der Disposition');
        }
    }
    
    /**
     * Lädt Timeline
     */
    async loadTimeline(workItemUuid) {
        try {
            const response = await fetch(this.getApiUrl(`/work-items/${workItemUuid}/timeline`));
            const timeline = await response.json();
            
            const container = document.getElementById('dialer-timeline-content');
            if (container) {
                if (timeline.length > 0) {
                    container.innerHTML = timeline.map(item => {
                        // Formatiere Wiedervorlage, falls vorhanden
                        let wvlDisplay = '';
                        if (item.next_action_at) {
                            const wvlDate = new Date(item.next_action_at);
                            wvlDisplay = `<div class="timeline-wvl" style="font-weight: 600; color: #0d6efd; margin-bottom: 8px;">
                                📅 WVL: ${wvlDate.toLocaleString('de-DE', { 
                                    weekday: 'short', 
                                    year: 'numeric', 
                                    month: '2-digit', 
                                    day: '2-digit', 
                                    hour: '2-digit', 
                                    minute: '2-digit' 
                                })}
                            </div>`;
                        }
                        
                        return `
                        <div class="timeline-item ${item.is_pinned ? 'pinned' : ''}">
                            <div class="timeline-header">
                                <span class="timeline-type">${item.activity_type}</span>
                                <span class="timeline-time">${new Date(item.occurred_at).toLocaleString('de-DE')}</span>
                            </div>
                            ${item.created_by === 'USER' ? `<div class="timeline-user">${item.user_name || 'User'}</div>` : ''}
                            ${wvlDisplay}
                            ${item.system_message ? `<div class="timeline-system">${Utils.escapeHtml(item.system_message)}</div>` : ''}
                            ${item.notes ? `<div class="timeline-notes">${Utils.escapeHtml(item.notes)}</div>` : ''}
                            ${item.outcome ? `<div class="timeline-outcome">Outcome: ${item.outcome}</div>` : ''}
                        </div>
                    `;
                    }).join('');
                } else {
                    container.innerHTML = '<div class="empty-state">Keine Timeline-Einträge</div>';
                }
            }
        } catch (error) {
            console.error('Error loading timeline:', error);
        }
    }
    
    /**
     * Markiert Lead als IN_PROGRESS (wenn Benutzer explizit mit dem Lead arbeitet)
     */
    async markLeadAsInProgress(workItemUuid) {
        try {
            await window.API.request(`/work-items/${workItemUuid}`, {
                method: 'PATCH',
                body: {
                    stage: 'IN_PROGRESS'
                }
            });
        } catch (error) {
            console.error('Error marking lead as IN_PROGRESS:', error);
            // Fehler nicht anzeigen, da dies nicht kritisch ist
        }
    }
    
    /**
     * Öffnet Firmenbearbeitung
     */
    openCompanyEdit() {
        if (!this.currentWorkItem || !this.currentWorkItem.org_uuid) {
            Utils.showError('Keine Firma zugeordnet');
            return;
        }
        
        if (this.app.orgDetail) {
            const orgUuid = this.currentWorkItem.org_uuid;
            this.app.orgDetail.showOrgDetail(orgUuid);
            
            // Setup Listener für Modal-Schließen, um Lead-Card zu aktualisieren
            this.setupOrgEditCloseListener(orgUuid);
        } else {
            Utils.showError('Org-Detail-Modul nicht verfügbar');
        }
    }
    
    /**
     * Setup Listener für Org-Edit-Modal-Schließen
     */
    setupOrgEditCloseListener(orgUuid) {
        // Prüfe, ob Modal geschlossen wurde (alle 500ms)
        const checkInterval = setInterval(() => {
            const modal = document.getElementById('modal-org-detail');
            if (!modal || !modal.classList.contains('active')) {
                clearInterval(checkInterval);
                // Modal wurde geschlossen, aktualisiere Lead-Card
                if (this.currentWorkItem && this.currentWorkItem.org_uuid === orgUuid) {
                    // Lade aktualisierte Firmendaten und aktualisiere Lead-Card
                    this.refreshCurrentLead();
                }
            }
        }, 500);
        
        // Timeout nach 30 Sekunden (falls Modal nie geschlossen wird)
        setTimeout(() => {
            clearInterval(checkInterval);
        }, 30000);
    }
    
    /**
     * Aktualisiert die aktuelle Lead-Card mit den neuesten Daten
     */
    async refreshCurrentLead() {
        if (!this.currentWorkItem || !this.currentWorkItem.case_uuid) {
            return;
        }
        
        try {
            // Lade aktualisierte WorkItem-Daten
            const updatedWorkItem = await window.API.getWorkItem(this.currentWorkItem.case_uuid);
            if (updatedWorkItem) {
                this.currentWorkItem = updatedWorkItem;
                await this.renderLeadCard(updatedWorkItem);
            }
        } catch (error) {
            console.error('Error refreshing lead:', error);
            // Fehler wird stillschweigend ignoriert, um den Workflow nicht zu stören
        }
    }
    
    /**
     * Öffnet Dialog zum Hinzufügen einer Person
     */
    openAddPerson() {
        if (!this.currentWorkItem || !this.currentWorkItem.org_uuid) {
            Utils.showError('Keine Firma zugeordnet');
            return;
        }
        
        if (this.app.personForms) {
            this.app.personForms.showAddPersonForm(this.currentWorkItem.org_uuid);
            // Setup Listener für Modal-Schließen, um Personenliste zu aktualisieren
            this.setupPersonFormCloseListener();
        } else {
            Utils.showError('Person-Forms-Modul nicht verfügbar');
        }
    }
    
    /**
     * Setup Listener für Person-Formular-Schließen
     */
    setupPersonFormCloseListener() {
        // Prüfe, ob Modal geschlossen wurde (alle 500ms)
        const checkInterval = setInterval(() => {
            const modal = document.getElementById('modal-create-person');
            if (!modal || !modal.classList.contains('active')) {
                clearInterval(checkInterval);
                // Modal wurde geschlossen, aktualisiere Personenliste
                if (this.currentWorkItem && this.currentWorkItem.org_uuid) {
                    this.loadPersonsList(this.currentWorkItem.org_uuid);
                }
            }
        }, 500);
        
        // Timeout nach 30 Sekunden (falls Modal nie geschlossen wird)
        setTimeout(() => {
            clearInterval(checkInterval);
        }, 30000);
    }
    
    /**
     * Lädt Personenliste für Organisation
     */
    async loadPersonsList(orgUuid) {
        try {
            const persons = await window.API.getOrgPersons(orgUuid, true);
            const container = document.getElementById('lead-persons-list');
            if (!container) return;
            
            // Finde erste Telefonnummer aus Personen (falls keine Firmen-Telefonnummer vorhanden)
            let firstPersonPhone = null;
            if (persons && persons.length > 0) {
                for (const person of persons) {
                    if (person.phone) {
                        firstPersonPhone = person.phone;
                        break;
                    } else if (person.mobile_phone) {
                        firstPersonPhone = person.mobile_phone;
                        break;
                    }
                }
            }
            
            if (persons && persons.length > 0) {
                container.innerHTML = persons.map(person => {
                    const name = `${person.first_name || ''} ${person.last_name || ''}`.trim() || 'Unbekannt';
                    const details = [];
                    if (person.email) details.push(`📧 ${Utils.escapeHtml(person.email)}`);
                    if (person.phone) {
                        details.push(`<a href="#" class="phone-link" data-phone="${Utils.escapeHtml(person.phone)}">📞 ${Utils.escapeHtml(person.phone)}</a>`);
                    }
                    if (person.mobile_phone) {
                        details.push(`<a href="#" class="phone-link" data-phone="${Utils.escapeHtml(person.mobile_phone)}">📱 ${Utils.escapeHtml(person.mobile_phone)}</a>`);
                    }
                    
                    return `
                        <div class="lead-person-item">
                            <div class="lead-person-info">
                                <div class="lead-person-name">
                                    ${Utils.escapeHtml(name)}
                                    <button class="lead-person-edit-btn" data-person-uuid="${person.person_uuid}" title="Person bearbeiten">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                        </svg>
                                    </button>
                                </div>
                                ${details.length > 0 ? `<div class="lead-person-details">${details.join(' | ')}</div>` : ''}
                            </div>
                        </div>
                    `;
                }).join('');
                
                // Event-Listener für Edit-Buttons
                container.querySelectorAll('.lead-person-edit-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        const personUuid = btn.dataset.personUuid;
                        if (!personUuid) return;
                        
                        // Lade vollständige Person-Daten von der API
                        if (this.app.personForms) {
                            try {
                                const fullPerson = await window.API.getPerson(personUuid);
                                if (fullPerson) {
                                    this.app.personForms.showEditPersonForm(fullPerson);
                                    // Setup Listener für Modal-Schließen, um Personenliste zu aktualisieren
                                    this.setupPersonFormCloseListener();
                                } else {
                                    Utils.showError('Person nicht gefunden');
                                }
                            } catch (error) {
                                console.error('Error loading person:', error);
                                Utils.showError('Fehler beim Laden der Person: ' + (error.message || 'Unbekannter Fehler'));
                            }
                        }
                    });
                });
            } else {
                container.innerHTML = '<div class="lead-persons-empty">Keine Personen vorhanden</div>';
            }
        } catch (error) {
            console.error('Error loading persons:', error);
            const container = document.getElementById('lead-persons-list');
            if (container) {
                container.innerHTML = '<div class="lead-persons-empty">Fehler beim Laden</div>';
            }
        }
    }
}

