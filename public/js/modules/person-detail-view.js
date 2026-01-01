/**
 * TOM3 - Person Detail View Module
 * Handles rendering of person detail view
 */

import { Utils } from './utils.js';

export class PersonDetailViewModule {
    constructor(app) {
        this.app = app;
    }
    
    renderPersonDetail(person) {
        const personUuid = person.person_uuid || person.uuid;
        if (!personUuid) {
            throw new Error('personUuid fehlt in den Daten');
        }
        
        const displayName = person.display_name || `${person.first_name || ''} ${person.last_name || ''}`.trim() || 'Unbekannt';
        const isActive = person.is_active !== 0;
        
        return `
            <div class="person-detail" data-person-uuid="${personUuid}">
                <!-- Sticky Header -->
                <div class="person-detail-header-sticky">
                    <div class="person-detail-header-content">
                        <h2 class="person-detail-title">${Utils.escapeHtml(displayName)}</h2>
                        <div class="person-detail-header-right">
                            <div class="person-detail-header-meta">
                                ${!isActive ? '<span class="badge badge-warning">Inaktiv</span>' : ''}
                            </div>
                            <div class="person-detail-header-actions">
                                <div class="person-detail-menu">
                                    <button class="person-detail-menu-toggle" type="button" aria-label="Menü öffnen">
                                        <span class="person-detail-menu-dots">&#8230;</span>
                                    </button>
                                    <div class="person-detail-menu-dropdown">
                                        <button class="person-detail-menu-item" data-action="audit-trail">
                                            <span class="person-detail-menu-icon">📋</span>
                                            <span>Audit-Trail</span>
                                        </button>
                                    </div>
                                </div>
                                <button class="person-detail-close btn-icon" title="Schließen">✕</button>
                            </div>
                        </div>
                    </div>
                    <div class="person-detail-header-divider"></div>
                </div>
                
                <!-- Tabs -->
                <div class="person-detail-tabs">
                    <button class="person-detail-tab active" data-tab="stammdaten">Stammdaten</button>
                    <button class="person-detail-tab" data-tab="historie">Historie</button>
                    <button class="person-detail-tab" data-tab="relationen">Relationen</button>
                    <button class="person-detail-tab" data-tab="dokumente">
                        Dokumente<span id="person-documents-count-badge" class="person-detail-tab-badge" style="display: none;"></span>
                    </button>
                </div>
                
                <!-- Tab Content -->
                <div class="person-detail-content">
                    <!-- Stammdaten Tab -->
                    <div class="person-detail-tab-content active" data-tab-content="stammdaten">
                        ${this.renderStammdaten(person)}
                    </div>
                    
                    <!-- Historie Tab -->
                    <div class="person-detail-tab-content" data-tab-content="historie">
                        ${this.renderHistorie(person)}
                    </div>
                    
                    <!-- Relationen Tab -->
                    <div class="person-detail-tab-content" data-tab-content="relationen">
                        ${this.renderRelationen(person)}
                    </div>
                    
                    <!-- Dokumente Tab -->
                    <div class="person-detail-tab-content" data-tab-content="dokumente">
                        ${this.renderDokumente(person)}
                    </div>
                </div>
            </div>
        `;
    }
    
    renderStammdaten(person) {
        return `
            <div class="person-stammdaten">
                <div class="form-section">
                    <h3>Persönliche Daten</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Anrede</label>
                            <div class="form-value">${Utils.escapeHtml(person.salutation || '-')}</div>
                        </div>
                        <div class="form-group">
                            <label>Titel</label>
                            <div class="form-value">${Utils.escapeHtml(person.title || '-')}</div>
                        </div>
                        <div class="form-group">
                            <label>Vorname</label>
                            <div class="form-value">${Utils.escapeHtml(person.first_name || '-')}</div>
                        </div>
                        <div class="form-group">
                            <label>Nachname</label>
                            <div class="form-value">${Utils.escapeHtml(person.last_name || '-')}</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Kontakt</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>E-Mail</label>
                            <div class="form-value">
                                ${person.email ? `<a href="mailto:${Utils.escapeHtml(person.email)}">${Utils.escapeHtml(person.email)}</a>` : '-'}
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Telefon</label>
                            <div class="form-value">${Utils.escapeHtml(person.phone || '-')}</div>
                        </div>
                        <div class="form-group">
                            <label>Mobil</label>
                            <div class="form-value">${Utils.escapeHtml(person.mobile_phone || '-')}</div>
                        </div>
                        <div class="form-group">
                            <label>LinkedIn</label>
                            <div class="form-value">
                                ${person.linkedin_url ? `<a href="${Utils.escapeHtml(person.linkedin_url)}" target="_blank">${Utils.escapeHtml(person.linkedin_url)}</a>` : '-'}
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Status</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Aktiv</label>
                            <div class="form-value">
                                <span class="badge ${person.is_active ? 'badge-success' : 'badge-warning'}">
                                    ${person.is_active ? 'Ja' : 'Nein'}
                                </span>
                            </div>
                        </div>
                        ${person.archived_at ? `
                        <div class="form-group">
                            <label>Archiviert am</label>
                            <div class="form-value">${Utils.formatDate(person.archived_at)}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                ${person.notes ? `
                <div class="form-section">
                    <h3>Notizen</h3>
                    <div class="form-value">${Utils.escapeHtml(person.notes)}</div>
                </div>
                ` : ''}
                
                <div class="form-actions">
                    <button class="btn btn-primary" id="btn-edit-person">Bearbeiten</button>
                </div>
            </div>
        `;
    }
    
    renderHistorie(person) {
        return `
            <div class="person-historie">
                <div class="section-header">
                    <h3>Beschäftigungsverlauf</h3>
                    <button class="btn btn-primary btn-sm" id="btn-add-affiliation">+ Affiliation hinzufügen</button>
                </div>
                <div id="person-affiliations-list" class="affiliations-list">
                    <div class="loading">Lade Historie...</div>
                </div>
            </div>
        `;
    }
    
    renderRelationen(person) {
        return `
            <div class="person-relationen">
                <div class="section-header">
                    <h3>Beziehungen</h3>
                    <button class="btn btn-primary btn-sm" id="btn-add-relationship">+ Beziehung hinzufügen</button>
                </div>
                <div id="person-relationships-list" class="relationships-list">
                    <div class="empty-state">
                        <p>Noch keine Beziehungen erfasst</p>
                    </div>
                </div>
            </div>
        `;
    }
    
    renderDokumente(person) {
        const personUuid = person.person_uuid || person.uuid;
        return `
            <div class="person-dokumente">
                <div class="section-header">
                    <h3>Dokumente</h3>
                    <button id="person-upload-document-btn" class="btn btn-sm btn-primary" data-person-uuid="${personUuid}">+ Dokument hochladen</button>
                </div>
                <div id="person-documents-list" class="document-list">
                    <!-- Wird dynamisch geladen -->
                </div>
            </div>
        `;
    }
}
