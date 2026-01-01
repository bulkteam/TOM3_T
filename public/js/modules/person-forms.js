/**
 * TOM3 - Person Forms Module
 * Handles person creation and editing forms
 */

import { Utils } from './utils.js';

export class PersonFormsModule {
    constructor(app) {
        this.app = app;
        this._createSubmitHandler = null;
        this._createCancelHandler = null;
        this._createCloseHandler = null;
        this._editSubmitHandler = null;
        this._editCancelHandler = null;
        this._editCloseHandler = null;
    }
    
    showCreatePersonForm() {
        const modal = Utils.getOrCreateModal('modal-create-person', 'Neue Person');
        const modalBody = document.getElementById('modal-create-person-body');
        
        if (modalBody) {
            modalBody.innerHTML = this.renderCreatePersonForm();
        }
        
        // Setze Close-Button-Handler für dieses Modal (nur Submodal schließen)
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            // Entferne alten Handler, falls vorhanden
            if (this._createCloseHandler) {
                closeBtn.removeEventListener('click', this._createCloseHandler);
            }
            
            // Erstelle neuen Handler
            this._createCloseHandler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                // Schließe nur das Create-Person-Modal, lasse das Hauptmodal offen
                Utils.closeSpecificModal('modal-create-person');
                return false;
            };
            
