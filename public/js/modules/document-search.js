/**
 * TOM3 - Document Search Module
 * Handles document search with filters and results display
 */

import { Utils } from './utils.js';
import { SearchInputModule } from './search-input.js';

export class DocumentSearchModule {
    constructor(app) {
        this.app = app;
        this.searchInput = new SearchInputModule();
        this._searchInputCleanup = null;
        this.currentFilters = {};
        this.currentResults = [];
    }
    
    init() {
        const searchInput = document.getElementById('document-search-input');
        const resultsContainer = document.getElementById('document-search-results');
        
        if (!searchInput || !resultsContainer) {
            console.warn('[DocumentSearch] Required elements not found');
            return;
        }
        
        // Entferne alte Event-Listener
        if (this._searchInputCleanup) {
            this._searchInputCleanup();
        }
        
        // Input-Handler (Debounced Search)
        this._searchInputCleanup = this.searchInput.setupDebouncedSearch(
            searchInput,
            (query) => {
                this.performSearch();
            },
            () => {
                this.currentResults = [];
                this.renderResults([]);
            },
            500, // delay
            0 // minLength (0 = auch leere Suche erlauben)
        );
        
        // Filter-Event-Handler
        this.setupFilters();
        
        // Reset-Button
        const resetBtn = document.getElementById('btn-reset-filters');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                this.resetFilters();
            });
        }
        
        // Initial: Lade alle Dokumente (ohne Query)
        this.performSearch();
    }
    
    setupFilters() {
        const filterIds = [
            'filter-classification',
            'filter-source',
            'filter-status',
            'filter-date-from',
            'filter-date-to',
            'filter-orphaned-only'
        ];
        
        filterIds.forEach(filterId => {
            const element = document.getElementById(filterId);
            if (element) {
                element.addEventListener('change', () => {
                    this.performSearch();
                });
            }
        });
    }
    
    getFilters() {
        const filters = {};
        
        const classification = document.getElementById('filter-classification')?.value;
        if (classification) {
            filters.classification = classification;
        }
        
        const source = document.getElementById('filter-source')?.value;
        if (source) {
            filters.source_type = source;
        }
        
        const status = document.getElementById('filter-status')?.value;
        if (status) {
            filters.status = status;
        }
        
        const dateFrom = document.getElementById('filter-date-from')?.value;
        if (dateFrom) {
            filters.date_from = dateFrom;
        }
        
        const dateTo = document.getElementById('filter-date-to')?.value;
        if (dateTo) {
            filters.date_to = dateTo;
        }
        
        const orphanedOnly = document.getElementById('filter-orphaned-only')?.checked;
        if (orphanedOnly) {
            filters.orphaned_only = true;
        }
        
        return filters;
    }
    
    resetFilters() {
        document.getElementById('filter-classification').value = '';
        document.getElementById('filter-source').value = '';
        document.getElementById('filter-status').value = '';
        document.getElementById('filter-date-from').value = '';
        document.getElementById('filter-date-to').value = '';
        document.getElementById('filter-orphaned-only').checked = false;
        document.getElementById('document-search-input').value = '';
        
        this.performSearch();
    }
    
    async performSearch() {
        const searchInput = document.getElementById('document-search-input');
        const resultsContainer = document.getElementById('document-search-results');
        
        if (!searchInput || !resultsContainer) {
            return;
        }
        
        const query = searchInput.value.trim();
        const filters = this.getFilters();
        
        // Loading-State
        resultsContainer.innerHTML = '<div class="loading">Suche läuft...</div>';
        
        try {
            const results = await window.API.searchDocuments(query, filters);
            this.currentResults = results || [];
            this.renderResults(this.currentResults);
        } catch (error) {
            console.error('Document search failed:', error);
            resultsContainer.innerHTML = `
                <div class="error-message">
                    Fehler bei der Suche: ${Utils.escapeHtml(error.message || 'Unbekannter Fehler')}
                </div>
            `;
        }
    }
    
    renderResults(documents) {
        const container = document.getElementById('document-search-results');
        if (!container) return;
        
        if (!documents || documents.length === 0) {
            container.innerHTML = `
                <div class="search-placeholder">
                    <p>Keine Dokumente gefunden.</p>
                </div>
            `;
            return;
        }
        
        const html = `
            <div class="document-results-header">
                <div class="results-count">${documents.length} Dokument${documents.length !== 1 ? 'e' : ''} gefunden</div>
            </div>
            <div class="document-results-list">
                ${documents.map(doc => this.renderDocumentCard(doc)).join('')}
            </div>
        `;
        
        container.innerHTML = html;
        
        // Event-Handler für Buttons
        container.querySelectorAll('.btn-download-document').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const documentUuid = btn.dataset.documentUuid;
                this.downloadDocument(documentUuid);
            });
        });
        
        container.querySelectorAll('.btn-view-document').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const documentUuid = btn.dataset.documentUuid;
                this.viewDocument(documentUuid);
            });
        });
        
        container.querySelectorAll('.document-attachment-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const entityType = link.dataset.entityType;
                const entityUuid = link.dataset.entityUuid;
                this.navigateToEntity(entityType, entityUuid);
            });
        });
    }
    
    renderDocumentCard(doc) {
        const tags = Array.isArray(doc.tags) ? doc.tags : [];
        const attachments = doc.attachments || [];
        const createdDate = doc.created_at ? new Date(doc.created_at).toLocaleDateString('de-DE') : '';
        const fileSize = doc.size_bytes ? this.formatFileSize(doc.size_bytes) : '';
        
        // Klassifikation-Badge
        const classificationLabels = {
            'invoice': 'Rechnung',
            'quote': 'Angebot',
            'contract': 'Vertrag',
            'email_attachment': 'E-Mail',
            'other': 'Sonstiges'
        };
        const classificationLabel = classificationLabels[doc.classification] || doc.classification;
        
        // Quelle-Badge
        const sourceLabels = {
            'upload': 'Upload',
            'email': 'E-Mail',
            'api': 'API',
            'import': 'Import'
        };
        const sourceLabel = sourceLabels[doc.source_type] || doc.source_type;
        
        // Scan-Status
        const scanStatusBadge = doc.scan_status === 'clean' 
            ? '<span class="badge badge-success">✓ Sicher</span>'
            : doc.scan_status === 'pending'
            ? '<span class="badge badge-warning">⏳ Prüfung läuft</span>'
            : '<span class="badge badge-error">⚠ Blockiert</span>';
        
        // Verknüpfungen
        const attachmentsHtml = attachments.length > 0
            ? `
                <div class="document-attachments">
                    <strong>Verknüpft mit:</strong>
                    ${attachments.map(att => {
                        const entityLabels = {
                            'org': 'Organisation',
                            'person': 'Person',
                            'case': 'Vorgang',
                            'project': 'Projekt',
                            'task': 'Aufgabe',
                            'email_message': 'E-Mail',
                            'email_thread': 'E-Mail-Thread'
                        };
                        const entityLabel = entityLabels[att.entity_type] || att.entity_type;
                        const roleText = att.role ? ` (${Utils.escapeHtml(att.role)})` : '';
                        return `
                            <a href="#" class="document-attachment-link" 
                               data-entity-type="${Utils.escapeHtml(att.entity_type)}" 
                               data-entity-uuid="${Utils.escapeHtml(att.entity_uuid)}">
                                ${Utils.escapeHtml(entityLabel)}${roleText}
                            </a>
                        `;
                    }).join(', ')}
                </div>
            `
            : '<div class="document-attachments"><em>Keine Zuordnung</em></div>';
        
        // Tags
        const tagsHtml = tags.length > 0
            ? `<div class="document-tags">${tags.map(tag => `<span class="tag">${Utils.escapeHtml(tag)}</span>`).join('')}</div>`
            : '';
        
        return `
            <div class="document-card" data-document-uuid="${doc.document_uuid}">
                <div class="document-card-header">
                    <h3 class="document-title">${Utils.escapeHtml(doc.title || 'Unbenannt')}</h3>
                    <div class="document-badges">
                        <span class="badge badge-info">${Utils.escapeHtml(classificationLabel)}</span>
                        <span class="badge badge-secondary">${Utils.escapeHtml(sourceLabel)}</span>
                        ${scanStatusBadge}
                    </div>
                </div>
                
                <div class="document-card-body">
                    <div class="document-meta">
                        <span class="meta-item">📅 ${createdDate}</span>
                        ${fileSize ? `<span class="meta-item">📦 ${fileSize}</span>` : ''}
                        ${doc.file_extension ? `<span class="meta-item">📄 ${Utils.escapeHtml(doc.file_extension.toUpperCase())}</span>` : ''}
                    </div>
                    
                    ${tagsHtml}
                    
                    ${attachmentsHtml}
                    
                    ${doc.extracted_text ? `
                        <div class="document-preview">
                            <p class="document-preview-text">${Utils.escapeHtml(doc.extracted_text.substring(0, 200))}${doc.extracted_text.length > 200 ? '...' : ''}</p>
                        </div>
                    ` : ''}
                </div>
                
                <div class="document-card-footer">
                    ${doc.scan_status === 'clean' ? `
                        <button class="btn btn-primary btn-sm btn-view-document" data-document-uuid="${doc.document_uuid}">
                            👁️ Ansehen
                        </button>
                        <button class="btn btn-secondary btn-sm btn-download-document" data-document-uuid="${doc.document_uuid}">
                            ⬇️ Download
                        </button>
                    ` : `
                        <span class="text-muted">Dokument nicht verfügbar (Status: ${doc.scan_status})</span>
                    `}
                </div>
            </div>
        `;
    }
    
    formatFileSize(bytes) {
        if (!bytes) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let size = bytes;
        let unitIndex = 0;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex++;
        }
        return `${size.toFixed(1)} ${units[unitIndex]}`;
    }
    
    async downloadDocument(documentUuid) {
        try {
            const url = `/tom3/public/api/documents/${documentUuid}/download`;
            window.open(url, '_blank');
        } catch (error) {
            console.error('Download failed:', error);
            Utils.showError('Download fehlgeschlagen');
        }
    }
    
    async viewDocument(documentUuid) {
        try {
            const url = `/tom3/public/api/documents/${documentUuid}/view`;
            window.open(url, '_blank');
        } catch (error) {
            console.error('View failed:', error);
            Utils.showError('Vorschau fehlgeschlagen');
        }
    }
    
    navigateToEntity(entityType, entityUuid) {
        // Navigiere zur entsprechenden Entität
        if (entityType === 'org') {
            if (this.app.orgDetail) {
                this.app.orgDetail.showOrgDetail(entityUuid);
                this.app.navigateTo('orgs');
            }
        } else if (entityType === 'person') {
            if (this.app.personDetail) {
                this.app.personDetail.showPersonDetail(entityUuid);
                this.app.navigateTo('persons');
            }
        } else {
            Utils.showInfo(`Navigation zu ${entityType} noch nicht implementiert`);
        }
    }
}
