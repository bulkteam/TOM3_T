/**
 * TOM3 - Person Detail Module
 * Handles person detail view, editing, and all related sub-entities
 */

import { Utils } from './utils.js';
import { AuditTrailModule } from './audit-trail.js';
import { PersonDetailViewModule } from './person-detail-view.js';
import { EntityDetailBaseModule } from './entity-detail-base.js';
import { PersonAffiliationModule } from './person-affiliation.js';
import { PersonRelationshipModule } from './person-relationship.js';

export class PersonDetailModule extends EntityDetailBaseModule {
    constructor(app) {
        const config = {
            entityType: 'person',
            entityTypeName: 'Person',
            modalId: 'modal-person-detail',
            modalBodyId: 'modal-person-body',
            closeButtonSelector: '.person-detail-close',
            tabSelector: '.person-detail-tab',
            tabContentSelector: '.person-detail-tab-content',
            headerMenuSelector: '.person-detail-menu',
            setupHeaderMenu: (container, entityUuid, entityName, baseModule) => {
                baseModule.setupPersonHeaderMenu(container, entityUuid, entityName);
            },
            onTabChange: (tabName, entityUuid, container) => {
                // Wird in showPersonDetail überschrieben
            }
        };
        super(app, config);
        this.app = app;
        this.viewModule = new PersonDetailViewModule(app);
        this.auditTrailModule = new AuditTrailModule(app);
        this.affiliationModule = new PersonAffiliationModule(app);
        this.relationshipModule = new PersonRelationshipModule(app);
        this._tabHandlers = new Map();
        this._menuHandlers = new Map();
    }
    
    async showPersonDetail(personUuid) {
        // Überschreibe onTabChange für person-spezifische Logik
        this.config.onTabChange = (tabName, uuid, container) => {
            if (tabName === 'historie') {
                this.affiliationModule.loadAffiliations(uuid);
            } else if (tabName === 'relationen') {
                this.relationshipModule.loadRelationships(uuid);
            }
        };
        
        await this.showEntityDetail(
            personUuid,
            async (uuid) => await window.API.getPerson(uuid),
            (person) => this.viewModule.renderPersonDetail(person),
            (person) => person.display_name || `${person.first_name || ''} ${person.last_name || ''}`.trim() || 'Unbekannt',
            async (uuid, userId) => await window.API.trackPersonAccess(uuid, userId, 'recent'),
            (modalBody, person, uuid) => {
                // Setup Tabs mit person-spezifischen Handlern
                this.setupTabs(modalBody, uuid);
                
                // Load initial data für aktiven Tab (Stammdaten ist standardmäßig aktiv)
                // Historie und Relationen werden beim Tab-Wechsel geladen
                
                // Edit Button
                const editBtn = modalBody.querySelector('#btn-edit-person');
                if (editBtn) {
                    editBtn.addEventListener('click', () => {
                        if (window.app.personForms) {
                            window.app.personForms.showEditPersonForm(person);
                        }
                    });
                }
                
                // Buttons für Historie und Relationen
                const addAffiliationBtn = modalBody.querySelector('#btn-add-affiliation');
                if (addAffiliationBtn) {
                    addAffiliationBtn.addEventListener('click', () => {
                        this.affiliationModule.showAddAffiliationDialog(uuid);
                    });
                }
                
                const addRelationshipBtn = modalBody.querySelector('#btn-add-relationship');
                if (addRelationshipBtn) {
                    addRelationshipBtn.addEventListener('click', () => {
                        this.relationshipModule.showAddRelationshipDialog(uuid);
                    });
                }
            }
        );
    }
    
    setupPersonHeaderMenu(modalBody, personUuid, personName) {
        this.setupHeaderMenu(modalBody, personUuid, personName);
    }
    
    setupTabs(container, personUuid) {
        // Verwende die Konfiguration aus der Basis-Klasse
        const tabs = container.querySelectorAll(this.config.tabSelector);
        const tabContents = container.querySelectorAll(this.config.tabContentSelector);
        
        tabs.forEach(tab => {
            const tabName = tab.dataset.tab;
            if (!tabName) return;
            
            const oldHandler = this._tabHandlers.get(tabName);
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
                if (tabName === 'historie') {
                    this.affiliationModule.loadAffiliations(personUuid);
                } else if (tabName === 'relationen') {
                    this.relationshipModule.loadRelationships(personUuid);
                }
            };
            
            this._tabHandlers.set(tabName, handler);
            tab.addEventListener('click', handler);
        });
    }
    
    
    setupHeaderMenu(modalBody, personUuid, personName) {
        const menuToggle = modalBody.querySelector('.person-detail-menu-toggle');
        const menuDropdown = modalBody.querySelector('.person-detail-menu-dropdown');
        
        if (!menuToggle || !menuDropdown) {
            return;
        }
        
        // Toggle-Menü beim Klick auf den Toggle-Button
        const oldToggleHandler = this._menuHandlers.get('toggle');
        if (oldToggleHandler) {
            menuToggle.removeEventListener('click', oldToggleHandler);
        }
        
        const toggleHandler = (e) => {
            e.preventDefault();
            e.stopPropagation();
            menuDropdown.classList.toggle('active');
        };
        
        this._menuHandlers.set('toggle', toggleHandler);
        menuToggle.addEventListener('click', toggleHandler);
        
        // Schließe Menü beim Klick außerhalb
        const oldOutsideClickHandler = this._menuHandlers.get('outside-click');
        if (oldOutsideClickHandler) {
            document.removeEventListener('click', oldOutsideClickHandler);
        }
        
        const outsideClickHandler = (e) => {
            if (!modalBody.contains(e.target)) {
                menuDropdown.classList.remove('active');
            }
        };
        
        this._menuHandlers.set('outside-click', outsideClickHandler);
        document.addEventListener('click', outsideClickHandler);
        
        // Audit-Trail-Menü-Item
        const auditTrailItem = menuDropdown.querySelector('[data-action="audit-trail"]');
        if (auditTrailItem) {
            const oldAuditHandler = this._menuHandlers.get('audit-trail');
            if (oldAuditHandler) {
                auditTrailItem.removeEventListener('click', oldAuditHandler);
            }
            
            const auditHandler = async (e) => {
                e.preventDefault();
                e.stopPropagation();
                menuDropdown.classList.remove('active');
                
                // Verwende AuditTrailModule (wie bei Org)
                if (this.auditTrailModule) {
                    await this.auditTrailModule.showAuditTrail(personUuid, personName, 'person');
                }
            };
            
            this._menuHandlers.set('audit-trail', auditHandler);
            auditTrailItem.addEventListener('click', auditHandler);
        }
    }
    
}
