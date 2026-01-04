/**
 * TOM3 - Document Search Module
 * Handles document search with filters and results display
 */

import { Utils } from './utils.js';
import { SearchInputModule } from './search-input.js';
import { RecentListModule } from './recent-list.js';

export class DocumentSearchModule {
    constructor(app) {
        this.app = app;
        this.searchInput = new SearchInputModule();
        this.recentListModule = new RecentListModule(app);
        this._searchInputCleanup = null;
        this.currentFilters = {};
        this.currentResults = [];
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalResults = 0;
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
        
        // Lade "Zuletzt verwendet"
        this.loadRecentDocuments();
        
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
        
        this.currentPage = 1;
        this.performSearch(1);
    }
    
    async performSearch(page = 1) {
        const searchInput = document.getElementById('document-search-input');
        const resultsContainer = document.getElementById('document-search-results');
        
        if (!searchInput || !resultsContainer) {
            return;
        }
        
        const query = searchInput.value.trim();
        const filters = this.getFilters();
        
        // Pagination-Parameter hinzufügen
        filters.limit = this.pageSize;
        filters.offset = (page - 1) * this.pageSize;
        this.currentPage = page;
        
        // Loading-State
        resultsContainer.innerHTML = '<div class="loading">Suche läuft...</div>';
        
        try {
            const results = await window.API.searchDocuments(query, filters);
            this.currentResults = results || [];
            this.totalResults = results.length; // TODO: API sollte total_count zurückgeben
            this.renderResults(this.currentResults, page);
        } catch (error) {
            console.error('Document search failed:', error);
            resultsContainer.innerHTML = `
                <div class="error-message">
                    Fehler bei der Suche: ${Utils.escapeHtml(error.message || 'Unbekannter Fehler')}
                </div>
            `;
        }
    }
    
