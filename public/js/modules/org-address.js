/**
 * TOM3 - Organization Address Module
 * Handles address management for organizations
 */

import { Utils } from './utils.js';

export class OrgAddressModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen für sauberes Event-Listener-Management (ohne cloneNode)
        this._addressCloseHandlers = new Map(); // modalId -> handler
        this._addressCancelHandlers = new Map(); // formId -> handler
        this._addressOverlayHandlers = new Map(); // modalId -> handler
    }
    
    async showAddAddressModal(orgUuid) {
        const modal = Utils.getOrCreateModal('modal-address', 'Adresse hinzufügen');
        const form = Utils.getOrCreateForm('form-address', () => this.createAddressForm(orgUuid), (form) => {
            form.dataset.orgUuid = orgUuid;
            this.setupAddressForm(form, orgUuid);
        });
        
        // Stelle sicher, dass setupAddressForm aufgerufen wird (auch wenn Formular bereits existiert)
        if (form) {
            form.reset();
            form.dataset.orgUuid = orgUuid;
            form.dataset.addressUuid = '';
            
            // Stelle sicher, dass Land auf 'DE' gesetzt ist
            const countrySelect = form.querySelector('#address-country');
            if (countrySelect) {
                countrySelect.value = 'DE';
            }
            
            // Entferne Flag, damit setupAddressForm die Listener erneut setzt
            const postalCodeInput = form.querySelector('#address-postal-code');
            if (postalCodeInput) {
                delete postalCodeInput.dataset.listenerAttached;
            }
            const cityInput = form.querySelector('#address-city');
            if (cityInput) {
                delete cityInput.dataset.listenerAttached;
            }
            const stateInput = form.querySelector('#address-state');
            if (stateInput) {
                delete stateInput.dataset.listenerAttached;
            }
            
            this.setupAddressForm(form, orgUuid);
        }
        
        // Setze Close-Button-Handler für dieses Modal
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            // Entferne alten Handler, falls vorhanden
            const oldHandler = this._addressCloseHandlers.get('modal-address');
            if (oldHandler) {
                closeBtn.removeEventListener('click', oldHandler);
            }
            
            // Erstelle neuen Handler
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                // Schließe nur das Adress-Modal, lasse das Stammdaten-Modal offen
                Utils.closeSpecificModal('modal-address');
                // Prüfe, ob das Stammdaten-Modal noch geöffnet ist
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    // Falls das Stammdaten-Modal geschlossen wurde, öffne es wieder
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(orgUuid);
                    }
                }
                return false;
            };
            
            // Speichere Handler-Referenz und füge Listener hinzu
            this._addressCloseHandlers.set('modal-address', handler);
            closeBtn.addEventListener('click', handler);
        }
        
        // Setze Overlay-Click-Handler für dieses Modal
        const oldOverlayHandler = this._addressOverlayHandlers.get('modal-address');
        if (oldOverlayHandler) {
            modal.removeEventListener('click', oldOverlayHandler, true);
        }
        
        const overlayHandler = (e) => {
            if (e.target === modal) {
                e.stopPropagation();
                e.stopImmediatePropagation();
                // Schließe nur das Adress-Modal, lasse das Stammdaten-Modal offen
                Utils.closeSpecificModal('modal-address');
                // Prüfe, ob das Stammdaten-Modal noch geöffnet ist
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    // Falls das Stammdaten-Modal geschlossen wurde, öffne es wieder
                    if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                        this.app.orgDetail.showOrgDetail(orgUuid);
                    }
                }
                return false;
            }
        };
        
        this._addressOverlayHandlers.set('modal-address', overlayHandler);
        modal.addEventListener('click', overlayHandler, true);
        
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
            
            // Stelle sicher, dass setupAddressForm aufgerufen wird (auch wenn Formular bereits existiert)
            if (form) {
                form.dataset.orgUuid = orgUuid;
                this.setupAddressForm(form, orgUuid);
            }
            
            form.dataset.orgUuid = orgUuid;
            form.dataset.addressUuid = addressUuid;
            
            // Fülle Formular
            form.querySelector('#address-type').value = address.address_type || 'other';
            form.querySelector('#address-street').value = address.street || '';
            form.querySelector('#address-additional').value = address.address_additional || '';
            form.querySelector('#address-city').value = address.city || '';
            form.querySelector('#address-postal-code').value = address.postal_code || '';
            const countrySelect = form.querySelector('#address-country');
            countrySelect.value = address.country || 'DE';
            form.querySelector('#address-state').value = address.state || '';
            form.querySelector('#address-is-default').checked = address.is_default === 1;
            form.querySelector('#address-notes').value = address.notes || '';
            
            // Stelle sicher, dass Event-Listener gesetzt werden (auch beim zweiten/third Aufruf)
            // Entferne Flag, damit setupAddressForm die Listener erneut setzt
            const postalCodeInput = form.querySelector('#address-postal-code');
            if (postalCodeInput) {
                delete postalCodeInput.dataset.listenerAttached;
            }
            const cityInput = form.querySelector('#address-city');
            if (cityInput) {
                delete cityInput.dataset.listenerAttached;
            }
            const stateInput = form.querySelector('#address-state');
            if (stateInput) {
                delete stateInput.dataset.listenerAttached;
            }
            
            // Setze setupAddressForm erneut auf, um Event-Listener zu aktualisieren
            this.setupAddressForm(form, orgUuid);
            
            // Setze Close-Button-Handler für dieses Modal
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) {
                // Entferne alten Handler, falls vorhanden
                const oldHandler = this._addressCloseHandlers.get('modal-address');
                if (oldHandler) {
                    closeBtn.removeEventListener('click', oldHandler);
                }
                
                // Erstelle neuen Handler
                const handler = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    
                    // Schließe nur das Adress-Modal, lasse das Stammdaten-Modal offen
                    Utils.closeSpecificModal('modal-address');
                    
                    // Prüfe, ob das Stammdaten-Modal noch geöffnet ist
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        // Falls das Stammdaten-Modal geschlossen wurde, öffne es wieder
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(orgUuid);
                        }
                    }
                    return false;
                };
                
                // Speichere Handler-Referenz und füge Listener hinzu
                this._addressCloseHandlers.set('modal-address', handler);
                closeBtn.addEventListener('click', handler);
            }
            
            // Setze Overlay-Click-Handler für dieses Modal
            const oldOverlayHandler = this._addressOverlayHandlers.get('modal-address');
            if (oldOverlayHandler) {
                modal.removeEventListener('click', oldOverlayHandler, true);
            }
            
            const overlayHandler = (e) => {
                if (e.target === modal) {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    // Schließe nur das Adress-Modal, lasse das Stammdaten-Modal offen
                    Utils.closeSpecificModal('modal-address');
                    // Prüfe, ob das Stammdaten-Modal noch geöffnet ist
                    const orgDetailModal = document.getElementById('modal-org-detail');
                    if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                        // Falls das Stammdaten-Modal geschlossen wurde, öffne es wieder
                        if (this.app.orgDetail && this.app.orgDetail.showOrgDetail) {
                            this.app.orgDetail.showOrgDetail(orgUuid);
                        }
                    }
                    return false;
                }
            };
            
            this._addressOverlayHandlers.set('modal-address', overlayHandler);
            modal.addEventListener('click', overlayHandler, true);
            
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
                            <option value="DE" selected>Deutschland</option>
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
                    <label class="checkbox-row" for="address-is-default">
                        <input type="checkbox" id="address-is-default" name="is_default" value="1">
                        <span class="checkbox-text">Als Standardadresse markieren</span>
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="address-notes">Notizen</label>
                    <textarea id="address-notes" name="notes" rows="3" placeholder="Zusätzliche Informationen..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="address-form-cancel">Abbrechen</button>
                    <button type="submit" class="btn btn-success">Speichern</button>
                </div>
            </form>
        `;
    }
    
    setupAddressForm(form, orgUuid) {
        if (!form) return;
        
        const orgDetailModule = this.app.orgDetail;
        
        // PLZ-Lookup: Automatisches Ausfüllen von Stadt und Bundesland
        const postalCodeInput = form.querySelector('#address-postal-code');
        const cityInput = form.querySelector('#address-city');
        const stateInput = form.querySelector('#address-state');
        const countrySelect = form.querySelector('#address-country');
        
        // Stelle sicher, dass Land auf 'DE' gesetzt ist, wenn es leer ist
        if (countrySelect && !countrySelect.value) {
            countrySelect.value = 'DE';
        }
        
        if (postalCodeInput) {
            // Prüfe, ob Event-Listener bereits gesetzt wurden (verhindert Duplikate)
            if (postalCodeInput.dataset.listenerAttached === 'true') {
                // Event-Listener wurden bereits gesetzt, überspringe
                return;
            }
            
            // Markiere, dass Event-Listener gesetzt wurden
            postalCodeInput.dataset.listenerAttached = 'true';
            if (cityInput) cityInput.dataset.listenerAttached = 'true';
            if (stateInput) stateInput.dataset.listenerAttached = 'true';
            
            // Verwende die originalen Referenzen
            const actualPostalCodeInput = postalCodeInput;
            const actualCityInput = cityInput;
            const actualStateInput = stateInput;
            const actualCountrySelect = countrySelect;
            
            let lookupTimeout = null;
            
            // Funktion zum Ausführen des PLZ-Lookups
            const performPlzLookup = async (plz) => {
                if (!plz || plz.length !== 5 || !/^\d{5}$/.test(plz) || actualCountrySelect.value !== 'DE') {
                    return;
                }
                
                try {
                    const result = await window.API.lookupPlz(plz);
                    if (result && result.city && result.bundesland) {
                        // Prüfe, ob der aktuelle Wert "Unbekannt" ist
                        const currentCityValue = (actualCityInput.value || '').trim();
                        const currentStateValue = (actualStateInput.value || '').trim();
                        const isCityUnknown = currentCityValue.toLowerCase() === 'unbekannt';
                        const isStateUnknown = currentStateValue.toLowerCase() === 'unbekannt';
                        
                        // Ersetze immer, wenn:
                        // 1. Feld ist leer
                        // 2. Feld wurde automatisch ausgefüllt (autoFilled === 'true')
                        // 3. Feld enthält "Unbekannt" (auch wenn manuell eingegeben)
                        const shouldReplaceCity = !currentCityValue || 
                                                 actualCityInput.dataset.autoFilled === 'true' || 
                                                 isCityUnknown;
                        const shouldReplaceState = !currentStateValue || 
                                                  actualStateInput.dataset.autoFilled === 'true' || 
                                                  isStateUnknown;
                        
                        if (shouldReplaceCity) {
                            actualCityInput.value = result.city;
                            actualCityInput.dataset.autoFilled = 'true';
                        }
                        if (shouldReplaceState) {
                            actualStateInput.value = result.bundesland;
                            actualStateInput.dataset.autoFilled = 'true';
                        }
                    }
                } catch (error) {
                    // Fehler beim Lookup ignorieren (z.B. PLZ nicht gefunden)
                }
            };
            
            // Bei Eingabe: Debounce für bessere Performance
            actualPostalCodeInput.addEventListener('input', async (e) => {
                const plz = e.target.value.trim();
                
                // Nur bei deutschen PLZ (5 Ziffern) und wenn Land DE ist
                if (plz.length === 5 && /^\d{5}$/.test(plz) && actualCountrySelect.value === 'DE') {
                    // Debounce: Warte 500ms nach der letzten Eingabe
                    clearTimeout(lookupTimeout);
                    lookupTimeout = setTimeout(() => performPlzLookup(plz), 500);
                } else {
                    // Wenn PLZ geändert wird, markiere Felder als nicht mehr automatisch ausgefüllt
                    if (actualCityInput && actualCityInput.dataset.autoFilled === 'true' && actualCityInput.value) {
                        actualCityInput.dataset.autoFilled = 'false';
                    }
                    if (actualStateInput && actualStateInput.dataset.autoFilled === 'true' && actualStateInput.value) {
                        actualStateInput.dataset.autoFilled = 'false';
                    }
                }
            });
            
            // Beim Verlassen des Feldes: Sofortiger Lookup (wichtig für Formular-Validierung)
            actualPostalCodeInput.addEventListener('blur', async (e) => {
                const plz = e.target.value.trim();
                // Stoppe laufenden Timeout
                clearTimeout(lookupTimeout);
                // Führe Lookup sofort aus
                await performPlzLookup(plz);
            });
            
            // Wenn Benutzer manuell Stadt oder Bundesland ändert, markiere als nicht automatisch
            if (actualCityInput) {
                actualCityInput.addEventListener('input', () => {
                    actualCityInput.dataset.autoFilled = 'false';
                });
            }
            if (actualStateInput) {
                actualStateInput.addEventListener('input', () => {
                    actualStateInput.dataset.autoFilled = 'false';
                });
            }
        }
        
        // Abbrechen-Button Handler
        // Suche nach Button mit ID oder nach Button mit Text "Abbrechen" im form-actions Bereich
        let cancelBtn = form.querySelector('#address-form-cancel');
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
            // Entferne alten Handler, falls vorhanden
            const formId = form.id || 'form-address';
            const oldHandler = this._addressCancelHandlers.get(formId);
            if (oldHandler) {
                cancelBtn.removeEventListener('click', oldHandler);
            }
            
            // Erstelle neuen Handler
            const handler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                
                // Schließe nur das Adress-Modal, lasse das Stammdaten-Modal offen
                Utils.closeSpecificModal('modal-address');
                
                // Prüfe, ob das Stammdaten-Modal noch geöffnet ist
                const orgDetailModal = document.getElementById('modal-org-detail');
                if (!orgDetailModal || !orgDetailModal.classList.contains('active')) {
                    // Falls das Stammdaten-Modal geschlossen wurde, öffne es wieder
                    if (orgDetailModule && orgDetailModule.showOrgDetail) {
                        orgDetailModule.showOrgDetail(orgUuid);
                    }
                }
                return false;
            };
            
            // Entferne onclick Attribut falls vorhanden
            cancelBtn.removeAttribute('onclick');
            
            // Speichere Handler-Referenz und füge Listener hinzu
            this._addressCancelHandlers.set(formId, handler);
            cancelBtn.addEventListener('click', handler);
        }
        
        form.onsubmit = async (e) => {
            e.preventDefault();
            const addressUuid = form.dataset.addressUuid;
            const data = Utils.processFormData(form, {
                checkboxFields: ['is_default']
            });
            
            try {
                if (addressUuid) {
                    await window.API.updateOrgAddress(orgUuid, addressUuid, data);
                    Utils.showSuccess('Adresse erfolgreich aktualisiert');
                } else {
                    await window.API.addOrgAddress(orgUuid, data);
                    Utils.showSuccess('Adresse erfolgreich hinzugefügt');
                }
                Utils.closeSpecificModal('modal-address');
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


