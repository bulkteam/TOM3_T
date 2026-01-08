/**
 * TOM3 - Import Review Module
 * Handles review and approval step
 */

import { Utils } from './utils.js';

export class ImportReviewModule {
    constructor(importModule) {
        this.importModule = importModule; // Referenz zum Haupt-Import-Modul
    }
    
    /**
     * Rendert Review-Step (Schritt 4)
     */
    async renderReviewStep() {
        const container = document.getElementById('review-content');
        if (!container || !this.importModule.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Staging-Daten...</p>';
        
        try {
            // Lade Batch-Status
            const batchResponse = await fetch(`${window.API?.baseUrl || '/api'}/import/batch/${this.importModule.currentBatch}/stats`);
            if (!batchResponse.ok) {
                throw new Error('Batch nicht gefunden');
            }
            const batch = await batchResponse.json();
            const batchStats = batch.stats || {};
            
            // Lade Staging-Rows (bereits importiert und angereichert)
            const stagingRows = await this.loadStagingRows(this.importModule.currentBatch);
            this.importModule.stagingRows = stagingRows;
            
            if (stagingRows.length === 0) {
                container.innerHTML = '<p class="error">Keine Staging-Daten gefunden.</p>';
                return;
            }
            
            // Filtere nur nicht-importierte Rows für Review (und nicht-skipped)
            const pendingRows = stagingRows.filter(row => 
                row.import_status !== 'imported' && 
                row.disposition !== 'skip'
            );
            
            // Wenn alle importiert sind, zeige Zusammenfassung
            if (pendingRows.length === 0 && batch.status === 'IMPORTED') {
                container.innerHTML = `
                    <div style="padding: 24px; background: #d4edda; border-radius: 8px; color: #155724;">
                        <h3 style="margin-top: 0;">✅ Import abgeschlossen</h3>
                        <p><strong>${batchStats.imported_rows || 0}</strong> Organisationen wurden erfolgreich importiert.</p>
                        <p style="margin-top: 16px;">
                            <button class="btn btn-secondary" onclick="window.app.import.overviewModule.showOverview()">
                                Zurück zur Übersicht
                            </button>
                        </p>
                    </div>
                `;
                return;
            }
            
            // Rendere Review-UI (nur für nicht-importierte Rows)
            const rowsToShow = stagingRows.filter(row => row.import_status !== 'imported');
            
            container.innerHTML = this.renderReviewUI(rowsToShow, { 
                total_rows: stagingRows.length,
                imported: batchStats.imported_rows || 0,
                pending: batchStats.pending_rows || 0,
                approved: batchStats.approved_rows || 0
            });
            
            // Zeige Commit-Button wenn pending oder approved Rows vorhanden sind UND noch nicht alles importiert
            const commitBtn = document.getElementById('commit-btn');
            if (commitBtn) {
                const hasPendingRows = (batchStats.pending_rows || 0) > 0;
                const hasApprovedRows = (batchStats.approved_rows || 0) > 0;
                const isNotFullyImported = batch.status !== 'IMPORTED' || hasPendingRows || hasApprovedRows;
                
                if ((hasPendingRows || hasApprovedRows) && isNotFullyImported) {
                    commitBtn.style.display = 'inline-block';
                    commitBtn.textContent = hasApprovedRows 
                        ? 'Freigegebene Zeilen importieren →' 
                        : 'Alle freigeben & importieren →';
                } else {
                    commitBtn.style.display = 'none';
                }
            }
            
        } catch (error) {
            console.error('Error loading review:', error);
            container.innerHTML = `<p class="error">Fehler beim Laden: ${error.message}</p>`;
        }
    }
    
    /**
     * Lädt Staging-Rows für Batch
     */
    async loadStagingRows(batchUuid) {
        try {
            const response = await fetch(`${window.API?.baseUrl || '/api'}/import/batch/${batchUuid}/staging-rows`);
            
            if (!response.ok) {
                return [];
            }
            
            const result = await response.json();
            return result.rows || [];
            
        } catch (error) {
            console.error('Error loading staging rows:', error);
            return [];
        }
    }
    
    /**
     * Rendert Review-UI
     */
    renderReviewUI(stagingRows, stats) {
        if (stagingRows.length === 0) {
            return '<p class="info">Keine Staging-Rows gefunden.</p>';
        }
        
        let html = '<div class="review-content">';
        html += '<div class="review-stats">';
        html += `<p><strong>Gesamt:</strong> ${stats?.total_rows || stagingRows.length} Zeilen</p>`;
        html += `<p><strong>Importiert:</strong> ${stats?.imported || 0} Zeilen</p>`;
        if (stats?.errors > 0) {
            html += `<p class="error"><strong>Fehler:</strong> ${stats.errors} Zeilen</p>`;
        }
        html += '</div>';
        
        html += '<div class="review-table-container">';
        html += '<table class="review-table" style="width: 100%; table-layout: auto;">';
        html += '<thead><tr>';
        html += '<th style="width: 60px;">Zeile</th>';
        html += '<th style="width: 200px;">Name</th>';
        html += '<th style="width: 180px;">Website</th>';
        html += '<th style="width: 100px;">Status</th>';
        html += '<th style="width: 100px;">Duplikat</th>';
        html += '<th style="width: 220px; min-width: 220px;">Freigabe</th>';
        html += '<th style="width: 100px;">Aktion</th>';
        html += '</tr></thead>';
        html += '<tbody>';
        
        const visibleRows = stagingRows.filter(row => row.import_status !== 'imported');
        
        visibleRows.forEach((row, index) => {
            const mappedData = row.mapped_data || {};
            const orgData = mappedData.org || {};
            const validationStatus = row.validation_status || 'pending';
            const duplicateStatus = row.duplicate_status || 'unknown';
            const disposition = row.disposition || 'pending';
            const isImported = row.import_status === 'imported';
            
            // Disposition-Badge
            let dispositionBadge = '';
            if (disposition === 'approved') {
                dispositionBadge = '<span class="badge" style="background: #198754; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">✅ Freigegeben</span>';
            } else if (disposition === 'skip') {
                dispositionBadge = '<span class="badge" style="background: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">⏭️ Wird nicht importiert</span>';
            } else if (disposition === 'needs_fix') {
                dispositionBadge = '<span class="badge" style="background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">⚠️ Muss korrigiert werden</span>';
            } else {
                dispositionBadge = '<span class="badge" style="background: #ffc107; color: #000; padding: 4px 8px; border-radius: 4px; font-size: 11px;">⏳ Pending</span>';
            }
            
            // Action-Buttons für Disposition
            let dispositionActions = '';
            if (!isImported) {
                if (disposition !== 'approved') {
                    dispositionActions += `<button class="btn btn-sm btn-success" onclick="window.app.import.reviewModule.setRowDisposition('${row.staging_uuid}', 'approved')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Freigeben">✓</button>`;
                }
                if (disposition !== 'skip') {
                    dispositionActions += `<button class="btn btn-sm btn-secondary" onclick="window.app.import.reviewModule.setRowDisposition('${row.staging_uuid}', 'skip')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Überspringen">⏭</button>`;
                }
                if (disposition !== 'needs_fix') {
                    dispositionActions += `<button class="btn btn-sm btn-warning" onclick="window.app.import.reviewModule.setRowDisposition('${row.staging_uuid}', 'needs_fix')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Muss korrigiert werden">⚠</button>`;
                }
                if (disposition !== 'pending') {
                    dispositionActions += `<button class="btn btn-sm btn-outline-secondary" onclick="window.app.import.reviewModule.setRowDisposition('${row.staging_uuid}', 'pending')" style="padding: 2px 8px; font-size: 11px;" title="Zurücksetzen">↺</button>`;
                }
            } else {
                dispositionActions = '<span style="color: #666; font-size: 11px;">Bereits importiert</span>';
            }
            
            html += '<tr style="min-height: 80px; height: auto;">';
            html += `<td style="vertical-align: top; padding: 12px 8px;">${row.row_number}</td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;">${orgData.name || '-'}</td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;">${orgData.website || '-'}</td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;"><span class="status-badge status-${validationStatus}">${validationStatus}</span></td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;"><span class="duplicate-badge duplicate-${duplicateStatus}">${duplicateStatus}</span></td>`;
            html += `<td style="min-width: 220px; white-space: normal; vertical-align: top; padding: 12px 8px;">${dispositionBadge}<br><div style="margin-top: 4px;">${dispositionActions}</div></td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;"><button class="btn btn-sm" onclick="window.app.import.reviewModule.showRowDetail('${row.staging_uuid}')">Details</button></td>`;
            html += '</tr>';
        });
        
        html += '</tbody>';
        html += '</table>';
        html += '</div>';
        html += '</div>';
        
        return html;
    }
    
    /**
     * Setzt Disposition für eine einzelne Staging-Row
     */
    async setRowDisposition(stagingUuid, disposition) {
        try {
            Utils.showInfo('Setze Disposition...');
            
            const response = await this.importModule.fetchWithToken(`${window.API?.baseUrl || '/api'}/import/staging/${stagingUuid}/disposition`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    disposition: disposition
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Setzen der Disposition');
            }
            
            const labels = {
                'approved': 'freigegeben',
                'skip': 'übersprungen',
                'needs_fix': 'als "muss korrigiert werden" markiert',
                'pending': 'auf pending zurückgesetzt'
            };
            
            Utils.showSuccess(`Zeile wurde ${labels[disposition] || disposition}.`);
            
            // Aktualisiere die Row in der Tabelle direkt
            await this.updateRowInTable(stagingUuid);
            
        } catch (error) {
            console.error('Error setting disposition:', error);
            Utils.showError('Fehler: ' + error.message);
        }
    }
    
    /**
     * Aktualisiert eine einzelne Row in der Review-Tabelle
     */
    async updateRowInTable(stagingUuid) {
        try {
            const row = await window.API.request(`/import/staging/${stagingUuid}`);
            
            if (!row) return;
            
            const table = document.querySelector('.review-table tbody');
            if (!table) {
                await this.renderReviewStep();
                return;
            }
            
            const rows = table.querySelectorAll('tr');
            let targetRow = null;
            for (const tr of rows) {
                const detailsBtn = tr.querySelector(`button[onclick*="${stagingUuid}"]`);
                if (detailsBtn) {
                    targetRow = tr;
                    break;
                }
            }
            
            if (!targetRow) {
                await this.renderReviewStep();
                return;
            }
            
            const effectiveData = row.effective_data || row.mapped_data || {};
            const mappedData = row.mapped_data || {};
            const orgData = effectiveData.org || mappedData.org || {};
            const validationStatus = row.validation_status || 'pending';
            const duplicateStatus = row.duplicate_status || 'unknown';
            const disposition = row.disposition || row.review_status || 'pending';
            const isImported = row.import_status === 'imported';
            
            // Disposition-Badge
            let dispositionBadge = '';
            if (disposition === 'approved') {
                dispositionBadge = '<span class="badge" style="background: #198754; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">✅ Freigegeben</span>';
            } else if (disposition === 'skip') {
                dispositionBadge = '<span class="badge" style="background: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">⏭️ Wird nicht importiert</span>';
            } else if (disposition === 'needs_fix') {
                dispositionBadge = '<span class="badge" style="background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">⚠️ Muss korrigiert werden</span>';
            } else {
                dispositionBadge = '<span class="badge" style="background: #ffc107; color: #000; padding: 4px 8px; border-radius: 4px; font-size: 11px;">⏳ Pending</span>';
            }
            
            // Action-Buttons für Disposition
            let dispositionActions = '';
            if (!isImported) {
                if (disposition !== 'approved') {
                    dispositionActions += `<button class="btn btn-sm btn-success" onclick="window.app.import.reviewModule.setRowDisposition('${stagingUuid}', 'approved')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Freigeben">✓</button>`;
                }
                if (disposition !== 'skip') {
                    dispositionActions += `<button class="btn btn-sm btn-secondary" onclick="window.app.import.reviewModule.setRowDisposition('${stagingUuid}', 'skip')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Überspringen">⏭</button>`;
                }
                if (disposition !== 'needs_fix') {
                    dispositionActions += `<button class="btn btn-sm btn-warning" onclick="window.app.import.reviewModule.setRowDisposition('${stagingUuid}', 'needs_fix')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Muss korrigiert werden">⚠</button>`;
                }
                if (disposition !== 'pending') {
                    dispositionActions += `<button class="btn btn-sm btn-outline-secondary" onclick="window.app.import.reviewModule.setRowDisposition('${stagingUuid}', 'pending')" style="padding: 2px 8px; font-size: 11px;" title="Zurücksetzen">↺</button>`;
                }
            } else {
                dispositionActions = '<span style="color: #666; font-size: 11px;">Bereits importiert</span>';
            }
            
            // Aktualisiere alle relevanten Spalten
            const cells = targetRow.querySelectorAll('td');
            if (cells.length >= 7) {
                cells[1].textContent = orgData.name || '-';
                cells[2].textContent = orgData.website || '-';
                cells[5].innerHTML = `${dispositionBadge}<br><div style="margin-top: 4px;">${dispositionActions}</div>`;
            }
            
            // Aktualisiere auch den Cache
            if (this.importModule.stagingRows) {
                const index = this.importModule.stagingRows.findIndex(r => r.staging_uuid === stagingUuid);
                if (index !== -1) {
                    this.importModule.stagingRows[index] = row;
                }
            }
            
            if (typeof this.updateCommitButton === 'function') {
                this.updateCommitButton();
            }
            
        } catch (error) {
            console.error('Error updating row in table:', error);
            await this.renderReviewStep();
        }
    }
    
    /**
     * Zeigt Detail-Ansicht für eine Staging-Row
     */
    async showRowDetail(stagingUuid) {
        try {
            console.log('Loading row detail for:', stagingUuid);
            const row = await window.API.request(`/import/staging/${stagingUuid}`);
            
            if (!row) {
                Utils.showError('Staging-Row nicht gefunden');
                return;
            }
            
            // Entferne vorhandenes Modal, falls vorhanden
            const existingModal = document.querySelector('.modal-overlay[data-staging-detail]');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Zeige Modal mit Details
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.setAttribute('data-staging-detail', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px;';
            
            const effectiveData = row.effective_data || row.mapped_data || {};
            const orgData = effectiveData.org || {};
            const addressData = effectiveData.address || {};
            const communicationData = effectiveData.communication || {};
            const industryData = effectiveData.industry || {};
            const industryResolution = row.industry_resolution || {};
            const decision = industryResolution.decision || {};
            const suggestions = industryResolution.suggestions || {};
            const hasCorrections = row.corrections && Object.keys(row.corrections).length > 0;
            
            // Baue Adress-String
            const addressParts = [];
            if (addressData.street) addressParts.push(addressData.street);
            if (addressData.postal_code || addressData.city) {
                addressParts.push([addressData.postal_code, addressData.city].filter(Boolean).join(' '));
            }
            if (addressData.state) addressParts.push(addressData.state);
            const fullAddress = addressParts.length > 0 ? addressParts.join(', ') : '-';
            
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto; background: white; border-radius: 8px; padding: 24px; position: relative;">
                    <button class="btn-close" onclick="this.closest('.modal-overlay').remove()" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
                    <h2 style="margin-top: 0;">Staging-Row Details</h2>
                    <p><strong>Zeile:</strong> ${row.row_number || '-'} | <strong>Staging UUID:</strong> <code style="font-size: 0.85em;">${stagingUuid}</code></p>
                    ${hasCorrections ? `<p style="color: #198754; font-weight: 600; margin-top: 8px;">✅ Korrekturen vorhanden (werden beim Import verwendet)</p>` : ''}
                    
                    <div style="margin-top: 20px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Organisationsdaten ${hasCorrections && row.corrections.org ? '<span style="color: #198754; font-size: 0.85em;">(korrigiert)</span>' : ''}</h3>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            <tr><td style="padding: 8px; border-bottom: 1px solid #eee; width: 180px;"><strong>Name:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(orgData.name || '-')}</td></tr>
                            ${orgData.vat_id ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>USt-IdNr.:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(orgData.vat_id)}</td></tr>` : ''}
                            ${orgData.employee_count ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Mitarbeiter:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.employee_count}</td></tr>` : ''}
                            ${orgData.revenue_range ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Umsatz:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.revenue_range}</td></tr>` : ''}
                            ${orgData.website ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Website:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="${Utils.escapeHtml(orgData.website)}" target="_blank">${Utils.escapeHtml(orgData.website)}</a></td></tr>` : ''}
                            ${orgData.notes ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Notizen:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(orgData.notes)}</td></tr>` : ''}
                        </table>
                    </div>
                    
                    ${(addressData.street || addressData.postal_code || addressData.city || addressData.state) ? `
                    <div style="margin-top: 20px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Adresse</h3>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            ${addressData.street ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee; width: 180px;"><strong>Straße:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(addressData.street)}</td></tr>` : ''}
                            ${addressData.postal_code || addressData.city ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>PLZ / Ort:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml([addressData.postal_code, addressData.city].filter(Boolean).join(' '))}</td></tr>` : ''}
                            ${addressData.state ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Bundesland:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(addressData.state)}</td></tr>` : ''}
                            <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Vollständige Adresse:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(fullAddress)}</td></tr>
                        </table>
                    </div>
                    ` : ''}
                    
                    ${(communicationData.email || communicationData.phone || communicationData.fax) ? `
                    <div style="margin-top: 20px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Kontaktdaten</h3>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            ${communicationData.email ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee; width: 180px;"><strong>E-Mail:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="mailto:${Utils.escapeHtml(communicationData.email)}">${Utils.escapeHtml(communicationData.email)}</a></td></tr>` : ''}
                            ${communicationData.phone ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Telefon:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="tel:${Utils.escapeHtml(communicationData.phone)}">${Utils.escapeHtml(communicationData.phone)}</a></td></tr>` : ''}
                            ${communicationData.fax ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Fax:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${Utils.escapeHtml(communicationData.fax)}</td></tr>` : ''}
                        </table>
                    </div>
                    ` : ''}
                    
                    ${industryResolution.excel || industryResolution.suggestions || decision.status || industryData.excel_level2_label || industryData.excel_level3_label ? `
                    <div style="margin-top: 24px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Branchenzuordnung</h3>
                        ${(industryResolution.excel && (industryResolution.excel.level2_label || industryResolution.excel.level3_label)) || industryData.excel_level2_label || industryData.excel_level3_label ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Excel-Labels (aus Datei):</strong></p>
                                <ul style="margin-left: 20px;">
                                    ${(industryResolution.excel?.level2_label || industryData.excel_level2_label) ? `<li><strong>Level 2:</strong> ${Utils.escapeHtml(industryResolution.excel?.level2_label || industryData.excel_level2_label)}</li>` : ''}
                                    ${(industryResolution.excel?.level3_label || industryData.excel_level3_label) ? `<li><strong>Level 3:</strong> ${Utils.escapeHtml(industryResolution.excel?.level3_label || industryData.excel_level3_label)}</li>` : ''}
                                </ul>
                            </div>
                        ` : ''}
                        ${suggestions.level2_candidates && suggestions.level2_candidates.length > 0 ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Vorschläge Level 2:</strong></p>
                                <ul style="margin-left: 20px;">
                                    ${suggestions.level2_candidates.slice(0, 3).map(c => `<li>${Utils.escapeHtml(c.name)} (Score: ${c.score?.toFixed(2) || 'N/A'})</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${suggestions.level3_candidates && suggestions.level3_candidates.length > 0 ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Vorschläge Level 3:</strong></p>
                                <ul style="margin-left: 20px;">
                                    ${suggestions.level3_candidates.slice(0, 3).map(c => `<li>${Utils.escapeHtml(c.name)} (Score: ${c.score?.toFixed(2) || 'N/A'})</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${suggestions.derived_level1 ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Abgeleitetes Level 1:</strong> ${Utils.escapeHtml(suggestions.derived_level1.name || suggestions.derived_level1.code || 'N/A')}</p>
                            </div>
                        ` : ''}
                        ${decision.status ? `
                            <div style="margin-top: 16px; padding: 12px; background: ${decision.status === 'APPROVED' ? '#d4edda' : '#fff3cd'}; border-radius: 4px;">
                                <p style="margin: 0;"><strong>Entscheidungs-Status:</strong> <span style="font-weight: bold; color: ${decision.status === 'APPROVED' ? '#155724' : '#856404'};"><code>${Utils.escapeHtml(decision.status)}</code></span></p>
                                ${decision.level1_uuid ? `<p style="margin: 4px 0 0 0;"><strong>Level 1 UUID:</strong> <code style="font-size: 0.85em;">${Utils.escapeHtml(decision.level1_uuid)}</code></p>` : ''}
                                ${decision.level2_uuid ? `<p style="margin: 4px 0 0 0;"><strong>Level 2 UUID:</strong> <code style="font-size: 0.85em;">${Utils.escapeHtml(decision.level2_uuid)}</code></p>` : ''}
                                ${decision.level3_uuid ? `<p style="margin: 4px 0 0 0;"><strong>Level 3 UUID:</strong> <code style="font-size: 0.85em;">${Utils.escapeHtml(decision.level3_uuid)}</code></p>` : ''}
                                ${decision.level3_action ? `<p style="margin: 4px 0 0 0;"><strong>Level 3 Aktion:</strong> ${Utils.escapeHtml(decision.level3_action)}</p>` : ''}
                                ${decision.level3_new_name ? `<p style="margin: 4px 0 0 0;"><strong>Neue Level 3:</strong> <strong style="color: #155724;">${Utils.escapeHtml(decision.level3_new_name)}</strong></p>` : ''}
                                ${decision.level2_confirmed ? `<p style="margin: 4px 0 0 0;">✅ Level 2 bestätigt</p>` : ''}
                                ${decision.level1_confirmed ? `<p style="margin: 4px 0 0 0;">✅ Level 1 bestätigt</p>` : ''}
                            </div>
                        ` : ''}
                    </div>
                    ` : ''}
                    
                    <div style="margin-top: 24px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Status</h3>
                        <p><strong>Validation:</strong> ${row.validation_status || 'unknown'}</p>
                        <p><strong>Duplicate:</strong> ${row.duplicate_status || 'unknown'}</p>
                        <p><strong>Review:</strong> ${row.review_status || 'pending'}</p>
                        ${row.import_status ? `<p><strong>Import:</strong> ${row.import_status}</p>` : ''}
                    </div>
                    
                    ${row.validation_errors && row.validation_errors.length > 0 ? `
                    <div style="margin-top: 24px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px; color: #d32f2f;">Validierungsfehler</h3>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">${Utils.escapeHtml(JSON.stringify(row.validation_errors, null, 2))}</pre>
                    </div>
                    ` : ''}
                    
                    ${row.duplicate_summary ? `
                    <div style="margin-top: 24px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Duplikat-Informationen</h3>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">${Utils.escapeHtml(JSON.stringify(row.duplicate_summary, null, 2))}</pre>
                    </div>
                    ` : ''}
                    
                    <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #ddd;">
                        ${row.import_status !== 'imported' ? `
                        <button class="btn btn-primary" onclick="window.app.import.reviewModule.showCorrectionForm('${stagingUuid}')" style="margin-right: 8px;">
                            ✏️ Daten korrigieren
                        </button>
                        ` : ''}
                        <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
                            Schließen
                        </button>
                    </div>
                    
                    <details style="margin-top: 24px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #f5f5f5; border-radius: 4px;">Raw Data (Original Excel)</summary>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;">${Utils.escapeHtml(JSON.stringify(row.raw_data, null, 2))}</pre>
                    </details>
                    
                    <details style="margin-top: 12px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #f5f5f5; border-radius: 4px;">Mapped Data</summary>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;">${Utils.escapeHtml(JSON.stringify(row.mapped_data, null, 2))}</pre>
                    </details>
                    
                    <details style="margin-top: 12px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #f5f5f5; border-radius: 4px;">Industry Resolution (Vollständig)</summary>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;">${Utils.escapeHtml(JSON.stringify(row.industry_resolution, null, 2))}</pre>
                    </details>
                </div>
            `;
            
            // Klick außerhalb des Modals schließt es
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
            document.body.appendChild(modal);
            
        } catch (error) {
            console.error('Error loading row detail:', error);
            Utils.showError('Fehler beim Laden der Details: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Zeigt Korrekturformular für eine Staging-Row
     */
    async showCorrectionForm(stagingUuid) {
        try {
            const row = await window.API.request(`/import/staging/${stagingUuid}`);
            
            if (!row) {
                Utils.showError('Staging-Row nicht gefunden');
                return;
            }
            
            // Schließe Details-Modal
            const existingModal = document.querySelector('.modal-overlay[data-staging-detail]');
            if (existingModal) {
                existingModal.remove();
            }
            
            const mappedData = row.mapped_data || {};
            const orgData = mappedData.org || {};
            const addressData = mappedData.address || {};
            const communicationData = mappedData.communication || {};
            
            // Erstelle Korrektur-Modal
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.setAttribute('data-correction-form', 'true');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10001; display: flex; align-items: center; justify-content: center; padding: 20px;';
            
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 800px; max-height: 90vh; overflow-y: auto; background: white; border-radius: 8px; padding: 24px; position: relative;">
                    <button class="btn-close" onclick="this.closest('.modal-overlay').remove()" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
                    <h2 style="margin-top: 0;">Daten korrigieren</h2>
                    <p><strong>Zeile:</strong> ${row.row_number || '-'}</p>
                    
                    <form id="correction-form" onsubmit="event.preventDefault(); window.app.import.reviewModule.saveCorrections('${stagingUuid}');">
                        <div style="margin-top: 20px;">
                            <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Organisationsdaten</h3>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Name *</label>
                                <input type="text" id="corr-org-name" value="${Utils.escapeHtml(orgData.name || '')}" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Website</label>
                                <input type="url" id="corr-org-website" value="${Utils.escapeHtml(orgData.website || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">USt-IdNr.</label>
                                <input type="text" id="corr-org-vat-id" value="${Utils.escapeHtml(orgData.vat_id || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px;">
                            <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Adresse</h3>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Straße</label>
                                <input type="text" id="corr-addr-street" value="${Utils.escapeHtml(addressData.street || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px; display: grid; grid-template-columns: 1fr 2fr; gap: 12px;">
                                <div>
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">PLZ</label>
                                    <input type="text" id="corr-addr-postal-code" value="${Utils.escapeHtml(addressData.postal_code || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Ort</label>
                                    <input type="text" id="corr-addr-city" value="${Utils.escapeHtml(addressData.city || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px;">
                            <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Kontaktdaten</h3>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">E-Mail</label>
                                <input type="email" id="corr-comm-email" value="${Utils.escapeHtml(communicationData.email || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Telefon</label>
                                <input type="tel" id="corr-comm-phone" value="${Utils.escapeHtml(communicationData.phone || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #ddd; display: flex; gap: 8px; justify-content: flex-end;">
                            <button type="button" class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
                                Abbrechen
                            </button>
                            <button type="submit" class="btn btn-primary">
                                Korrekturen speichern
                            </button>
                        </div>
                    </form>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Klick außerhalb des Modals schließt es
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
        } catch (error) {
            console.error('Error showing correction form:', error);
            Utils.showError('Fehler beim Laden: ' + error.message);
        }
    }
    
    /**
     * Speichert Korrekturen für eine Staging-Row
     */
    async saveCorrections(stagingUuid) {
        try {
            // Sammle Korrekturen aus dem Formular
            const corrections = {
                org: {
                    name: document.getElementById('corr-org-name')?.value || null,
                    website: document.getElementById('corr-org-website')?.value || null,
                    vat_id: document.getElementById('corr-org-vat-id')?.value || null
                },
                address: {
                    street: document.getElementById('corr-addr-street')?.value || null,
                    postal_code: document.getElementById('corr-addr-postal-code')?.value || null,
                    city: document.getElementById('corr-addr-city')?.value || null
                },
                communication: {
                    email: document.getElementById('corr-comm-email')?.value || null,
                    phone: document.getElementById('corr-comm-phone')?.value || null
                }
            };
            
            // Entferne null-Werte
            Object.keys(corrections).forEach(key => {
                if (corrections[key]) {
                    Object.keys(corrections[key]).forEach(subKey => {
                        if (corrections[key][subKey] === null || corrections[key][subKey] === '') {
                            delete corrections[key][subKey];
                        }
                    });
                    if (Object.keys(corrections[key]).length === 0) {
                        delete corrections[key];
                    }
                }
            });
            
            // Speichere Korrekturen über API
            const response = await this.importModule.fetchWithToken(`${window.API?.baseUrl || '/api'}/import/staging/${stagingUuid}/corrections`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    corrections: corrections
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Speichern der Korrekturen');
            }
            
            Utils.showSuccess('Korrekturen gespeichert.');
            
            // Schließe Modal
            const modal = document.querySelector('.modal-overlay[data-correction-form]');
            if (modal) {
                modal.remove();
            }
            
            // Aktualisiere die betroffene Zeile in der Tabelle
            await this.updateRowInTable(stagingUuid);
            
        } catch (error) {
            console.error('Error saving corrections:', error);
            Utils.showError('Fehler: ' + error.message);
        }
    }
    
    /**
     * Committet Batch (Import in Produktion)
     */
    async commitBatch() {
        if (!this.importModule.currentBatch) {
            Utils.showError('Kein Batch vorhanden');
            return;
        }
        
        // Prüfe, ob es pending Rows gibt, die automatisch approved werden sollen
        const batchResponse = await fetch(`${window.API?.baseUrl || '/api'}/import/batch/${this.importModule.currentBatch}/stats`);
        const batch = await batchResponse.json();
        const batchStats = batch.stats || {};
        const hasPendingRows = (batchStats.pending_rows || 0) > 0;
        const hasApprovedRows = (batchStats.approved_rows || 0) > 0;
        
        let confirmMessage = 'Möchten Sie den Import wirklich durchführen? Die Daten werden in die Produktions-Datenbank importiert.';
        if (hasPendingRows && !hasApprovedRows) {
            confirmMessage = 'Möchten Sie alle pending Zeilen freigeben und importieren? Die Daten werden in die Produktions-Datenbank importiert.';
        } else if (hasPendingRows && hasApprovedRows) {
            confirmMessage = 'Möchten Sie die freigegebenen Zeilen importieren? Pending Zeilen werden übersprungen.';
        }
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        try {
            let commitMode = 'APPROVED_ONLY';
            if (hasPendingRows && !hasApprovedRows) {
                commitMode = 'PENDING_AUTO_APPROVE';
            } else if (hasPendingRows && hasApprovedRows) {
                commitMode = 'APPROVED_ONLY';
            }
            
            Utils.showInfo('Import wird durchgeführt...');
            
            const response = await this.importModule.fetchWithToken(`${window.API?.baseUrl || '/api'}/import/batch/${this.importModule.currentBatch}/commit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mode: commitMode,
                    start_workflows: true,
                    dry_run: false
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Commit');
            }
            
            const result = await response.json();
            
            const rowsImported = result.result?.stats?.rows_imported || result.stats?.rows_imported || 0;
            Utils.showSuccess(`Import erfolgreich! ${rowsImported} Organisationen importiert.`);
            
            // Setze State zurück
            this.importModule.currentBatch = null;
            this.importModule.currentStep = 1;
            
            // Navigiere zur Übersichtsseite
            const url = new URL(window.location.href);
            url.searchParams.delete('batch');
            window.history.replaceState({}, '', url);
            
            // Rendere Übersichtsseite
            const page = document.getElementById('page-import');
            if (page) {
                await this.importModule.overviewModule.renderOverviewPage(page);
            }
            
        } catch (error) {
            console.error('Commit error:', error);
            Utils.showError('Fehler beim Import: ' + error.message);
        }
    }
    
    updateCommitButton() {
        const commitBtn = document.getElementById('commit-btn');
        if (!commitBtn) return;
        const rows = Array.isArray(this.importModule.stagingRows) ? this.importModule.stagingRows : [];
        const pending = rows.filter(r => r.import_status !== 'imported' && r.disposition === 'pending').length;
        const approved = rows.filter(r => r.import_status !== 'imported' && r.disposition === 'approved').length;
        const show = (pending + approved) > 0;
        if (!show) {
            commitBtn.style.display = 'none';
            return;
        }
        commitBtn.style.display = 'inline-block';
        commitBtn.textContent = (approved > 0) 
            ? 'Freigegebene Zeilen importieren →' 
            : 'Alle freigeben & importieren →';
    }
}


