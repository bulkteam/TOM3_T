/**
 * TOM3 - Organization Relation Module
 * Handles organization relation management
 */

import { Utils } from './utils.js';

export class OrgRelationModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen für sauberes Event-Listener-Management (ohne cloneNode)
        this._relationCloseHandlers = new Map(); // modalId -> handler
        this._relationCancelHandlers = new Map(); // buttonId -> handler
        this._relationSubmitHandlers = new Map(); // buttonId -> handler
        this._relationOverlayHandlers = new Map(); // modalId -> handler
        this._relationTypeChangeHandlers = new Map(); // selectId -> handler
    }
    
    showAddRelationDialog(parentOrgUuid) {
        const modal = document.getElementById('modal-relation');
        const form = document.getElementById('form-relation');
        const title = document.getElementById('modal-relation-title');
        
        if (!modal || !form) {
            console.error('Relation modal not found');
            return;
        }
        
        // Reset form
        form.reset();
        document.getElementById('relation-uuid').value = '';
        document.getElementById('relation-parent-org-uuid').value = parentOrgUuid;
        document.getElementById('relation-child-org-uuid').value = '';
        document.getElementById('relation-child-org-search').value = '';
        document.getElementById('relation-child-org-results').style.display = 'none';
        document.getElementById('relation-ownership-fields').style.display = 'none';
        
        if (title) {
            title.textContent = 'Neue Relation hinzufügen';
        }
        
        // Setup form submit
        const submitBtn = document.getElementById('btn-submit-relation');
        if (submitBtn) {
            const oldHandler = this._relationSubmitHandlers.get('btn-submit-relation');
            if (oldHandler) {
                submitBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = async (e) => {
                e.preventDefault();
                await this.submitRelationForm(parentOrgUuid);
            };
            
            this._relationSubmitHandlers.set('btn-submit-relation', handler);
            submitBtn.addEventListener('click', handler);
        }
        
        // Setup cancel button
        const cancelBtn = document.getElementById('btn-cancel-relation');
        if (cancelBtn) {
            const oldHandler = this._relationCancelHandlers.get('btn-cancel-relation');
            if (oldHandler) {
                cancelBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-relation');
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(parentOrgUuid);
                    }
                }
                return false;
            };
            
            this._relationCancelHandlers.set('btn-cancel-relation', handler);
            cancelBtn.addEventListener('click', handler);
        }
        
        // Setze Close-Button-Handler für dieses Modal
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            const oldHandler = this._relationCloseHandlers.get('modal-relation');
            if (oldHandler) {
                closeBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-relation');
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(parentOrgUuid);
                    }
                }
                return false;
            };
            
            this._relationCloseHandlers.set('modal-relation', handler);
            closeBtn.addEventListener('click', handler);
        }
        
        // Setze Overlay-Click-Handler
        const oldOverlayHandler = this._relationOverlayHandlers.get('modal-relation');
        if (oldOverlayHandler) {
            modal.removeEventListener('click', oldOverlayHandler);
        }
        
        const overlayHandler = (e) => {
            if (e.target === modal) {
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-relation');
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(parentOrgUuid);
                    }
                }
                return false;
            }
        };
        
        this._relationOverlayHandlers.set('modal-relation', overlayHandler);
        modal.addEventListener('click', overlayHandler);
        
        // Setup org search
        this.setupRelationOrgSearch();
        
        // Show ownership fields for ownership relation types
        const relationTypeSelect = document.getElementById('relation-type');
        if (relationTypeSelect) {
            const oldHandler = this._relationTypeChangeHandlers.get('relation-type');
            if (oldHandler) {
                relationTypeSelect.removeEventListener('change', oldHandler);
            }
            
            const handler = () => {
                const ownershipTypes = ['owns_stake_in', 'ubo_of'];
                const ownershipFields = document.getElementById('relation-ownership-fields');
                if (ownershipFields) {
                    ownershipFields.style.display = ownershipTypes.includes(relationTypeSelect.value) ? 'block' : 'none';
                }
            };
            
            this._relationTypeChangeHandlers.set('relation-type', handler);
            relationTypeSelect.addEventListener('change', handler);
        }
        
        modal.classList.add('active');
    }
    
    async editRelation(parentOrgUuid, relationUuid) {
        try {
            const relations = await window.API.getOrgRelations(parentOrgUuid);
            const relation = relations.find(r => r.relation_uuid === relationUuid);
            
            if (!relation) {
                Utils.showError('Relation nicht gefunden');
                return;
            }
            
            const modal = document.getElementById('modal-relation');
            const form = document.getElementById('form-relation');
            const title = document.getElementById('modal-relation-title');
            
            if (!modal || !form) {
                console.error('Relation modal not found');
                return;
            }
            
            // Fill form with relation data
            document.getElementById('relation-uuid').value = relationUuid;
            document.getElementById('relation-parent-org-uuid').value = parentOrgUuid;
            document.getElementById('relation-child-org-uuid').value = relation.child_org_uuid || '';
            document.getElementById('relation-type').value = relation.relation_type || '';
            document.getElementById('relation-child-org-search').value = relation.child_org_name || '';
            
            if (relation.since_date) {
                document.getElementById('relation-since-date').value = relation.since_date.split(' ')[0];
            }
            if (relation.until_date) {
                document.getElementById('relation-until-date').value = relation.until_date.split(' ')[0];
            }
            if (relation.ownership_percent) {
                document.getElementById('relation-ownership-percent').value = relation.ownership_percent;
            }
            document.getElementById('relation-has-voting-rights').checked = relation.has_voting_rights === 1 || relation.has_voting_rights === true;
            document.getElementById('relation-is-direct').checked = relation.is_direct !== 0 && relation.is_direct !== false;
            document.getElementById('relation-confidence').value = relation.confidence || 'high';
            document.getElementById('relation-is-current').checked = relation.is_current !== 0 && relation.is_current !== false;
            document.getElementById('relation-tags').value = relation.tags || '';
            document.getElementById('relation-source').value = relation.source || '';
            document.getElementById('relation-notes').value = relation.notes || '';
            
            // Show ownership fields if applicable
            const ownershipTypes = ['owns_stake_in', 'ubo_of'];
            const ownershipFields = document.getElementById('relation-ownership-fields');
            if (ownershipFields) {
                ownershipFields.style.display = ownershipTypes.includes(relation.relation_type) ? 'block' : 'none';
            }
            
            if (title) {
                title.textContent = 'Relation bearbeiten';
            }
            
            // Setup form submit
            const submitBtn = document.getElementById('btn-submit-relation');
            if (submitBtn) {
                const oldHandler = this._relationSubmitHandlers.get('btn-submit-relation');
                if (oldHandler) {
                    submitBtn.removeEventListener('click', oldHandler);
                }
                
                const handler = async (e) => {
                    e.preventDefault();
                    await this.submitRelationForm(parentOrgUuid, relationUuid);
                };
                
                this._relationSubmitHandlers.set('btn-submit-relation', handler);
                submitBtn.addEventListener('click', handler);
            }
            
            // Setup cancel button
            const cancelBtn = document.getElementById('btn-cancel-relation');
            if (cancelBtn) {
                const oldHandler = this._relationCancelHandlers.get('btn-cancel-relation');
                if (oldHandler) {
                    cancelBtn.removeEventListener('click', oldHandler);
                }
                
                const handler = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-relation');
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(parentOrgUuid);
                        }
                    }
                    return false;
                };
                
                this._relationCancelHandlers.set('btn-cancel-relation', handler);
                cancelBtn.addEventListener('click', handler);
            }
            
            // Setze Close-Button-Handler für dieses Modal
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) {
                const oldHandler = this._relationCloseHandlers.get('modal-relation');
                if (oldHandler) {
                    closeBtn.removeEventListener('click', oldHandler);
                }
                
                const handler = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-relation');
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(parentOrgUuid);
                        }
                    }
                    return false;
                };
                
                this._relationCloseHandlers.set('modal-relation', handler);
                closeBtn.addEventListener('click', handler);
            }
            
            // Setze Overlay-Click-Handler
            const oldOverlayHandler = this._relationOverlayHandlers.get('modal-relation');
            if (oldOverlayHandler) {
                modal.removeEventListener('click', oldOverlayHandler);
            }
            
            const overlayHandler = (e) => {
                if (e.target === modal) {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-relation');
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(parentOrgUuid);
                        }
                    }
                    return false;
                }
            };
            
            this._relationOverlayHandlers.set('modal-relation', overlayHandler);
            modal.addEventListener('click', overlayHandler);
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading relation:', error);
            Utils.showError('Fehler beim Laden der Relation');
        }
    }
    
    setupRelationOrgSearch() {
        const searchInput = document.getElementById('relation-child-org-search');
        const resultsContainer = document.getElementById('relation-child-org-results');
        const uuidInput = document.getElementById('relation-child-org-uuid');
        
        if (!searchInput || !resultsContainer || !uuidInput) return;
        
        let searchTimeout;
        
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            const query = searchInput.value.trim();
            
            if (query.length < 2) {
                resultsContainer.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(async () => {
                try {
                    const results = await window.API.searchOrgs(query, {}, 10);
                    if (results.length > 0) {
                        resultsContainer.innerHTML = results.map(org => `
                            <div class="org-search-result" data-org-uuid="${org.org_uuid || org.uuid}" style="cursor: pointer;">
                                <h4>${Utils.escapeHtml(org.name || 'Unbekannt')}</h4>
                                ${org.city ? `<p>${Utils.escapeHtml(org.city)}</p>` : ''}
                            </div>
                        `).join('');
                        
                        resultsContainer.querySelectorAll('.org-search-result').forEach(result => {
                            result.addEventListener('click', () => {
                                const orgUuid = result.dataset.orgUuid;
                                uuidInput.value = orgUuid;
                                searchInput.value = result.querySelector('h4').textContent;
                                resultsContainer.style.display = 'none';
                            });
                        });
                        
                        resultsContainer.style.display = 'block';
                    } else {
                        resultsContainer.innerHTML = '<div class="empty-state"><p>Keine Organisationen gefunden</p></div>';
                        resultsContainer.style.display = 'block';
                    }
                } catch (error) {
                    console.error('Error searching orgs:', error);
                }
            }, 300);
        });
    }
    
    async submitRelationForm(parentOrgUuid, relationUuid = null) {
        const form = document.getElementById('form-relation');
        if (!form) return;
        
        const orgDetailModule = this.app.orgDetail;
        
        // Verwende Utils.processFormData für konsistente Datenverarbeitung
        let data = Utils.processFormData(form, {
            filterEmpty: false, // Wir wollen alle Felder, auch leere
            checkboxFields: ['has_voting_rights', 'is_direct', 'is_current'],
            nullFields: ['since_date', 'until_date', 'tags', 'source', 'notes']
        });
        
        // Setze parent_org_uuid (wird nicht aus Formular gelesen)
        data.parent_org_uuid = parentOrgUuid;
        
        // Spezielle Verarbeitung für ownership_percent (kann leer sein)
        if (data.ownership_percent) {
            data.ownership_percent = parseFloat(data.ownership_percent);
        } else {
            data.ownership_percent = null;
        }
        
        // Confidence default
        if (!data.confidence) {
            data.confidence = 'high';
        }
        
        // Remove null/empty values
        Object.keys(data).forEach(key => {
            if (data[key] === null || data[key] === '') {
                delete data[key];
            }
        });
        
        try {
            if (relationUuid) {
                await window.API.updateOrgRelation(parentOrgUuid, relationUuid, data);
                Utils.showSuccess('Relation wurde aktualisiert');
            } else {
                await window.API.addOrgRelation(parentOrgUuid, data);
                Utils.showSuccess('Relation wurde hinzugefügt');
            }
            
            Utils.closeSpecificModal('modal-relation');
            // Reload org detail to show updated relations
            if (orgDetailModule && orgDetailModule.showOrgDetail) {
                await orgDetailModule.showOrgDetail(parentOrgUuid);
            }
        } catch (error) {
            console.error('Error saving relation:', error);
            Utils.showError('Fehler beim Speichern: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}


