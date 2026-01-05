/**
 * TOM3 - Person Affiliation Module
 * Handles affiliation management for persons (Historie/Beschäftigungsverlauf)
 */

import { Utils } from './utils.js';

export class PersonAffiliationModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen für sauberes Event-Listener-Management
        this._affiliationCloseHandlers = new Map(); // modalId -> handler
        this._affiliationCancelHandlers = new Map(); // buttonId -> handler
        this._affiliationSubmitHandlers = new Map(); // buttonId -> handler
        this._affiliationOverlayHandlers = new Map(); // modalId -> handler
    }
    
    /**
     * Lädt Affiliations für eine Person
     */
    async loadAffiliations(personUuid) {
        const container = document.getElementById('person-affiliations-list');
        if (!container) return;
        
        try {
            const affiliations = await window.API.getPersonAffiliations(personUuid, true);
            
            // Stelle sicher, dass affiliations ein Array ist
            if (!affiliations || !Array.isArray(affiliations)) {
                container.innerHTML = '<div class="empty-state"><p>Keine Affiliations gefunden</p></div>';
                return;
            }
            
            if (affiliations.length === 0) {
                container.innerHTML = '<div class="empty-state"><p>Keine Affiliations gefunden</p></div>';
                return;
            }
            
            container.innerHTML = affiliations.map(aff => this.renderAffiliation(aff)).join('');
        } catch (error) {
            console.error('Error loading affiliations:', error);
            container.innerHTML = '<div class="empty-state"><p style="color: var(--danger);">Fehler beim Laden</p></div>';
        }
    }
    
    /**
     * Rendert eine Affiliation
     */
    renderAffiliation(affiliation) {
        const startDate = affiliation.since_date ? Utils.formatDate(affiliation.since_date) : '-';
        const endDate = affiliation.until_date ? Utils.formatDate(affiliation.until_date) : 'Aktiv';
        const isActive = !affiliation.until_date;
        
        return `
            <div class="affiliation-item ${isActive ? 'active' : 'inactive'}">
                <div class="affiliation-header">
                    <h4>${Utils.escapeHtml(affiliation.org_name || 'Unbekannt')}</h4>
                    ${affiliation.is_primary ? '<span class="badge badge-primary">Hauptarbeitgeber</span>' : ''}
                </div>
                <div class="affiliation-details">
                    <div class="detail-row">
                        <span class="label">Art:</span>
                        <span>${Utils.escapeHtml(affiliation.kind || '-')}</span>
                    </div>
                    ${affiliation.title ? `
                    <div class="detail-row">
                        <span class="label">Titel:</span>
                        <span>${Utils.escapeHtml(affiliation.title)}</span>
                    </div>
                    ` : ''}
                    ${affiliation.job_function ? `
                    <div class="detail-row">
                        <span class="label">Funktion:</span>
                        <span>${Utils.escapeHtml(affiliation.job_function)}</span>
                    </div>
                    ` : ''}
                    ${affiliation.seniority ? `
                    <div class="detail-row">
                        <span class="label">Hierarchie:</span>
                        <span>${Utils.escapeHtml(affiliation.seniority)}</span>
                    </div>
                    ` : ''}
                    <div class="detail-row">
                        <span class="label">Zeitraum:</span>
                        <span>${startDate} - ${endDate}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Zeigt Dialog zum Hinzufügen einer Affiliation
     */
    showAddAffiliationDialog(personUuid) {
        const modal = Utils.getOrCreateModal('modal-person-affiliation', 'Affiliation hinzufügen');
        const form = Utils.getOrCreateForm('form-person-affiliation', () => this.createAffiliationForm(personUuid), (form) => {
            form.dataset.personUuid = personUuid;
            this.setupAffiliationForm(form, personUuid);
        });
        
        if (form) {
            form.reset();
            form.dataset.personUuid = personUuid;
            const hiddenInput = form.querySelector('#affiliation-person-uuid');
            if (hiddenInput) hiddenInput.value = personUuid;
            
            // Reset org search
            const orgSearch = form.querySelector('#affiliation-org-search');
            const orgUuid = form.querySelector('#affiliation-org-uuid');
            const orgResults = form.querySelector('#affiliation-org-results');
            if (orgSearch) orgSearch.value = '';
            if (orgUuid) orgUuid.value = '';
            if (orgResults) orgResults.style.display = 'none';
            
            // Reset checkbox
            const isPrimary = form.querySelector('#affiliation-is-primary');
            if (isPrimary) isPrimary.checked = false;
            
            this.setupAffiliationForm(form, personUuid);
        }
        
        // Setup close button
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            const oldHandler = this._affiliationCloseHandlers.get('modal-person-affiliation');
            if (oldHandler) {
                closeBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-person-affiliation');
                const personDetailModal = document.getElementById('modal-person-detail');
                if (!personDetailModal || !personDetailModal.classList.contains('active')) {
                    if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                        this.app.personDetail.showPersonDetail(personUuid);
                    }
                }
                return false;
            };
            
            this._affiliationCloseHandlers.set('modal-person-affiliation', handler);
            closeBtn.addEventListener('click', handler);
        }
        
        // Setup overlay click handler
        const oldOverlayHandler = this._affiliationOverlayHandlers.get('modal-person-affiliation');
        if (oldOverlayHandler) {
            modal.removeEventListener('click', oldOverlayHandler);
        }
        
        const overlayHandler = (e) => {
            if (e.target === modal) {
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-person-affiliation');
                const personDetailModal = document.getElementById('modal-person-detail');
                if (!personDetailModal || !personDetailModal.classList.contains('active')) {
                    if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                        this.app.personDetail.showPersonDetail(personUuid);
                    }
                }
                return false;
            }
        };
        
        this._affiliationOverlayHandlers.set('modal-person-affiliation', overlayHandler);
        modal.addEventListener('click', overlayHandler);
        
        modal.classList.add('active');
    }
    
    /**
     * Erstellt das Affiliation-Formular HTML
     */
    createAffiliationForm(personUuid) {
        return `
            <form id="form-person-affiliation">
                <input type="hidden" id="affiliation-person-uuid" name="person_uuid" value="${personUuid}">
                
                <div class="form-group">
                    <label for="affiliation-org">Organisation <span class="required">*</span></label>
                    <input type="text" id="affiliation-org-search" class="org-search-input" 
                           placeholder="Organisation suchen..." autocomplete="off">
                    <input type="hidden" id="affiliation-org-uuid" name="org_uuid" required>
                    <div id="affiliation-org-results" class="org-search-results" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label for="affiliation-kind">Art <span class="required">*</span></label>
                    <select id="affiliation-kind" name="kind" required>
                        <option value="">-- Bitte wählen --</option>
                        <option value="employee">Mitarbeiter</option>
                        <option value="contractor">Freelancer/Berater</option>
                        <option value="advisor">Berater</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="affiliation-title">Titel/Position</label>
                        <input type="text" id="affiliation-title" name="title" 
                               placeholder="z.B. Geschäftsführer, Einkäufer">
                    </div>

                    <div class="form-group">
                        <label for="affiliation-job-function">Funktion</label>
                        <input type="text" id="affiliation-job-function" name="job_function" 
                               placeholder="z.B. Einkauf, Technik, Vertrieb">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="affiliation-seniority">Hierarchie</label>
                        <select id="affiliation-seniority" name="seniority">
                            <option value="">-- Bitte wählen --</option>
                            <option value="intern">Praktikant</option>
                            <option value="junior">Junior</option>
                            <option value="mid">Mittel</option>
                            <option value="senior">Senior</option>
                            <option value="lead">Lead</option>
                            <option value="head">Head</option>
                            <option value="vp">VP</option>
                            <option value="cxo">C-Level</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="affiliation-is-primary" name="is_primary" value="1">
                            Hauptarbeitgeber
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="affiliation-since-date">Seit (Datum)</label>
                        <input type="date" id="affiliation-since-date" name="since_date">
                    </div>

                    <div class="form-group">
                        <label for="affiliation-until-date">Bis (Datum)</label>
                        <input type="date" id="affiliation-until-date" name="until_date">
                        <small class="form-hint">Leer lassen für aktuelle Beschäftigung</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btn-cancel-affiliation">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-affiliation">Speichern</button>
                </div>
            </form>
        `;
    }
    
    /**
     * Setzt Event-Handler für das Affiliation-Formular
     */
    setupAffiliationForm(form, personUuid) {
        // Setup form submit
        const submitBtn = form.querySelector('#btn-submit-affiliation');
        if (submitBtn && !submitBtn.dataset.listenerAttached) {
            const handler = async (e) => {
                e.preventDefault();
                await this.submitAffiliationForm(personUuid);
            };
            
            const oldHandler = this._affiliationSubmitHandlers.get('btn-submit-affiliation');
            if (oldHandler) {
                submitBtn.removeEventListener('click', oldHandler);
            }
            
            this._affiliationSubmitHandlers.set('btn-submit-affiliation', handler);
            submitBtn.addEventListener('click', handler);
            submitBtn.dataset.listenerAttached = 'true';
        }
        
        // Setup cancel button
        const cancelBtn = form.querySelector('#btn-cancel-affiliation');
        if (cancelBtn && !cancelBtn.dataset.listenerAttached) {
            const handler = () => {
                Utils.closeSpecificModal('modal-person-affiliation');
            };
            
            const oldHandler = this._affiliationCancelHandlers.get('btn-cancel-affiliation');
            if (oldHandler) {
                cancelBtn.removeEventListener('click', oldHandler);
            }
            
            this._affiliationCancelHandlers.set('btn-cancel-affiliation', handler);
            cancelBtn.addEventListener('click', handler);
            cancelBtn.dataset.listenerAttached = 'true';
        }
        
        // Setup org search
        this.setupAffiliationOrgSearch();
    }
    
    /**
     * Setzt die Organisationen-Suche für Affiliations
     */
    setupAffiliationOrgSearch() {
        const searchInput = document.getElementById('affiliation-org-search');
        const resultsDiv = document.getElementById('affiliation-org-results');
        const hiddenInput = document.getElementById('affiliation-org-uuid');
        
        if (!searchInput || !resultsDiv || !hiddenInput) return;
        
        // Entferne alte Listener, falls vorhanden
        if (searchInput.dataset.listenerAttached) {
            return; // Bereits eingerichtet
        }
        
        let searchTimeout;
        const inputHandler = async (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            
            if (query.length < 2) {
                resultsDiv.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(async () => {
                try {
                    const orgs = await window.API.searchOrgs(query, {}, 10);
                    if (orgs.length === 0) {
                        resultsDiv.innerHTML = '<div class="search-result-item">Keine Ergebnisse</div>';
                        resultsDiv.style.display = 'block';
                        return;
                    }
                    
                    resultsDiv.innerHTML = orgs.map(org => `
                        <div class="search-result-item" data-org-uuid="${org.org_uuid}">
                            <strong>${Utils.escapeHtml(org.name || 'Unbekannt')}</strong>
                            ${org.city ? `<br><small>${Utils.escapeHtml(org.city)}</small>` : ''}
                        </div>
                    `).join('');
                    
                    resultsDiv.style.display = 'block';
                    
                    // Setup click handlers
                    resultsDiv.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', () => {
                            const orgUuid = item.dataset.orgUuid;
                            hiddenInput.value = orgUuid;
                            searchInput.value = item.querySelector('strong').textContent;
                            resultsDiv.style.display = 'none';
                        });
                    });
                } catch (error) {
                    console.error('Error searching orgs:', error);
                    Utils.showError('Fehler bei der Suche');
                }
            }, 300);
        };
        
        searchInput.addEventListener('input', inputHandler);
        searchInput.dataset.listenerAttached = 'true';
        
        // Close results when clicking outside
        const outsideClickHandler = (e) => {
            if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        };
        document.addEventListener('click', outsideClickHandler);
    }
    
    /**
     * Speichert das Affiliation-Formular
     */
    async submitAffiliationForm(personUuid) {
        const form = document.getElementById('form-person-affiliation');
        if (!form) {
            Utils.showError('Formular nicht gefunden');
            return;
        }
        
        const formData = new FormData(form);
        const data = {
            person_uuid: personUuid,
            org_uuid: formData.get('org_uuid'),
            kind: formData.get('kind'),
            title: formData.get('title') || null,
            job_function: formData.get('job_function') || null,
            seniority: formData.get('seniority') || null,
            is_primary: formData.get('is_primary') === '1' ? 1 : 0,
            since_date: formData.get('since_date') || null,
            until_date: formData.get('until_date') || null
        };
        
        if (!data.org_uuid || !data.kind) {
            Utils.showError('Bitte füllen Sie alle Pflichtfelder aus');
            return;
        }
        
        try {
            await window.API.createPersonAffiliation(personUuid, data);
            Utils.showSuccess('Affiliation erfolgreich hinzugefügt');
            Utils.closeSpecificModal('modal-person-affiliation');
            
            // Reload affiliations
            await this.loadAffiliations(personUuid);
            
            // Reload person detail to update UI
            if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                await this.app.personDetail.showPersonDetail(personUuid);
            }
        } catch (error) {
            console.error('Error creating affiliation:', error);
            Utils.showError('Fehler beim Hinzufügen der Affiliation: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}


