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
import { EntityDetailBaseModule } from './entity-detail-base.js';

export class OrgDetailModule extends EntityDetailBaseModule {
    constructor(app) {
        const config = {
            entityType: 'org',
            entityTypeName: 'Organisation',
            modalId: 'modal-org-detail',
            modalBodyId: 'modal-org-body',
            closeButtonSelector: '.org-detail-close',
            tabSelector: '.org-detail-tab',
            tabContentSelector: '.org-detail-tab-content',
            headerMenuSelector: '.org-detail-menu',
            setupHeaderMenu: (container, entityUuid, entityName, baseModule) => {
                baseModule.setupOrgHeaderMenu(container, entityUuid, entityName);
            }
        };
        super(app, config);
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
        await this.showEntityDetail(
            orgUuid,
            async (uuid) => await window.API.getOrgDetails(uuid),
            (org) => this.viewModule.renderOrgDetail(org),
            (org) => org.name || 'Unbekannt',
            async (uuid, userId) => await window.API.trackOrgAccess(uuid, userId, 'recent'),
            async (modalBody, org, uuid) => {
                // Lade verfügbare Account Owners für Edit-Modus
                try {
                    await this.editModule.loadAccountOwnersForEdit(uuid, org.account_owner_user_id);
                } catch (error) {
                    console.warn('Could not load account owners for edit:', error);
                }
                
                // Setze aktuelle Branchen-Werte für Edit-Modus (3-stufige Hierarchie)
                const industryLevel1Input = modalBody.querySelector('#org-input-industry_level1');
                const industryLevel2Input = modalBody.querySelector('#org-input-industry_level2');
                const industryLevel3Input = modalBody.querySelector('#org-input-industry_level3');
                
                // Verwende Level-Werte oder fallback auf alte Werte (Rückwärtskompatibilität)
                const level1Uuid = org.industry_level1_uuid || org.industry_main_uuid;
                const level2Uuid = org.industry_level2_uuid || org.industry_sub_uuid;
                const level3Uuid = org.industry_level3_uuid;
                
                if (industryLevel1Input && level1Uuid) {
                    industryLevel1Input.dataset.currentLevel1Uuid = level1Uuid;
                }
                if (industryLevel2Input && level2Uuid) {
                    industryLevel2Input.dataset.currentLevel2Uuid = level2Uuid;
                }
                if (industryLevel3Input && level3Uuid) {
                    industryLevel3Input.dataset.currentLevel3Uuid = level3Uuid;
                }
                
                // Setze Tab-Navigation
                this.setupTabs(modalBody, uuid);
                
                // Lade initial Dokumente-Anzahl für Badge
                this.updateDocumentsCount(uuid);
            }
        );
    }
    
    setupOrgHeaderMenu(modalBody, orgUuid, orgName) {
        this.setupHeaderMenu(modalBody, orgUuid, orgName);
    }
    
    setupTabs(container, orgUuid) {
        const tabs = container.querySelectorAll(this.config.tabSelector);
        const tabContents = container.querySelectorAll(this.config.tabContentSelector);
        
        tabs.forEach(tab => {
            const tabName = tab.dataset.tab;
            if (!tabName) return;
            
            const oldHandler = this._tabHandlers?.get(tabName);
            if (oldHandler) {
                tab.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                // Update active tab
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Update active content
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    const contentTabName = content.dataset.tabContent;
                    if (contentTabName === tabName) {
                        content.classList.add('active');
                    }
                });
                
                // Load content if needed
                if (tabName === 'dokumente') {
                    this.loadDocuments(orgUuid);
                    // Event-Listener für Upload-Button registrieren, wenn Dokumente-Tab geöffnet wird
                    this.setupUploadButton(container, orgUuid);
                }
            };
            
            if (!this._tabHandlers) {
                this._tabHandlers = new Map();
            }
            this._tabHandlers.set(tabName, handler);
            tab.addEventListener('click', handler);
        });
    }
    
    async loadDocuments(orgUuid) {
        try {
            const documentListModule = this.app.modules?.documentList;
            if (documentListModule) {
                await documentListModule.loadDocuments('org', orgUuid, '#org-documents-list', '#org-documents-count-badge');
            }
        } catch (error) {
            console.warn('Could not load documents:', error);
        }
    }
    
    async updateDocumentsCount(orgUuid) {
        try {
            const documents = await window.API.getEntityDocuments('org', orgUuid);
            const badge = document.querySelector('#org-documents-count-badge');
            if (badge) {
                const count = documents?.length || 0;
                if (count > 0) {
                    badge.textContent = count;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            }
        } catch (error) {
            console.warn('Could not update documents count:', error);
        }
    }
    
    setupUploadButton(container, orgUuid) {
        // Entferne alten Event-Listener falls vorhanden
        const existingButton = container.querySelector('#org-upload-document-btn');
        if (existingButton) {
            const newButton = existingButton.cloneNode(true);
            existingButton.parentNode.replaceChild(newButton, existingButton);
        }
        
        // Neuen Event-Listener registrieren
        const uploadButton = container.querySelector('#org-upload-document-btn');
        if (uploadButton) {
            uploadButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                if (this.app.modules?.documentUpload) {
                    this.app.modules.documentUpload.showUploadDialog('org', orgUuid);
                } else if (window.app?.documentUpload) {
                    window.app.documentUpload.showUploadDialog('org', orgUuid);
                }
            });
        } else {
            console.warn('Upload-Button nicht gefunden:', '#org-upload-document-btn');
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
        // Überschreibt die Basis-Methode für org-spezifische Logik
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

