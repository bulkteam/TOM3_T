/**
 * TOM3 - Entity Detail Base Module (Zentral)
 * Basis-Klasse für Detail-Views (Organisationen, Personen, etc.)
 * Eliminiert Code-Duplikation zwischen OrgDetail und PersonDetail
 */

import { Utils } from './utils.js';

export class EntityDetailBaseModule {
    constructor(app, config) {
        this.app = app;
        this.config = config; // { entityType, modalId, modalBodyId, closeButtonSelector, tabSelector, tabContentSelector, ... }
        this._detailCloseHandlers = new Map();
        this._tabHandlers = new Map();
    }
    
    /**
     * Generische Methode zum Öffnen eines Detail-Modals
     * 
     * @param {string} entityUuid UUID der Entität
     * @param {Function} fetchEntity Callback zum Laden der Entität (muss Promise zurückgeben)
     * @param {Function} renderEntity Callback zum Rendern der Entität (muss HTML-String zurückgeben)
     * @param {Function} getEntityName Callback zum Ermitteln des Namens der Entität
     * @param {Function} trackAccess Callback zum Tracken des Zugriffs
     * @param {Function} onAfterRender Optional: Callback nach dem Rendern
     */
    async showEntityDetail(entityUuid, fetchEntity, renderEntity, getEntityName, trackAccess, onAfterRender = null) {
        const modal = document.getElementById(this.config.modalId);
        if (!modal) {
            console.error(`${this.config.entityType} detail modal not found: ${this.config.modalId}`);
            return;
        }
        
        try {
            const entity = await fetchEntity(entityUuid);
            if (!entity) {
                Utils.showError(`${this.config.entityTypeName || 'Entität'} nicht gefunden`);
                return;
            }
            
            const modalBody = document.getElementById(this.config.modalBodyId);
            const modalHeader = modal?.querySelector('.modal-header');
            
            // Verstecke den Modal-Header komplett, da wir alles im Sticky Header haben
            if (modalHeader) {
                modalHeader.style.display = 'none';
            }
            
            if (modalBody) {
                try {
                    modalBody.innerHTML = renderEntity(entity);
                    
                    // Close Button Handler
                    this.setupCloseButtons(modal, modalBody);
                    
                    // Tab Navigation
                    if (this.config.tabSelector && this.config.tabContentSelector) {
                        this.setupTabs(modalBody, entityUuid);
                    }
                    
                    // Header Menu (Dreipunkte-Menü)
                    const entityName = getEntityName(entity);
                    if (this.config.headerMenuSelector) {
                        this.setupHeaderMenu(modalBody, entityUuid, entityName);
                    }
                    
                    // Zusätzliche Callbacks nach dem Rendern
                    if (onAfterRender) {
                        onAfterRender(modalBody, entity, entityUuid);
                    }
                } catch (renderError) {
                    console.error(`Error rendering ${this.config.entityType} detail:`, renderError);
                    console.error('Entity data:', entity);
                    modalBody.innerHTML = `
                        <div style="padding: 2rem; text-align: center;">
                            <h3 style="color: var(--danger);">Fehler beim Rendern der Details</h3>
                            <p style="color: var(--text-light);">Bitte öffnen Sie die Browser-Konsole für Details.</p>
                            <pre style="text-align: left; background: var(--bg); padding: 1rem; border-radius: 4px; overflow: auto; max-height: 400px;">${Utils.escapeHtml(JSON.stringify(renderError, null, 2))}</pre>
                        </div>
                    `;
                    throw renderError;
                }
            }
            
            // Track Zugriff
            if (trackAccess) {
                try {
                    const user = await window.API.getCurrentUser();
                    if (user && user.user_id) {
                        await trackAccess(entityUuid, user.user_id);
                        // Aktualisiere "Zuletzt angesehen" Liste
                        this.refreshRecentList();
                    }
                } catch (error) {
                    console.warn(`Could not track ${this.config.entityType} access:`, error);
                }
            }
            
            modal.classList.add('active');
        } catch (error) {
            console.error(`Error loading ${this.config.entityType} detail:`, error);
            console.error('Error stack:', error.stack);
            Utils.showError(`Fehler beim Laden der ${this.config.entityTypeName || 'Details'}: ` + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Setzt Close-Button-Handler
     */
    setupCloseButtons(modal, modalBody) {
        // Close Button im Sticky Header
        const closeBtn = modalBody.querySelector(this.config.closeButtonSelector);
        if (closeBtn) {
            const oldHandler = this._detailCloseHandlers.get(`${this.config.entityType}-detail-close`);
            if (oldHandler) {
                closeBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal(this.config.modalId);
                return false;
            };
            
            this._detailCloseHandlers.set(`${this.config.entityType}-detail-close`, handler);
            closeBtn.addEventListener('click', handler);
        }
        
        // Close Button im Modal-Header (falls vorhanden)
        const modalCloseBtn = modal.querySelector('.modal-close');
        if (modalCloseBtn) {
            const oldHandler = this._detailCloseHandlers.get(`modal-${this.config.entityType}-detail-close`);
            if (oldHandler) {
                modalCloseBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal(this.config.modalId);
                return false;
            };
            
            this._detailCloseHandlers.set(`modal-${this.config.entityType}-detail-close`, handler);
            modalCloseBtn.addEventListener('click', handler);
        }
    }
    
    /**
     * Setzt Tab-Navigation
     */
    setupTabs(container, entityUuid) {
        const tabs = container.querySelectorAll(this.config.tabSelector);
        const tabContents = container.querySelectorAll(this.config.tabContentSelector);
        
        tabs.forEach(tab => {
            const tabName = tab.dataset.tab || tab.dataset[`${this.config.entityType}Tab`];
            if (!tabName) return;
            
            const oldHandler = this._tabHandlers.get(tabName);
            if (oldHandler) {
                tab.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                
                // Update active tab
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Update active content
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    const contentTabName = content.dataset.tabContent || content.dataset[`${this.config.entityType}TabContent`];
                    if (contentTabName === tabName) {
                        content.classList.add('active');
                    }
                });
                
                // Optional: Callback für Tab-Wechsel
                if (this.config.onTabChange) {
                    this.config.onTabChange(tabName, entityUuid, container);
                }
            };
            
            this._tabHandlers.set(tabName, handler);
            tab.addEventListener('click', handler);
        });
    }
    
    /**
     * Setzt Header-Menu (Dreipunkte-Menü)
     * Wird über config.setupHeaderMenu aufgerufen, kann von abgeleiteten Klassen überschrieben werden
     */
    setupHeaderMenu(container, entityUuid, entityName) {
        // Wird über config.setupHeaderMenu aufgerufen
        if (this.config.setupHeaderMenu) {
            this.config.setupHeaderMenu(container, entityUuid, entityName, this);
        }
    }
    
    /**
     * Aktualisiert die "Zuletzt angesehen" Liste
     */
    refreshRecentList() {
        if (this.config.entityType === 'org' && window.app.orgSearch && window.app.orgSearch.loadRecentOrgs) {
            window.app.orgSearch.loadRecentOrgs();
        } else if (this.config.entityType === 'person' && window.app.personSearch && window.app.personSearch.loadRecentPersons) {
            window.app.personSearch.loadRecentPersons();
        }
    }
}