            closeBtn.addEventListener('click', this._createCloseHandler);
        }
        
        this.setupCreatePersonForm();
        Utils.showModal('modal-create-person');
    }
    
    renderCreatePersonForm() {
        return `
            <form id="form-create-person">
                <div class="form-section">
                    <h3>Persönliche Daten</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="person-create-salutation">Anrede</label>
                            <select id="person-create-salutation" name="salutation">
                                <option value="">-- Bitte wählen --</option>
                                <option value="Herr">Herr</option>
                                <option value="Frau">Frau</option>
                                <option value="Dr.">Dr.</option>
                                <option value="Prof.">Prof.</option>
                                <option value="Prof. Dr.">Prof. Dr.</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="person-create-title">Titel</label>
                            <input type="text" id="person-create-title" name="title" placeholder="z.B. Dr., Prof.">
                        </div>
                        <div class="form-group">
                            <label for="person-create-first-name">Vorname</label>
                            <input type="text" id="person-create-first-name" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label for="person-create-last-name">Nachname <span class="required">*</span></label>
                            <input type="text" id="person-create-last-name" name="last_name" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Kontakt</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="person-create-email">E-Mail</label>
                            <input type="email" id="person-create-email" name="email">
                        </div>
                        <div class="form-group">
                            <label for="person-create-phone">Telefon</label>
                            <input type="text" id="person-create-phone" name="phone" placeholder="mit Durchwahl">
                        </div>
                        <div class="form-group">
                            <label for="person-create-mobile-phone">Mobil</label>
                            <input type="text" id="person-create-mobile-phone" name="mobile_phone">
                        </div>
                        <div class="form-group">
                            <label for="person-create-linkedin">LinkedIn URL</label>
                            <input type="url" id="person-create-linkedin" name="linkedin_url" placeholder="https://linkedin.com/in/...">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Zusätzlich</h3>
                    <div class="form-group">
                        <label for="person-create-notes">Notizen</label>
                        <textarea id="person-create-notes" name="notes" rows="4"></textarea>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="person-create-is-active" name="is_active" value="1" checked>
                            Person ist aktiv
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Person erstellen</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancel-create-person">Abbrechen</button>
                </div>
            </form>
        `;
    }
    
    setupCreatePersonForm() {
        const form = document.getElementById('form-create-person');
        if (!form) return;
        
        // Submit-Handler
        if (this._createSubmitHandler) {
            form.removeEventListener('submit', this._createSubmitHandler);
        }
        this._createSubmitHandler = async (e) => {
            e.preventDefault();
            await this.submitCreatePerson();
        };
        form.addEventListener('submit', this._createSubmitHandler);
        
        // Cancel-Button
        const cancelBtn = document.getElementById('btn-cancel-create-person');
        if (cancelBtn) {
            if (this._createCancelHandler) {
                cancelBtn.removeEventListener('click', this._createCancelHandler);
            }
            this._createCancelHandler = () => Utils.closeSpecificModal('modal-create-person');
            cancelBtn.addEventListener('click', this._createCancelHandler);
        }
    }
    
    async submitCreatePerson() {
        const form = document.getElementById('form-create-person');
        if (!form) return;
        
        const data = Utils.processFormData(form, {
            checkboxFields: ['is_active'],
            nullFields: ['salutation', 'title', 'email', 'phone', 'mobile_phone', 'linkedin_url', 'notes']
        });
        
        // is_active als 1 oder 0 setzen
        data.is_active = data.is_active || 0;
        
        try {
            const person = await window.API.createPerson(data);
            Utils.showSuccess('Person wurde erfolgreich angelegt');
            Utils.closeSpecificModal('modal-create-person');
            
            // Zeige die neue Person an (öffnet neues Modal)
            if (person.person_uuid && window.app.personDetail) {
                await window.app.personDetail.showPersonDetail(person.person_uuid);
            }
        } catch (error) {
            console.error('Error creating person:', error);
            Utils.showError('Fehler beim Anlegen der Person: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    showEditPersonForm(person) {
        const modal = Utils.getOrCreateModal('modal-edit-person', 'Person bearbeiten');
        const modalBody = document.getElementById('modal-edit-person-body');
        
        if (modalBody) {
            modalBody.innerHTML = this.renderEditPersonForm(person);
        }
        
        // Setze Close-Button-Handler für dieses Modal (nur Submodal schließen)
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            // Entferne alten Handler, falls vorhanden
            if (this._editCloseHandler) {
                closeBtn.removeEventListener('click', this._editCloseHandler);
            }
            
            // Erstelle neuen Handler
            this._editCloseHandler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                // Schließe nur das Edit-Person-Modal, lasse das Hauptmodal offen
                Utils.closeSpecificModal('modal-edit-person');
                
                // Prüfe, ob das Person-Detail-Modal noch geöffnet ist
                const personDetailModal = document.getElementById('modal-person-detail');
                if (!personDetailModal || !personDetailModal.classList.contains('active')) {
                    // Falls das Person-Detail-Modal geschlossen wurde, öffne es wieder
                    if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                        this.app.personDetail.showPersonDetail(person.person_uuid);
                    }
                }
                return false;
            };
            
            closeBtn.addEventListener('click', this._editCloseHandler);
        }
        
        this.setupEditPersonForm(person.person_uuid);
        Utils.showModal('modal-edit-person');
    }
    
    renderEditPersonForm(person) {
        return `
            <form id="form-edit-person">
                <div class="form-section">
                    <h3>Persönliche Daten</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="person-edit-salutation">Anrede</label>
                            <select id="person-edit-salutation" name="salutation">
                                <option value="">-- Bitte wählen --</option>
                                <option value="Herr" ${person.salutation === 'Herr' ? 'selected' : ''}>Herr</option>
                                <option value="Frau" ${person.salutation === 'Frau' ? 'selected' : ''}>Frau</option>
                                <option value="Dr." ${person.salutation === 'Dr.' ? 'selected' : ''}>Dr.</option>
                                <option value="Prof." ${person.salutation === 'Prof.' ? 'selected' : ''}>Prof.</option>
                                <option value="Prof. Dr." ${person.salutation === 'Prof. Dr.' ? 'selected' : ''}>Prof. Dr.</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="person-edit-title">Titel</label>
                            <input type="text" id="person-edit-title" name="title" value="${Utils.escapeHtml(person.title || '')}">
                        </div>
                        <div class="form-group">
                            <label for="person-edit-first-name">Vorname</label>
                            <input type="text" id="person-edit-first-name" name="first_name" value="${Utils.escapeHtml(person.first_name || '')}">
                        </div>
                        <div class="form-group">
                            <label for="person-edit-last-name">Nachname <span class="required">*</span></label>
                            <input type="text" id="person-edit-last-name" name="last_name" value="${Utils.escapeHtml(person.last_name || '')}" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Kontakt</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="person-edit-email">E-Mail</label>
                            <input type="email" id="person-edit-email" name="email" value="${Utils.escapeHtml(person.email || '')}">
                        </div>
                        <div class="form-group">
                            <label for="person-edit-phone">Telefon</label>
                            <input type="text" id="person-edit-phone" name="phone" value="${Utils.escapeHtml(person.phone || '')}" placeholder="mit Durchwahl">
                        </div>
                        <div class="form-group">
                            <label for="person-edit-mobile-phone">Mobil</label>
                            <input type="text" id="person-edit-mobile-phone" name="mobile_phone" value="${Utils.escapeHtml(person.mobile_phone || '')}">
                        </div>
                        <div class="form-group">
                            <label for="person-edit-linkedin">LinkedIn URL</label>
                            <input type="url" id="person-edit-linkedin" name="linkedin_url" value="${Utils.escapeHtml(person.linkedin_url || '')}" placeholder="https://linkedin.com/in/...">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Zusätzlich</h3>
                    <div class="form-group">
                        <label for="person-edit-notes">Notizen</label>
                        <textarea id="person-edit-notes" name="notes" rows="4">${Utils.escapeHtml(person.notes || '')}</textarea>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="person-edit-is-active" name="is_active" value="1" ${person.is_active !== 0 ? 'checked' : ''}>
                            Person ist aktiv
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">Änderungen speichern</button>
                    <button type="button" class="btn btn-secondary" id="btn-cancel-edit-person">Abbrechen</button>
                </div>
            </form>
        `;
    }
    
    setupEditPersonForm(personUuid) {
        const form = document.getElementById('form-edit-person');
        if (!form) return;
        
        // Submit-Handler
        if (this._editSubmitHandler) {
            form.removeEventListener('submit', this._editSubmitHandler);
        }
        this._editSubmitHandler = async (e) => {
            e.preventDefault();
            await this.submitEditPerson(personUuid);
        };
        form.addEventListener('submit', this._editSubmitHandler);
        
        // Cancel-Button
        const cancelBtn = document.getElementById('btn-cancel-edit-person');
        if (cancelBtn) {
            if (this._editCancelHandler) {
                cancelBtn.removeEventListener('click', this._editCancelHandler);
            }
            this._editCancelHandler = () => {
                Utils.closeSpecificModal('modal-edit-person');
                // Prüfe, ob das Person-Detail-Modal noch geöffnet ist
                const personDetailModal = document.getElementById('modal-person-detail');
                if (!personDetailModal || !personDetailModal.classList.contains('active')) {
                    // Falls das Person-Detail-Modal geschlossen wurde, öffne es wieder
                    if (this.app.personDetail && this.app.personDetail.showPersonDetail) {
                        this.app.personDetail.showPersonDetail(personUuid);
                    }
                }
            };
            cancelBtn.addEventListener('click', this._editCancelHandler);
        }
    }
    
    async submitEditPerson(personUuid) {
        const form = document.getElementById('form-edit-person');
        if (!form) return;
        
        const data = Utils.processFormData(form, {
            checkboxFields: ['is_active'],
            nullFields: ['salutation', 'title', 'email', 'phone', 'mobile_phone', 'linkedin_url', 'notes']
        });
        
        // is_active als 1 oder 0 setzen
        data.is_active = data.is_active || 0;
        
        try {
            const person = await window.API.updatePerson(personUuid, data);
            Utils.showSuccess('Person wurde erfolgreich aktualisiert');
            Utils.closeSpecificModal('modal-edit-person');
            
            // Aktualisiere die Person-Detail-Ansicht (öffnet/aktualisiert das Hauptmodal)
            if (window.app.personDetail) {
                await window.app.personDetail.showPersonDetail(personUuid);
            }
        } catch (error) {
            console.error('Error updating person:', error);
            Utils.showError('Fehler beim Aktualisieren der Person: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}
