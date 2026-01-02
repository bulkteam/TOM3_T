/**
 * TOM3 - Document List Module
 * Zeigt Liste von Dokumenten für eine Entität an
 */

import { Utils } from './utils.js';

export class DocumentListModule {
    constructor(app) {
        this.app = app;
        this.refreshIntervals = new Map(); // entityType+entityUuid -> intervalId
    }
    
    /**
     * Lädt Dokumente und rendert sie in Container, aktualisiert auch Badge
     */
    async loadDocuments(entityType, entityUuid, containerSelector, badgeSelector) {
        const container = document.querySelector(containerSelector);
        if (!container) {
            console.warn(`Container ${containerSelector} nicht gefunden`);
            return;
        }
        
        try {
            // Dokumente laden
            const documents = await window.API.getEntityDocuments(entityType, entityUuid);
            
            // Badge aktualisieren
            if (badgeSelector) {
                const badge = document.querySelector(badgeSelector);
                if (badge) {
                    const count = documents?.length || 0;
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }
            
            // Rendern
            container.innerHTML = this.renderDocumentsHTML(documents);
            
            // Auto-Refresh starten, wenn Dokumente mit "pending" Status vorhanden sind
            this.startAutoRefreshIfNeeded(entityType, entityUuid, containerSelector, badgeSelector, documents);
            
            // Event-Handler für Preview-Buttons
            container.querySelectorAll('.btn-preview-document').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    // Button selbst hat das dataset, nicht das span
                    const documentUuid = btn.dataset.documentUuid;
                    const mimeType = btn.dataset.mime;
                    if (documentUuid) {
                        this.previewDocument(documentUuid, mimeType);
                    } else {
                        console.error('Preview: documentUuid nicht gefunden', btn);
                    }
                });
            });
            
            // Event-Handler für Download-Buttons
            container.querySelectorAll('.btn-download-document').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    // Button selbst hat das dataset, nicht das span
                    const documentUuid = btn.dataset.documentUuid;
                    if (documentUuid) {
                        this.downloadDocument(documentUuid);
                    } else {
                        console.error('Download: documentUuid nicht gefunden', btn);
                    }
                });
            });
            
            // Event-Handler für Delete-Buttons
            container.querySelectorAll('.btn-delete-document').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    // Button selbst hat das dataset, nicht das span
                    const documentUuid = btn.dataset.documentUuid;
                    if (documentUuid) {
                        this.deleteDocument(documentUuid, entityType, entityUuid, containerSelector);
                    } else {
                        console.error('Delete: documentUuid nicht gefunden', btn);
                    }
                });
            });
            
        } catch (error) {
            console.error('Fehler beim Laden der Dokumente:', error);
            container.innerHTML = `
                <div class="error-message">
                    Fehler beim Laden der Dokumente: ${Utils.escapeHtml(error.message)}
                </div>
            `;
        }
    }
    
    /**
     * Rendert Dokumenten-Liste (Legacy-Methode, verwendet loadDocuments)
     */
    async renderDocuments(entityType, entityUuid, containerId) {
        await this.loadDocuments(entityType, entityUuid, `#${containerId}`, null);
    }
    
    /**
     * Rendert HTML für Dokumenten-Liste
     */
    renderDocumentsHTML(documents) {
        if (!documents || documents.length === 0) {
            return `
                <div class="documents-empty">
                    <p>Keine Dokumente vorhanden</p>
                </div>
            `;
        }
        
        return `
            <div class="documents-list">
                ${documents.map(doc => this.renderDocumentItem(doc)).join('')}
            </div>
        `;
    }
    
    /**
     * Rendert einzelnes Dokument-Item
     */
    renderDocumentItem(doc) {
        const statusBadge = this.getStatusBadge(doc.scan_status);
        const fileSize = doc.size_bytes ? this.formatFileSize(doc.size_bytes) : '';
        const uploadDate = doc.created_at ? new Date(doc.created_at).toLocaleDateString('de-DE') : '';
        const mimeIcon = this.getMimeIcon(doc.mime_detected);
        
        const canDownload = doc.scan_status === 'clean';
        const canPreview = canDownload && (doc.mime_detected === 'application/pdf' || doc.mime_detected?.startsWith('image/'));
        
        let actionButtons = '';
        if (canDownload) {
            if (canPreview) {
                // Preview-Button für PDFs und Bilder
                actionButtons = `
                    <button class="btn-preview-document btn-sm" data-document-uuid="${doc.document_uuid}" data-mime="${doc.mime_detected || ''}" title="In neuem Tab anzeigen">
                        <span>👁</span>
                    </button>
                    <button class="btn-download-document btn-sm" data-document-uuid="${doc.document_uuid}" title="Download">
                        <span>⬇</span>
                    </button>
                `;
            } else {
                // Nur Download für andere Dateitypen
                actionButtons = `
                    <button class="btn-download-document btn-sm" data-document-uuid="${doc.document_uuid}" title="Download">
                        <span>⬇</span>
                    </button>
                `;
            }
        } else {
            actionButtons = `<span class="text-muted" title="Download nicht verfügbar (Status: ${doc.scan_status})">-</span>`;
        }
        
        return `
            <div class="document-item" data-document-uuid="${doc.document_uuid}">
                <div class="document-icon">${mimeIcon}</div>
                <div class="document-info">
                    <div class="document-title">${Utils.escapeHtml(doc.title)}</div>
                    <div class="document-meta">
                        ${statusBadge}
                        ${fileSize ? `<span class="document-size">${fileSize}</span>` : ''}
                        ${uploadDate ? `<span class="document-date">${uploadDate}</span>` : ''}
                        ${doc.role ? `<span class="document-role">${Utils.escapeHtml(doc.role)}</span>` : ''}
                    </div>
                    ${doc.description ? `<div class="document-description">${Utils.escapeHtml(doc.description)}</div>` : ''}
                </div>
                <div class="document-actions">
                    ${actionButtons}
                    <button class="btn-delete-document btn-sm btn-danger" data-document-uuid="${doc.document_uuid}" title="Löschen">
                        <span>🗑</span>
                    </button>
                </div>
            </div>
        `;
    }
    
    /**
     * Status-Badge rendern
     */
    getStatusBadge(scanStatus) {
        const badges = {
            'pending': '<span class="badge badge-warning">Wird geprüft...</span>',
            'clean': '<span class="badge badge-success">✓ Verfügbar</span>',
            'infected': '<span class="badge badge-error">⚠ Blockiert</span>',
            'unsupported': '<span class="badge badge-error">Nicht unterstützt</span>',
            'error': '<span class="badge badge-warning">Fehler</span>'
        };
        return badges[scanStatus] || '<span class="badge">Unbekannt</span>';
    }
    
    /**
     * MIME-Icon
     */
    getMimeIcon(mime) {
        if (!mime) return '📄';
        
        if (mime.startsWith('image/')) return '🖼';
        if (mime === 'application/pdf') return '📕';
        if (mime.includes('word') || mime.includes('document')) return '📝';
        if (mime.includes('excel') || mime.includes('spreadsheet')) return '📊';
        if (mime.includes('powerpoint') || mime.includes('presentation')) return '📽';
        if (mime.includes('zip') || mime.includes('archive')) return '📦';
        
        return '📄';
    }
    
    /**
     * Dateigröße formatieren
     */
    formatFileSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
    
    /**
     * Dokument herunterladen
     */
    async downloadDocument(documentUuid) {
        try {
            // API-Endpunkt für Download (relativ zur aktuellen URL)
            const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '');
            const url = `${baseUrl}/api/documents/${documentUuid}/download`;
            window.open(url, '_blank');
        } catch (error) {
            console.error('Fehler beim Download:', error);
            alert('Fehler beim Download: ' + error.message);
        }
    }
    
    /**
     * Dokument in neuem Tab anzeigen (Preview)
     */
    async previewDocument(documentUuid, mimeType) {
        try {
            // Für PDFs und Bilder: In neuem Tab anzeigen
            if (mimeType === 'application/pdf' || mimeType?.startsWith('image/')) {
                const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '');
                const url = `${baseUrl}/api/documents/${documentUuid}/view`;
                window.open(url, '_blank');
            } else {
                // Für andere Dateitypen: Download
                await this.downloadDocument(documentUuid);
            }
        } catch (error) {
            console.error('Fehler beim Anzeigen:', error);
            alert('Fehler beim Anzeigen: ' + error.message);
        }
    }
    
    /**
     * Dokument löschen
     */
    async deleteDocument(documentUuid, entityType, entityUuid, containerSelector = null) {
        if (!confirm('Möchten Sie dieses Dokument wirklich löschen?')) {
            return;
        }
        
        try {
            await window.API.deleteDocument(documentUuid);
            
            // Liste neu laden - finde Container automatisch
            const container = document.querySelector('#org-documents-list') || 
                             document.querySelector('#person-documents-list') ||
                             document.querySelector('.documents-list') ||
                             document.querySelector(containerSelector || '#documents-container');
            
            if (container) {
                const selector = container.id ? `#${container.id}` : containerSelector || '#documents-container';
                await this.loadDocuments(entityType, entityUuid, selector, null);
            } else {
                // Fallback: renderDocuments
                await this.renderDocuments(entityType, entityUuid, 'documents-container');
            }
            
            // Erfolgs-Meldung
            if (this.app && this.app.showNotification) {
                this.app.showNotification('Dokument erfolgreich gelöscht', 'success');
            } else {
                alert('Dokument erfolgreich gelöscht');
            }
        } catch (error) {
            console.error('Fehler beim Löschen:', error);
            alert('Fehler beim Löschen: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Startet Auto-Refresh, wenn Dokumente mit "pending" Status vorhanden sind
     */
    startAutoRefreshIfNeeded(entityType, entityUuid, containerSelector, badgeSelector, documents) {
        // Prüfe, ob es Dokumente mit "pending" Status gibt
        const hasPending = documents?.some(doc => doc.scan_status === 'pending');
        
        const key = `${entityType}:${entityUuid}`;
        
        // Wenn keine pending-Dokumente mehr vorhanden: Stoppe Auto-Refresh
        if (!hasPending) {
            if (this.refreshIntervals.has(key)) {
                console.log(`[DocumentList] Stoppe Auto-Refresh für ${entityType}:${entityUuid} - keine pending-Dokumente mehr`);
                clearInterval(this.refreshIntervals.get(key));
                this.refreshIntervals.delete(key);
            }
            return;
        }
        
        // Wenn pending-Dokumente vorhanden: Starte oder setze Auto-Refresh fort
        if (hasPending) {
            // Prüfe, ob bereits ein Interval läuft
            if (!this.refreshIntervals.has(key)) {
                const pendingCount = documents.filter(d => d.scan_status === 'pending').length;
                console.log(`[DocumentList] Starte Auto-Refresh für ${entityType}:${entityUuid} - ${pendingCount} pending-Dokument(e)`);
                
                // Alle 10 Sekunden prüfen (Scan-Worker läuft alle 5 Minuten, aber Status kann sich schneller ändern)
                const intervalId = setInterval(() => {
                    // Lade Dokumente neu und prüfe, ob noch pending-Dokumente vorhanden sind
                    this.loadDocuments(entityType, entityUuid, containerSelector, badgeSelector);
                }, 10000); // 10 Sekunden
                
                this.refreshIntervals.set(key, intervalId);
                
                // Stoppe nach 10 Minuten (maximale Wartezeit für Scan - erhöht von 5 auf 10 Minuten)
                setTimeout(() => {
                    if (this.refreshIntervals.has(key)) {
                        console.log(`[DocumentList] Auto-Refresh Timeout für ${entityType}:${entityUuid} - stoppe nach 10 Minuten`);
                        clearInterval(this.refreshIntervals.get(key));
                        this.refreshIntervals.delete(key);
                    }
                }, 10 * 60 * 1000); // 10 Minuten
            }
            // Wenn Interval bereits läuft: nichts zu tun (keine Log-Meldung, um Spam zu vermeiden)
        }
    }
    
    /**
     * Stoppt Auto-Refresh für eine Entität
     */
    stopAutoRefresh(entityType, entityUuid) {
        const key = `${entityType}:${entityUuid}`;
        if (this.refreshIntervals.has(key)) {
            clearInterval(this.refreshIntervals.get(key));
            this.refreshIntervals.delete(key);
        }
    }
}
