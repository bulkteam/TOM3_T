/**
 * TOM3 - Organization Detail Module
 * Handles organization detail view, editing, and all related sub-entities
 */

import { Utils } from './utils.js';
import { AuditTrailModule } from './audit-trail.js';
import { OrgAddressModule } from './org-address.js';
import { OrgChannelModule } from './org-channel.js';
import { OrgVatModule } from './org-vat.js';
import { OrgRelationModule } from './org-relation.js';
import { OrgDetailViewModule } from './org-detail-view.js';
import { OrgDetailEditModule } from './org-detail-edit.js';

export class OrgDetailModule {
    constructor(app) {
        this.app = app;
        this.auditTrailModule = new AuditTrailModule(app);
        this.addressModule = new OrgAddressModule(app);
        this.channelModule = new OrgChannelModule(app);
        this.vatModule = new OrgVatModule(app);
        this.relationModule = new OrgRelationModule(app);
        this.viewModule = new OrgDetailViewModule(app);
        this.editModule = new OrgDetailEditModule(app);
    }
    
    async showOrgDetail(orgUuid) {
        const modal = document.getElementById('modal-org-detail');
        if (!modal) {
            console.error('Org detail modal not found');
            return;
        }
        
        try {
            const org = await window.API.getOrgDetails(orgUuid);
            if (!org) {
                Utils.showError('Organisation nicht gefunden');
                return;
            }
            
            const modalBody = document.getElementById('modal-org-body');
            const modalHeader = modal?.querySelector('.modal-header');
            
            // Verstecke den Modal-Header komplett, da wir alles im Sticky Header haben
            if (modalHeader) {
                modalHeader.style.display = 'none';
            }
            
            if (modalBody) {
                try {
                    modalBody.innerHTML = this.viewModule.renderOrgDetail(org);
                    
                    // Event-Handler für Close-Button im Sticky Header
                    const closeBtn = modalBody.querySelector('.org-detail-close');
                    if (closeBtn) {
                        // Entferne alle vorhandenen Event-Listener durch Klonen
                        const newCloseBtn = closeBtn.cloneNode(true);
                        closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
                        newCloseBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            // Schließe nur das Stammdaten-Modal, gehe zurück zur Suche
                            Utils.closeSpecificModal('modal-org-detail');
                            return false;
                        });
                    }
                    
                    // Event-Handler für Close-Button im Modal-Header (falls vorhanden)
                    const modalCloseBtn = modal.querySelector('.modal-close');
                    if (modalCloseBtn) {
                        const newModalCloseBtn = modalCloseBtn.cloneNode(true);
                        modalCloseBtn.parentNode.replaceChild(newModalCloseBtn, modalCloseBtn);
                        newModalCloseBtn.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            // Schließe nur das Stammdaten-Modal, gehe zurück zur Suche
                            Utils.closeSpecificModal('modal-org-detail');
                            return false;
                        });
                    }
                    
                    // Event-Handler für Drei-Punkte-Menü
                    this.setupHeaderMenu(modalBody, orgUuid, org.name);
                    
                    // Lade verfügbare Account Owners für Edit-Modus
                    try {
                        await this.editModule.loadAccountOwnersForEdit(orgUuid, org.account_owner_user_id);
                    } catch (error) {
                        console.warn('Could not load account owners for edit:', error);
                        // Nicht kritisch, weiter machen
                    }
                    
