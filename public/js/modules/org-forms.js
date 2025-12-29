/**
 * TOM3 - Organization Forms Module
 * Handles organization creation and editing forms
 */

import { Utils } from './utils.js';

export class OrgFormsModule {
    constructor(app) {
        this.app = app;
    }
    
    async showCreateOrgModal() {
        const modal = document.getElementById('modal-create-org');
        if (!modal) {
            console.error('Create org modal not found');
            return;
        }
        
        modal.classList.add('active');
        
        // Formular zurücksetzen
        const form = document.getElementById('form-create-org');
        if (form) {
            form.reset();
        }
        
        // Lade nächste Kundennummer
        await this.loadNextCustomerNumber();
        
        // Lade Hauptbranchen
        await this.loadIndustryMainClasses();
        
        // Setup Branchen-Abhängigkeit
        const industryMainSelect = document.getElementById('org-create-industry-main');
        const industrySubSelect = document.getElementById('org-create-industry-sub');
        
        if (industryMainSelect && industrySubSelect) {
            // Entferne alte Event-Listener
            const newMainSelect = industryMainSelect.cloneNode(true);
            industryMainSelect.parentNode.replaceChild(newMainSelect, industryMainSelect);
            
            newMainSelect.addEventListener('change', async (e) => {
                const parentUuid = e.target.value;
                const subSelect = document.getElementById('org-create-industry-sub');
                if (!subSelect) return;
                
                if (parentUuid) {
                    subSelect.disabled = false;
                    subSelect.innerHTML = '<option value="">Lade Unterklassen...</option>';
                    try {
                        await this.loadIndustrySubClasses(parentUuid);
                    } catch (error) {
                        console.error('Error loading sub classes:', error);
                        subSelect.innerHTML = '<option value="">Fehler beim Laden</option>';
                    }
                } else {
                    subSelect.disabled = true;
                    subSelect.innerHTML = '<option value="">-- Zuerst Hauptklasse wählen --</option>';
                }
            });
        }
        
        // Setup Form-Submit
        this.setupCreateOrgForm();
        
        // Website-URL-Normalisierung
        const websiteInput = document.getElementById('org-create-website');
        if (websiteInput) {
            websiteInput.addEventListener('blur', () => {
                Utils.normalizeUrl(websiteInput);
            });
        }
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
    
    async loadIndustryMainClasses() {
        try {
            const industries = await window.API.getIndustries(null, true);
            const select = document.getElementById('org-create-industry-main');
            if (!select) return;
            
            select.innerHTML = '<option value="">-- Bitte wählen --</option>';
            industries.forEach(industry => {
                const option = document.createElement('option');
                option.value = industry.industry_uuid;
                option.textContent = industry.name;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading industry main classes:', error);
        }
    }
    
    async loadIndustrySubClasses(parentUuid) {
        try {
            const industries = await window.API.getIndustries(parentUuid, false);
            const select = document.getElementById('org-create-industry-sub');
            if (!select) return;
            
            select.innerHTML = '<option value="">-- Bitte wählen --</option>';
            industries.forEach(industry => {
                const option = document.createElement('option');
                option.value = industry.industry_uuid;
                option.textContent = industry.name;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading industry sub classes:', error);
        }
    }
    
    setupCreateOrgForm() {
        const form = document.getElementById('form-create-org');
        if (!form) return;
        
        // Entferne alte Event-Listener
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        
        newForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.submitCreateOrg();
        });
        
        // Cancel-Button
        const cancelBtn = document.getElementById('btn-cancel-create-org');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                Utils.closeModal();
            });
        }
    }
    
    async submitCreateOrg() {
        const form = document.getElementById('form-create-org');
        if (!form) return;
        
        const formData = new FormData(form);
        const data = {};
        
        // Konvertiere FormData zu Objekt
        for (const [key, value] of formData.entries()) {
            if (value) {
                data[key] = value;
            }
        }
        
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


