/**
 * TOM3 - Import Overview Module
 * Verwaltet die Übersichtsseite mit Batch-Liste
 */

import { Utils } from './utils.js';

export class ImportOverviewModule {
    constructor(importModule) {
        this.importModule = importModule; // Referenz zum Haupt-Import-Modul
    }
    
    /**
     * Rendert Übersichtsseite mit allen Batches
     */
    async renderOverviewPage(container) {
        try {
            container.innerHTML = `
                <div class="page-header">
                    <h2>📥 Import-Verwaltung</h2>
                    <p class="page-description">Verwalten Sie Ihre Import-Batches oder starten Sie einen neuen Import</p>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <button class="btn btn-primary" onclick="window.app.import.startNewImport()">
                        ➕ Neuen Import starten
                    </button>
                </div>
                
                <div id="import-batches-list">
                    <p>Lade Batches...</p>
                </div>
            `;
            
            await this.loadBatchesList();
        } catch (error) {
            console.error('Error rendering overview:', error);
            Utils.showError('Fehler beim Laden der Übersicht');
        }
    }
    
    /**
     * Lädt Batch-Liste
     */
    async loadBatchesList() {
        try {
            const response = await fetch('/tom3/public/api/import/batches');
            if (!response.ok) {
                throw new Error('Fehler beim Laden der Batches');
            }
            
            const data = await response.json();
            this.renderBatchesList(data.batches || []);
        } catch (error) {
            console.error('Error loading batches:', error);
            const container = document.getElementById('import-batches-list');
            if (container) {
                container.innerHTML = `
                    <div class="error-message">Fehler beim Laden der Batches: ${error.message}</div>
                `;
            }
        }
    }
    
