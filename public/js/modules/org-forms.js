/**
 * TOM3 - Organization Forms Module
 * Handles organization creation and editing forms
 */

import { Utils } from './utils.js';

export class OrgFormsModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen, damit wir Listener sauber entfernen können (ohne cloneNode)
        this._createSubmitHandler = null;
        this._createCancelHandler = null;
        this._createWebsiteBlurHandler = null;
    }
    
    async showCreateOrgModal() {
        // Log sofort am Anfang - vor allem anderen Code
        console.log('========================================');
        console.log('[OrgForms] ===== showCreateOrgModal called =====');
        console.log('[OrgForms] This:', this);
        console.log('[OrgForms] window.app:', window.app);
        console.log('[OrgForms] window.app.orgForms:', window.app?.orgForms);
        console.log('========================================');
        
        const modal = document.getElementById('modal-create-org');
        if (!modal) {
            console.error('[OrgForms] Create org modal not found');
            return;
        }
        
        console.log('[OrgForms] Modal found, activating...');
        modal.classList.add('active');
        
        // Lade nächste Kundennummer
        console.log('[OrgForms] Loading next customer number...');
        await this.loadNextCustomerNumber();
        
        // Formular zurücksetzen
        const form = document.getElementById('form-create-org');
        if (form) {
            console.log('[OrgForms] Resetting form...');
            form.reset();
        }
        
        // Setup Form-Submit (ohne cloneNode, damit Event-Listener erhalten bleiben)
        this.setupCreateOrgForm();
        
        // Setup Branchen-Auswahl (NACH setupCreateOrgForm, damit keine Listener verloren gehen)
        const mainSelect = document.getElementById('org-create-industry-main');
        const subSelect = document.getElementById('org-create-industry-sub');
        
        if (mainSelect && subSelect) {
            // Wie im Edit-Modus: Abhängigkeit setzen und Hauptbranchen laden
            console.log('[OrgForms] Setting up industry dependency (like edit mode)...');
            Utils.setupIndustryDependency(mainSelect, subSelect, false); // false = kein Klonen (wie im Edit-Modus)
            await Utils.loadIndustryMainClasses(mainSelect);
            console.log('[OrgForms] Industry setup completed');
        } else {
            console.error('[OrgForms] Industry selects not found!', { mainSelect, subSelect });
        }
        
        // Website-URL-Normalisierung
        const websiteInput = document.getElementById('org-create-website');
        if (websiteInput) {
            // Entferne alten Handler, falls vorhanden
            if (this._createWebsiteBlurHandler) {
                websiteInput.removeEventListener('blur', this._createWebsiteBlurHandler);
            }
            // Setze neuen Handler
            this._createWebsiteBlurHandler = () => Utils.normalizeUrl(websiteInput);
            websiteInput.addEventListener('blur', this._createWebsiteBlurHandler);
        }
        
        console.log('[OrgForms] ===== showCreateOrgModal completed =====');
    }
    
    async loadNextCustomerNumber() {
        try {
            const data = await window.API.getNextCustomerNumber();
            const customerNumberInput = document.getElementById('org-create-external-ref');
            if (customerNumberInput && data.next_customer_number) {
                customerNumberInput.value = data.next_customer_number;
            }
        } catch (error) {
            console.error('Error loading next customer number:', error);
        }
    }
    
    
    setupCreateOrgForm() {
        const form = document.getElementById('form-create-org');
        if (!form) return;
        
        // Submit-Handler sauber ersetzen (ohne cloneNode)
        if (this._createSubmitHandler) {
            form.removeEventListener('submit', this._createSubmitHandler);
        }
        this._createSubmitHandler = async (e) => {
            e.preventDefault();
            await this.submitCreateOrg();
        };
        form.addEventListener('submit', this._createSubmitHandler);
        
        // Cancel-Button
        const cancelBtn = document.getElementById('btn-cancel-create-org');
        if (cancelBtn) {
            // Entferne alten Handler, falls vorhanden
            if (this._createCancelHandler) {
                cancelBtn.removeEventListener('click', this._createCancelHandler);
            }
            // Setze neuen Handler
            this._createCancelHandler = () => Utils.closeModal();
            cancelBtn.addEventListener('click', this._createCancelHandler);
        }
    }
    
    async submitCreateOrg() {
        const form = document.getElementById('form-create-org');
        if (!form) return;
        
        const data = Utils.formDataToObject(form, {
            filterEmpty: true
        });
        
        // Debug: Prüfe Branchen-Felder
        console.log('[OrgForms] Form data before submit:', data);
        console.log('[OrgForms] Industry fields:', {
            industry_main_uuid: data.industry_main_uuid,
            industry_sub_uuid: data.industry_sub_uuid,
            mainSelect: document.getElementById('org-create-industry-main')?.value,
            subSelect: document.getElementById('org-create-industry-sub')?.value
        });
        
        // Normalisiere Website-URL
        if (data.website) {
            const websiteInput = document.getElementById('org-create-website');
            if (websiteInput) {
                Utils.normalizeUrl(websiteInput);
                data.website = websiteInput.value;
            }
        }
        
        try {
            const org = await window.API.createOrg(data);
            Utils.showSuccess('Organisation wurde erfolgreich angelegt');
            Utils.closeModal();
            
            // Optional: Zeige die neue Organisation an
            if (org.org_uuid && window.app.orgDetail) {
                await window.app.orgDetail.showOrgDetail(org.org_uuid);
            }
        } catch (error) {
            console.error('Error creating org:', error);
            Utils.showError('Fehler beim Anlegen der Organisation: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}



