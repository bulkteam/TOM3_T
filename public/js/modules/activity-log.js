/**
 * TOM3 - Activity Log Module
 * Displays activity log for users and entities
 */

import { Utils } from '../utils.js';

export class ActivityLogModule {
    constructor(app) {
        this.app = app;
        this._closeHandler = null;
    }
    
    /**
     * Zeigt Activity-Log für einen User
     */
    async showUserActivityLog(userId, userName) {
        try {
            const response = await window.API.getUserActivities(userId, 100, 0);
            const activities = response.data || [];
            
            const modal = Utils.getOrCreateModal('modal-activity-log', `Activity-Log: ${Utils.escapeHtml(userName || userId)}`);
            const modalBody = modal.querySelector('.modal-body');
            
            modalBody.innerHTML = this.renderActivityLog(activities, 'user', userId);
            
            // Close-Button
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) {
                if (this._closeHandler) {
                    closeBtn.removeEventListener('click', this._closeHandler);
                }
                this._closeHandler = (e) => {
                    e.preventDefault();
                    Utils.closeSpecificModal('modal-activity-log');
                };
                closeBtn.addEventListener('click', this._closeHandler);
            }
            
            // ESC-Taste
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    Utils.closeSpecificModal('modal-activity-log');
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading activity log:', error);
            Utils.showError('Fehler beim Laden des Activity-Logs: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Zeigt Activity-Log für eine Entität
     */
    async showEntityActivityLog(entityType, entityUuid, entityName) {
        try {
            const response = await window.API.getEntityActivities(entityType, entityUuid, 100);
            const activities = response.data || [];
            
            const entityLabel = entityType === 'org' ? 'Organisation' : entityType === 'person' ? 'Person' : entityType;
            const modal = Utils.getOrCreateModal('modal-activity-log', `Activity-Log: ${Utils.escapeHtml(entityName || entityLabel)}`);
            const modalBody = modal.querySelector('.modal-body');
            
            modalBody.innerHTML = this.renderActivityLog(activities, 'entity', entityUuid, entityType);
            
            // Close-Button
            const closeBtn = modal.querySelector('.modal-close');
            if (closeBtn) {
                if (this._closeHandler) {
                    closeBtn.removeEventListener('click', this._closeHandler);
                }
                this._closeHandler = (e) => {
                    e.preventDefault();
                    Utils.closeSpecificModal('modal-activity-log');
                };
                closeBtn.addEventListener('click', this._closeHandler);
            }
            
            // ESC-Taste
            const escHandler = (e) => {
                if (e.key === 'Escape') {
                    Utils.closeSpecificModal('modal-activity-log');
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
            
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading activity log:', error);
            Utils.showError('Fehler beim Laden des Activity-Logs: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Rendert Activity-Log
     */
    renderActivityLog(activities, type = 'user', id = null, entityType = null) {
        if (!Array.isArray(activities) || activities.length === 0) {
            return `
                <div class="activity-log-empty">
                    <p>Keine Activity-Log-Einträge vorhanden.</p>
                </div>
            `;
        }
        
        // Gruppiere Einträge nach Datum
        const grouped = this.groupByDate(activities);
        
        let html = '<div class="activity-log">';
        
        for (const [date, entries] of Object.entries(grouped)) {
            html += `
                <div class="activity-log-day">
                    <div class="activity-log-day-header">
                        <span class="activity-log-date">${this.formatDate(date)}</span>
                        <span class="activity-log-count">${entries.length} ${entries.length === 1 ? 'Eintrag' : 'Einträge'}</span>
                    </div>
                    <div class="activity-log-entries">
                        ${entries.map(entry => this.renderActivityEntry(entry, entityType)).join('')}
                    </div>
                </div>
            `;
        }
        
        html += '</div>';
        return html;
    }
    
    /**
     * Rendert einen Activity-Eintrag
     */
    renderActivityEntry(entry, entityType = null) {
        const actionLabel = this.getActionLabel(entry.action_type);
        const actionClass = this.getActionClass(entry.action_type);
        const [date, time] = this.splitDateTime(entry.created_at);
        const user = entry.user_name || entry.user_id || 'Unbekannt';
        
        let detailsHtml = '';
        if (entry.details) {
            if (entry.action_type === 'entity_change' && entry.details.changed_fields) {
                detailsHtml = `
                    <div class="activity-log-details">
                        <strong>Geänderte Felder:</strong> ${entry.details.changed_fields.join(', ')}
                        ${entry.details.entity_name ? `<br><strong>Entität:</strong> ${Utils.escapeHtml(entry.details.entity_name)}` : ''}
                    </div>
                `;
            } else if (entry.details.file_name) {
                detailsHtml = `
                    <div class="activity-log-details">
                        <strong>Datei:</strong> ${Utils.escapeHtml(entry.details.file_name)}
                        ${entry.details.file_size ? `<br><strong>Größe:</strong> ${this.formatFileSize(entry.details.file_size)}` : ''}
                    </div>
                `;
            } else if (entry.details.export_type) {
                detailsHtml = `
                    <div class="activity-log-details">
                        <strong>Export-Typ:</strong> ${Utils.escapeHtml(entry.details.export_type)}
                    </div>
                `;
            }
        }
        
        // Link zu Audit-Trail, wenn vorhanden
        let auditTrailLink = '';
        if (entry.audit_trail_id && entry.audit_trail_table && entry.entity_uuid) {
            const entityTypeForLink = entry.entity_type || entityType || 'org';
            auditTrailLink = `
                <a href="#" class="activity-log-audit-link" data-entity-type="${entityTypeForLink}" data-entity-uuid="${entry.entity_uuid}">
                    Details anzeigen
                </a>
            `;
        }
        
        return `
            <div class="activity-log-entry" data-action="${entry.action_type}">
                <div class="activity-log-entry-header">
                    <div class="activity-log-entry-left">
                        <span class="activity-log-action-badge activity-log-action-${actionClass}">${Utils.escapeHtml(actionLabel)}</span>
                        ${entry.entity_type && entry.entity_uuid ? `
                            <span class="activity-log-entity">${Utils.escapeHtml(entry.entity_type)}: ${Utils.escapeHtml(entry.entity_uuid.substring(0, 8))}...</span>
                        ` : ''}
                    </div>
                    <div class="activity-log-entry-right">
                        <span class="activity-log-date">${Utils.escapeHtml(date)}</span>
                        <span class="activity-log-time">${time}</span>
                        <span class="activity-log-user">${Utils.escapeHtml(user)}</span>
                    </div>
                </div>
                ${detailsHtml}
                ${auditTrailLink}
            </div>
        `;
    }
    
    /**
     * Gruppiert Einträge nach Datum
     */
    groupByDate(activities) {
        const grouped = {};
        for (const entry of activities) {
            const date = entry.created_at.split(' ')[0];
            if (!grouped[date]) {
                grouped[date] = [];
            }
            grouped[date].push(entry);
        }
        return grouped;
    }
    
    /**
     * Formatiert Datum
     */
    formatDate(dateString) {
        const date = new Date(dateString);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        if (date.toDateString() === today.toDateString()) {
            return 'Heute';
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'Gestern';
        } else {
            return date.toLocaleDateString('de-DE', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }
    }
    
    /**
     * Trennt Datum und Zeit
     */
    splitDateTime(dateTimeString) {
        const [date, time] = dateTimeString.split(' ');
        return [date, time.substring(0, 5)]; // HH:MM
    }
    
    /**
     * Gibt Label für Action-Type zurück
     */
    getActionLabel(actionType) {
        const labels = {
            'login': 'Login',
            'logout': 'Logout',
            'export': 'Export',
            'upload': 'Upload',
            'download': 'Download',
            'entity_change': 'Änderung',
            'assignment': 'Zuweisung',
            'system_action': 'System'
        };
        return labels[actionType] || actionType;
    }
    
    /**
     * Gibt CSS-Klasse für Action-Type zurück
     */
    getActionClass(actionType) {
        const classes = {
            'login': 'success',
            'logout': 'info',
            'export': 'primary',
            'upload': 'primary',
            'download': 'primary',
            'entity_change': 'warning',
            'assignment': 'info',
            'system_action': 'secondary'
        };
        return classes[actionType] || 'secondary';
    }
    
    /**
     * Formatiert Dateigröße
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
}


