/**
 * TOM3 - Audit Trail Module
 * Displays audit trail for organizations
 */

import { Utils } from './utils.js';

export class AuditTrailModule {
    constructor(app) {
        this.app = app;
    }
    
    async showAuditTrail(orgUuid, orgName) {
        try {
            // Lade Audit-Trail-Daten
            const auditTrail = await window.API.getOrgAuditTrail(orgUuid, 200);
            
            // Erstelle oder hole Modal
            const modal = Utils.getOrCreateModal('modal-audit-trail', `Audit-Trail: ${Utils.escapeHtml(orgName || 'Organisation')}`);
            
            const modalBody = modal.querySelector('.modal-body');
            if (!modalBody) {
                throw new Error('Modal body not found');
            }
            
            // Rendere Audit-Trail
            modalBody.innerHTML = this.renderAuditTrail(auditTrail, orgUuid);
            
            // Überschreibe Close-Button-Handler, damit nur dieses Modal geschlossen wird
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) {
                const newCloseBtn = closeBtn.cloneNode(true);
                closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
                newCloseBtn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-audit-trail');
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
            }
            
            // Überschreibe Overlay-Click-Handler, damit nur dieses Modal geschlossen wird
            modal.removeEventListener('click', modal._overlayClickHandler);
            modal._overlayClickHandler = (e) => {
                if (e.target === modal) {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    Utils.closeSpecificModal('modal-audit-trail');
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
            modal.addEventListener('click', modal._overlayClickHandler);
            
            // Zeige Modal
            modal.classList.add('active');
            
        } catch (error) {
            console.error('Error loading audit trail:', error);
            Utils.showError('Fehler beim Laden des Audit-Trails: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    renderAuditTrail(auditTrail, orgUuid) {
        if (!Array.isArray(auditTrail) || auditTrail.length === 0) {
            return `
                <div class="audit-trail-empty">
                    <p>Keine Audit-Trail-Einträge vorhanden.</p>
                </div>
            `;
        }
        
        // Gruppiere Einträge nach Datum
        const grouped = this.groupByDate(auditTrail);
        
        let html = '<div class="audit-trail">';
        
        for (const [date, entries] of Object.entries(grouped)) {
            html += `
                <div class="audit-trail-day">
                    <div class="audit-trail-day-header">
                        <span class="audit-trail-date">${this.formatDate(date)}</span>
                        <span class="audit-trail-count">${entries.length} ${entries.length === 1 ? 'Eintrag' : 'Einträge'}</span>
                    </div>
                    <div class="audit-trail-entries">
                        ${entries.map(entry => this.renderAuditEntry(entry)).join('')}
                    </div>
                </div>
            `;
        }
        
        html += '</div>';
        return html;
    }
    
    renderAuditEntry(entry) {
        const actionLabel = this.getActionLabel(entry.action);
        const changeTypeLabel = this.getChangeTypeLabel(entry.change_type);
        const date = this.formatEntryDate(entry.created_at);
        const time = this.formatTime(entry.created_at);
        const user = entry.user_name || entry.user_id || 'Unbekannt';
        
        let changeDetails = '';
        
        if (entry.change_type === 'field_change' && entry.field_name) {
            const fieldLabel = this.getFieldLabel(entry.field_name);
            changeDetails = `
                <div class="audit-trail-change">
                    <span class="audit-trail-field">${Utils.escapeHtml(fieldLabel)}:</span>
                    <span class="audit-trail-old-value">${Utils.escapeHtml(entry.old_value || '(leer)')}</span>
                    <span class="audit-trail-arrow">→</span>
                    <span class="audit-trail-new-value">${Utils.escapeHtml(entry.new_value || '(leer)')}</span>
                </div>
            `;
        } else if (entry.change_type === 'org_created') {
            changeDetails = '<div class="audit-trail-change">Organisation erstellt</div>';
        } else if (entry.change_type === 'org_archived') {
            const metadata = entry.metadata ? JSON.parse(entry.metadata) : {};
            const archivedAt = metadata.archived_at ? new Date(metadata.archived_at).toLocaleString('de-DE') : '';
            changeDetails = `<div class="audit-trail-change">Organisation archiviert${archivedAt ? ` am ${archivedAt}` : ''}</div>`;
        } else if (entry.change_type === 'org_unarchived') {
            const metadata = entry.metadata ? JSON.parse(entry.metadata) : {};
            const oldArchivedAt = metadata.archived_at ? new Date(metadata.archived_at).toLocaleString('de-DE') : '';
            changeDetails = `<div class="audit-trail-change">Organisation reaktiviert${oldArchivedAt ? ` (war archiviert am ${oldArchivedAt})` : ''}</div>`;
        } else if (entry.metadata) {
            try {
                const metadata = typeof entry.metadata === 'string' ? JSON.parse(entry.metadata) : entry.metadata;
                changeDetails = `<div class="audit-trail-change">${Utils.escapeHtml(JSON.stringify(metadata, null, 2))}</div>`;
            } catch (e) {
                changeDetails = `<div class="audit-trail-change">${Utils.escapeHtml(entry.metadata)}</div>`;
            }
        }
        
        return `
            <div class="audit-trail-entry" data-action="${entry.action}" data-change-type="${entry.change_type || ''}">
                <div class="audit-trail-entry-header">
                    <div class="audit-trail-entry-left">
                        <span class="audit-trail-action-badge audit-trail-action-${entry.action}">${Utils.escapeHtml(actionLabel)}</span>
                        <span class="audit-trail-change-type">${Utils.escapeHtml(changeTypeLabel)}</span>
                    </div>
                    <div class="audit-trail-entry-right">
                        <span class="audit-trail-date">${Utils.escapeHtml(date)}</span>
                        <span class="audit-trail-time">${time}</span>
                        <span class="audit-trail-user">${Utils.escapeHtml(user)}</span>
                    </div>
                </div>
                ${changeDetails}
            </div>
        `;
    }
    
    groupByDate(entries) {
        const grouped = {};
        
        for (const entry of entries) {
            const date = entry.created_at ? entry.created_at.split(' ')[0] : 'Unbekannt';
            if (!grouped[date]) {
                grouped[date] = [];
            }
            grouped[date].push(entry);
        }
        
        // Sortiere Daten (neueste zuerst)
        const sorted = {};
        const dates = Object.keys(grouped).sort((a, b) => {
            if (a === 'Unbekannt') return 1;
            if (b === 'Unbekannt') return -1;
            return new Date(b) - new Date(a);
        });
        
        for (const date of dates) {
            sorted[date] = grouped[date];
        }
        
        return sorted;
    }
    
    formatDate(dateStr) {
        if (dateStr === 'Unbekannt') return 'Unbekannt';
        try {
            const date = new Date(dateStr);
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            
            if (date.toDateString() === today.toDateString()) {
                return 'Heute';
            } else if (date.toDateString() === yesterday.toDateString()) {
                return 'Gestern';
            } else {
                return date.toLocaleDateString('de-DE', { 
                    weekday: 'long', 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric' 
                });
            }
        } catch (e) {
            return dateStr;
        }
    }
    
    formatTime(dateTimeStr) {
        if (!dateTimeStr) return '';
        try {
            const date = new Date(dateTimeStr);
            return date.toLocaleTimeString('de-DE', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
        } catch (e) {
            return dateTimeStr.split(' ')[1] || '';
        }
    }
    
    formatEntryDate(dateTimeStr) {
        if (!dateTimeStr) return '';
        try {
            const date = new Date(dateTimeStr);
            return date.toLocaleDateString('de-DE', { 
                year: 'numeric', 
                month: '2-digit', 
                day: '2-digit' 
            });
        } catch (e) {
            return dateTimeStr.split(' ')[0] || '';
        }
    }
    
    getActionLabel(action) {
        const labels = {
            'create': 'Erstellt',
            'update': 'Geändert',
            'delete': 'Gelöscht'
        };
        return labels[action] || action;
    }
    
    getChangeTypeLabel(changeType) {
        const labels = {
            'org_created': 'Organisation erstellt',
            'org_archived': 'Organisation archiviert',
            'org_unarchived': 'Organisation reaktiviert',
            'field_change': 'Feld geändert',
            'relation_added': 'Beziehung hinzugefügt',
            'relation_removed': 'Beziehung entfernt',
            'address_added': 'Adresse hinzugefügt',
            'address_updated': 'Adresse aktualisiert',
            'address_removed': 'Adresse entfernt',
            'channel_added': 'Kommunikationskanal hinzugefügt',
            'channel_updated': 'Kommunikationskanal aktualisiert',
            'channel_removed': 'Kommunikationskanal entfernt',
            'vat_added': 'USt-ID hinzugefügt',
            'vat_updated': 'USt-ID aktualisiert',
            'vat_removed': 'USt-ID entfernt'
        };
        return labels[changeType] || changeType || 'Unbekannt';
    }
    
    getFieldLabel(fieldName) {
        const labels = {
            // Stammdaten
            'name': 'Name',
            'org_kind': 'Organisationsart',
            'external_ref': 'Externe Referenz',
            'industry': 'Branche',
            'industry_main_uuid': 'Hauptbranche',
            'industry_sub_uuid': 'Unterbranche',
            'revenue_range': 'Umsatz',
            'employee_count': 'Mitarbeiteranzahl',
            'website': 'Website',
            'notes': 'Notizen',
            'status': 'Status',
            'account_owner_user_id': 'Account-Verantwortung',
            'account_owner_since': 'Account-Verantwortung seit',
            'archived_at': 'Archiviert',
            // Adressfelder
            'address_address_type': 'Adresstyp',
            'address_street': 'Straße',
            'address_address_additional': 'Adresszusatz',
            'address_city': 'Stadt',
            'address_postal_code': 'PLZ',
            'address_country': 'Land',
            'address_state': 'Bundesland',
            'address_latitude': 'Breitengrad',
            'address_longitude': 'Längengrad',
            'address_is_default': 'Standardadresse',
            'address_notes': 'Notizen',
            // USt-ID-Felder
            'vat_vat_id': 'USt-ID',
            'vat_country_code': 'Länderkennzeichen',
            'vat_valid_from': 'Gültig ab',
            'vat_valid_to': 'Gültig bis',
            'vat_is_primary_for_country': 'Primär für Land',
            'vat_location_type': 'Standorttyp',
            'vat_notes': 'Notizen',
            // Kommunikationskanal-Felder
            'channel_channel_type': 'Kanaltyp',
            'channel_country_code': 'Länderkennzeichen',
            'channel_area_code': 'Vorwahl',
            'channel_number': 'Nummer',
            'channel_extension': 'Durchwahl',
            'channel_email_address': 'E-Mail-Adresse',
            'channel_label': 'Bezeichnung',
            'channel_is_primary': 'Primär',
            'channel_is_public': 'Öffentlich',
            'channel_notes': 'Notizen'
        };
        return labels[fieldName] || fieldName;
    }
}

