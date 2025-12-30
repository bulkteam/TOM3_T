/**
 * TOM3 - Organization VAT Module
 * Handles VAT registration management for organizations
 */

import { Utils } from './utils.js';

export class OrgVatModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen für sauberes Event-Listener-Management (ohne cloneNode)
        this._vatCloseHandlers = new Map(); // modalId -> handler
        this._vatCancelHandlers = new Map(); // formId -> handler
        this._vatOverlayHandlers = new Map(); // modalId -> handler
    }
    
    async showAddVatRegistrationModal(orgUuid) {
        const modal = Utils.getOrCreateModal('modal-vat', 'USt-ID hinzufügen');
        const form = Utils.getOrCreateForm('form-vat', () => this.createVatForm(orgUuid), (form) => {
            form.dataset.orgUuid = orgUuid;
            this.setupVatForm(form, orgUuid);
        });
        
        // Stelle sicher, dass setupVatForm aufgerufen wird (auch wenn Formular bereits existiert)
        if (form) {
            form.reset();
            form.dataset.orgUuid = orgUuid;
            form.dataset.vatUuid = '';
            // Setze setupVatForm erneut auf, um Event-Listener zu aktualisieren
            this.setupVatForm(form, orgUuid);
        }
        
        // Setze Close-Button-Handler für dieses Modal
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            const oldHandler = this._vatCloseHandlers.get('modal-vat');
            if (oldHandler) {
                closeBtn.removeEventListener('click', oldHandler);
            }
            
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-vat');
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(orgUuid);
                    }
                }
                return false;
            };
            
            this._vatCloseHandlers.set('modal-vat', handler);
            closeBtn.addEventListener('click', handler);
        }
        
        // Setze Overlay-Click-Handler
        const oldOverlayHandler = this._vatOverlayHandlers.get('modal-vat');
        if (oldOverlayHandler) {
            modal.removeEventListener('click', oldOverlayHandler);
        }
        
        const overlayHandler = (e) => {
            if (e.target === modal) {
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-vat');
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(orgUuid);
                    }
                }
                return false;
            }
        };
        
        this._vatOverlayHandlers.set('modal-vat', overlayHandler);
        modal.addEventListener('click', overlayHandler);
        
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
            
            // Setze Close-Button-Handler für dieses Modal
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) {
                const oldHandler = this._vatCloseHandlers.get('modal-vat');
                if (oldHandler) {
                    closeBtn.removeEventListener('click', oldHandler);
                }
                
                const handler = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-vat');
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(orgUuid);
                        }
                    }
                    return false;
                };
                
                this._vatCloseHandlers.set('modal-vat', handler);
                closeBtn.addEventListener('click', handler);
            }
            
            // Setze Overlay-Click-Handler
            const oldOverlayHandler = this._vatOverlayHandlers.get('modal-vat');
            if (oldOverlayHandler) {
                modal.removeEventListener('click', oldOverlayHandler);
            }
            
            const overlayHandler = (e) => {
                if (e.target === modal) {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-vat');
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(orgUuid);
                        }
                    }
                    return false;
                }
            };
            
            this._vatOverlayHandlers.set('modal-vat', overlayHandler);
            modal.addEventListener('click', overlayHandler);
            
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
                        <label for="vat-valid-from">Gültig ab (optional)</label>
                        <input type="date" id="vat-valid-from" name="valid_from">
                        <small class="form-hint">Standard: Heute</small>
                    </div>
                    <div class="form-group">
                        <label for="vat-valid-to">Gültig bis (optional)</label>
                        <input type="date" id="vat-valid-to" name="valid_to">
                        <small class="form-hint">Leer lassen für aktuell gültig</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="vat-location-type">Standorttyp (optional)</label>
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
                    <small class="form-hint">Nur für Kontext bei mehreren USt-IDs (z.B. Betriebsstätte)</small>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-row" for="vat-is-primary">
                        <input type="checkbox" id="vat-is-primary" name="is_primary_for_country" value="1">
                        <span class="checkbox-text">Als primäre USt-ID für dieses Land markieren</span>
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="vat-notes">Notizen</label>
                    <textarea id="vat-notes" name="notes" rows="3" placeholder="Zusätzliche Informationen..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="vat-form-cancel">Abbrechen</button>
                    <button type="submit" class="btn btn-success">Speichern</button>
                </div>
            </form>
        `;
    }
    
    setupVatForm(form, orgUuid) {
        if (!form) return;
        
        const orgDetailModule = this.app.orgDetail;
        
        // Abbrechen-Button Handler
        // Suche nach Button mit ID oder nach Button mit Text "Abbrechen" im form-actions Bereich
        let cancelBtn = form.querySelector('#vat-form-cancel');
        if (!cancelBtn) {
            // Fallback: Suche nach Button mit Text "Abbrechen" in form-actions
            const formActions = form.querySelector('.form-actions');
            if (formActions) {
                const buttons = formActions.querySelectorAll('button.btn-secondary');
                for (const btn of buttons) {
                    if (btn.textContent.trim() === 'Abbrechen') {
                        cancelBtn = btn;
                        break;
                    }
                }
            }
        }
        
        if (cancelBtn) {
            console.log('[VAT Modal] Abbrechen-Button gefunden, setze Handler', cancelBtn);
            // Entferne alten Handler, falls vorhanden
            const formId = form.id || 'form-vat';
            const oldHandler = this._vatCancelHandlers.get(formId);
            if (oldHandler) {
                cancelBtn.removeEventListener('click', oldHandler);
            }
            
            // Erstelle neuen Handler
            const handler = (e) => {
                console.log('[VAT Modal] Abbrechen-Button geklickt');
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                Utils.closeSpecificModal('modal-vat');
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    if (orgDetailModule && orgDetailModule.showOrgDetail) {
                        orgDetailModule.showOrgDetail(orgUuid);
                    }
                }
                return false;
            };
            
            // Entferne onclick Attribut falls vorhanden
            cancelBtn.removeAttribute('onclick');
            
            // Speichere Handler-Referenz und füge Listener hinzu
            this._vatCancelHandlers.set(formId, handler);
            cancelBtn.addEventListener('click', handler);
        } else {
            console.warn('[VAT Modal] Abbrechen-Button NICHT gefunden!');
        }
        
        form.onsubmit = async (e) => {
            e.preventDefault();
            const vatUuid = form.dataset.vatUuid;
            const data = Utils.processFormData(form, {
                checkboxFields: ['is_primary_for_country'],
                nullFields: ['valid_from', 'valid_to', 'location_type', 'notes']
            });
            
            console.log('[VAT Form] Submitting data:', data);
            console.log('[VAT Form] VAT UUID:', vatUuid, 'Is new:', !vatUuid || vatUuid === '');
            
            try {
                if (vatUuid && vatUuid !== '') {
                    console.log('[VAT Form] Updating existing VAT registration');
                    await window.API.updateOrgVatRegistration(orgUuid, vatUuid, data);
                    Utils.showSuccess('USt-ID erfolgreich aktualisiert');
                } else {
                    console.log('[VAT Form] Creating new VAT registration');
                    const result = await window.API.addOrgVatRegistration(orgUuid, data);
                    console.log('[VAT Form] VAT registration created:', result);
                    Utils.showSuccess('USt-ID erfolgreich hinzugefügt');
                }
                Utils.closeSpecificModal('modal-vat');
                if (orgDetailModule && orgDetailModule.showOrgDetail) {
                    await orgDetailModule.showOrgDetail(orgUuid);
                }
            } catch (error) {
                console.error('Error saving VAT registration:', error);
                let errorMessage = 'Fehler beim Speichern';
                if (error.message) {
                    errorMessage += ': ' + error.message;
                } else if (error.error) {
                    errorMessage += ': ' + error.error;
                } else if (typeof error === 'string') {
                    errorMessage += ': ' + error;
                }
                Utils.showError(errorMessage);
            }
        };
    }
}