    renderResults(documents, page = 1) {
        const container = document.getElementById('document-search-results');
        if (!container) return;
        
        // Verstecke "Zuletzt verwendet" wenn Suche aktiv ist
        const recentSection = document.getElementById('document-recent-section');
        if (recentSection) {
            const query = document.getElementById('document-search-input')?.value.trim() || '';
            const hasFilters = Object.keys(this.getFilters()).length > 0;
            if (query || hasFilters) {
                recentSection.style.display = 'none';
            } else {
                recentSection.style.display = 'block';
            }
        }
        
        if (!documents || documents.length === 0) {
            container.innerHTML = `
                <div class="search-placeholder">
                    <p>Keine Dokumente gefunden.</p>
                </div>
            `;
            return;
        }
        
        const totalPages = Math.ceil(this.totalResults / this.pageSize);
        const hasMore = documents.length === this.pageSize; // Annahme: wenn genau pageSize Ergebnisse, gibt es mehr
        
        const html = `
            <div class="document-results-header">
                <div class="results-count">${documents.length} Dokument${documents.length !== 1 ? 'e' : ''} gefunden</div>
            </div>
            <div class="document-results-list">
                ${documents.map(doc => this.renderDocumentCard(doc)).join('')}
            </div>
            ${this.renderPagination(page, totalPages, hasMore)}
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
                        
                        // Zeige Klarname, falls vorhanden, sonst nur Entity-Type
                        let displayText = entityLabel;
                        if (att.entity_name) {
                            displayText = `${Utils.escapeHtml(att.entity_name)} (${entityLabel})`;
                        }
                        
                        const roleText = att.role ? ` - ${Utils.escapeHtml(att.role)}` : '';
                        return `
                            <a href="#" class="document-attachment-link" 
                               data-entity-type="${Utils.escapeHtml(att.entity_type)}" 
                               data-entity-uuid="${Utils.escapeHtml(att.entity_uuid)}">
                                ${displayText}${roleText}
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
        
        // Button-Logik: Ansehen nur für PDFs und Bilder
        const canDownload = doc.scan_status === 'clean';
        const canPreview = canDownload && (doc.mime_detected === 'application/pdf' || doc.mime_detected?.startsWith('image/'));
        
        let actionButtons = '';
        if (canDownload) {
            if (canPreview) {
                // Ansehen + Download für PDFs und Bilder
                actionButtons = `
                    <button class="btn btn-primary btn-sm btn-view-document" data-document-uuid="${doc.document_uuid}">
                        👁️ Ansehen
                    </button>
                    <button class="btn btn-secondary btn-sm btn-download-document" data-document-uuid="${doc.document_uuid}">
                        ⬇️ Download
                    </button>
                `;
            } else {
                // Nur Download für andere Dateitypen
                actionButtons = `
                    <button class="btn btn-secondary btn-sm btn-download-document" data-document-uuid="${doc.document_uuid}">
                        ⬇️ Download
                    </button>
                `;
            }
        } else {
            actionButtons = `<span class="text-muted">Dokument nicht verfügbar (Status: ${doc.scan_status})</span>`;
        }
        
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
                    ${actionButtons}
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
    
    async loadRecentDocuments() {
        await this.recentListModule.loadRecentList(
            'document',
            'document-recent-list',
            (doc) => `
                <div class="document-recent-item" data-document-uuid="${doc.document_uuid || doc.uuid}" style="padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                    <h4 style="margin: 0 0 0.25rem 0; font-size: 0.875rem; color: var(--text);">${Utils.escapeHtml(doc.title || 'Unbenannt')}</h4>
                    ${doc.file_extension ? `<p style="margin: 0; color: var(--text-light); font-size: 0.75rem;">${Utils.escapeHtml(doc.file_extension.toUpperCase())}</p>` : ''}
                </div>
            `,
            (documentUuid) => {
                // Öffne Dokument-Detail oder zeige in neuem Tab
                this.viewDocument(documentUuid);
            },
            10
        );
    }
    
    renderPagination(currentPage, totalPages, hasMore) {
        if (totalPages <= 1 && !hasMore) {
            return '';
        }
        
        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = !hasMore && currentPage >= totalPages ? 'disabled' : '';
        
        return `
            <div class="document-pagination" style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border); display: flex; justify-content: center; align-items: center; gap: 1rem;">
                <button class="btn btn-secondary btn-sm ${prevDisabled}" 
                        ${prevDisabled ? 'disabled' : ''} 
                        onclick="window.app.documentSearch.performSearch(${currentPage - 1})">
                    ← Zurück
                </button>
                <span class="pagination-info" style="color: var(--text-light);">
                    Seite ${currentPage}${hasMore ? '+' : totalPages > 1 ? ` von ${totalPages}` : ''}
                </span>
                <button class="btn btn-secondary btn-sm ${nextDisabled}" 
                        ${nextDisabled ? 'disabled' : ''} 
                        onclick="window.app.documentSearch.performSearch(${currentPage + 1})">
                    Weiter →
                </button>
            </div>
        `;
    }
    
    async downloadDocument(documentUuid) {
        try {
            // Track Access
            const user = await window.API.getCurrentUser();
            if (user && user.user_id) {
                await window.API.trackDocumentAccess(documentUuid, user.user_id, 'recent');
                // Aktualisiere "Zuletzt verwendet" Liste
                this.loadRecentDocuments();
            }
            
            const url = `/tom3/public/api/documents/${documentUuid}/download`;
            window.open(url, '_blank');
        } catch (error) {
            console.error('Download failed:', error);
            Utils.showError('Download fehlgeschlagen');
        }
    }
    
    async viewDocument(documentUuid) {
        try {
            // Track Access
            const user = await window.API.getCurrentUser();
            if (user && user.user_id) {
                await window.API.trackDocumentAccess(documentUuid, user.user_id, 'recent');
                // Aktualisiere "Zuletzt verwendet" Liste
                this.loadRecentDocuments();
            }
            
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