                    // Setze aktuelle Branchen-Werte für Edit-Modus
                    const industryMainInput = modalBody.querySelector('#org-input-industry_main');
                    const industrySubInput = modalBody.querySelector('#org-input-industry_sub');
                    if (industryMainInput && org.industry_main_uuid) {
                        industryMainInput.dataset.currentMainUuid = org.industry_main_uuid;
                    }
                    if (industrySubInput && org.industry_sub_uuid) {
                        industrySubInput.dataset.currentSubUuid = org.industry_sub_uuid;
                    }
                } catch (renderError) {
                    console.error('Error rendering org detail:', renderError);
                    console.error('Org data:', org);
                    modalBody.innerHTML = `
                        <div style="padding: 2rem; text-align: center;">
                            <h3 style="color: var(--danger);">Fehler beim Rendern der Details</h3>
                            <p style="color: var(--text-light);">Bitte öffnen Sie die Browser-Konsole für Details.</p>
                            <pre style="text-align: left; background: var(--bg); padding: 1rem; border-radius: 4px; overflow: auto; max-height: 400px;">${Utils.escapeHtml(JSON.stringify(renderError, null, 2))}</pre>
                        </div>
                    `;
                    throw renderError; // Re-throw um den äußeren catch zu triggern
                }
            }
            
            // Track Zugriff
            try {
                const user = await window.API.getCurrentUser();
                if (user && user.user_id) {
                    await window.API.trackOrgAccess(orgUuid, user.user_id, 'recent');
                    // Aktualisiere "Zuletzt verwendet" Liste
                    if (window.app.orgSearch && window.app.orgSearch.loadRecentOrgs) {
                        await window.app.orgSearch.loadRecentOrgs();
                    } else if (window.app.loadRecentOrgs) {
                        await window.app.loadRecentOrgs();
                    }
                }
            } catch (error) {
                console.warn('Could not track org access:', error);
            }
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading org detail:', error);
            console.error('Error stack:', error.stack);
            Utils.showError('Fehler beim Laden der Organisationsdetails: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    // Rendering delegated to OrgDetailViewModule
    renderOrgDetail(org) {
        return this.viewModule.renderOrgDetail(org);
    }
    
    async archiveOrg(orgUuid) {
        if (!confirm('Möchten Sie diese Organisation wirklich archivieren?\n\nArchivierte Organisationen erscheinen nicht mehr in aktiven Listen, bleiben aber in der Suche auffindbar.')) {
            return;
        }
        
        try {
            const user = await window.API.getCurrentUser();
            const userId = user?.user_id || 'default_user';
            await window.API.archiveOrg(orgUuid, userId);
            Utils.showSuccess('Organisation wurde archiviert');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error archiving org:', error);
            Utils.showError('Fehler beim Archivieren: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    async unarchiveOrg(orgUuid) {
        if (!confirm('Möchten Sie diese Organisation wirklich reaktivieren?')) {
            return;
        }
        
        try {
            const user = await window.API.getCurrentUser();
            const userId = user?.user_id || 'default_user';
            await window.API.unarchiveOrg(orgUuid, userId);
            Utils.showSuccess('Organisation wurde reaktiviert');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error unarchiving org:', error);
            Utils.showError('Fehler beim Reaktivieren: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    // Address management delegated to OrgAddressModule
    async showAddAddressModal(orgUuid) {
        return this.addressModule.showAddAddressModal(orgUuid);
    }
    
    async editAddress(orgUuid, addressUuid) {
        return this.addressModule.editAddress(orgUuid, addressUuid);
    }
    
    // Channel management delegated to OrgChannelModule
    async showAddChannelModal(orgUuid) {
        return this.channelModule.showAddChannelModal(orgUuid);
    }
    
    async editChannel(orgUuid, channelUuid) {
        return this.channelModule.editChannel(orgUuid, channelUuid);
    }
    
    // VAT management delegated to OrgVatModule
    async showAddVatRegistrationModal(orgUuid) {
        return this.vatModule.showAddVatRegistrationModal(orgUuid);
    }
    
    async editVatRegistration(orgUuid, vatUuid) {
        return this.vatModule.editVatRegistration(orgUuid, vatUuid);
    }
    
    // Relation management delegated to OrgRelationModule
    showAddRelationDialog(parentOrgUuid) {
        return this.relationModule.showAddRelationDialog(parentOrgUuid);
    }
    
    async editRelation(parentOrgUuid, relationUuid) {
        return this.relationModule.editRelation(parentOrgUuid, relationUuid);
    }
    
    // Edit mode management delegated to OrgDetailEditModule
    toggleOrgEditMode(orgUuid) {
        return this.editModule.toggleOrgEditMode(orgUuid);
    }
    
    cancelOrgEdit(orgUuid) {
        return this.editModule.cancelOrgEdit(orgUuid);
    }
    
    async loadAccountOwnersForEdit(orgUuid, currentOwnerId) {
        return this.editModule.loadAccountOwnersForEdit(orgUuid, currentOwnerId);
    }
    
    async saveOrgChanges(orgUuid) {
        return this.editModule.saveOrgChanges(orgUuid);
    }
    
    setupHeaderMenu(modalBody, orgUuid, orgName) {
        const menuToggle = modalBody.querySelector('.org-detail-menu-toggle');
        const menuDropdown = modalBody.querySelector('.org-detail-menu-dropdown');
        
        if (!menuToggle || !menuDropdown) {
            return;
        }
        
        // Toggle-Menü beim Klick auf den Toggle-Button
        menuToggle.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            menuDropdown.classList.toggle('active');
        });
        
        // Schließe Menü beim Klick außerhalb
        document.addEventListener('click', (e) => {
            if (!menuToggle.contains(e.target) && !menuDropdown.contains(e.target)) {
                menuDropdown.classList.remove('active');
            }
        });
        
        // Event-Handler für Menü-Items
        const menuItems = modalBody.querySelectorAll('.org-detail-menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                menuDropdown.classList.remove('active');
                
                const action = item.getAttribute('data-action');
                
                if (action === 'audit-trail') {
                    await this.auditTrailModule.showAuditTrail(orgUuid, orgName);
                }
            });
        });
    }
}

