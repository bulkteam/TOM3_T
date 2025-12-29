/**
 * TOM3 - Organization Address Module
 * Handles address management for organizations
 */

import { Utils } from './utils.js';

export class OrgAddressModule {
    constructor(app) {
        this.app = app;
    }
    
    async showAddAddressModal(orgUuid) {
        const modal = Utils.getOrCreateModal('modal-address', 'Adresse hinzufügen');
        const form = Utils.getOrCreateForm('form-address', () => this.createAddressForm(orgUuid), (form) => {
            form.dataset.orgUuid = orgUuid;
            this.setupAddressForm(form, orgUuid);
        });
        
        form.reset();
        form.dataset.orgUuid = orgUuid;
        form.dataset.addressUuid = '';
        
        modal.classList.add('active');
    }
    
    async editAddress(orgUuid, addressUuid) {
        try {
            const addresses = await window.API.getOrgAddresses(orgUuid);
            const address = addresses.find(a => a.address_uuid === addressUuid);
            
            if (!address) {
                Utils.showError('Adresse nicht gefunden');
                return;
            }
            
            const modal = Utils.getOrCreateModal('modal-address', 'Adresse bearbeiten');
            const form = Utils.getOrCreateForm('form-address', () => this.createAddressForm(orgUuid), (form) => {
                form.dataset.orgUuid = orgUuid;
                this.setupAddressForm(form, orgUuid);
            });
            
            form.dataset.orgUuid = orgUuid;
            form.dataset.addressUuid = addressUuid;
            
            // Fülle Formular
            form.querySelector('#address-type').value = address.address_type || 'other';
            form.querySelector('#address-street').value = address.street || '';
            form.querySelector('#address-additional').value = address.address_additional || '';
            form.querySelector('#address-city').value = address.city || '';
            form.querySelector('#address-postal-code').value = address.postal_code || '';
            form.querySelector('#address-country').value = address.country || 'DE';
            form.querySelector('#address-state').value = address.state || '';
            form.querySelector('#address-is-default').checked = address.is_default === 1;
            form.querySelector('#address-notes').value = address.notes || '';
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading address:', error);
            Utils.showError('Fehler beim Laden der Adresse');
        }
    }
    
    createAddressForm(orgUuid) {
        return `
            <form id="form-address">
                <input type="hidden" id="address-uuid" name="address_uuid">
                
                <div class="form-group">
                    <label for="address-type">Adresstyp <span class="required">*</span></label>
                    <select id="address-type" name="address_type" required>
                        <option value="headquarters">Hauptsitz</option>
                        <option value="delivery">Lieferadresse</option>
                        <option value="billing">Rechnungsadresse</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="address-street">Straße</label>
                    <input type="text" id="address-street" name="street" placeholder="Musterstraße">
                </div>
                
                <div class="form-group">
                    <label for="address-additional">Adresszusatz</label>
                    <input type="text" id="address-additional" name="address_additional" placeholder="z.B. Gebäude A, Etage 3">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="address-postal-code">PLZ</label>
                        <input type="text" id="address-postal-code" name="postal_code" placeholder="12345" maxlength="10">
                    </div>
                    <div class="form-group">
                        <label for="address-city">Stadt <span class="required">*</span></label>
                        <input type="text" id="address-city" name="city" required placeholder="Musterstadt">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="address-country">Land</label>
                        <select id="address-country" name="country">
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
                        <label for="address-state">Bundesland / Region</label>
                        <input type="text" id="address-state" name="state" placeholder="z.B. Bayern">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="address-is-default" name="is_default" value="1">
                        Als Standardadresse markieren
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="address-notes">Notizen</label>
                    <textarea id="address-notes" name="notes" rows="3" placeholder="Zusätzliche Informationen..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="Utils.closeModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        `;
    }
    
    setupAddressForm(form, orgUuid) {
        if (!form) return;
        
        const orgDetailModule = this.app.orgDetail;
        
        form.onsubmit = async (e) => {
            e.preventDefault();
            const addressUuid = form.dataset.addressUuid;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            
            data.is_default = data.is_default === '1' ? 1 : 0;
            
            try {
                if (addressUuid) {
                    await window.API.updateOrgAddress(orgUuid, addressUuid, data);
                    Utils.showSuccess('Adresse erfolgreich aktualisiert');
                } else {
                    await window.API.addOrgAddress(orgUuid, data);
                    Utils.showSuccess('Adresse erfolgreich hinzugefügt');
                }
                Utils.closeModal();
                if (orgDetailModule && orgDetailModule.showOrgDetail) {
                    await orgDetailModule.showOrgDetail(orgUuid);
                }
            } catch (error) {
                console.error('Error saving address:', error);
                Utils.showError('Fehler beim Speichern: ' + (error.message || 'Unbekannter Fehler'));
            }
        };
    }
}

