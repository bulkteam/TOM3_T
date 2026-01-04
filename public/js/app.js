/**
 * TOM3 - Main Application
 * Hauptklasse - nur Initialisierung und Koordination der Module
 */

import { AuthModule } from './modules/auth.js';
import { AdminModule } from './modules/admin.js';
import { OrgSearchModule } from './modules/org-search.js';
import { OrgDetailModule } from './modules/org-detail.js';
import { OrgFormsModule } from './modules/org-forms.js';
import { PersonSearchModule } from './modules/person-search.js';
import { PersonDetailModule } from './modules/person-detail.js';
import { PersonFormsModule } from './modules/person-forms.js';
import { MonitoringModule } from './modules/monitoring.js';
import { DocumentUploadModule } from './modules/document-upload.js';
import { DocumentListModule } from './modules/document-list.js';
import { DocumentSearchModule } from './modules/document-search.js';
import { ImportModule } from './modules/import.js';
import { Utils } from './modules/utils.js';

class TOM3App {
    constructor() {
        this.currentPage = localStorage.getItem('currentPage') || 'dashboard';
        
        // Initialisiere Module
        this.auth = new AuthModule(this);
        this.admin = new AdminModule(this);
        this.orgSearch = new OrgSearchModule(this);
        this.orgDetail = new OrgDetailModule(this);
        this.orgForms = new OrgFormsModule(this);
        this.personSearch = new PersonSearchModule(this);
        this.personDetail = new PersonDetailModule(this);
        this.personForms = new PersonFormsModule(this);
        this.monitoring = new MonitoringModule(this);
        this.documentUpload = new DocumentUploadModule(this);
        this.documentList = new DocumentListModule(this);
        this.documentSearch = new DocumentSearchModule(this);
        this.import = new ImportModule(this);
        
        // Module-Referenz für Zugriff von anderen Modulen
        this.modules = {
            documentUpload: this.documentUpload,
            documentList: this.documentList,
            documentSearch: this.documentSearch,
            import: this.import
        };
        
        this.init();
    }
    
