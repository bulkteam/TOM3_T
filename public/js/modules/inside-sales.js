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
        if (!page) return;
        
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
                
                // NICHT automatisch auf IN_PROGRESS setzen beim Öffnen aus der Queue
                // Der Lead bleibt in seinem ursprünglichen Stage (NEW, IN_PROGRESS, etc.)
                // Nur wenn der Benutzer explizit auf "Nächster" klickt, wird NEW → IN_PROGRESS
                
                this.renderLeadCard(workItem);
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
            console.error('initQueue: Container ist null oder undefined');
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
            console.log('[DEBUG] initQueue() - Tab aus Hash gesetzt:', this.currentTab);
        }
        
        // Setze Sortierung aus Hash (Hash hat Vorrang)
        if (sortFromHash) {
            this.currentSort = sortFromHash;
            console.log('[DEBUG] initQueue() - Sortierung aus Hash gesetzt:', this.currentSort);
        }
        
        console.log('[DEBUG] initQueue() - Finaler currentTab:', this.currentTab, 'currentSort:', this.currentSort);
        
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
        await this.loadQueue(initialTab);
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
                <!-- Zeile 0: Button-Leiste (volle Breite) -->
                <div class="dialer-actions-bar">
                    <button id="btn-next-lead" class="btn btn-primary">Nächster</button>
                    <button id="btn-close-dialer" class="btn btn-secondary">Schließen</button>
                </div>
                
                <!-- Zeile 1: Drei Spalten nebeneinander -->
                <div class="dialer-content-row">
                    <!-- Linke Spalte: Mini-Queue -->
                    <div class="dialer-queue">
                        <div class="dialer-queue-header">
                            <h3>Queue</h3>
                        </div>
                        <div id="dialer-queue-list" class="dialer-queue-list">
                            <!-- Wird dynamisch geladen -->
                        </div>
                    </div>
                    
                    <!-- Mitte: Lead-Karte -->
                    <div class="dialer-main">
                    <div id="dialer-lead-card" class="dialer-lead-card">
                        <div class="lead-card-header">
                            <h2 id="lead-company-name">-</h2>
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
                                <span id="lead-phone">-</span>
                                <button id="btn-call" class="btn btn-sm btn-primary" style="margin-left: 1rem;">📞 Anrufen</button>
                            </div>
                            
                            <!-- Call Status (versteckt) -->
                            <div id="call-status" class="call-status" style="display: none;">
                                <div class="call-status-info">
                                    <span id="call-status-text">-</span>
                                    <span id="call-timer">00:00</span>
                                </div>
                                <button id="btn-end-call" class="btn btn-sm btn-danger">Beenden</button>
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
                        
                        <!-- Outcome-Bar -->
                        <div class="outcome-bar">
                            <button class="outcome-btn" data-outcome="erreicht">✅ Erreicht</button>
                            <button class="outcome-btn" data-outcome="nicht_erreicht">❌ Nicht erreicht</button>
                            <button class="outcome-btn" data-outcome="rueckruf">📞 Rückruf</button>
                            <button class="outcome-btn" data-outcome="falsche_nummer">⚠️ Falsche Nummer</button>
                            <button class="outcome-btn" data-outcome="kein_bedarf">🚫 Kein Bedarf</button>
                            <button class="outcome-btn" data-outcome="qualifiziert">⭐ Qualifiziert</button>
                        </div>
                        
                        <!-- Disposition Sheet (versteckt) -->
                        <div id="disposition-sheet" class="disposition-sheet" style="display: none;">
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
        
        // Next Lead Button
        document.getElementById('btn-next-lead')?.addEventListener('click', () => {
            // Beim expliziten "Nächster"-Klick: Lead auf IN_PROGRESS setzen
            this.loadNextLead(null, true);
        });
        
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
        
        // Call Button
        document.getElementById('btn-call')?.addEventListener('click', () => {
            this.startCall();
        });
        
        // End Call Button
        document.getElementById('btn-end-call')?.addEventListener('click', () => {
            this.endCall();
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
            const response = await fetch(this.getApiUrl(`/work-items?type=LEAD&tab=${tab}&${sortParam}`));
            if (!response.ok) {
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
                            <div class="work-item-next">${item.next_action_at ? new Date(item.next_action_at).toLocaleDateString('de-DE') : '-'}</div>
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
        
        const phoneEl = document.getElementById('lead-phone');
        if (phoneEl) phoneEl.textContent = '-';
        
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
     * @param {boolean} markAsInProgress - Ob der Lead auf IN_PROGRESS gesetzt werden soll (nur bei explizitem "Nächster"-Klick)
     */
    async loadNextLead(tab = null, markAsInProgress = true) {
        try {
            // Verwende aktuellen Tab, falls nicht angegeben
            const targetTab = tab || this.currentTab || 'new';
            
            let response = await window.API.request(`/queues/inside-sales/next?tab=${targetTab}`, {
                method: 'POST'
            });
            
            // Wenn kein Lead verfügbar (null zurückgegeben), zeige leeren Dialer
            if (!response || !response.case_uuid) {
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
                return;
            }
            
            this.currentWorkItem = response;
            
            // Setze Lead auf IN_PROGRESS nur wenn explizit gewünscht (z.B. bei "Nächster"-Klick)
            // NICHT beim automatischen Laden beim Öffnen des Leadplayers
            if (markAsInProgress && response.stage === 'NEW') {
                await this.markLeadAsInProgress(response.case_uuid);
                // Lade Lead neu, um aktualisierten Status zu erhalten
                const updatedLead = await window.API.request(`/work-items/${response.case_uuid}`);
                if (updatedLead) {
                    this.currentWorkItem = updatedLead;
                    response = updatedLead;
                }
            }
            
            this.renderLeadCard(response);
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
            const sortParam = `sort=${this.currentSort.field}&order=${this.currentSort.direction}`;
            const data = await window.API.request(`/work-items?type=LEAD&tab=${targetTab}&${sortParam}`);
            
            if (data && data.items && data.items.length > 0) {
                // Zeige max. 10 Leads
                const items = data.items.slice(0, 10);
                
                container.innerHTML = items.map(item => {
                    const isActive = this.currentWorkItem && this.currentWorkItem.case_uuid === item.case_uuid;
                    const activeClass = isActive ? 'dialer-queue-item-active' : '';
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
    renderLeadCard(workItem) {
        // Prüfe, ob Dialer-Elemente existieren (könnte in Queue-Ansicht fehlen)
        const companyNameEl = document.getElementById('lead-company-name');
        if (!companyNameEl) {
            console.warn('Dialer-Elemente nicht gefunden. Stelle sicher, dass initDialer() aufgerufen wurde.');
            return;
        }
        
        companyNameEl.textContent = workItem.company_name || '-';
        
        const cityEl = document.getElementById('lead-city');
        if (cityEl) {
            cityEl.textContent = workItem.company_city || '-';
        }
        
        const phoneEl = document.getElementById('lead-phone');
        if (phoneEl) {
            phoneEl.textContent = workItem.company_phone || '-';
        }
        
        // Reset Call Status
        const callStatusEl = document.getElementById('call-status');
        if (callStatusEl) callStatusEl.style.display = 'none';
        
        const btnCallEl = document.getElementById('btn-call');
        if (btnCallEl) btnCallEl.style.display = 'inline-block';
        
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
            for (let i = 1; i <= 5; i++) {
                const star = document.createElement('span');
                star.className = `star ${i <= (workItem.priority_stars || 0) ? 'active' : ''}`;
                star.textContent = '⭐';
                star.dataset.stars = i;
                star.addEventListener('click', () => this.setStars(i));
                starsEl.appendChild(star);
            }
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
                this.currentWorkItem = updatedWorkItem;
                this.renderLeadCard(updatedWorkItem);
                
                // Aktualisiere Mini-Queue, damit der Lead aus "Fällig" verschwindet
                await this.loadDialerQueue();
            }
        } catch (error) {
            console.error('Error setting stars:', error);
        }
    }
    
    /**
     * Öffnet Disposition Sheet
     */
    openDisposition(outcome) {
        this.isDispositionOpen = true;
        const sheet = document.getElementById('disposition-sheet');
        if (sheet) {
            sheet.style.display = 'block';
            document.getElementById('disposition-notes').focus();
            
            // Wenn Call beendet, markiere Outcome-Button
            if (outcome === 'call_ended') {
                // Setze default Outcome auf "erreicht" wenn Call erfolgreich war
                const reachedBtn = document.querySelector('.outcome-btn[data-outcome="erreicht"]');
                if (reachedBtn) {
                    reachedBtn.classList.add('active');
                }
            }
        }
    }
    
    /**
     * Schließt Disposition Sheet
     */
    closeDisposition() {
        this.isDispositionOpen = false;
        const sheet = document.getElementById('disposition-sheet');
        if (sheet) {
            sheet.style.display = 'none';
            document.getElementById('disposition-notes').value = '';
        }
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
            
            // Load Next Lead - nach Handover arbeitet Benutzer aktiv, daher IN_PROGRESS setzen
            await this.loadNextLead(null, true);
            
        } catch (error) {
            console.error('Error submitting handover:', error);
            Utils.showError(error.message || 'Fehler bei der Übergabe');
        }
    }
    
    /**
     * Startet Call
     */
    async startCall() {
        if (!this.currentWorkItem) return;
        
        // Default Nummer: Person-Telefon > Firmen-Telefon
        const phoneNumber = this.currentWorkItem.company_phone || '';
        
        if (!phoneNumber) {
            Utils.showError('Keine Telefonnummer verfügbar');
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
                    phone_number: phoneNumber
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler beim Starten des Calls');
            }
            
            const result = await response.json();
            this.currentCall = {
                call_ref: result.call_ref,
                activity_id: result.activity_id
            };
            this.currentActivityId = result.activity_id;
            
            // Zeige Call Status
            document.getElementById('call-status').style.display = 'block';
            document.getElementById('btn-call').style.display = 'none';
            
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
        document.getElementById('call-status').style.display = 'none';
        document.getElementById('btn-call').style.display = 'inline-block';
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
            
            // Load Next Lead
            // Nach Disposition: nächsten Lead laden und auf IN_PROGRESS setzen (Benutzer arbeitet aktiv)
            await this.loadNextLead(null, true);
            
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
                    container.innerHTML = timeline.map(item => `
                        <div class="timeline-item ${item.is_pinned ? 'pinned' : ''}">
                            <div class="timeline-header">
                                <span class="timeline-type">${item.activity_type}</span>
                                <span class="timeline-time">${new Date(item.occurred_at).toLocaleString('de-DE')}</span>
                            </div>
                            ${item.created_by === 'USER' ? `<div class="timeline-user">${item.user_name || 'User'}</div>` : ''}
                            ${item.system_message ? `<div class="timeline-system">${Utils.escapeHtml(item.system_message)}</div>` : ''}
                            ${item.notes ? `<div class="timeline-notes">${Utils.escapeHtml(item.notes)}</div>` : ''}
                            ${item.outcome ? `<div class="timeline-outcome">Outcome: ${item.outcome}</div>` : ''}
                        </div>
                    `).join('');
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
}

