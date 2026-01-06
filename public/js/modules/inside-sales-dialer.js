/**
 * TOM3 - Inside Sales Dialer Module
 * Handles dialer functionality and lead card rendering
 */

import { Utils } from './utils.js';

export class InsideSalesDialerModule {
    constructor(insideSalesModule) {
        this.insideSalesModule = insideSalesModule;
    }
    
    /**
     * Initialisiert Lead-Player (Dialer)
     */
    async initDialer(container) {
        if (!container) {
            console.error('initDialer: Container ist null oder undefined');
            return;
        }
        
        container.innerHTML = '';
        
        // HTML wird aus der Originaldatei übernommen (sehr lang)
        // Hier nur die wichtigsten Teile
        container.innerHTML = `
            <div class="dialer-container">
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
                
                <div class="dialer-content-row">
                    <div class="dialer-queue">
                        <div class="dialer-queue-header">
                            <h3>Aktuelle Queue</h3>
                        </div>
                        <div id="dialer-queue-list" class="dialer-queue-list"></div>
                    </div>
                    
                    <div class="dialer-main">
                        <div id="dialer-lead-card" class="dialer-lead-card">
                            <div class="lead-company-view">
                                <div class="lead-card-header">
                                    <h2>
                                        <span id="lead-company-name">-</span>
                                        <span id="lead-company-edit" class="lead-company-edit" style="cursor: pointer; margin-left: 8px; opacity: 0.6;" title="Firma bearbeiten">✏️</span>
                                    </h2>
                                    <div id="lead-stars" class="lead-stars"></div>
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
                            
                            <div class="lead-persons-view">
                                <div class="lead-persons-header-inline">
                                    <span class="lead-persons-label">👥 Personen</span>
                                    <button id="btn-add-person" class="btn-add-person btn-add-person-white" title="Neue Person hinzufügen">+</button>
                                </div>
                                
                                <div id="call-status" class="call-status" style="display: none;">
                                    <div class="call-status-info">
                                        <span id="call-status-text">-</span>
                                        <span id="call-timer">00:00</span>
                                    </div>
                                    <button id="btn-end-call" class="btn btn-sm btn-danger">Beenden</button>
                                </div>
                                
                                <div id="lead-persons-list" class="lead-persons-list"></div>
                            </div>
                        
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
                    
                    <div class="dialer-timeline">
                        <div class="timeline-header">
                            <h3>Timeline</h3>
                        </div>
                        <div id="dialer-timeline-content" class="timeline-content"></div>
                    </div>
                </div>
            </div>
        `;
        
        await new Promise(resolve => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    resolve();
                });
            });
        });
        
        let companyNameEl = container.querySelector('#lead-company-name');
        if (!companyNameEl) {
            companyNameEl = document.getElementById('lead-company-name');
        }
        
        if (!companyNameEl) {
            console.error('Dialer-Elemente konnten nicht erstellt werden');
            return;
        }
        
        this.setupDialerEvents();
        
        const tab = this.insideSalesModule.currentTab || 'new';
        await this.insideSalesModule.queueModule.loadDialerQueue(tab);
        
        const hash = window.location.hash;
        const hasLeadParam = hash.includes('?lead=');
        if (!hasLeadParam) {
            await this.loadNextLead(tab, false);
        }
    }
    
    /**
     * Setup Event-Listener für Dialer
     */
    setupDialerEvents() {
        // Outcome-Buttons
        document.querySelectorAll('.outcome-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.outcome-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const outcome = btn.dataset.outcome;
                this.insideSalesModule.dispositionModule.openDisposition(outcome);
            });
        });
        
        // Snooze-Buttons
        document.querySelectorAll('.snooze-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const offset = btn.dataset.offset;
                this.insideSalesModule.dispositionModule.setSnooze(offset);
            });
        });
        
        // Save & Next
        document.getElementById('btn-save-next')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.saveDisposition();
        });
        
        // Cancel
        document.getElementById('btn-cancel-disposition')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.closeDisposition();
        });
        
        // Qualify Button
        document.getElementById('btn-qualify')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.openHandoverForm('quote');
        });
        
        // Data Check Button
        document.getElementById('btn-data-check')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.openHandoverForm('data_check');
        });
        
        // Handover Form - Quote
        document.getElementById('btn-submit-handover-quote')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.submitHandover('QUOTE_REQUEST');
        });
        
        document.getElementById('btn-cancel-handover')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.closeHandoverForm();
        });
        
        // Handover Form - Data Check
        document.getElementById('btn-submit-handover-data-check')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.submitHandover('DATA_CHECK');
        });
        
        document.getElementById('btn-cancel-handover-data-check')?.addEventListener('click', () => {
            this.insideSalesModule.dispositionModule.closeHandoverForm();
        });
        
        // Next Lead Button
        setTimeout(() => {
            const btnNext = document.getElementById('btn-next-lead');
            if (btnNext) {
                const newBtn = btnNext.cloneNode(true);
                btnNext.parentNode?.replaceChild(newBtn, btnNext);
                newBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    await this.loadNextLead(null, false);
                });
            }
        }, 100);
        
        // Close Dialer Button
        document.getElementById('btn-close-dialer')?.addEventListener('click', () => {
            const hash = window.location.hash;
            let tabToUse = this.insideSalesModule.currentTab || 'new';
            let sortField = this.insideSalesModule.currentSort?.field || 'stars';
            let sortOrder = this.insideSalesModule.currentSort?.direction || 'desc';
            
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
        
        // Phone Links - Event Delegation
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('phone-link') || e.target.closest('.phone-link')) {
                e.preventDefault();
                const link = e.target.classList.contains('phone-link') ? e.target : e.target.closest('.phone-link');
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
        
        // Company Edit Button
        document.getElementById('lead-company-edit')?.addEventListener('click', () => {
            this.openCompanyEdit();
        });
        
        // Add Person Button
        document.getElementById('btn-add-person')?.addEventListener('click', () => {
            this.openAddPerson();
        });
        
        // Hotkeys
        document.addEventListener('keydown', (e) => {
            if (this.insideSalesModule.isDispositionOpen) return;
            
            if (e.key >= '1' && e.key <= '5') {
                e.preventDefault();
                this.setStars(parseInt(e.key));
            }
            
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey) {
                e.preventDefault();
                if (this.insideSalesModule.currentWorkItem) {
                    this.insideSalesModule.dispositionModule.openDisposition('note');
                }
            }
            
            if (e.key === 's' || e.key === 'S') {
                e.preventDefault();
                this.insideSalesModule.dispositionModule.openDisposition('snooze');
            }
            
            if (e.key === 'n' || e.key === 'N') {
                e.preventDefault();
                this.insideSalesModule.dispositionModule.openDisposition('note');
            }
        });
    }
    
    /**
     * Lädt spezifischen Lead
     */
    async loadSpecificLead(workItemUuid) {
        try {
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
                this.insideSalesModule.currentWorkItem = workItem;
                await this.renderLeadCard(workItem);
                await this.insideSalesModule.timelineModule.loadTimeline(workItem.case_uuid);
                await this.insideSalesModule.queueModule.loadDialerQueue(this.insideSalesModule.currentTab);
            }
        } catch (error) {
            console.error('Error loading specific lead:', error);
            Utils.showError('Fehler beim Laden des Leads');
        }
    }
    
    /**
     * Lädt nächsten Lead
     */
    async loadNextLead(tab = null, markAsInProgress = false) {
        try {
            const targetTab = tab || this.insideSalesModule.currentTab || 'new';
            const sortField = this.insideSalesModule.currentSort?.field || 'stars';
            const sortOrder = this.insideSalesModule.currentSort?.direction || 'desc';
            const sortParam = `sort=${sortField}&order=${sortOrder}`;
            
            const data = await window.API.request(`/work-items?type=LEAD&tab=${targetTab}&${sortParam}`);
            
            if (!data || !data.items || data.items.length === 0) {
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
                return;
            }
            
            const currentIndex = this.insideSalesModule.currentWorkItem 
                ? data.items.findIndex(item => item.case_uuid === this.insideSalesModule.currentWorkItem.case_uuid)
                : -1;
            
            let nextIndex = currentIndex >= 0 ? currentIndex + 1 : 0;
            
            if (nextIndex >= data.items.length) {
                nextIndex = 0;
            }
            
            let response = data.items[nextIndex];
            
            if (!response || !response.case_uuid) {
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
                return;
            }
            
            if (!response.company_phone || response.company_phone === '-' || (typeof response.company_phone === 'string' && response.company_phone.trim() === '')) {
                try {
                    const fullLead = await window.API.request(`/work-items/${response.case_uuid}`);
                    if (fullLead && fullLead.company_phone) {
                        response = fullLead;
                    }
                } catch (error) {
                    // Ignore
                }
            }
            
            this.insideSalesModule.currentWorkItem = response;
            
            await this.renderLeadCard(response);
            await this.insideSalesModule.timelineModule.loadTimeline(response.case_uuid);
            await this.insideSalesModule.queueModule.loadDialerQueue(targetTab);
        } catch (error) {
            console.error('Error loading next lead:', error);
            if (error.message && (error.message.includes('404') || error.message.includes('No leads available'))) {
                const targetTab = tab || this.insideSalesModule.currentTab || 'new';
                Utils.showInfo(`Keine weiteren Leads verfügbar im Tab "${targetTab}"`);
                this.renderEmptyDialer();
            } else {
                Utils.showError('Fehler beim Laden des nächsten Leads');
            }
        }
    }
    
    /**
     * Rendert Lead-Karte
     */
    async renderLeadCard(workItem) {
        const companyNameEl = document.getElementById('lead-company-name');
        if (!companyNameEl) {
            console.warn('Dialer-Elemente nicht gefunden. Stelle sicher, dass initDialer() aufgerufen wurde.');
            return;
        }
        
        companyNameEl.textContent = workItem.company_name || '-';
        
        const editBtn = document.getElementById('lead-company-edit');
        if (editBtn && workItem.org_uuid) {
            editBtn.dataset.orgUuid = workItem.org_uuid;
        }
        
        const cityEl = document.getElementById('lead-city');
        if (cityEl) {
            cityEl.textContent = workItem.company_city || '-';
        }
        
        const companyPhoneEl = document.getElementById('lead-company-phone');
        if (companyPhoneEl) {
            let phoneNumber = workItem.company_phone;
            if (!phoneNumber || phoneNumber === '' || (typeof phoneNumber === 'string' && phoneNumber.trim() === '')) {
                phoneNumber = '-';
            } else if (typeof phoneNumber === 'string') {
                phoneNumber = phoneNumber.trim();
            }
            
            if (phoneNumber !== '-') {
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
        
        const callStatusEl = document.getElementById('call-status');
        if (callStatusEl) callStatusEl.style.display = 'none';
        
        this.insideSalesModule.currentCall = null;
        this.insideSalesModule.currentActivityId = null;
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
        
        const starsEl = document.getElementById('lead-stars');
        if (starsEl) {
            starsEl.innerHTML = '';
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
                star.innerHTML = isActive 
                    ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>'
                    : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
                star.dataset.stars = i;
                star.addEventListener('click', () => this.setStars(i));
                starsEl.appendChild(star);
            }
        }
        
        if (workItem.org_uuid) {
            await this.loadPersonsList(workItem.org_uuid);
        }
    }
    
    /**
     * Setzt Sterne
     */
    async setStars(stars) {
        if (!this.insideSalesModule.currentWorkItem) return;
        
        try {
            const token = await window.csrfTokenService?.fetchToken();
            
            const updateData = { priority_stars: stars };
            if (this.insideSalesModule.currentWorkItem.stage === 'NEW') {
                updateData.stage = 'IN_PROGRESS';
            }
            
            await fetch(this.insideSalesModule.getApiUrl(`/work-items/${this.insideSalesModule.currentWorkItem.case_uuid}`), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify(updateData)
            });
            
            this.insideSalesModule.currentWorkItem.priority_stars = stars;
            if (updateData.stage) {
                this.insideSalesModule.currentWorkItem.stage = updateData.stage;
            }
            
            await this.renderLeadCard(this.insideSalesModule.currentWorkItem);
            
        } catch (error) {
            console.error('Error setting stars:', error);
            Utils.showError('Fehler beim Setzen der Sterne');
        }
    }
    
    /**
     * Rendert leeren Dialer
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
        
        this.insideSalesModule.currentWorkItem = null;
    }
    
    /**
     * Startet Call mit Telefonnummer
     */
    async startCallWithNumber(phoneNumber) {
        if (!this.insideSalesModule.currentWorkItem) {
            Utils.showError('Kein Lead ausgewählt');
            return;
        }
        
        if (!phoneNumber || phoneNumber === '-') {
            Utils.showError('Keine Telefonnummer verfügbar');
            return;
        }
        
        const cleanPhoneNumber = phoneNumber.replace(/[\s\-\(\)]/g, '');
        
        if (!cleanPhoneNumber) {
            Utils.showError('Ungültige Telefonnummer');
            return;
        }
        
        try {
            const token = await window.csrfTokenService?.fetchToken();
            
            const response = await fetch(this.insideSalesModule.getApiUrl('/telephony/calls'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token || ''
                },
                body: JSON.stringify({
                    work_item_uuid: this.insideSalesModule.currentWorkItem.case_uuid,
                    phone_number: cleanPhoneNumber
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler beim Starten des Calls');
            }
            
            const result = await response.json();
            this.insideSalesModule.currentCall = {
                call_ref: result.call_ref,
                phone_number: cleanPhoneNumber,
                activity_id: result.activity_id
            };
            this.insideSalesModule.currentActivityId = result.activity_id;
            
            document.getElementById('call-status').style.display = 'block';
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
        if (!this.insideSalesModule.currentCall) return;
        
        let pollInterval = 1000;
        let callStartTime = null;
        let connectedTime = null;
        
        const poll = async () => {
            try {
                const response = await fetch(this.insideSalesModule.getApiUrl(`/telephony/calls/${this.insideSalesModule.currentCall.call_ref}`));
                if (!response.ok) {
                    throw new Error('Polling failed');
                }
                
                const call = await response.json();
                
                const statusText = this.getCallStatusText(call.state);
                document.getElementById('call-status-text').textContent = statusText;
                
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
                
                if (call.state === 'ended' || call.state === 'failed') {
                    this.stopCallPolling();
                    
                    if (call.state === 'ended') {
                        this.insideSalesModule.dispositionModule.openDisposition('call_ended');
                    } else {
                        Utils.showError('Call fehlgeschlagen');
                    }
                } else {
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
        
        poll();
        this.insideSalesModule.callPollingInterval = setInterval(poll, pollInterval);
    }
    
    /**
     * Stoppt Call Polling
     */
    stopCallPolling() {
        if (this.insideSalesModule.callPollingInterval) {
            clearInterval(this.insideSalesModule.callPollingInterval);
            this.insideSalesModule.callPollingInterval = null;
        }
    }
    
    /**
     * Beendet Call
     */
    async endCall() {
        this.stopCallPolling();
        const callStatusEl = document.getElementById('call-status');
        if (callStatusEl) {
            callStatusEl.style.display = 'none';
        }
        this.insideSalesModule.currentCall = null;
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
     * Aktualisiert aktuellen Lead
     */
    async refreshCurrentLead() {
        if (!this.insideSalesModule.currentWorkItem || !this.insideSalesModule.currentWorkItem.case_uuid) {
            return;
        }
        
        try {
            const updatedWorkItem = await window.API.getWorkItem(this.insideSalesModule.currentWorkItem.case_uuid);
            if (updatedWorkItem) {
                this.insideSalesModule.currentWorkItem = updatedWorkItem;
                await this.renderLeadCard(updatedWorkItem);
            }
        } catch (error) {
            console.error('Error refreshing lead:', error);
        }
    }
    
    /**
     * Öffnet Firmenbearbeitung
     */
    openCompanyEdit() {
        if (!this.insideSalesModule.currentWorkItem || !this.insideSalesModule.currentWorkItem.org_uuid) {
            Utils.showError('Keine Firma zugeordnet');
            return;
        }
        
        if (this.insideSalesModule.app.orgDetail) {
            const orgUuid = this.insideSalesModule.currentWorkItem.org_uuid;
            this.insideSalesModule.app.orgDetail.showOrgDetail(orgUuid);
            this.setupOrgEditCloseListener(orgUuid);
        } else {
            Utils.showError('Org-Detail-Modul nicht verfügbar');
        }
    }
    
    /**
     * Setup Listener für Org-Edit-Modal-Schließen
     */
    setupOrgEditCloseListener(orgUuid) {
        const checkInterval = setInterval(() => {
            const modal = document.getElementById('modal-org-detail');
            if (!modal || !modal.classList.contains('active')) {
                clearInterval(checkInterval);
                if (this.insideSalesModule.currentWorkItem && this.insideSalesModule.currentWorkItem.org_uuid === orgUuid) {
                    this.refreshCurrentLead();
                }
            }
        }, 500);
        
        setTimeout(() => {
            clearInterval(checkInterval);
        }, 30000);
    }
    
    /**
     * Öffnet Dialog zum Hinzufügen einer Person
     */
    openAddPerson() {
        if (!this.insideSalesModule.currentWorkItem || !this.insideSalesModule.currentWorkItem.org_uuid) {
            Utils.showError('Keine Firma zugeordnet');
            return;
        }
        
        if (this.insideSalesModule.app.personForms) {
            this.insideSalesModule.app.personForms.showAddPersonForm(this.insideSalesModule.currentWorkItem.org_uuid);
            this.setupPersonFormCloseListener();
        } else {
            Utils.showError('Person-Forms-Modul nicht verfügbar');
        }
    }
    
    /**
     * Setup Listener für Person-Formular-Schließen
     */
    setupPersonFormCloseListener() {
        const checkInterval = setInterval(() => {
            const modal = document.getElementById('modal-create-person');
            if (!modal || !modal.classList.contains('active')) {
                clearInterval(checkInterval);
                if (this.insideSalesModule.currentWorkItem && this.insideSalesModule.currentWorkItem.org_uuid) {
                    this.loadPersonsList(this.insideSalesModule.currentWorkItem.org_uuid);
                }
            }
        }, 500);
        
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
                
                container.querySelectorAll('.lead-person-edit-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        const personUuid = btn.dataset.personUuid;
                        if (!personUuid) return;
                        
                        if (this.insideSalesModule.app.personForms) {
                            try {
                                const fullPerson = await window.API.getPerson(personUuid);
                                if (fullPerson) {
                                    this.insideSalesModule.app.personForms.showEditPersonForm(fullPerson);
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