    /**
     * Zeigt eine Notification an
     * @param {string} message Nachricht
     * @param {string} type 'success' | 'error' | 'info'
     */
    showNotification(message, type = 'info') {
        // Einfache Toast-Notification
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
            color: white;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 10000;
            animation: slideIn 0.3s ease-out;
        `;
        
        document.body.appendChild(notification);
        
        // Nach 3 Sekunden entfernen
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }

    async init() {
        // Hole CSRF-Token beim App-Start
        if (window.csrfTokenService) {
            try {
                await window.csrfTokenService.fetchToken();
            } catch (error) {
                console.warn('Could not fetch CSRF token:', error);
                // In Dev-Mode: Token ist optional, daher kein Fehler werfen
            }
        }
        
        await this.auth.loadCurrentUser();
        this.setupEventListeners();
        this.setupNavigation();
        // Expandiere das Menü für die aktuelle Seite
        this.expandMenuForPage(this.currentPage);
        this.navigateTo(this.currentPage, false);
    }
    
    expandMenuForPage(page) {
        // Finde das übergeordnete Menü für diese Seite
        const navLink = document.querySelector(`[data-page="${page}"]`);
        if (navLink) {
            const menuItem = navLink.closest('.nav-menu-item');
            if (menuItem) {
                menuItem.classList.add('expanded');
            }
        }
    }
    
    setupEventListeners() {
        // Modal-Close-Buttons
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                Utils.closeModal();
            });
        });
        
        // Modal-Overlay-Klick schließt Modal
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    Utils.closeModal();
                }
            });
        });
    }

    setupNavigation() {
        // Hauptmenüpunkte (Expand/Collapse)
        document.querySelectorAll('.nav-parent').forEach(parentLink => {
            parentLink.addEventListener('click', (e) => {
                e.preventDefault();
                const menuItem = parentLink.closest('.nav-menu-item');
                if (menuItem) {
                    // Toggle expand/collapse
                    if (menuItem.classList.contains('expanded')) {
                        menuItem.classList.remove('expanded');
                    } else {
                        // Schließe alle anderen Menüs
                        document.querySelectorAll('.nav-menu-item.expanded').forEach(item => {
                            if (item !== menuItem) {
                                item.classList.remove('expanded');
                            }
                        });
                        menuItem.classList.add('expanded');
                    }
                }
            });
        });
        
        // Untermenü-Links (Navigation)
        document.querySelectorAll('.nav-child').forEach(childLink => {
            childLink.addEventListener('click', (e) => {
                e.preventDefault();
                const page = childLink.dataset.page;
                if (page) {
                    this.navigateTo(page);
                    // Update URL hash
                    window.location.hash = page;
                }
            });
        });
        
        // Hash-Change-Listener für Browser-Navigation (nur einmal registrieren)
        if (!this.hashChangeListenerAttached) {
            window.addEventListener('hashchange', () => {
                const hash = window.location.hash.replace('#', '');
                if (hash && hash !== this.currentPage) {
                    this.navigateTo(hash);
                }
            });
            this.hashChangeListenerAttached = true;
        }
    }

    navigateTo(page, storePage = true) {
        // Update navigation - nur Untermenü-Links aktivieren
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        const navLink = document.querySelector(`[data-page="${page}"]`);
        if (navLink) {
            navLink.classList.add('active');
            // Expandiere das übergeordnete Menü, falls vorhanden
            const menuItem = navLink.closest('.nav-menu-item');
            if (menuItem) {
                menuItem.classList.add('expanded');
            }
        }

        // Update pages
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        const targetPage = document.getElementById(`page-${page}`);
        if (targetPage) {
            targetPage.classList.add('active');
        } else {
            console.warn(`Page "${page}" not found, falling back to dashboard`);
            page = 'dashboard';
            document.getElementById('page-dashboard')?.classList.add('active');
            const dashboardLink = document.querySelector('[data-page="dashboard"]');
            if (dashboardLink) dashboardLink.classList.add('active');
        }

        this.currentPage = page;
        
        if (storePage) {
            localStorage.setItem('currentPage', page);
        }

        // Load page data - delegiere an Module
        switch(page) {
            case 'dashboard':
                // this.loadDashboard();
                break;
            case 'cases':
                // this.loadCases();
                break;
            case 'projects':
                // this.loadProjects();
                break;
            case 'import':
                if (this.import) {
                    this.import.init();
                }
                break;
            case 'orgs':
                if (this.orgSearch) {
                    this.orgSearch.init();
                }
                break;
            case 'accounts':
                // this.loadAccounts();
                break;
            case 'persons':
                if (this.personSearch) {
                    this.personSearch.init();
                }
                break;
            case 'documents':
                if (this.documentSearch) {
                    this.documentSearch.init();
                }
                break;
            case 'import':
                // Import-Seite - noch nicht implementiert
                break;
            case 'admin':
                if (this.admin) {
                    this.admin.load();
                }
                break;
            case 'monitoring':
                if (this.monitoring) {
                    this.monitoring.init();
                }
                break;
        }
    }
    
    // Legacy-Methoden für Kompatibilität (delegieren an Utils)
    showError(message) {
        Utils.showError(message);
    }
    
    showSuccess(message) {
        Utils.showSuccess(message);
    }
    
    escapeHtml(text) {
        return Utils.escapeHtml(text);
    }
    
    closeModal() {
        Utils.closeModal();
    }
    
    // Legacy-Methoden für Kompatibilität (delegieren an Module)
    async showOrgDetail(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.showOrgDetail(orgUuid);
        }
    }
    
    async showCreateOrgModal() {
        if (this.orgForms) {
            return await this.orgForms.showCreateOrgModal();
        }
    }
    
    async loadRecentOrgs() {
        if (this.orgSearch) {
            return await this.orgSearch.loadRecentOrgs();
        }
    }
    
    // Legacy-Methoden für onclick-Handler (werden später durch app.orgDetail.xxx ersetzt)
    toggleOrgEditMode(orgUuid) {
        if (this.orgDetail) {
            return this.orgDetail.toggleOrgEditMode(orgUuid);
        }
    }
    
    cancelOrgEdit(orgUuid) {
        if (this.orgDetail) {
            return this.orgDetail.cancelOrgEdit(orgUuid);
        }
    }
    
    async saveOrgChanges(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.saveOrgChanges(orgUuid);
        }
    }
    
    async archiveOrg(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.archiveOrg(orgUuid);
        }
    }
    
    async unarchiveOrg(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.unarchiveOrg(orgUuid);
        }
    }
    
    async showAddAddressModal(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.showAddAddressModal(orgUuid);
        }
    }
    
    async editAddress(orgUuid, addressUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.editAddress(orgUuid, addressUuid);
        }
    }
    
    async showAddChannelModal(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.showAddChannelModal(orgUuid);
        }
    }
    
    async editChannel(orgUuid, channelUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.editChannel(orgUuid, channelUuid);
        }
    }
    
    async showAddVatRegistrationModal(orgUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.showAddVatRegistrationModal(orgUuid);
        }
    }
    
    async editVatRegistration(orgUuid, vatUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.editVatRegistration(orgUuid, vatUuid);
        }
    }
    
    showAddRelationDialog(parentOrgUuid) {
        if (this.orgDetail) {
            return this.orgDetail.showAddRelationDialog(parentOrgUuid);
        }
    }
    
    async editRelation(parentOrgUuid, relationUuid) {
        if (this.orgDetail) {
            return await this.orgDetail.editRelation(parentOrgUuid, relationUuid);
        }
    }
}

// Initialize app
const app = new TOM3App();
window.app = app;