    /**
     * Rendert Batch-Liste als Tabelle
     */
    renderBatchesList(batches) {
        const container = document.getElementById('import-batches-list');
        if (!container) return;
        
        if (batches.length === 0) {
            container.innerHTML = `
                <div style="padding: 24px; text-align: center; background: #f5f5f5; border-radius: 8px;">
                    <p style="margin: 0; color: #666;">Keine Batches gefunden.</p>
                    <p style="margin: 8px 0 0 0;">Starten Sie einen neuen Import, um zu beginnen.</p>
                </div>
            `;
            return;
        }
        
        const statusLabels = {
            'DRAFT': 'Entwurf',
            'STAGED': 'In Staging',
            'IN_REVIEW': 'In Prüfung',
            'APPROVED': 'Freigegeben',
            'IMPORTED': 'Importiert'
        };
        
        const statusColors = {
            'DRAFT': '#6c757d',
            'STAGED': '#0d6efd',
            'IN_REVIEW': '#ffc107',
            'APPROVED': '#198754',
            'IMPORTED': '#198754'
        };
        
        // Sortiere nach Datum (neueste zuerst)
        batches.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        
        let html = `
            <div style="overflow-x: auto;">
                <table class="table" style="width: 100%; border-collapse: collapse; background: white;">
                    <thead>
                        <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Dateiname</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Durchgeführt von</th>
                            <th style="padding: 12px; text-align: left; font-weight: 600;">Datum/Uhrzeit</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600;">Status</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600;">Zeilen</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        batches.forEach(batch => {
            const stats = batch.stats || {};
            const pendingCount = stats.pending_rows || 0;
            const approvedCount = stats.approved_rows || 0;
            const importedCount = stats.imported_rows || 0;
            const totalCount = stats.total_rows || 0;
            // Batch kann gelöscht werden, wenn nicht ALLE Rows importiert wurden
            const canDelete = batch.status !== 'IMPORTED' && importedCount < totalCount;
            
            // User-Name anzeigen
            const userName = batch.uploaded_by_name || batch.uploaded_by_email || `User ${batch.uploaded_by_user_id}`;
            
            // Datum formatieren
            const date = new Date(batch.created_at);
            const dateStr = date.toLocaleDateString('de-DE');
            const timeStr = date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
            
            // Status-Badge
            const statusLabel = statusLabels[batch.status] || batch.status;
            const statusColor = statusColors[batch.status] || '#6c757d';
            
            // Bestimme, ob Batch anklickbar ist
            const isClickable = batch.status !== 'IMPORTED' || (stats.pending_rows || 0) > 0 || (stats.approved_rows || 0) > 0;
            const rowStyle = isClickable 
                ? "border-bottom: 1px solid #dee2e6; cursor: pointer;" 
                : "border-bottom: 1px solid #dee2e6; cursor: default; opacity: 0.7;";
            const onClick = isClickable 
                ? `onclick="window.app.import.openBatch('${batch.batch_uuid}')"`
                : '';
            
            html += `
                <tr style="${rowStyle}"
                    ${onClick}
                    onmouseover="${isClickable ? "this.style.background='#f8f9fa'" : ""}" 
                    onmouseout="${isClickable ? "this.style.background='white'" : ""}">
                    <td style="padding: 12px;">
                        <strong>${this.escapeHtml(batch.filename || 'Unbenannt')}</strong>
                        ${batch.status === 'IMPORTED' && !isClickable ? '<br><small style="color: #666;">✅ Vollständig importiert</small>' : ''}
                    </td>
                    <td style="padding: 12px;">
                        ${this.escapeHtml(userName)}
                    </td>
                    <td style="padding: 12px;">
                        ${dateStr}<br>
                        <small style="color: #666;">${timeStr}</small>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <span class="badge" style="background: ${statusColor}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                            ${statusLabel}
                        </span>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <div style="font-size: 13px;">
                            <div><strong>${totalCount}</strong> gesamt</div>
                            ${pendingCount > 0 ? `<div style="color: #ffc107; font-size: 11px;">${pendingCount} pending</div>` : ''}
                            ${approvedCount > 0 ? `<div style="color: #198754; font-size: 11px;">${approvedCount} approved</div>` : ''}
                            ${stats.skipped_rows > 0 ? `<div style="color: #6c757d; font-size: 11px;">${stats.skipped_rows} übersprungen</div>` : ''}
                            ${importedCount > 0 ? `<div style="color: #198754; font-size: 11px;">${importedCount} importiert</div>` : ''}
                        </div>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        <div style="display: flex; gap: 8px; justify-content: center; align-items: center;">
                            ${isClickable ? `
                                <button class="btn btn-sm btn-primary" 
                                        onclick="event.stopPropagation(); window.app.import.openBatch('${batch.batch_uuid}')"
                                        style="padding: 4px 12px; font-size: 12px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer;"
                                        title="Öffnen">
                                    ${batch.status === 'IMPORTED' ? 'Details' : 'Öffnen'}
                                </button>
                            ` : `
                                <span style="color: #666; font-size: 12px;">Abgeschlossen</span>
                            `}
                            ${canDelete ? `
                                <button class="btn btn-sm btn-danger" 
                                        onclick="event.stopPropagation(); window.app.import.deleteBatch('${batch.batch_uuid}', '${this.escapeHtml(batch.filename || 'Unbenannt')}')"
                                        style="padding: 4px 8px; font-size: 12px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;"
                                        title="Löschen">
                                    🗑️
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
    }
    
    /**
     * Zeigt Zusammenfassung für vollständig importierte Batches
     */
    showImportSummary(batch) {
        const page = document.getElementById('page-import');
        if (!page) return;
        
        const stats = batch.stats || {};
        
        page.innerHTML = `
            <div class="page-header">
                <h2>📥 Import-Zusammenfassung</h2>
            </div>
            
            <div style="margin-bottom: 24px;">
                <button class="btn btn-secondary" onclick="window.app.import.showOverview()">
                    ← Zurück zur Übersicht
                </button>
            </div>
            
            <div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 24px;">
                <h3 style="margin-top: 0;">${this.escapeHtml(batch.filename || 'Unbenannt')}</h3>
                
                <div style="margin-top: 20px;">
                    <p><strong>Status:</strong> <span style="color: #198754; font-weight: bold;">✅ Importiert</span></p>
                    <p><strong>Importiert am:</strong> ${batch.imported_at ? new Date(batch.imported_at).toLocaleString('de-DE') : 'N/A'}</p>
                    <p><strong>Erstellt am:</strong> ${new Date(batch.created_at).toLocaleString('de-DE')}</p>
                </div>
                
                <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #eee;">
                    <h4>Statistiken:</h4>
                    <ul style="list-style: none; padding: 0;">
                        <li style="padding: 8px 0;"><strong>Gesamt Zeilen:</strong> ${stats.total_rows || 0}</li>
                        <li style="padding: 8px 0;"><strong>Importiert:</strong> <span style="color: #198754;">${stats.imported_rows || 0}</span></li>
                        ${stats.failed_rows > 0 ? `<li style="padding: 8px 0;"><strong>Fehlgeschlagen:</strong> <span style="color: #dc3545;">${stats.failed_rows}</span></li>` : ''}
                    </ul>
                </div>
                
                <div style="margin-top: 24px; padding: 16px; background: #d4edda; border-radius: 4px; color: #155724;">
                    <p style="margin: 0;"><strong>✅ Import abgeschlossen</strong></p>
                    <p style="margin: 8px 0 0 0;">Alle Daten wurden erfolgreich in die Produktivtabellen importiert.</p>
                </div>
            </div>
        `;
    }
    
    /**
     * Escaped HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

