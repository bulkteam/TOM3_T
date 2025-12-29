/**
 * TOM3 - Organization VAT Module
 * Handles VAT registration management for organizations
 */

import { Utils } from './utils.js';

export class OrgVatModule {
    constructor(app) {
        this.app = app;
    }
    
    async showAddVatRegistrationModal(orgUuid) {
        const modal = Utils.getOrCreateModal('modal-vat', 'USt-ID hinzufügen');
        const form = Utils.getOrCreateForm('form-vat', () => this.createVatForm(orgUuid), (form) => {
            form.dataset.orgUuid = orgUuid;
            this.setupVatForm(form, orgUuid);
        });
        
        form.reset();
        form.dataset.orgUuid = orgUuid;
        form.dataset.vatUuid = '';
        
        modal.classList.add('active');
    }
    
    async editVatRegistration(orgUuid, vatUuid) {
        try {
            const vatRegs = await window.API.getOrgVatRegistrations(orgUuid, false);
            const vat = vatRegs.find(v => v.vat_registration_uuid === vatUuid);
            
            if (!vat) {
                Utils.showError('USt-ID nicht gefunden');
                return;
            }
            
            const modal = Utils.getOrCreateModal('modal-vat', 'USt-ID bearbeiten');
            const form = Utils.getOrCreateForm('form-vat', () => this.createVatForm(orgUuid), (form) => {
                form.dataset.orgUuid = orgUuid;
                this.setupVatForm(form, orgUuid);
            });
            
            form.dataset.orgUuid = orgUuid;
            form.dataset.vatUuid = vatUuid;
            
            // Fülle Formular
            form.querySelector('#vat-id').value = vat.vat_id || '';
            form.querySelector('#vat-country-code').value = vat.country_code || 'DE';
            form.querySelector('#vat-valid-from').value = vat.valid_from ? new Date(vat.valid_from).toISOString().split('T')[0] : '';
            form.querySelector('#vat-valid-to').value = vat.valid_to ? new Date(vat.valid_to).toISOString().split('T')[0] : '';
            form.querySelector('#vat-is-primary').checked = vat.is_primary_for_country === 1;
            form.querySelector('#vat-location-type').value = vat.location_type || '';
            form.querySelector('#vat-notes').value = vat.notes || '';
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading VAT registration:', error);
            Utils.showError('Fehler beim Laden der USt-ID');
        }
    }
    
    createVatForm(orgUuid) {
        return `
            <form id="form-vat">
                <input type="hidden" id="vat-uuid" name="vat_registration_uuid">
                
                <div class="form-group">
                    <label for="vat-country-code">Land <span class="required">*</span></label>
                    <select id="vat-country-code" name="country_code" required>
                        <option value="DE">Deutschland</option>
                        <option value="AT">Österreich</option>
                        <option value="CH">Schweiz</option>
                        <option value="FR">Frankreich</option>
                        <option value="IT">Italien</option>
                        <option value="NL">Niederlande</option>
                        <option value="BE">Belgien</option>
                        <option value="PL">Polen</option>
                        <option value="CZ">Tschechien</option>
                        <option value="UK">Vereinigtes Königreich</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="vat-id">USt-ID <span class="required">*</span></label>
                    <input type="text" id="vat-id" name="vat_id" required 
                           placeholder="z.B. DE123456789 oder ATU12345678">
                    <small class="form-hint">Format je nach Land unterschiedlich</small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="vat-valid-from">Gültig ab</label>
                        <input type="date" id="vat-valid-from" name="valid_from">
                    </div>
                    <div class="form-group">
                        <label for="vat-valid-to">Gültig bis</label>
                        <input type="date" id="vat-valid-to" name="valid_to">
                        <small class="form-hint">Leer lassen für aktuell gültig</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="vat-location-type">Standorttyp</label>
                    <select id="vat-location-type" name="location_type">
                        <option value="">-- Keine Angabe --</option>
                        <option value="HQ">Hauptsitz</option>
                        <option value="Branch">Niederlassung</option>
                        <option value="Subsidiary">Tochtergesellschaft</option>
                        <option value="SalesOffice">Verkaufsbüro</option>
                        <option value="Plant">Produktionsstätte</option>
                        <option value="Warehouse">Lager</option>
                        <option value="Other">Sonstiges</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="vat-is-primary" name="is_primary_for_country" value="1">
                        Als primäre USt-ID für dieses Land markieren
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="vat-notes">Notizen</label>
                    <textarea id="vat-notes" name="notes" rows="3" placeholder="Zusätzliche Informationen..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="Utils.closeModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        `;
    }
    
    setupVatForm(form, orgUuid) {
        if (!form) return;
        
        const orgDetailModule = this.app.orgDetail;
        
        form.onsubmit = async (e) => {
            e.preventDefault();
            const vatUuid = form.dataset.vatUuid;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            
            data.is_primary_for_country = data.is_primary_for_country === '1' ? 1 : 0;
            
            try {
                if (vatUuid) {
                    await window.API.updateOrgVatRegistration(orgUuid, vatUuid, data);
                    Utils.showSuccess('USt-ID erfolgreich aktualisiert');
                } else {
                    await window.API.addOrgVatRegistration(orgUuid, data);
                    Utils.showSuccess('USt-ID erfolgreich hinzugefügt');
                }
                Utils.closeModal();
                if (orgDetailModule && orgDetailModule.showOrgDetail) {
                    await orgDetailModule.showOrgDetail(orgUuid);
                }
            } catch (error) {
                console.error('Error saving VAT registration:', error);
                Utils.showError('Fehler beim Speichern: ' + (error.message || 'Unbekannter Fehler'));
            }
        };
    }
}

