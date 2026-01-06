/**
 * TOM3 - Person Relationship Module
 * Handles person-to-person relationship management
 */

import { Utils } from './utils.js';

export class PersonRelationshipModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen für sauberes Event-Listener-Management
        this._relationshipCloseHandlers = new Map(); // modalId -> handler
        this._relationshipCancelHandlers = new Map(); // buttonId -> handler
        this._relationshipSubmitHandlers = new Map(); // buttonId -> handler
        this._relationshipOverlayHandlers = new Map(); // modalId -> handler
    }
    
    /**
     * Lädt Relationships für eine Person
     */
    async loadRelationships(personUuid) {
        const container = document.getElementById('person-relationships-list');
        if (!container) {
            console.warn('Container person-relationships-list nicht gefunden');
            return;
        }
        
        try {
            // Lade sowohl aktive als auch inaktive Relationships
            const relationships = await window.API.getPersonRelationships(personUuid, false);
            
            // Stelle sicher, dass relationships ein Array ist
            if (!relationships || !Array.isArray(relationships)) {
                container.innerHTML = '<div class="empty-state"><p>Keine Beziehungen gefunden</p></div>';
                return;
            }
            
            if (relationships.length === 0) {
                container.innerHTML = '<div class="empty-state"><p>Keine Beziehungen gefunden</p></div>';
                return;
            }
            
            container.innerHTML = relationships.map(rel => this.renderRelationship(rel)).join('');
            
            // Delete buttons
            container.querySelectorAll('.btn-delete-relationship').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    // Verhindere Blockierung - verschiebe async Operationen
                    const relationshipUuid = e.target.dataset.relationshipUuid;
                    if (!relationshipUuid) return;
                    
                    // Bestätigung außerhalb des async Handlers
                    if (!confirm('Beziehung wirklich löschen?')) {
                        return;
                    }
                    
                    // Async Operation in separater Funktion
                    this.handleDeleteRelationship(personUuid, relationshipUuid, e.target);
                });
            });
        } catch (error) {
            console.error('Error loading relationships:', error);
            container.innerHTML = '<div class="empty-state"><p style="color: var(--danger);">Fehler beim Laden</p></div>';
        }
    }
    
    /**
     * Rendert eine Relationship
     */
    renderRelationship(relationship) {
        const relationTypeLabels = {
            'knows': 'Kennt',
            'friendly': 'Freundlich',
            'adversarial': 'Gegnerisch',
            'advisor_of': 'Berät',
            'mentor_of': 'Mentor von',
            'former_colleague': 'Ehemaliger Kollege',
            'influences': 'Beeinflusst',
            'gatekeeper_for': 'Türöffner für'
        };
        
        const directionLabels = {
            'a_to_b': '→',
            'b_to_a': '←',
            'bidirectional': '↔'
        };
        
        const typeLabel = relationTypeLabels[relationship.relation_type] || relationship.relation_type;
        const directionLabel = directionLabels[relationship.direction] || '';
        const otherPersonName = relationship.other_person_name || 'Unbekannt';
        const contextInfo = relationship.context_org_name ? ` (bei ${Utils.escapeHtml(relationship.context_org_name)})` : '';
        
        return `
            <div class="relationship-item">
                <div class="relationship-header">
                    <h4>${Utils.escapeHtml(otherPersonName)}</h4>
                    <button class="btn btn-sm btn-danger btn-delete-relationship" data-relationship-uuid="${relationship.relationship_uuid}">Löschen</button>
                </div>
                <div class="relationship-details">
                    <div class="detail-row">
                        <span class="label">Beziehung:</span>
                        <span>${Utils.escapeHtml(typeLabel)} ${directionLabel}</span>
                    </div>
                    ${relationship.strength ? `
                    <div class="detail-row">
                        <span class="label">Stärke:</span>
                        <span>${relationship.strength}/10</span>
                    </div>
                    ` : ''}
                    ${relationship.confidence ? `
                    <div class="detail-row">
                        <span class="label">Vertrauen:</span>
                        <span>${relationship.confidence}/10</span>
                    </div>
                    ` : ''}
                    ${contextInfo ? `
                    <div class="detail-row">
                        <span class="label">Kontext:</span>
                        <span>${contextInfo}</span>
                    </div>
                    ` : ''}
                    ${relationship.notes ? `
                    <div class="detail-row">
                        <span class="label">Notizen:</span>
                        <span>${Utils.escapeHtml(relationship.notes)}</span>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    /**
     * Zeigt Dialog zum Hinzufügen einer Relationship
     */
    showAddRelationshipDialog(personUuid) {
        const modal = Utils.getOrCreateModal('modal-person-relationship', 'Beziehung hinzufügen');
        const form = Utils.getOrCreateForm('form-person-relationship', () => this.createRelationshipForm(personUuid), (form) => {
            form.dataset.personUuid = personUuid;
            this.setupRelationshipForm(form, personUuid);
        });
        
        if (form) {
            form.reset();
            form.dataset.personUuid = personUuid;
            const hiddenInput = form.querySelector('#relationship-person-a-uuid');
            if (hiddenInput) hiddenInput.value = personUuid;
            
            // Reset person search
            const personSearch = form.querySelector('#relationship-person-b-search');
            const personUuidInput = form.querySelector('#relationship-person-b-uuid');
            const personResults = form.querySelector('#relationship-person-b-results');
            if (personSearch) personSearch.value = '';
            if (personUuidInput) personUuidInput.value = '';
            if (personResults) personResults.style.display = 'none';
            
            // Reset org search
            const orgSearch = form.querySelector('#relationship-context-org-search');
            const orgUuid = form.querySelector('#relationship-context-org-uuid');
            const orgResults = form.querySelector('#relationship-context-org-results');
            if (orgSearch) orgSearch.value = '';
            if (orgUuid) orgUuid.value = '';
            if (orgResults) orgResults.style.display = 'none';
            
            // Reset direction
            const direction = form.querySelector('#relationship-direction');
            if (direction) direction.value = 'bidirectional';
            
            this.setupRelationshipForm(form, personUuid);
        }
        
        // Setup close button
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            const oldHandler = this._relationshipCloseHandlers.get('modal-person-relationship');
            if (oldHandler) {
                closeBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-person-relationship');
                const personDetailModal = document.getElementById('modal-person-detail');
                if (!personDetailModal || !personDetailModal.classList.contains('active')) {
                    if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                        this.app.personDetail.showPersonDetail(personUuid);
                    }
                }
                return false;
            };
            
            this._relationshipCloseHandlers.set('modal-person-relationship', handler);
            closeBtn.addEventListener('click', handler);
        }
        
        // Setup overlay click handler
        const oldOverlayHandler = this._relationshipOverlayHandlers.get('modal-person-relationship');
        if (oldOverlayHandler) {
            modal.removeEventListener('click', oldOverlayHandler);
        }
        
        const overlayHandler = (e) => {
            if (e.target === modal) {
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-person-relationship');
                const personDetailModal = document.getElementById('modal-person-detail');
                if (!personDetailModal || !personDetailModal.classList.contains('active')) {
                    if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                        this.app.personDetail.showPersonDetail(personUuid);
                    }
                }
                return false;
            }
        };
        
        this._relationshipOverlayHandlers.set('modal-person-relationship', overlayHandler);
        modal.addEventListener('click', overlayHandler);
        
        modal.classList.add('active');
    }
    
    /**
     * Erstellt das Relationship-Formular HTML
     */
    createRelationshipForm(personUuid) {
        return `
            <form id="form-person-relationship">
                <input type="hidden" id="relationship-person-a-uuid" name="person_a_uuid" value="${personUuid}">
                
                <div class="form-group">
                    <label for="relationship-person-b">Person <span class="required">*</span></label>
                    <input type="text" id="relationship-person-b-search" class="person-search-input" 
                           placeholder="Person suchen..." autocomplete="off">
                    <input type="hidden" id="relationship-person-b-uuid" name="person_b_uuid" required>
                    <div id="relationship-person-b-results" class="person-search-results" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label for="relationship-type">Beziehungstyp <span class="required">*</span></label>
                    <select id="relationship-type" name="relation_type" required>
                        <option value="">-- Bitte wählen --</option>
                        <option value="knows">Kennt</option>
                        <option value="friendly">Freundlich</option>
                        <option value="adversarial">Gegnerisch</option>
                        <option value="advisor_of">Berät</option>
                        <option value="mentor_of">Mentor von</option>
                        <option value="former_colleague">Ehemaliger Kollege</option>
                        <option value="influences">Beeinflusst</option>
                        <option value="gatekeeper_for">Türöffner für</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="relationship-direction">Richtung <span class="required">*</span></label>
                    <select id="relationship-direction" name="direction" required>
                        <option value="bidirectional" selected>Gegenseitig</option>
                        <option value="a_to_b">Einseitig: Diese Person → Andere Person</option>
                        <option value="b_to_a">Einseitig: Andere Person → Diese Person</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="relationship-strength">Stärke (1-10)</label>
                        <input type="number" id="relationship-strength" name="strength" 
                               min="1" max="10" placeholder="z.B. 8">
                    </div>

                    <div class="form-group">
                        <label for="relationship-confidence">Vertrauen (1-10)</label>
                        <input type="number" id="relationship-confidence" name="confidence" 
                               min="1" max="10" placeholder="z.B. 9">
                    </div>
                </div>

                <div class="form-group">
                    <label for="relationship-context-org">Kontext (Organisation)</label>
                    <input type="text" id="relationship-context-org-search" class="org-search-input" 
                           placeholder="Organisation suchen (optional)..." autocomplete="off">
                    <input type="hidden" id="relationship-context-org-uuid" name="context_org_uuid">
                    <div id="relationship-context-org-results" class="org-search-results" style="display: none;"></div>
                    <small class="form-hint">Falls die Beziehung im Kontext einer Organisation besteht</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="relationship-start-date">Beginn (Datum)</label>
                        <input type="date" id="relationship-start-date" name="start_date">
                    </div>

                    <div class="form-group">
                        <label for="relationship-end-date">Ende (Datum)</label>
                        <input type="date" id="relationship-end-date" name="end_date">
                    </div>
                </div>

                <div class="form-group">
                    <label for="relationship-notes">Notizen</label>
                    <textarea id="relationship-notes" name="notes" rows="3" 
                              placeholder="Zusätzliche Informationen zur Beziehung..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btn-cancel-relationship">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-relationship">Speichern</button>
                </div>
            </form>
        `;
    }
    
    /**
     * Setzt Event-Handler für das Relationship-Formular
     */
    setupRelationshipForm(form, personUuid) {
        // Setup form submit
        const submitBtn = form.querySelector('#btn-submit-relationship');
        if (submitBtn && !submitBtn.dataset.listenerAttached) {
            const handler = async (e) => {
                e.preventDefault();
                await this.submitRelationshipForm(personUuid);
            };
            
            const oldHandler = this._relationshipSubmitHandlers.get('btn-submit-relationship');
            if (oldHandler) {
                submitBtn.removeEventListener('click', oldHandler);
            }
            
            this._relationshipSubmitHandlers.set('btn-submit-relationship', handler);
            submitBtn.addEventListener('click', handler);
            submitBtn.dataset.listenerAttached = 'true';
        }
        
        // Setup cancel button
        const cancelBtn = form.querySelector('#btn-cancel-relationship');
        if (cancelBtn && !cancelBtn.dataset.listenerAttached) {
            const handler = () => {
                Utils.closeSpecificModal('modal-person-relationship');
            };
            
            const oldHandler = this._relationshipCancelHandlers.get('btn-cancel-relationship');
            if (oldHandler) {
                cancelBtn.removeEventListener('click', oldHandler);
            }
            
            this._relationshipCancelHandlers.set('btn-cancel-relationship', handler);
            cancelBtn.addEventListener('click', handler);
            cancelBtn.dataset.listenerAttached = 'true';
        }
        
        // Setup person search
        this.setupRelationshipPersonSearch();
        
        // Setup org search for context
        this.setupRelationshipOrgSearch();
    }
    
    /**
     * Setzt die Personen-Suche für Relationships
     */
    setupRelationshipPersonSearch() {
        const searchInput = document.getElementById('relationship-person-b-search');
        const resultsDiv = document.getElementById('relationship-person-b-results');
        const hiddenInput = document.getElementById('relationship-person-b-uuid');
        const personAUuidInput = document.getElementById('relationship-person-a-uuid');
        
        if (!searchInput || !resultsDiv || !hiddenInput || !personAUuidInput) return;
        
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
                    const personAUuid = personAUuidInput.value;
                    const persons = await window.API.searchPersons(query, 10);
                    // Filter out the current person
                    const filteredPersons = persons.filter(p => {
                        const uuid = p.person_uuid || p.uuid;
                        return uuid !== personAUuid;
                    });
                    
                    if (filteredPersons.length === 0) {
                        resultsDiv.innerHTML = '<div class="search-result-item">Keine Ergebnisse</div>';
                        resultsDiv.style.display = 'block';
                        return;
                    }
                    
                    resultsDiv.innerHTML = filteredPersons.map(person => {
                        const uuid = person.person_uuid || person.uuid;
                        const name = person.display_name || `${person.first_name || ''} ${person.last_name || ''}`.trim() || 'Unbekannt';
                        return `
                            <div class="search-result-item" data-person-uuid="${uuid}">
                                <strong>${Utils.escapeHtml(name)}</strong>
                                ${person.email ? `<br><small>${Utils.escapeHtml(person.email)}</small>` : ''}
                            </div>
                        `;
                    }).join('');
                    
                    resultsDiv.style.display = 'block';
                    
                    // Setup click handlers
                    resultsDiv.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', () => {
                            const personUuid = item.dataset.personUuid;
                            hiddenInput.value = personUuid;
                            searchInput.value = item.querySelector('strong').textContent;
                            resultsDiv.style.display = 'none';
                        });
                    });
                } catch (error) {
                    console.error('Error searching persons:', error);
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
     * Setzt die Organisationen-Suche für Relationship-Kontext
     */
    setupRelationshipOrgSearch() {
        const searchInput = document.getElementById('relationship-context-org-search');
        const resultsDiv = document.getElementById('relationship-context-org-results');
        const hiddenInput = document.getElementById('relationship-context-org-uuid');
        
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
     * Behandelt das Löschen einer Relationship
     */
    async handleDeleteRelationship(personUuid, relationshipUuid, buttonElement) {
        // Deaktiviere Button während des Löschens
        const originalText = buttonElement.textContent;
        buttonElement.disabled = true;
        buttonElement.textContent = 'Löschen...';
        
        try {
            await window.API.deletePersonRelationship(personUuid, relationshipUuid);
            Utils.showSuccess('Beziehung gelöscht');
            
            // Entferne das Element direkt aus dem DOM statt alles neu zu laden
            const relationshipItem = buttonElement.closest('.relationship-item');
            if (relationshipItem) {
                relationshipItem.remove();
                
                // Prüfe, ob noch Relationships vorhanden sind
                const container = document.getElementById('person-relationships-list');
                if (container && container.querySelectorAll('.relationship-item').length === 0) {
                    container.innerHTML = '<div class="empty-state"><p>Keine Beziehungen gefunden</p></div>';
                }
            } else {
                // Fallback: Neu laden, wenn Element nicht gefunden
                await this.loadRelationships(personUuid);
            }
        } catch (error) {
            console.error('Error deleting relationship:', error);
            Utils.showError('Fehler beim Löschen: ' + (error.message || 'Unbekannter Fehler'));
            // Reaktiviere Button bei Fehler
            buttonElement.disabled = false;
            buttonElement.textContent = originalText;
        }
    }
    
    /**
     * Speichert das Relationship-Formular
     */
    async submitRelationshipForm(personUuid) {
        const form = document.getElementById('form-person-relationship');
        if (!form) {
            Utils.showError('Formular nicht gefunden');
            return;
        }
        
        const formData = new FormData(form);
        const data = {
            person_a_uuid: personUuid,
            person_b_uuid: formData.get('person_b_uuid'),
            relation_type: formData.get('relation_type'),
            direction: formData.get('direction'),
            strength: formData.get('strength') ? parseInt(formData.get('strength')) : null,
            confidence: formData.get('confidence') ? parseInt(formData.get('confidence')) : null,
            context_org_uuid: formData.get('context_org_uuid') || null,
            start_date: formData.get('start_date') || null,
            end_date: formData.get('end_date') || null,
            notes: formData.get('notes') || null
        };
        
        if (!data.person_b_uuid || !data.relation_type || !data.direction) {
            Utils.showError('Bitte füllen Sie alle Pflichtfelder aus');
            return;
        }
        
        if (data.person_a_uuid === data.person_b_uuid) {
            Utils.showError('Eine Person kann nicht mit sich selbst in Beziehung stehen');
            return;
        }
        
        try {
            await window.API.createPersonRelationship(personUuid, data);
            Utils.showSuccess('Beziehung erfolgreich hinzugefügt');
            Utils.closeSpecificModal('modal-person-relationship');
            
            // Stelle sicher, dass der Relations-Tab aktiv ist, damit der Container sichtbar ist
            const relationenTab = document.querySelector('.person-detail-tab[data-tab="relationen"]');
            const relationenTabContent = document.querySelector('.person-detail-tab-content[data-tab-content="relationen"]');
            
            if (relationenTab && relationenTabContent) {
                // Aktiviere den Tab, falls er nicht aktiv ist
                document.querySelectorAll('.person-detail-tab').forEach(tab => tab.classList.remove('active'));
                document.querySelectorAll('.person-detail-tab-content').forEach(content => content.classList.remove('active'));
                relationenTab.classList.add('active');
                relationenTabContent.classList.add('active');
            }
            
            // Reload relationships - mit kurzer Verzögerung, damit der Container sichtbar ist
            setTimeout(async () => {
                await this.loadRelationships(personUuid);
            }, 100);
        } catch (error) {
            console.error('Error creating relationship:', error);
            Utils.showError('Fehler beim Hinzufügen der Beziehung: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}


