/**
 * TOM3 - Organization Channel Module
 * Handles communication channel management for organizations
 */

import { Utils } from './utils.js';

export class OrgChannelModule {
    constructor(app) {
        this.app = app;
    }
    
    async showAddChannelModal(orgUuid) {
        const modal = Utils.getOrCreateModal('modal-channel', 'Kommunikationskanal hinzufügen');
        const form = Utils.getOrCreateForm('form-channel', () => this.createChannelForm(orgUuid), (form) => {
            form.dataset.orgUuid = orgUuid;
            this.setupChannelForm(form, orgUuid);
        });
        
        form.reset();
        form.dataset.orgUuid = orgUuid;
        form.dataset.channelUuid = '';
        
        modal.classList.add('active');
    }
    
    async editChannel(orgUuid, channelUuid) {
        try {
            const channels = await window.API.getOrgChannels(orgUuid);
            const channel = channels.find(c => c.channel_uuid === channelUuid);
            
            if (!channel) {
                Utils.showError('Kanal nicht gefunden');
                return;
            }
            
            const modal = Utils.getOrCreateModal('modal-channel', 'Kommunikationskanal bearbeiten');
            const form = Utils.getOrCreateForm('form-channel', () => this.createChannelForm(orgUuid), (form) => {
                form.dataset.orgUuid = orgUuid;
                this.setupChannelForm(form, orgUuid);
            });
            
            form.dataset.orgUuid = orgUuid;
            form.dataset.channelUuid = channelUuid;
            
            // Fülle Formular
            form.querySelector('#channel-type').value = channel.channel_type || 'other';
            form.querySelector('#channel-value').value = channel.value || channel.email_address || channel.number || '';
            form.querySelector('#channel-label').value = channel.label || '';
            form.querySelector('#channel-is-primary').checked = channel.is_primary === 1;
            form.querySelector('#channel-is-public').checked = channel.is_public === 1;
            form.querySelector('#channel-notes').value = channel.notes || '';
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading channel:', error);
            Utils.showError('Fehler beim Laden des Kanals');
        }
    }
    
    createChannelForm(orgUuid) {
        return `
            <form id="form-channel">
                <input type="hidden" id="channel-uuid" name="channel_uuid">
                
                <div class="form-group">
                    <label for="channel-type">Kanaltyp <span class="required">*</span></label>
                    <select id="channel-type" name="channel_type" required>
                        <option value="email">E-Mail</option>
                        <option value="phone">Telefon</option>
                        <option value="mobile">Mobil</option>
                        <option value="fax">Fax</option>
                        <option value="website">Website</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="channel-value">Wert <span class="required">*</span></label>
                    <input type="text" id="channel-value" name="value" required 
                           placeholder="z.B. info@example.com oder +49 123 456789">
                </div>
                
                <div class="form-group">
                    <label for="channel-label">Bezeichnung</label>
                    <input type="text" id="channel-label" name="label" placeholder="z.B. Hauptnummer, Geschäftsführung">
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="channel-is-primary" name="is_primary" value="1">
                        Als primären Kanal markieren
                    </label>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="channel-is-public" name="is_public" value="1">
                        Öffentlich sichtbar
                    </label>
                </div>
                
                <div class="form-group">
                    <label for="channel-notes">Notizen</label>
                    <textarea id="channel-notes" name="notes" rows="3" placeholder="Zusätzliche Informationen..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="Utils.closeModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        `;
    }
    
    setupChannelForm(form, orgUuid) {
        if (!form) return;
        
        const orgDetailModule = this.app.orgDetail;
        
        form.onsubmit = async (e) => {
            e.preventDefault();
            const channelUuid = form.dataset.channelUuid;
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            
            // Konvertiere value zu email_address oder number je nach Typ
            if (data.channel_type === 'email') {
                data.email_address = data.value;
            } else if (['phone', 'mobile', 'fax'].includes(data.channel_type)) {
                data.number = data.value;
            }
            
            data.is_primary = data.is_primary === '1' ? 1 : 0;
            data.is_public = data.is_public === '1' ? 1 : 0;
            
            try {
                if (channelUuid) {
                    await window.API.updateOrgChannel(orgUuid, channelUuid, data);
                    Utils.showSuccess('Kommunikationskanal erfolgreich aktualisiert');
                } else {
                    await window.API.addOrgChannel(orgUuid, data);
                    Utils.showSuccess('Kommunikationskanal erfolgreich hinzugefügt');
                }
                Utils.closeModal();
                if (orgDetailModule && orgDetailModule.showOrgDetail) {
                    await orgDetailModule.showOrgDetail(orgUuid);
                }
            } catch (error) {
                console.error('Error saving channel:', error);
                Utils.showError('Fehler beim Speichern: ' + (error.message || 'Unbekannter Fehler'));
            }
        };
    }
}

