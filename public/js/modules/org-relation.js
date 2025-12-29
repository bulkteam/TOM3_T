/**
 * TOM3 - Organization Relation Module
 * Handles organization relation management
 */

import { Utils } from './utils.js';

export class OrgRelationModule {
    constructor(app) {
        this.app = app;
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
            const newSubmitBtn = submitBtn.cloneNode(true);
            submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
            newSubmitBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.submitRelationForm(parentOrgUuid);
            });
        }
        
        // Setup cancel button
        const cancelBtn = document.getElementById('btn-cancel-relation');
        if (cancelBtn) {
            const newCancelBtn = cancelBtn.cloneNode(true);
            cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
            newCancelBtn.addEventListener('click', () => {
                Utils.closeModal();
            });
        }
        
        // Setup org search
        this.setupRelationOrgSearch();
        
        // Show ownership fields for ownership relation types
        const relationTypeSelect = document.getElementById('relation-type');
        if (relationTypeSelect) {
            const newSelect = relationTypeSelect.cloneNode(true);
            relationTypeSelect.parentNode.replaceChild(newSelect, relationTypeSelect);
            newSelect.addEventListener('change', () => {
                const ownershipTypes = ['owns_stake_in', 'ubo_of'];
                const ownershipFields = document.getElementById('relation-ownership-fields');
                if (ownershipFields) {
                    ownershipFields.style.display = ownershipTypes.includes(newSelect.value) ? 'block' : 'none';
                }
            });
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
                const newSubmitBtn = submitBtn.cloneNode(true);
                submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
                newSubmitBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    await this.submitRelationForm(parentOrgUuid, relationUuid);
                });
            }
            
            // Setup cancel button
            const cancelBtn = document.getElementById('btn-cancel-relation');
            if (cancelBtn) {
                const newCancelBtn = cancelBtn.cloneNode(true);
                cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
                newCancelBtn.addEventListener('click', () => {
                    Utils.closeModal();
                });
            }
            
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
        
        const formData = new FormData(form);
        const data = {
            parent_org_uuid: parentOrgUuid,
            child_org_uuid: formData.get('child_org_uuid'),
            relation_type: formData.get('relation_type'),
            since_date: formData.get('since_date') || null,
            until_date: formData.get('until_date') || null,
            ownership_percent: formData.get('ownership_percent') ? parseFloat(formData.get('ownership_percent')) : null,
            has_voting_rights: formData.get('has_voting_rights') === '1' ? 1 : 0,
            is_direct: formData.get('is_direct') === '1' ? 1 : 0,
            confidence: formData.get('confidence') || 'high',
            is_current: formData.get('is_current') === '1' ? 1 : 0,
            tags: formData.get('tags') || null,
            source: formData.get('source') || null,
            notes: formData.get('notes') || null
        };
        
        // Remove null values
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
            
            Utils.closeModal();
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

