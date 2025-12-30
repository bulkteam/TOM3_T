/**
 * TOM3 - Main Application
 * Hauptklasse - nur Initialisierung und Koordination der Module
 */

import { AuthModule } from './modules/auth.js';
import { AdminModule } from './modules/admin.js';
import { OrgSearchModule } from './modules/org-search.js';
import { OrgDetailModule } from './modules/org-detail.js';
import { OrgFormsModule } from './modules/org-forms.js';
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
        
        this.init();
    }

    async init() {
        await this.auth.loadCurrentUser();
        this.setupEventListeners();
        this.setupNavigation();
        this.navigateTo(this.currentPage, false);
    }
    
    setupEventListeners() {
        // Modal-Close-Buttons
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                console.log('[App] Globaler Close-Button Handler ausgeführt auf:', btn.closest('.modal')?.id || 'unbekannt');
                console.log('[App] Event-Details:', {
                    target: e.target,
                    currentTarget: e.currentTarget,
                    defaultPrevented: e.defaultPrevented,
                    propagationStopped: e.cancelBubble
                });
                Utils.closeModal();
            });
        });
        
        // Modal-Overlay-Klick schließt Modal
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    console.log('[App] Globaler Overlay-Click Handler ausgeführt auf:', modal.id || 'unbekannt');
                    Utils.closeModal();
                }
            });
        });
    }

    setupNavigation() {
        // Navigation-Links mit Event-Listenern versehen
        document.querySelectorAll('.nav-link').forEach(link => {
            const newLink = link.cloneNode(true);
            link.parentNode.replaceChild(newLink, link);
            
            newLink.addEventListener('click', (e) => {
                e.preventDefault();
                const page = newLink.dataset.page;
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
        // Update navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        const navLink = document.querySelector(`[data-page="${page}"]`);
        if (navLink) {
            navLink.classList.add('active');
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
            case 'orgs':
                if (this.orgSearch) {
                    this.orgSearch.init();
                }
                break;
            case 'accounts':
                // this.loadAccounts();
                break;
            case 'persons':
                // this.loadPersons();
                break;
            case 'admin':
                if (this.admin) {
                    this.admin.load();
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



