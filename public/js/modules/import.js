/**
 * TOM3 - Import Module (Neu - Clean Slate)
 * Nutzt serverseitige State-Engine für Industry-Entscheidungen
 */

import { Utils } from './utils.js';

export class ImportModule {
    constructor(app) {
        this.app = app;
        this.currentBatch = null;
        this.currentStep = 1; // 1: Upload, 2: Mapping, 3: Branchen-Prüfung, 4: Review
        this.stagingRows = []; // Cache für Staging-Rows
        this.stagingImported = false; // Flag: Wurde bereits in Staging importiert?
    }
    
    /**
     * Helper-Methode für fetch mit CSRF-Token
     * Fallback falls csrfTokenService nicht verfügbar ist
     */
    async fetchWithToken(url, options = {}) {
        // Wenn csrfTokenService verfügbar ist, verwende diesen
        if (window.csrfTokenService && typeof window.csrfTokenService.fetchWithToken === 'function') {
            return await window.csrfTokenService.fetchWithToken(url, options);
        }
        
        // Fallback: Manuell Token holen und Request senden
        const method = options.method || 'GET';
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
            try {
                // Versuche Token zu holen
                const tokenResponse = await fetch(`${window.API?.baseUrl || '/api'}/auth/csrf-token`);
                if (tokenResponse.ok) {
                    const tokenData = await tokenResponse.json();
                    if (tokenData.token) {
                        if (!options.headers) {
                            options.headers = {};
                        }
                        options.headers['X-CSRF-Token'] = tokenData.token;
                    }
                }
            } catch (error) {
                console.warn('Could not fetch CSRF token, continuing without it:', error);
            }
        }
        
        return fetch(url, options);
    }
    
    /**
     * Initialisiert Import-Seite
     */
    async init() {
        const page = document.getElementById('page-import');
        if (!page) return;
        
        // Prüfe, ob ein Batch-Parameter in der URL ist
        const urlParams = new URLSearchParams(window.location.search);
        const batchUuid = urlParams.get('batch');
        
        if (batchUuid) {
            // Lade bestehenden Batch
            this.currentBatch = batchUuid;
            await this.loadExistingBatch(batchUuid);
        } else {
            // Zeige Übersichtsseite
            await this.renderOverviewPage(page);
        }
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
            document.getElementById('import-batches-list').innerHTML = `
                <div class="error-message">Fehler beim Laden der Batches: ${error.message}</div>
            `;
        }
    }
    
    /**
     * Rendert Batch-Liste als Tabelle
     */
    renderBatchesList(batches) {
        const container = document.getElementById('import-batches-list');
        
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
            // Erlaube Löschen, wenn noch nicht-importierte Rows vorhanden sind
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
     * Startet neuen Import
     */
    startNewImport() {
        this.currentBatch = null;
        this.currentStep = 1;
        const page = document.getElementById('page-import');
        this.renderImportPage(page);
        this.setupEventHandlers();
    }
    
    /**
     * Öffnet bestehenden Batch
     */
    async openBatch(batchUuid) {
        this.currentBatch = batchUuid;
        await this.loadExistingBatch(batchUuid);
    }
    
    /**
     * Lädt bestehenden Batch
     */
    async loadExistingBatch(batchUuid) {
        try {
            const page = document.getElementById('page-import');
            
            // Lade Batch-Statistiken
            const response = await fetch(`/tom3/public/api/import/batch/${batchUuid}/stats`);
            if (!response.ok) {
                throw new Error('Batch nicht gefunden');
            }
            
            const batch = await response.json();
            const stats = batch.stats || {};
            
            // Setze stagingImported-Flag basierend auf Batch-Status
            // Wenn Batch bereits gestaged wurde, sollte stagingImported = true sein
            if (batch.status === 'STAGED' || batch.status === 'IN_REVIEW' || 
                batch.status === 'APPROVED' || batch.status === 'IMPORTED') {
                this.stagingImported = true;
            } else {
                this.stagingImported = false;
            }
            
            // IMPORTED-Batches: Zeige Zusammenfassung oder Review
            if (batch.status === 'IMPORTED') {
                // Prüfe, ob noch nicht-importierte Staging-Rows vorhanden sind
                const nonImportedRows = (stats.pending_rows || 0) + (stats.approved_rows || 0);
                
                if (nonImportedRows > 0) {
                    // Es gibt noch nicht importierte Rows - zeige Review
                    this.currentStep = 4;
                    this.renderImportPage(page);
                    this.setupEventHandlers();
                    // Wichtig: Warte kurz, damit DOM gerendert ist, dann goToStep() aufrufen
                    await new Promise(resolve => setTimeout(resolve, 10));
                    this.goToStep(4);
                    await this.renderReviewStep();
                } else {
                    // Alles importiert - zeige Zusammenfassung
                    this.showImportSummary(batch);
                }
                return;
            }
            
            // Bestimme aktuellen Schritt basierend auf Status
            let step = 1;
            if (batch.status === 'STAGED' || batch.status === 'IN_REVIEW' || batch.status === 'APPROVED') {
                step = 4; // Review-Schritt
            } else if (batch.status === 'DRAFT') {
                step = 2; // Mapping-Schritt
            }
            
            this.currentStep = step;
            this.renderImportPage(page);
            this.setupEventHandlers();
            
            // Wichtig: Warte kurz, damit DOM gerendert ist, dann goToStep() aufrufen
            await new Promise(resolve => setTimeout(resolve, 10));
            
            // Lade Daten für den aktuellen Schritt VOR goToStep (um doppelte Aufrufe zu vermeiden)
            if (step === 4) {
                await this.renderReviewStep();
                this.goToStep(step);
            } else if (step === 2) {
                // Für DRAFT-Batches: Setze currentBatch
                this.currentBatch = batchUuid;
                
                // Wenn mapping_config vorhanden ist, verwende es
                if (batch.mapping_config) {
                    // Konvertiere mapping_config zu analysis-Format für renderMappingStep
                    this.analysis = {
                        mapping_suggestion: this.convertMappingConfigToSuggestion(batch.mapping_config),
                        industry_validation: null
                    };
                } else {
                    // Kein mapping_config vorhanden - lade Analyse-Daten neu
                    // Hole Dokument für diesen Batch und analysiere es neu
                    await this.reloadAnalysisForBatch(batchUuid);
                }
                
                // Rendere Mapping-Step VOR goToStep, damit goToStep nicht nochmal renderMappingStep aufruft
                await this.renderMappingStep();
                this.goToStep(step);
            } else {
                this.goToStep(step);
            }
            
        } catch (error) {
            console.error('Error loading batch:', error);
            Utils.showError('Fehler beim Laden des Batches: ' + error.message);
            // Zurück zur Übersicht
            const page = document.getElementById('page-import');
            await this.renderOverviewPage(page);
        }
    }
    
    /**
     * Zeigt Zusammenfassung für vollständig importierte Batches
     */
    showImportSummary(batch) {
        const page = document.getElementById('page-import');
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
     * Zeigt Übersichtsseite
     */
    async showOverview() {
        const page = document.getElementById('page-import');
        this.currentBatch = null;
        await this.renderOverviewPage(page);
    }
    
    /**
     * Löscht einen Batch
     */
    async deleteBatch(batchUuid, filename) {
        if (!confirm(`Möchten Sie den Batch "${filename}" wirklich löschen?\n\nDies kann nicht rückgängig gemacht werden.`)) {
            return;
        }
        
        try {
            Utils.showInfo('Batch wird gelöscht...');
            
            const response = await this.fetchWithToken(`/tom3/public/api/import/batch/${batchUuid}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Löschen');
            }
            
            Utils.showSuccess('Batch erfolgreich gelöscht');
            
            // Liste neu laden
            await this.loadBatchesList();
            
        } catch (error) {
            console.error('Delete batch error:', error);
            Utils.showError('Fehler beim Löschen: ' + error.message);
        }
    }
    
    /**
     * Escaped HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Rendert Import-Seite
     */
    renderImportPage(container) {
        container.innerHTML = `
            <div class="page-header">
                <h2>📥 Organisationen importieren</h2>
                <p class="page-description">Importieren Sie Organisationen aus Excel- oder CSV-Dateien</p>
            </div>
            
            <div class="import-wizard">
                <!-- Wizard-Navigation -->
                <div class="wizard-navigation">
                    <div class="wizard-step-indicator ${this.currentStep === 1 ? 'active' : ''} ${this.currentStep > 1 ? 'completed' : ''}" data-step="1">
                        <div class="step-indicator-number">1</div>
                        <div class="step-indicator-label">Datei hochladen</div>
                    </div>
                    <div class="wizard-step-connector"></div>
                    <div class="wizard-step-indicator ${this.currentStep === 2 ? 'active' : ''} ${this.currentStep > 2 ? 'completed' : ''}" data-step="2">
                        <div class="step-indicator-number">2</div>
                        <div class="step-indicator-label">Mapping</div>
                    </div>
                    <div class="wizard-step-connector"></div>
                    <div class="wizard-step-indicator ${this.currentStep === 3 ? 'active' : ''} ${this.currentStep > 3 ? 'completed' : ''}" data-step="3">
                        <div class="step-indicator-number">3</div>
                        <div class="step-indicator-label">Branchen-Prüfung</div>
                    </div>
                    <div class="wizard-step-connector"></div>
                    <div class="wizard-step-indicator ${this.currentStep === 4 ? 'active' : ''}" data-step="4">
                        <div class="step-indicator-number">4</div>
                        <div class="step-indicator-label">Review & Freigabe</div>
                    </div>
                </div>
                
                <!-- Schritt 1: Upload -->
                <div class="wizard-step ${this.currentStep === 1 ? 'active' : ''}" data-step="1">
                    <div class="step-header">
                        <h3>Schritt 1 von 3: Datei hochladen</h3>
                    </div>
                    <div class="step-content">
                        <form id="import-upload-form" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="import-file">Excel/CSV-Datei *</label>
                                <input type="file" id="import-file" name="file" 
                                       accept=".xlsx,.xls,.csv" required>
                                <small class="form-hint">
                                    Unterstützte Formate: Excel (.xlsx, .xls), CSV (.csv)
                                </small>
                            </div>
                            
                            <div id="upload-progress" class="upload-progress" style="display: none;">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="upload-progress-fill"></div>
                                </div>
                                <div class="progress-text" id="upload-progress-text">Wird hochgeladen...</div>
                            </div>
                            
                            <div id="upload-error" class="error-message" style="display: none;"></div>
                            
                            <button type="submit" class="btn btn-primary" id="upload-btn">
                                📤 Datei hochladen
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Schritt 2: Mapping -->
                <div class="wizard-step ${this.currentStep === 2 ? 'active' : ''}" data-step="2" style="display: none;">
                    <div class="step-header">
                        <h3>Schritt 2 von 4: Spalten-Mapping</h3>
                    </div>
                    <div class="step-content">
                        <div id="mapping-configurator">
                            <p>Lädt Mapping-Vorschlag...</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.app.import.goToStep(1)">
                                ← Zurück
                            </button>
                            <button type="button" class="btn btn-secondary" id="save-template-btn" onclick="window.app.import.saveAsTemplate()" style="display: none;">
                                💾 Als Template speichern
                            </button>
                            <button type="button" class="btn btn-primary" id="save-mapping-btn" onclick="window.app.import.saveMapping()">
                                Mapping speichern →
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Schritt 3: Branchen-Prüfung -->
                <div class="wizard-step ${this.currentStep === 3 ? 'active' : ''}" data-step="3" style="display: none;">
                    <div class="step-header">
                        <h3>Schritt 3 von 4: Branchen-Prüfung</h3>
                    </div>
                    <div class="step-content">
                        <div id="industry-check-content">
                            <p>Lädt Branchen-Vorschläge...</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.app.import.goToStep(2)">
                                ← Zurück
                            </button>
                            <button type="button" class="btn btn-primary" id="confirm-industries-btn" onclick="window.app.import.confirmAllIndustries()" style="display: none;">
                                Branchen bestätigen →
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Schritt 4: Review -->
                <div class="wizard-step ${this.currentStep === 4 ? 'active' : ''}" data-step="4" style="${this.currentStep === 4 ? 'display: block;' : 'display: none;'}">
                    <div class="step-header">
                        <h3>Schritt 4 von 4: Review & Freigabe</h3>
                    </div>
                    <div class="step-content">
                        <div id="review-content">
                            <p>Lädt Staging-Daten...</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.app.import.goToStep(3)">
                                ← Zurück zur Branchen-Prüfung
                            </button>
                            <button type="button" class="btn btn-primary" id="commit-btn" onclick="window.app.import.commitBatch()" style="display: none;">
                                Importieren →
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Setup Event Handlers
     */
    setupEventHandlers() {
        const uploadForm = document.getElementById('import-upload-form');
        if (uploadForm) {
            uploadForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleUpload();
            });
        }
    }
    
    /**
     * Gehe zu Schritt
     */
    goToStep(step) {
        this.currentStep = step;
        
        // Update Indicators
        document.querySelectorAll('.wizard-step-indicator').forEach((indicator, index) => {
            const stepNum = index + 1;
            indicator.classList.remove('active', 'completed');
            if (stepNum === step) {
                indicator.classList.add('active');
            } else if (stepNum < step) {
                indicator.classList.add('completed');
            }
        });
        
        // Show/Hide Steps
        document.querySelectorAll('.wizard-step').forEach((stepEl, index) => {
            const stepNum = index + 1;
            if (stepNum === step) {
                stepEl.classList.add('active');
                stepEl.style.display = 'block';
            } else {
                stepEl.classList.remove('active');
                stepEl.style.display = 'none';
            }
        });
        
        // Load step content (nur wenn nicht bereits geladen)
        // Prüfe, ob der Container bereits Inhalt hat, um doppelte Aufrufe zu vermeiden
        if (step === 2 && this.currentBatch) {
            const container = document.getElementById('mapping-configurator');
            if (container && (!container.innerHTML || container.innerHTML.trim() === '' || container.innerHTML.includes('Lädt Mapping-Vorschlag'))) {
                this.renderMappingStep();
            }
        } else if (step === 3 && this.currentBatch) {
            this.renderIndustryCheckStep();
        } else if (step === 4 && this.currentBatch) {
            this.renderReviewStep();
        }
    }
    
    /**
     * Upload Handler
     */
    async handleUpload() {
        const fileInput = document.getElementById('import-file');
        if (!fileInput || !fileInput.files[0]) {
            Utils.showError('Bitte wählen Sie eine Datei aus.');
            return;
        }
        
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        
        const progressDiv = document.getElementById('upload-progress');
        const progressFill = document.getElementById('upload-progress-fill');
        const progressText = document.getElementById('upload-progress-text');
        const errorDiv = document.getElementById('upload-error');
        
        progressDiv.style.display = 'block';
        errorDiv.style.display = 'none';
        progressFill.style.width = '0%';
        progressText.textContent = 'Wird hochgeladen...';
        
        try {
            const response = await this.fetchWithToken('/tom3/public/api/import/upload', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Upload fehlgeschlagen');
            }
            
            const result = await response.json();
            this.currentBatch = result.batch_uuid;
            this.analysis = result.analysis || {};
            this.templateMatch = result.analysis?.template_match || null;
            
            progressFill.style.width = '100%';
            progressText.textContent = 'Upload erfolgreich!';
            
            // Prüfe Template-Vorschlag
            if (this.templateMatch && this.templateMatch.template && this.templateMatch.decision !== 'NO_MATCH') {
                // Zeige Template-Vorschlag
                this.showTemplateSuggestion();
            } else {
                // Gehe direkt zu Schritt 2
                setTimeout(() => {
                    this.goToStep(2);
                }, 500);
            }
            
        } catch (error) {
            console.error('Upload error:', error);
            errorDiv.textContent = error.message || 'Upload fehlgeschlagen';
            errorDiv.style.display = 'block';
            progressDiv.style.display = 'none';
        }
    }
    
    /**
     * Rendert Mapping-Step
     */
    async renderMappingStep() {
        const container = document.getElementById('mapping-configurator');
        if (!container || !this.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Mapping-Vorschlag...</p>';
        
        try {
            // Verwende gespeicherte Analysis-Daten (aus Upload) oder lade neu
            let analysis = this.analysis;
            
            if (!analysis || !analysis.mapping_suggestion) {
                // Falls nicht vorhanden, hole Batch-Details (mit stats, da dort mapping_config enthalten ist)
                const batchResponse = await fetch(`/tom3/public/api/import/batch/${this.currentBatch}/stats`);
                if (!batchResponse.ok) {
                    throw new Error('Batch nicht gefunden');
                }
                const batch = await batchResponse.json();
                
                // Verwende mapping_config aus Batch, falls vorhanden
                if (batch && batch.mapping_config) {
                    // Konvertiere mapping_config zu mapping_suggestion Format
                    analysis = {
                        mapping_suggestion: this.convertMappingConfigToSuggestion(batch.mapping_config),
                        industry_validation: null
                    };
                    // Speichere für spätere Verwendung
                    this.analysis = analysis;
                } else {
                    throw new Error('Keine Analyse-Daten gefunden. Bitte laden Sie die Datei erneut hoch.');
                }
            }
            
            const mapping = analysis.mapping_suggestion || {};
            
            // NUR Mapping-UI (ohne Branchen-Prüfung - die kommt in Schritt 3)
            let html = '<div class="mapping-section">';
            html += this.renderMappingUI(mapping);
            html += '</div>';
            
            container.innerHTML = html;
            
            // Zeige "Als Template speichern" Button (wenn Mapping vorhanden)
            const saveTemplateBtn = document.getElementById('save-template-btn');
            if (saveTemplateBtn && Object.keys(mapping.by_field || {}).length > 0) {
                saveTemplateBtn.style.display = 'inline-block';
            }
            
        } catch (error) {
            console.error('Error loading mapping:', error);
            container.innerHTML = `<p class="error">Fehler beim Laden: ${error.message}</p>`;
        }
    }
    
    /**
     * Lädt Analyse-Daten für einen Batch neu (wenn mapping_config fehlt)
     */
    async reloadAnalysisForBatch(batchUuid) {
        try {
            Utils.showInfo('Lade Analyse-Daten...');
            
            // Rufe Analyse-Endpoint auf (analysiert die Datei neu)
            const response = await this.fetchWithToken(`/tom3/public/api/import/analyze`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    batch_uuid: batchUuid
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Laden der Analyse-Daten');
            }
            
            const result = await response.json();
            this.analysis = result.analysis || result;
            
            Utils.showSuccess('Analyse-Daten geladen.');
            
        } catch (error) {
            console.error('Error reloading analysis:', error);
            // Falls Analyse fehlschlägt, versuche es mit mapping_config aus Batch
            const batchResponse = await fetch(`/tom3/public/api/import/batch/${batchUuid}/stats`);
            if (batchResponse.ok) {
                const batch = await batchResponse.json();
                if (batch.mapping_config) {
                    this.analysis = {
                        mapping_suggestion: this.convertMappingConfigToSuggestion(batch.mapping_config),
                        industry_validation: null
                    };
                } else {
                    throw error; // Re-throw original error
                }
            } else {
                throw error; // Re-throw original error
            }
        }
    }
    
    /**
     * Konvertiert mapping_config zu mapping_suggestion Format (für Anzeige)
     */
    convertMappingConfigToSuggestion(mappingConfig) {
        // mapping_config hat Struktur: { columns: { field: { excel_column: 'A', ... } } }
        // mapping_suggestion hat Struktur: { by_field: { field: [{ excel_column, excel_header, ... }] } }
        
        const byField = {};
        const byColumn = {};
        
        if (mappingConfig.columns) {
            Object.entries(mappingConfig.columns).forEach(([field, config]) => {
                if (!byField[field]) {
                    byField[field] = [];
                }
                
                const candidate = {
                    excel_column: config.excel_column || '',
                    excel_header: config.excel_header || config.excel_column || '',
                    confidence: config.confidence || 100,
                    examples: config.examples || []
                };
                
                byField[field].push(candidate);
                
                if (config.excel_column) {
                    byColumn[config.excel_column] = {
                        excel_column: config.excel_column,
                        excel_header: config.excel_header || config.excel_column,
                        tom_field: field,
                        confidence: config.confidence || 100,
                        examples: config.examples || []
                    };
                }
            });
        }
        
        return { by_field: byField, by_column: byColumn };
    }
    
    /**
     * Rendert Mapping-UI (verbessertes Design)
     */
    renderMappingUI(mapping) {
        let html = '<div class="mapping-configurator">';
        html += '<div class="mapping-header">';
        html += '<h4>📋 Spalten-Mapping</h4>';
        html += '<p class="mapping-description">Ordnen Sie die Excel-Spalten den Systemfeldern zu:</p>';
        html += '</div>';
        
        const byField = mapping.by_field || {};
        const byColumn = mapping.by_column || {};
        
        // Gruppiere Felder in logische Kategorien
        // WICHTIG: Branchen-Felder werden hier NICHT angezeigt - die kommen in Schritt 3 (Branchen-Prüfung)
        const fieldCategories = {
            'Basis-Informationen': ['name', 'website', 'notes'],
            'Adresse': ['address_street', 'address_postal_code', 'address_city', 'address_state'],
            'Kontakt': ['email', 'phone', 'fax'],
            'Weitere Daten': ['vat_id', 'revenue_range', 'employee_count']
        };
        
        // Zeige pro Kategorie
        html += '<div class="mapping-by-field">';
        
        for (const [category, fields] of Object.entries(fieldCategories)) {
            const categoryFields = fields.filter(field => byField[field] && byField[field].length > 0);
            if (categoryFields.length === 0) continue;
            
            html += `<div class="mapping-category">`;
            html += `<h5 class="category-title">${category}</h5>`;
            
            for (const field of categoryFields) {
                const candidates = byField[field];
                if (candidates.length === 0) continue;
                
                html += `<div class="mapping-field-group">`;
                html += `<label class="field-label"><strong>${this.getFieldLabel(field)}</strong></label>`;
                
                candidates.forEach((candidate, index) => {
                    const isSelected = index === 0 && candidate.confidence >= 80;
                    const confidenceClass = candidate.confidence >= 90 ? 'high' : candidate.confidence >= 70 ? 'medium' : 'low';
                    
                    html += `<div class="mapping-candidate ${isSelected ? 'selected' : ''}">`;
                    html += `<input type="radio" name="mapping_${field}" value="${candidate.excel_column}" 
                             id="mapping_${field}_${index}" ${isSelected ? 'checked' : ''}>`;
                    html += `<label for="mapping_${field}_${index}" class="candidate-label">`;
                    html += `<span class="candidate-header">${candidate.excel_header} <span class="column-badge">(${candidate.excel_column})</span></span>`;
                    if (candidate.examples && candidate.examples.length > 0) {
                        const uniqueExamples = [...new Set(candidate.examples.slice(0, 3))];
                        html += `<span class="examples">Beispiele: ${uniqueExamples.join(', ')}</span>`;
                    }
                    html += `<span class="confidence-badge ${confidenceClass}">${candidate.confidence}%</span>`;
                    html += `</label>`;
                    html += `</div>`;
                });
                
                html += `</div>`;
            }
            
            html += `</div>`;
        }
        
        html += '</div>';
        html += '</div>';
        return html;
    }
    
    /**
     * Rendert Industry-Warnings (neu: nutzt serverseitige State-Engine)
     */
    renderIndustryWarnings(validation) {
        // Prüfe Konsistenz
        const consistency = validation.consistency || {};
        if (!consistency.is_consistent) {
            return `
                <div class="warning-section">
                    <h4>⚠️ Branchen-Daten inkonsistent</h4>
                    <p>Die Excel-Datei enthält unterschiedliche Branchenwerte. Das Branchen-Mapping wird übersprungen.</p>
                    <p><strong>Gefundene Level 1 Werte:</strong> ${(consistency.unique_level1_values || []).join(', ')}</p>
                    <p><strong>Gefundene Level 2 Werte:</strong> ${(consistency.unique_level2_values || []).join(', ')}</p>
                    <p class="info">Branchen müssen nach dem Import manuell zugeordnet werden.</p>
                </div>
            `;
        }
        
        // Zeige Kombinationen
        const combinations = validation.combinations || [];
        if (combinations.length === 0) {
            return '<p class="info">Keine Branchenkombinationen in den Excel-Daten gefunden.</p>';
        }
        
        let html = '<div class="industry-warnings-section">';
        html += '<div class="industry-header">';
        html += '<div class="industry-header-icon">⚠️</div>';
        html += '<div class="industry-header-text">';
        html += '<h4>Branchen-Prüfung</h4>';
        html += '<p class="industry-description">Bitte wählen Sie die Branchenhierarchie sequenziell aus:</p>';
        html += '</div>';
        html += '</div>';
        
        // Für jede Kombination
        combinations.forEach((combo, index) => {
            const comboId = `combo_${index}`;
            html += this.renderIndustryCombination(comboId, combo);
        });
        
        html += '</div>';
        return html;
    }
    
    /**
     * Rendert Industry-Kombination (neu: sequenzielle 3-Level-Auswahl)
     */
    renderIndustryCombination(comboId, combo) {
        // combo enthält: excel_level2, excel_level3, staging_uuid, count
        // industry_resolution kommt aus Staging-Row (wird später geladen)
        const stagingUuid = combo.staging_uuid || '';
        
        // Initialisiere mit leeren Werten (werden später aus Staging geladen)
        // suggestions und decision werden erst nach loadStagingRowForCombination verfügbar sein
        const suggestions = {}; // Wird später gefüllt
        const decision = {}; // Wird später gefüllt
        
        // Level 1 (Branchenbereich)
        const level1PreSelected = null; // Wird aus Staging geladen
        const level1Uuid = null;
        const level1Confirmed = false;
        
        // Level 2 (Branche)
        const level2PreSelected = suggestions.level2_candidates?.[0] || null;
        const level2Uuid = decision.level2_uuid || level2PreSelected?.industry_uuid || null;
        const level2Confirmed = decision.level2_confirmed || false;
        
        // Level 3 (Unterbranche)
        const level3PreSelected = suggestions.level3_candidates?.[0] || null;
        const level3Uuid = decision.level3_uuid || null;
        const level3Action = decision.level3_action || 'UNDECIDED';
        
        let html = `<div class="industry-combination" data-combo-id="${comboId}" data-staging-uuid="${stagingUuid}">`;
        html += `<div class="combination-header">`;
        html += `<div class="combination-excel-data">`;
        html += `<span class="excel-label">Excel-Daten:</span> `;
        // WICHTIG: Level 1 kommt NICHT aus Excel, nur Level 2 und Level 3
        // Zeige nur die tatsächlich aus Excel stammenden Werte
        html += `<span class="excel-value">`;
        if (combo.excel_level2) {
            html += `Level 2: ${combo.excel_level2}`;
        }
        if (combo.excel_level3) {
            html += ` / Level 3: ${combo.excel_level3}`;
        }
        if (!combo.excel_level2 && !combo.excel_level3) {
            html += `Keine Branchendaten gefunden`;
        }
        if (combo.count && combo.count > 1) {
            html += ` <span class="count-badge">(${combo.count} Zeilen)</span>`;
        }
        html += `</span>`;
        html += `<div class="excel-data-explanation">`;
        html += `<small>Diese Werte wurden aus Ihrer Excel-Datei gelesen (Spalte D/E). `;
        html += `Level 1 (Branchenbereich) wird automatisch aus Level 2 abgeleitet.</small>`;
        html += `</div>`;
        html += `</div>`;
        html += `</div>`;
        
        // Lade Vorschläge (async) - zeige Loading-Hinweis
        html += `<div class="loading-suggestions" id="loading_${comboId}">`;
        html += `<small>💡 Lade Vorschläge...</small>`;
        html += `</div>`;
        // Lade asynchron (auch wenn stagingUuid leer ist, wird Fallback verwendet)
        this.loadStagingRowForCombination(comboId, stagingUuid);
        
        // Level 1: Branchenbereich - ZUERST anzeigen, wird automatisch aus Level 2 vorbelegt
        html += `<div class="industry-step" data-step="1" data-combo-id="${comboId}" 
                 style="display: block; padding: 1rem; margin-top: 1rem; border-radius: 4px; background: #f9f9f9;">`;
        html += `<label class="step-label"><strong>1. Branchenbereich (Level 1):</strong></label>`;
        html += `<div class="step-help-text">`;
        html += `<small>Wird automatisch aus Level 2 abgeleitet, kann aber manuell geändert werden. Wenn Level 1 geändert wird, werden die Level 2 Optionen entsprechend gefiltert.</small>`;
        html += `</div>`;
        html += `<select class="industry-level1-select" data-combo-id="${comboId}" 
                 onchange="window.app.import.onLevel1Selected('${comboId}', this.value); window.app.import.updateConfirmIndustriesButton();" 
                 ${level1Confirmed ? 'disabled' : ''}>`;
        html += `<option value="">-- Bitte wählen --</option>`;
        // Options werden dynamisch geladen (wird nach Rendering geladen)
        html += `</select>`;
        html += `<div class="suggestion-container" id="suggestion_${comboId}_level1"></div>`;
        if (!level1Confirmed) {
            html += `<button type="button" class="btn btn-sm btn-primary confirm-level1-btn" 
                     data-combo-id="${comboId}" 
                     onclick="window.app.import.confirmLevel1('${comboId}')" 
                     ${level1Uuid ? '' : 'disabled'}>`;
            html += `<span class="btn-icon">✓</span> Bestätigen`;
            html += `</button>`;
        } else {
            html += `<div class="level-confirmation-feedback">✅ Branchenbereich ausgewählt</div>`;
        }
        html += `</div>`;
        
        // Level 2: Branche - WICHTIG: Dies ist der primäre Matching-Schritt!
        // Level 2 kommt direkt aus Excel (Spalte D) und wird mit System-Level 2 verglichen
        html += `<div class="industry-step" data-step="2" data-combo-id="${comboId}">`;
        html += `<div class="excel-value-hint">Excel-Wert (Spalte D): <strong>${combo.excel_level2 || 'N/A'}</strong></div>`;
        html += `<label class="step-label"><strong>2. Branche (Level 2) wählen:</strong></label>`;
        html += `<div class="step-help-text">`;
        html += `<small>Dieser Wert kommt direkt aus Ihrer Excel-Datei. Wenn ein Match gefunden wird, wird Level 1 (Branchenbereich) automatisch vorbelegt.</small>`;
        html += `</div>`;
        html += `<select class="industry-level2-select" data-combo-id="${comboId}" 
                 onchange="window.app.import.onLevel2Selected('${comboId}', this.value); window.app.import.updateConfirmIndustriesButton();" 
                 ${level2Confirmed ? 'disabled' : ''}>`;
        html += `<option value="">-- Bitte wählen --</option>`;
        // Options werden dynamisch geladen
        html += `</select>`;
        html += `<div class="suggestion-container" id="suggestion_${comboId}_level2"></div>`;
        if (!level2Confirmed) {
            html += `<button type="button" class="btn btn-sm btn-primary confirm-level2-btn" 
                     data-combo-id="${comboId}" 
                     onclick="window.app.import.confirmLevel2('${comboId}')" 
                     ${level2Uuid ? '' : 'disabled'}>`;
            html += `<span class="btn-icon">✓</span> Bestätigen`;
            html += `</button>`;
        } else {
            html += `<div class="level-confirmation-feedback">✅ Branche ausgewählt</div>`;
        }
        html += `</div>`;
        
        // Level 3: Unterbranche (nur aktiv wenn Level 2 bestätigt)
        html += `<div class="industry-step" data-step="3" data-combo-id="${comboId}" 
                 style="display: ${level2Confirmed ? 'block' : 'none'}; opacity: ${level2Confirmed ? '1' : '0.5'}; 
                 background: ${level2Confirmed ? '#f9f9f9' : '#f0f0f0'}; padding: 1rem; margin-top: 1rem; border-radius: 4px;">`;
        html += `<label><strong>3. Unterbranche (Level 3)</strong></label>`;
        
        if (level3PreSelected && level3PreSelected.industry_uuid) {
            // Bestehende Unterbranche gefunden
            html += `<select class="industry-level3-select" data-combo-id="${comboId}" 
                     onchange="window.app.import.onLevel3Selected('${comboId}', this.value); window.app.import.updateConfirmIndustriesButton();" 
                     style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;">`;
            html += `<option value="">-- Bitte wählen --</option>`;
            // Options werden dynamisch geladen
            html += `</select>`;
            html += `<p class="suggestion-hint">💡 Vorschlag: ${level3PreSelected.name}</p>`;
        } else {
            // Keine passende Unterbranche gefunden - Option zum Anlegen
            html += `<p class="info">Keine passende Unterbranche gefunden.</p>`;
            html += `<div class="form-group" style="margin-top: 0.5rem;">`;
            html += `<label>Neue Unterbranche anlegen:</label>`;
            html += `<input type="text" class="industry-level3-new-input" data-combo-id="${comboId}" 
                     placeholder="${combo.excel_level3 || 'Unterbranche'}" 
                     value="${combo.excel_level3 || ''}" style="width: 100%; padding: 0.5rem;">`;
            html += `<button type="button" class="btn btn-sm btn-success" 
                     onclick="window.app.import.addLevel3FromCombo('${comboId}')" 
                     style="margin-top: 0.5rem;">Als neue Unterbranche übernehmen</button>`;
            html += `</div>`;
        }
        html += `</div>`;
        
        html += `</div>`;
        return html;
    }
    
    /**
     * Wird aufgerufen, wenn Level 1 ausgewählt wird
     * WICHTIG: Level 1 wird normalerweise automatisch aus Level 2 abgeleitet!
     */
    async onLevel1Selected(comboId, level1Uuid) {
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (!comboEl) return;
        
        if (!level1Uuid) {
            // Level 1 wurde zurückgesetzt - Level 2 Optionen zurücksetzen
            const level2Select = comboEl.querySelector('.industry-level2-select');
            if (level2Select) {
                level2Select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                level2Select.value = '';
            }
            // Vorschlag ausblenden
            const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
            if (suggestionContainer2) {
                suggestionContainer2.innerHTML = '';
            }
            return;
        }
        
        // Lade Level 2 Optionen basierend auf Level 1
        await this.loadLevel2Options(comboId, level1Uuid);
        
        // Vorschlag ausblenden, da Level 1 manuell geändert wurde
        const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
        if (suggestionContainer2) {
            suggestionContainer2.innerHTML = '';
        }
        
        // Level 2 Select zurücksetzen (nur wenn nicht bestätigt)
        const level2Select = comboEl.querySelector('.industry-level2-select');
        if (level2Select && !level2Select.disabled) {
            level2Select.value = '';
        }
    }
    
    /**
     * Bestätigt Level 1
     */
    async confirmLevel1(comboId) {
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (!comboEl) return;
        
        const stagingUuid = comboEl.dataset.stagingUuid;
        const level1Select = comboEl.querySelector('.industry-level1-select');
        const level1Uuid = level1Select?.value;
        
        if (!stagingUuid || !level1Uuid) return;
        
        try {
            // Speichere Entscheidung serverseitig
            const response = await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level1_uuid: level1Uuid,
                    confirm_level1: true
                })
            });
            
            // Update UI mit Dropdown-Optionen
            if (response.dropdown_options) {
                this.updateDropdownOptions(comboId, response.dropdown_options);
            }
            
            // Zeige Feedback
            const step1 = comboEl.querySelector('.industry-step[data-step="1"]');
            if (step1) {
                const existingFeedback = step1.querySelector('.level-confirmation-feedback');
                if (existingFeedback) existingFeedback.remove();
                
                const feedback = document.createElement('div');
                feedback.className = 'level-confirmation-feedback';
                feedback.style.cssText = 'background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;';
                feedback.textContent = '✅ Branchenbereich ausgewählt';
                step1.appendChild(feedback);
                
                level1Select.style.background = '#e9ecef';
                level1Select.disabled = true;
            }
            
            // Aktiviere Level 1 (wird aus Level 2 abgeleitet)
            this.activateLevel1(comboId);
            
            // Prüfe, ob alle Branchen bestätigt sind
            this.updateConfirmIndustriesButton();
            
        } catch (error) {
            console.error('Error confirming Level 1:', error);
            Utils.showError('Fehler beim Bestätigen: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Lädt Level 2 Optionen
     */
    async loadLevel2Options(comboId, level1Uuid) {
        try {
            const industries = await window.API.getIndustries(level1Uuid, false, 2);
            
            const level2Select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
            if (!level2Select) return;
            
            const currentValue = level2Select.value;
            level2Select.innerHTML = '<option value="">-- Bitte wählen --</option>';
            
            industries.forEach(industry => {
                const option = document.createElement('option');
                option.value = industry.industry_uuid;
                const displayName = industry.display_name || industry.name_short || industry.name;
                option.textContent = industry.code ? `${industry.code} - ${displayName}` : displayName;
                level2Select.appendChild(option);
            });
            
            // Setze vorbelegten Wert (wenn vorhanden)
            if (currentValue) {
                level2Select.value = currentValue;
            }
            
        } catch (error) {
            console.error('Error loading Level 2 options:', error);
        }
    }
    
    /**
     * Wird aufgerufen, wenn Level 2 ausgewählt wird
     * WICHTIG: Wenn Level 2 ausgewählt wird, sollte Level 1 automatisch abgeleitet werden!
     */
    async onLevel2Selected(comboId, level2Uuid) {
        if (!level2Uuid) {
            this.resetLevel1(comboId);
            this.resetLevel3(comboId);
            return;
        }
        
        // Lade Level 1 aus Level 2 ab (automatisch)
        // Hole Parent-Info aus den bereits geladenen level2_candidates
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (comboEl) {
            // Versuche Parent-Info aus dem ausgewählten Option-Element zu holen
            const level2Select = comboEl.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
            if (level2Select) {
                const selectedOption = level2Select.options[level2Select.selectedIndex];
                // Parent-Info sollte in data-Attributen gespeichert sein, oder wir holen es aus der Staging-Row
                // Für jetzt: Lade es aus der Staging-Row (wenn verfügbar)
            }
        }
        
        // Lade Level 1 Parent von Level 2 über API
        try {
            // Hole alle Level 2 Industries mit Parent-Info
            const industries = await window.API.getIndustries(null, false, 2);
            const selectedLevel2 = industries.find(i => i.industry_uuid === level2Uuid);
            
            if (selectedLevel2 && selectedLevel2.parent_industry_uuid) {
                const level1Select = document.querySelector(`.industry-level1-select[data-combo-id="${comboId}"]`);
                if (level1Select) {
                    // Lade Level 1 Optionen, falls noch nicht geladen
                    if (level1Select.options.length <= 1) {
                        await this.loadAllLevel1Options();
                    }
                    
                    level1Select.value = selectedLevel2.parent_industry_uuid;
                    
                    // Hole Parent-Name
                    const level1Industries = await window.API.getIndustries(null, false, 1);
                    const parentLevel1 = level1Industries.find(i => i.industry_uuid === selectedLevel2.parent_industry_uuid);
                    
                    // Zeige Hinweis
                    const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                    if (suggestionContainer) {
                        suggestionContainer.innerHTML = `
                            <p class="suggestion-hint">
                                ✅ <strong>Automatisch abgeleitet:</strong> ${parentLevel1?.name || 'Branchenbereich'}
                                <small>(aus Level 2: "${selectedLevel2.name}")</small>
                            </p>
                        `;
                    }
                    
                    // Aktiviere Bestätigen-Button
                    const confirmBtn = document.querySelector(`.confirm-level1-btn[data-combo-id="${comboId}"]`);
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                    }
                    
                    // Aktiviere Level 1 Schritt
                    this.activateLevel1(comboId);
                }
            }
        } catch (error) {
            console.error('Error deriving Level 1 from Level 2:', error);
        }
        
        // Lade Level 3 Optionen
        await this.loadLevel3Options(comboId, level2Uuid);
    }
    
    /**
     * Aktiviere Level 1
     */
    activateLevel1(comboId) {
        const step1 = document.querySelector(`.industry-step[data-step="1"][data-combo-id="${comboId}"]`);
        if (step1) {
            step1.style.display = 'block';
            step1.style.opacity = '1';
            step1.style.background = '#f9f9f9';
        }
    }
    
    /**
     * Reset Level 1
     */
    resetLevel1(comboId) {
        const step1 = document.querySelector(`.industry-step[data-step="1"][data-combo-id="${comboId}"]`);
        if (step1) {
            const select = step1.querySelector('.industry-level1-select');
            if (select) select.value = '';
            const feedback = step1.querySelector('.level-confirmation-feedback');
            if (feedback) feedback.remove();
            step1.style.display = 'none';
        }
    }
    
    /**
     * Bestätigt Level 2
     */
    async confirmLevel2(comboId) {
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (!comboEl) return;
        
        const stagingUuid = comboEl.dataset.stagingUuid;
        const level2Select = comboEl.querySelector('.industry-level2-select');
        const level2Uuid = level2Select?.value;
        
        if (!stagingUuid || !level2Uuid) return;
        
        try {
            // Speichere Entscheidung serverseitig
            const response = await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level2_uuid: level2Uuid,
                    confirm_level2: true
                })
            });
            
            // Update UI
            const step2 = comboEl.querySelector('.industry-step[data-step="2"]');
            if (step2) {
                const existingFeedback = step2.querySelector('.level-confirmation-feedback');
                if (existingFeedback) existingFeedback.remove();
                
                const feedback = document.createElement('div');
                feedback.className = 'level-confirmation-feedback';
                feedback.style.cssText = 'background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;';
                feedback.textContent = '✅ Branche ausgewählt';
                step2.appendChild(feedback);
                
                level2Select.style.background = '#e9ecef';
                level2Select.disabled = true;
            }
            
            // Aktiviere Level 3
            this.activateLevel3(comboId);
            
            // Lade Level 3 Optionen
            await this.loadLevel3Options(comboId, level2Uuid);
            
            // Level 1 automatisch bestätigen, da es aus Level 2 abgeleitet wird
            const level1Select = comboEl.querySelector('.industry-level1-select');
            const level1Feedback = comboEl.querySelector('.industry-step[data-step="1"] .level-confirmation-feedback');
            if (level1Select && level1Select.value && !level1Select.disabled && !level1Feedback) {
                // Level 1 ist bereits aus Level 2 abgeleitet, aber noch nicht bestätigt - bestätige es automatisch
                await this.confirmLevel1(comboId);
            }
            
            // Prüfe, ob alle Branchen bestätigt sind
            this.updateConfirmIndustriesButton();
            
        } catch (error) {
            console.error('Error confirming Level 2:', error);
            Utils.showError('Fehler beim Bestätigen: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Lädt Level 3 Optionen
     */
    async loadLevel3Options(comboId, level2Uuid) {
        try {
            const industries = await window.API.getIndustries(level2Uuid, false, 3);
            
            const level3Select = document.querySelector(`.industry-level3-select[data-combo-id="${comboId}"]`);
            if (!level3Select) return;
            
            const currentValue = level3Select.value;
            level3Select.innerHTML = '<option value="">-- Bitte wählen --</option>';
            
            industries.forEach(industry => {
                const option = document.createElement('option');
                option.value = industry.industry_uuid;
                const displayName = industry.display_name || industry.name_short || industry.name;
                option.textContent = displayName;
                level3Select.appendChild(option);
            });
            
            if (currentValue) {
                level3Select.value = currentValue;
            }
            
        } catch (error) {
            console.error('Error loading Level 3 options:', error);
        }
    }
    
    /**
     * Wird aufgerufen, wenn Level 3 ausgewählt wird
     */
    async onLevel3Selected(comboId, level3Uuid) {
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (!comboEl) return;
        
        const stagingUuid = comboEl.dataset.stagingUuid;
        if (!stagingUuid || !level3Uuid) return;
        
        try {
            // Speichere Entscheidung serverseitig
            await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level3_uuid: level3Uuid,
                    level3_action: 'SELECT_EXISTING'
                })
            });
            
            // Update UI: Zeige Bestätigung
            const step3 = comboEl.querySelector('.industry-step[data-step="3"]');
            if (step3) {
                const level3Select = comboEl.querySelector('.industry-level3-select');
                if (level3Select) {
                    level3Select.style.background = '#e9ecef';
                    level3Select.disabled = true;
                }
                
                const existingFeedback = step3.querySelector('.level-confirmation-feedback');
                if (existingFeedback) existingFeedback.remove();
                
                const feedback = document.createElement('div');
                feedback.className = 'level-confirmation-feedback';
                feedback.style.cssText = 'background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;';
                feedback.textContent = '✅ Unterbranche ausgewählt';
                step3.appendChild(feedback);
            }
            
            Utils.showSuccess('Unterbranche ausgewählt');
            
            // Prüfe, ob alle Branchen bestätigt sind (nach kurzer Verzögerung, damit DOM aktualisiert ist)
            setTimeout(() => {
                this.updateConfirmIndustriesButton();
            }, 100);
            
        } catch (error) {
            console.error('Error selecting Level 3:', error);
            Utils.showError('Fehler: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Fügt Level 3 als neue Unterbranche hinzu
     */
    async addLevel3FromCombo(comboId) {
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (!comboEl) return;
        
        const stagingUuid = comboEl.dataset.stagingUuid;
        const level3Input = comboEl.querySelector('.industry-level3-new-input');
        const level3Name = level3Input?.value?.trim();
        const level2Select = comboEl.querySelector('.industry-level2-select');
        const level2Uuid = level2Select?.value;
        
        if (!stagingUuid || !level3Name || !level2Uuid) {
            Utils.showError('Bitte füllen Sie alle Felder aus.');
            return;
        }
        
        try {
            // Speichere Entscheidung serverseitig
            const response = await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level3_new_name: level3Name,
                    level3_action: 'CREATE_NEW'
                })
            });
            
            // Update UI: Zeige Bestätigung
            const step3 = comboEl.querySelector('.industry-step[data-step="3"]');
            if (step3) {
                const existingFeedback = step3.querySelector('.level-confirmation-feedback');
                if (existingFeedback) existingFeedback.remove();
                
                const feedback = document.createElement('div');
                feedback.className = 'level-confirmation-feedback';
                feedback.style.cssText = 'background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;';
                feedback.textContent = `✅ Unterbranche "${level3Name}" wird beim Import erstellt.`;
                step3.appendChild(feedback);
                
                // Deaktiviere Input
                if (level3Input) {
                    level3Input.style.background = '#e9ecef';
                    level3Input.disabled = true;
                }
            }
            
            Utils.showSuccess(`Unterbranche "${level3Name}" wird beim Import erstellt.`);
            
            // Prüfe, ob alle Branchen bestätigt sind (nach kurzer Verzögerung, damit DOM aktualisiert ist)
            setTimeout(() => {
                this.updateConfirmIndustriesButton();
            }, 200);
            
        } catch (error) {
            console.error('Error adding Level 3:', error);
            const errorMsg = error.message || 'Unbekannter Fehler';
            Utils.showError('Fehler: ' + errorMsg);
        }
    }
    
    /**
     * Aktiviere Level 2
     */
    activateLevel2(comboId) {
        const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
        if (step2) {
            step2.style.display = 'block';
            step2.style.opacity = '1';
            step2.style.background = '#f9f9f9';
        }
    }
    
    /**
     * Aktiviere Level 3
     */
    activateLevel3(comboId) {
        const step3 = document.querySelector(`.industry-step[data-step="3"][data-combo-id="${comboId}"]`);
        if (step3) {
            step3.style.display = 'block';
            step3.style.opacity = '1';
            step3.style.background = '#f9f9f9';
        }
    }
    
    /**
     * Reset Level 2
     */
    resetLevel2(comboId) {
        const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
        if (step2) {
            const select = step2.querySelector('.industry-level2-select');
            if (select) select.value = '';
            const feedback = step2.querySelector('.level-confirmation-feedback');
            if (feedback) feedback.remove();
            step2.style.display = 'none';
        }
    }
    
    /**
     * Reset Level 3
     */
    resetLevel3(comboId) {
        const step3 = document.querySelector(`.industry-step[data-step="3"][data-combo-id="${comboId}"]`);
        if (step3) {
            const select = step3.querySelector('.industry-level3-select');
            if (select) select.value = '';
            const input = step3.querySelector('.industry-level3-new-input');
            if (input) input.value = '';
            const feedback = step3.querySelector('.level-confirmation-feedback');
            if (feedback) feedback.remove();
            step3.style.display = 'none';
        }
    }
    
    /**
     * Update Dropdown-Optionen
     */
    updateDropdownOptions(comboId, options) {
        // Level 1
        if (options.level1) {
            const select = document.querySelector(`.industry-level1-select[data-combo-id="${comboId}"]`);
            if (select) {
                // Options werden bereits geladen
            }
        }
        
        // Level 2
        if (options.level2) {
            const select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
            if (select) {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                options.level2.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.industry_uuid;
                    option.textContent = opt.code ? `${opt.code} - ${opt.name}` : opt.name;
                    select.appendChild(option);
                });
                if (currentValue) select.value = currentValue;
            }
        }
        
        // Level 3
        if (options.level3) {
            const select = document.querySelector(`.industry-level3-select[data-combo-id="${comboId}"]`);
            if (select) {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                options.level3.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt.industry_uuid;
                    const displayName = opt.display_name || opt.name_short || opt.name;
                    option.textContent = displayName;
                    select.appendChild(option);
                });
                if (currentValue) select.value = currentValue;
            }
        }
    }
    
    /**
     * Lädt Level 1 Optionen für alle Dropdowns
     */
    async loadAllLevel1Options() {
        try {
            const industries = await window.API.getIndustries(null, false, 1);
            
            document.querySelectorAll('.industry-level1-select').forEach(select => {
                const currentValue = select.value;
                const comboId = select.dataset.comboId;
                
                select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    const displayName = industry.display_name || industry.name_short || industry.name;
                    option.textContent = industry.code ? `${industry.code} - ${displayName}` : displayName;
                    select.appendChild(option);
                });
                
                // Setze vorbelegten Wert (wenn vorhanden)
                if (currentValue) {
                    select.value = currentValue;
                } else {
                    // Prüfe, ob es einen Vorschlag gibt
                    const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
                    if (comboEl) {
                        const suggestionHint = comboEl.querySelector('.suggestion-hint');
                        if (suggestionHint) {
                            // Extrahiere UUID aus Vorschlag (wird in renderIndustryCombination gesetzt)
                            const suggestionText = suggestionHint.textContent;
                            // Versuche UUID aus data-attribute zu holen
                            const preSelectedUuid = comboEl.dataset.level1Uuid;
                            if (preSelectedUuid) {
                                select.value = preSelectedUuid;
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Error loading Level 1 options:', error);
        }
    }
    
    /**
     * Get Field Label
     */
    getFieldLabel(field) {
        const labels = {
            'name': 'Name',
            'website': 'Website',
            'industry_level1': 'Branchenbereich',
            'industry_level2': 'Branche',
            'industry_level3': 'Unterbranche',
            'revenue_range': 'Umsatz',
            'employee_count': 'Mitarbeiter',
            'notes': 'Notizen',
            'address_street': 'Straße',
            'address_postal_code': 'PLZ',
            'address_city': 'Stadt',
            'address_state': 'Bundesland',
            'email': 'E-Mail',
            'fax': 'Fax',
            'phone': 'Telefon',
            'vat_id': 'USt-ID'
        };
        return labels[field] || field;
    }
    
    /**
     * Speichert Mapping-Konfiguration
     */
    async saveMapping() {
        if (!this.currentBatch) {
            Utils.showError('Kein Batch vorhanden');
            return;
        }
        
        try {
            // Sammle Mapping aus Radio-Buttons
            const mappingConfig = {
                header_row: this.analysis?.header_row || 1,
                data_start_row: (this.analysis?.header_row || 1) + 1,
                columns: {}
            };
            
            // Sammle alle Radio-Button-Auswahlen
            // WICHTIG: Branchen-Felder werden hier NICHT gespeichert - die werden in Schritt 3 behandelt
            document.querySelectorAll('input[type="radio"][name^="mapping_"]:checked').forEach(radio => {
                const name = radio.name;
                const field = name.replace('mapping_', '');
                const excelColumn = radio.value;
                
                // Überspringe Branchen-Felder (werden in Schritt 3 behandelt)
                if (field === 'industry_level1' || field === 'industry_level2' || field === 'industry_level3' ||
                    field === 'industry_main' || field === 'industry_sub') {
                    return;
                }
                
                if (field && excelColumn) {
                    // Finde Header-Name aus Analysis
                    const headerName = this.getHeaderNameForColumn(excelColumn);
                    
                    mappingConfig.columns[field] = {
                        excel_column: excelColumn,
                        excel_header: headerName,
                        required: field === 'name' // Name ist Pflichtfeld
                    };
                }
            });
            
            // Prüfe, ob mindestens Name gemappt ist
            if (!mappingConfig.columns.name) {
                Utils.showError('Bitte mappen Sie mindestens das Feld "Name"');
                return;
            }
            
            // Speichere Mapping
            const response = await this.fetchWithToken(`/tom3/public/api/import/mapping/${this.currentBatch}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mapping_config: mappingConfig })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Speichern');
            }
            
            Utils.showSuccess('Mapping erfolgreich gespeichert!');
            
            // Automatisch in Staging importieren (mit Branchen-Vorschlägen)
            Utils.showInfo('Importiere Daten in Staging und erstelle Branchen-Vorschläge...');
            await this.importToStaging();
            
            // Gehe zu Schritt 3 (Branchen-Prüfung)
            this.goToStep(3);
            
        } catch (error) {
            console.error('Save mapping error:', error);
            Utils.showError('Fehler beim Speichern des Mappings: ' + error.message);
        }
    }
    
    /**
     * Holt Header-Name für Spalte aus Analysis
     */
    getHeaderNameForColumn(excelColumn) {
        if (!this.analysis || !this.analysis.columns) {
            return excelColumn;
        }
        
        return this.analysis.columns[excelColumn] || excelColumn;
    }
    
    /**
     * Importiert in Staging (wird nach Mapping-Speicherung automatisch aufgerufen)
     * WICHTIG: Backend holt file_path automatisch aus DocumentService/BlobService
     */
    async importToStaging() {
        try {
            // 1. Prüfe, ob Mapping vorhanden ist
            const batch = await window.API.request(`/import/batch/${this.currentBatch}`);
            
            if (!batch || !batch.mapping_config) {
                throw new Error('Kein Mapping gefunden. Bitte speichern Sie zuerst das Mapping.');
            }
            
            // 2. Importiere in Staging (mit Branchen-Vorschlägen)
            // Backend holt file_path automatisch aus DocumentService/BlobService
            const stagingResponse = await this.fetchWithToken(`/tom3/public/api/import/staging/${this.currentBatch}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
                // Kein Body nötig - Backend holt file_path selbst
            });
            
            if (!stagingResponse.ok) {
                const error = await stagingResponse.json();
                throw new Error(error.message || 'Fehler beim Import in Staging');
            }
            
            const stagingResult = await stagingResponse.json();
            
            // Prüfe, ob Daten importiert wurden
            if (stagingResult.error) {
                // Backend hat bereits einen Fehler gemeldet
                throw new Error(stagingResult.message || stagingResult.error);
            }
            
            if (stagingResult.stats && stagingResult.stats.imported === 0) {
                if (stagingResult.stats.total_rows > 0) {
                    // Zeilen vorhanden, aber alle hatten Fehler
                    const errorDetails = stagingResult.stats.errors_detail?.[0];
                    const errorMsg = errorDetails 
                        ? `Import fehlgeschlagen: ${stagingResult.stats.errors || 0} Fehler. Erster Fehler (Zeile ${errorDetails.row}): ${errorDetails.error}`
                        : `Import fehlgeschlagen: ${stagingResult.stats.errors || 0} Fehler. Bitte prüfen Sie die Mapping-Konfiguration.`;
                    throw new Error(errorMsg);
                } else {
                    // Keine Zeilen gefunden
                    throw new Error('Keine Datenzeilen in der Excel-Datei gefunden. Bitte prüfen Sie die Datei.');
                }
            }
            
            this.stagingImported = true;
            
            Utils.showSuccess(`Import erfolgreich! ${stagingResult.stats?.imported || 0} Zeilen importiert.`);
            
            return stagingResult;
            
        } catch (error) {
            console.error('Error importing to staging:', error);
            Utils.showError('Fehler beim Import in Staging: ' + error.message);
            throw error;
        }
    }
    
    /**
     * Rendert Branchen-Prüfung Step (Schritt 3)
     */
    async renderIndustryCheckStep() {
        const container = document.getElementById('industry-check-content');
        if (!container || !this.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Branchen-Vorschläge...</p>';
        
        try {
            // Prüfe, ob bereits in Staging importiert wurde
            if (!this.stagingImported) {
                // Falls nicht, importiere jetzt
                await this.importToStaging();
            }
            
            // Lade Staging-Rows mit industry_resolution
            const stagingRows = await this.loadStagingRows(this.currentBatch);
            
            if (stagingRows.length === 0) {
                container.innerHTML = '<p class="error">Keine Staging-Daten gefunden. Bitte importieren Sie zuerst die Daten.</p>';
                return;
            }
            
            // Extrahiere eindeutige Branchen-Kombinationen aus Staging-Rows
            const combinations = this.extractIndustryCombinations(stagingRows);
            
            if (combinations.length === 0) {
                container.innerHTML = '<p class="info">Keine Branchendaten in den importierten Daten gefunden.</p>';
                const confirmBtn = document.getElementById('confirm-industries-btn');
                if (confirmBtn) {
                    confirmBtn.style.display = 'inline-block';
                    confirmBtn.textContent = 'Weiter zum Review →';
                    confirmBtn.onclick = () => this.goToStep(4);
                }
                return;
            }
            
            // Rendere Branchen-Prüfung UI
            let html = '<div class="industry-check-section">';
            html += '<div class="industry-header">';
            html += '<div class="industry-header-icon">⚠️</div>';
            html += '<div class="industry-header-text">';
            html += '<h4>Branchen-Prüfung</h4>';
            html += '<p class="industry-description">Bitte prüfen und bestätigen Sie die Branchenzuordnungen:</p>';
            html += '</div>';
            html += '</div>';
            
            // Für jede Kombination
            combinations.forEach((combo, index) => {
                const comboId = `combo_${index}`;
                html += this.renderIndustryCombination(comboId, combo);
            });
            
            html += '</div>';
            container.innerHTML = html;
            
            // Lade Level 1 Optionen für alle Dropdowns
            await this.loadAllLevel1Options();
            
            // Lade Vorschläge für alle Kombinationen
            combinations.forEach((combo, index) => {
                const comboId = `combo_${index}`;
                if (combo.staging_uuid) {
                    this.loadStagingRowForCombination(comboId, combo.staging_uuid);
                }
            });
            
            // Zeige Bestätigen-Button
            const confirmBtn = document.getElementById('confirm-industries-btn');
            if (confirmBtn) {
                confirmBtn.style.display = 'inline-block';
                // Initial prüfen und Button entsprechend aktivieren/deaktivieren
                this.updateConfirmIndustriesButton();
            }
            
        } catch (error) {
            console.error('Error loading industry check:', error);
            container.innerHTML = `<p class="error">Fehler beim Laden: ${error.message}</p>`;
        }
    }
    
    /**
     * Prüft, ob alle Branchen-Level für alle Kombinationen bestätigt sind
     * @returns {boolean} true wenn alle Level bestätigt sind
     */
    checkAllIndustriesConfirmed() {
        const combinations = document.querySelectorAll('.industry-combination');
        if (combinations.length === 0) {
            return false;
        }
        
        for (let i = 0; i < combinations.length; i++) {
            const combo = combinations[i];
            
            // Level 1 muss bestätigt sein (disabled select + feedback vorhanden)
            const level1Select = combo.querySelector('.industry-level1-select');
            const level1Feedback = combo.querySelector('.industry-step[data-step="1"] .level-confirmation-feedback');
            const level1Ok = level1Select && level1Select.disabled && level1Feedback && level1Select.value;
            if (!level1Ok) {
                return false;
            }
            
            // Prüfe, ob Level 2 Optionen verfügbar sind
            const level2Select = combo.querySelector('.industry-level2-select');
            const hasLevel2Options = level2Select && level2Select.options.length > 1; // Mehr als nur "-- Bitte wählen --"
            
            // Wenn Level 2 Optionen verfügbar sind, muss Level 2 bestätigt sein
            if (hasLevel2Options) {
                const level2Ok = level2Select && level2Select.disabled && 
                               combo.querySelector('.industry-step[data-step="2"] .level-confirmation-feedback') && 
                               level2Select.value;
                if (!level2Ok) {
                    return false;
                }
                
                // Wenn Level 2 bestätigt ist, muss Level 3 geprüft werden
                const level3Step = combo.querySelector('.industry-step[data-step="3"]');
                if (level3Step) {
                    // Prüfe Sichtbarkeit über computed style
                    const computedStyle = window.getComputedStyle(level3Step);
                    const isVisible = computedStyle.display !== 'none' && 
                                     computedStyle.visibility !== 'hidden' &&
                                     computedStyle.opacity !== '0';
                    
                    if (isVisible) {
                        // Level 3 Schritt ist sichtbar, daher muss es bestätigt sein
                        const level3Select = combo.querySelector('.industry-level3-select');
                        const level3Input = combo.querySelector('.industry-level3-new-input');
                        const level3Feedback = combo.querySelector('.industry-step[data-step="3"] .level-confirmation-feedback');
                        
                        // Prüfe, ob Level 3 bestätigt ist
                        let level3Confirmed = false;
                        
                        if (level3Select) {
                            // Select vorhanden: muss ausgewählt UND bestätigt sein (disabled + feedback)
                            level3Confirmed = level3Select.value && 
                                            level3Select.value !== '' &&
                                            level3Select.disabled && 
                                            !!level3Feedback;
                        } else if (level3Input) {
                            // Input vorhanden: muss bestätigt sein (disabled + feedback)
                            level3Confirmed = level3Input.disabled && !!level3Feedback;
                        }
                        
                        if (!level3Confirmed) {
                            return false;
                        }
                    }
                }
            }
        }
        
        return true;
    }
    
    /**
     * Aktualisiert den "Branchen bestätigen" Button basierend auf dem Bestätigungsstatus
     */
    updateConfirmIndustriesButton() {
        const confirmBtn = document.getElementById('confirm-industries-btn');
        if (!confirmBtn) {
            return;
        }
        
        const allConfirmed = this.checkAllIndustriesConfirmed();
        
        if (allConfirmed) {
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
            confirmBtn.style.cursor = 'pointer';
            confirmBtn.title = '';
        } else {
            confirmBtn.disabled = true;
            confirmBtn.style.opacity = '0.5';
            confirmBtn.style.cursor = 'not-allowed';
            confirmBtn.title = 'Bitte bestätigen Sie zuerst alle Branchen-Level (Level 1-3) für alle Kombinationen';
        }
    }
    
    /**
     * Extrahiert eindeutige Branchen-Kombinationen aus Staging-Rows
     */
    extractIndustryCombinations(stagingRows) {
        const combinationsMap = new Map();
        
        stagingRows.forEach(row => {
            const mappedData = row.mapped_data || {};
            const industry = mappedData.industry || {};
            const excelLevel2 = industry.excel_level2_label || industry.industry_level2 || null;
            const excelLevel3 = industry.excel_level3_label || industry.industry_level3 || null;
            
            if (excelLevel2) {
                const key = `${excelLevel2}|||${excelLevel3 || ''}`;
                if (!combinationsMap.has(key)) {
                    combinationsMap.set(key, {
                        excel_level2: excelLevel2,
                        excel_level3: excelLevel3 || null,
                        staging_uuid: row.staging_uuid, // Erste Row mit dieser Kombination
                        count: 1
                    });
                } else {
                    combinationsMap.get(key).count++;
                }
            }
        });
        
        return Array.from(combinationsMap.values());
    }
    
    /**
     * Bestätigt alle Branchen-Entscheidungen und reichert Staging-Daten an
     */
    async confirmAllIndustries() {
        try {
            Utils.showInfo('Reichere Staging-Daten mit Branchen-Entscheidungen an...');
            
            // Lade alle Staging-Rows
            const stagingRows = await this.loadStagingRows(this.currentBatch);
            
            // Für jede Row: Aktualisiere industry_resolution und mapped_data
            let updated = 0;
            for (const row of stagingRows) {
                const comboEl = document.querySelector(`[data-staging-uuid="${row.staging_uuid}"]`);
                if (!comboEl) continue;
                
                // Hole Entscheidungen aus UI
                const level1Select = comboEl.querySelector('.industry-level1-select');
                const level2Select = comboEl.querySelector('.industry-level2-select');
                const level3Select = comboEl.querySelector('.industry-level3-select');
                
                const level1Uuid = level1Select?.value || null;
                const level2Uuid = level2Select?.value || null;
                const level3Uuid = level3Select?.value || null;
                
                if (level1Uuid && level2Uuid) {
                    // Aktualisiere industry_resolution.decision
                    const resolution = row.industry_resolution || { decision: {} };
                    resolution.decision = {
                        ...resolution.decision,
                        level1_uuid: level1Uuid,
                        level2_uuid: level2Uuid,
                        level3_uuid: level3Uuid,
                        level1_confirmed: true,
                        level2_confirmed: true,
                        level3_action: level3Uuid ? 'SELECT_EXISTING' : 'UNDECIDED'
                    };
                    
                    // Speichere aktualisierte industry_resolution
                    await this.fetchWithToken(`/tom3/public/api/import/staging/${row.staging_uuid}/industry-decision`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            level1_uuid: level1Uuid,
                            level2_uuid: level2Uuid,
                            level3_uuid: level3Uuid,
                            confirm_level1: true,
                            confirm_level2: true
                        })
                    });
                    
                    updated++;
                }
            }
            
            Utils.showSuccess(`${updated} Zeilen mit Branchendaten angereichert.`);
            
            // Gehe zu Schritt 4 (Review)
            this.goToStep(4);
            
        } catch (error) {
            console.error('Error confirming industries:', error);
            Utils.showError('Fehler beim Anreichern der Daten: ' + error.message);
        }
    }
    
    /**
     * Rendert Review-Step (Schritt 4)
     */
    async renderReviewStep() {
        const container = document.getElementById('review-content');
        if (!container || !this.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Staging-Daten...</p>';
        
        try {
            // Lade Batch-Status
            const batchResponse = await fetch(`/tom3/public/api/import/batch/${this.currentBatch}/stats`);
            if (!batchResponse.ok) {
                throw new Error('Batch nicht gefunden');
            }
            const batch = await batchResponse.json();
            const batchStats = batch.stats || {};
            
            // Lade Staging-Rows (bereits importiert und angereichert)
            const stagingRows = await this.loadStagingRows(this.currentBatch);
            this.stagingRows = stagingRows;
            
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
                            <button class="btn btn-secondary" onclick="window.app.import.showOverview()">
                                Zurück zur Übersicht
                            </button>
                        </p>
                    </div>
                `;
                return;
            }
            
            // Rendere Review-UI (nur für nicht-importierte Rows)
            // Filtere importierte Rows heraus
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
                    if (hasPendingRows) {
                        // Wenn es pending Rows gibt, zeige "Alle freigeben & importieren"
                        commitBtn.textContent = 'Alle freigeben & importieren →';
                    } else {
                        // Nur approved Rows - zeige "Importieren"
                        commitBtn.textContent = 'Importieren →';
                    }
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
     * Holt Datei-Pfad für Batch (wird vom Backend gehandhabt)
     * @deprecated Backend holt file_path automatisch aus DocumentService/BlobService
     */
    async getFilePathForBatch(batch) {
        // Backend holt file_path automatisch aus DocumentService/BlobService
        // Diese Methode wird nicht mehr benötigt
        return null;
    }
    
    /**
     * Lädt Staging-Rows für Batch
     */
    async loadStagingRows(batchUuid) {
        try {
            // Hole alle Staging-Rows für Batch (via API)
            const response = await fetch(`/tom3/public/api/import/batch/${batchUuid}/staging-rows`);
            
            if (!response.ok) {
                // Fallback: Versuche einzelne Rows zu laden (nicht ideal)
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
        
        // Verwende nur nicht-importierte Rows für Anzeige
        const visibleRows = stagingRows.filter(row => row.import_status !== 'imported');
        
        visibleRows.forEach((row, index) => {
            const mappedData = row.mapped_data || {};
            const orgData = mappedData.org || {};
            const validationStatus = row.validation_status || 'pending';
            const duplicateStatus = row.duplicate_status || 'unknown';
            const disposition = row.disposition || 'pending';
            const isImported = row.import_status === 'imported';
            
            // Disposition-Badge (isImported sollte hier immer false sein, da visibleRows bereits gefiltert)
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
            
            // Action-Buttons für Disposition (nur wenn nicht importiert)
            let dispositionActions = '';
            if (!isImported) {
                if (disposition !== 'approved') {
                    dispositionActions += `<button class="btn btn-sm btn-success" onclick="window.app.import.setRowDisposition('${row.staging_uuid}', 'approved')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Freigeben">✓</button>`;
                }
                if (disposition !== 'skip') {
                    dispositionActions += `<button class="btn btn-sm btn-secondary" onclick="window.app.import.setRowDisposition('${row.staging_uuid}', 'skip')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Überspringen">⏭</button>`;
                }
                if (disposition !== 'needs_fix') {
                    dispositionActions += `<button class="btn btn-sm btn-warning" onclick="window.app.import.setRowDisposition('${row.staging_uuid}', 'needs_fix')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Muss korrigiert werden">⚠</button>`;
                }
                // Button zum Zurücksetzen auf pending (wenn nicht bereits pending)
                if (disposition !== 'pending') {
                    dispositionActions += `<button class="btn btn-sm btn-outline-secondary" onclick="window.app.import.setRowDisposition('${row.staging_uuid}', 'pending')" style="padding: 2px 8px; font-size: 11px;" title="Zurücksetzen">↺</button>`;
                }
            } else {
                dispositionActions = '<span style="color: #666; font-size: 11px;">Bereits importiert</span>';
            }
            
            html += '<tr style="min-height: 80px; height: auto;">';
            html += `<td style="vertical-align: top; padding: 12px 8px;">${row.row_number}</td>`; // Zeige originale row_number
            html += `<td style="vertical-align: top; padding: 12px 8px;">${orgData.name || '-'}</td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;">${orgData.website || '-'}</td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;"><span class="status-badge status-${validationStatus}">${validationStatus}</span></td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;"><span class="duplicate-badge duplicate-${duplicateStatus}">${duplicateStatus}</span></td>`;
            html += `<td style="min-width: 220px; white-space: normal; vertical-align: top; padding: 12px 8px;">${dispositionBadge}<br><div style="margin-top: 4px;">${dispositionActions}</div></td>`;
            html += `<td style="vertical-align: top; padding: 12px 8px;"><button class="btn btn-sm" onclick="window.app.import.showRowDetail('${row.staging_uuid}')">Details</button></td>`;
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
            
            const response = await this.fetchWithToken(`/tom3/public/api/import/staging/${stagingUuid}/disposition`, {
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
            
            // Aktualisiere die Row in der Tabelle direkt (ohne vollständiges Neuladen)
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
            // Lade aktualisierte Row-Daten
            const row = await window.API.request(`/import/staging/${stagingUuid}`);
            
            if (!row) return;
            
            // Finde die Tabellenzeile
            const table = document.querySelector('.review-table tbody');
            if (!table) {
                // Falls Tabelle nicht gefunden, lade Review-Seite neu
                await this.renderReviewStep();
                return;
            }
            
            // Finde die Zeile (suche nach staging_uuid in einem data-Attribut oder über Details-Button)
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
                // Zeile nicht gefunden, lade Review-Seite neu
                await this.renderReviewStep();
                return;
            }
            
            // Verwende effective_data (mapped_data + corrections merged), falls vorhanden
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
                    dispositionActions += `<button class="btn btn-sm btn-success" onclick="window.app.import.setRowDisposition('${stagingUuid}', 'approved')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Freigeben">✓</button>`;
                }
                if (disposition !== 'skip') {
                    dispositionActions += `<button class="btn btn-sm btn-secondary" onclick="window.app.import.setRowDisposition('${stagingUuid}', 'skip')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Überspringen">⏭</button>`;
                }
                if (disposition !== 'needs_fix') {
                    dispositionActions += `<button class="btn btn-sm btn-warning" onclick="window.app.import.setRowDisposition('${stagingUuid}', 'needs_fix')" style="margin-right: 4px; padding: 2px 8px; font-size: 11px;" title="Muss korrigiert werden">⚠</button>`;
                }
                // Button zum Zurücksetzen auf pending (wenn nicht bereits pending)
                if (disposition !== 'pending') {
                    dispositionActions += `<button class="btn btn-sm btn-outline-secondary" onclick="window.app.import.setRowDisposition('${stagingUuid}', 'pending')" style="padding: 2px 8px; font-size: 11px;" title="Zurücksetzen">↺</button>`;
                }
            } else {
                dispositionActions = '<span style="color: #666; font-size: 11px;">Bereits importiert</span>';
            }
            
            // Aktualisiere alle relevanten Spalten
            const cells = targetRow.querySelectorAll('td');
            if (cells.length >= 7) {
                // Spalte 1: Name (wird aktualisiert, wenn Korrekturen vorhanden)
                cells[1].textContent = orgData.name || '-';
                
                // Spalte 2: Website (wird aktualisiert, wenn Korrekturen vorhanden)
                cells[2].textContent = orgData.website || '-';
                
                // Spalte 3: Status (validationStatus) - bleibt unverändert
                // cells[3] bleibt unverändert
                
                // Spalte 4: Duplikat (duplicateStatus) - bleibt unverändert
                // cells[4] bleibt unverändert
                
                // Spalte 5: Disposition (wird immer aktualisiert)
                cells[5].innerHTML = `${dispositionBadge}<br><div style="margin-top: 4px;">${dispositionActions}</div>`;
                
                // Spalte 6: Aktion (Details-Button) - bleibt unverändert
                // cells[6] bleibt unverändert
            }
            
            // Aktualisiere auch den Cache
            if (this.stagingRows) {
                const index = this.stagingRows.findIndex(r => r.staging_uuid === stagingUuid);
                if (index !== -1) {
                    this.stagingRows[index] = row;
                }
            }
            
        } catch (error) {
            console.error('Error updating row in table:', error);
            // Fallback: Lade Review-Seite neu
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
            
            // Verwende effective_data (mapped_data + corrections merged), falls vorhanden
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
                            <tr><td style="padding: 8px; border-bottom: 1px solid #eee; width: 180px;"><strong>Name:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.name || '-'}</td></tr>
                            ${orgData.vat_id ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>USt-IdNr.:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.vat_id}</td></tr>` : ''}
                            ${orgData.employee_count ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Mitarbeiter:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.employee_count}</td></tr>` : ''}
                            ${orgData.revenue_range ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Umsatz:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.revenue_range}</td></tr>` : ''}
                            ${orgData.website ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Website:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="${orgData.website}" target="_blank">${orgData.website}</a></td></tr>` : ''}
                            ${orgData.notes ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Notizen:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${orgData.notes}</td></tr>` : ''}
                        </table>
                    </div>
                    
                    ${(addressData.street || addressData.postal_code || addressData.city || addressData.state) ? `
                    <div style="margin-top: 20px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Adresse</h3>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            ${addressData.street ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee; width: 180px;"><strong>Straße:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${addressData.street}</td></tr>` : ''}
                            ${addressData.postal_code || addressData.city ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>PLZ / Ort:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${[addressData.postal_code, addressData.city].filter(Boolean).join(' ')}</td></tr>` : ''}
                            ${addressData.state ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Bundesland:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${addressData.state}</td></tr>` : ''}
                            <tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Vollständige Adresse:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${fullAddress}</td></tr>
                        </table>
                    </div>
                    ` : ''}
                    
                    ${(communicationData.email || communicationData.phone || communicationData.fax) ? `
                    <div style="margin-top: 20px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Kontaktdaten</h3>
                        <table style="width: 100%; border-collapse: collapse; margin-top: 12px;">
                            ${communicationData.email ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee; width: 180px;"><strong>E-Mail:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="mailto:${communicationData.email}">${communicationData.email}</a></td></tr>` : ''}
                            ${communicationData.phone ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Telefon:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;"><a href="tel:${communicationData.phone}">${communicationData.phone}</a></td></tr>` : ''}
                            ${communicationData.fax ? `<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Fax:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">${communicationData.fax}</td></tr>` : ''}
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
                                    ${(industryResolution.excel?.level2_label || industryData.excel_level2_label) ? `<li><strong>Level 2:</strong> ${industryResolution.excel?.level2_label || industryData.excel_level2_label}</li>` : ''}
                                    ${(industryResolution.excel?.level3_label || industryData.excel_level3_label) ? `<li><strong>Level 3:</strong> ${industryResolution.excel?.level3_label || industryData.excel_level3_label}</li>` : ''}
                                </ul>
                            </div>
                        ` : ''}
                        ${suggestions.level2_candidates && suggestions.level2_candidates.length > 0 ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Vorschläge Level 2:</strong></p>
                                <ul style="margin-left: 20px;">
                                    ${suggestions.level2_candidates.slice(0, 3).map(c => `<li>${c.name} (Score: ${c.score?.toFixed(2) || 'N/A'})</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${suggestions.level3_candidates && suggestions.level3_candidates.length > 0 ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Vorschläge Level 3:</strong></p>
                                <ul style="margin-left: 20px;">
                                    ${suggestions.level3_candidates.slice(0, 3).map(c => `<li>${c.name} (Score: ${c.score?.toFixed(2) || 'N/A'})</li>`).join('')}
                                </ul>
                            </div>
                        ` : ''}
                        ${suggestions.derived_level1 ? `
                            <div style="margin-bottom: 16px;">
                                <p><strong>Abgeleitetes Level 1:</strong> ${suggestions.derived_level1.name || suggestions.derived_level1.code || 'N/A'}</p>
                            </div>
                        ` : ''}
                        ${decision.status ? `
                            <div style="margin-top: 16px; padding: 12px; background: ${decision.status === 'APPROVED' ? '#d4edda' : '#fff3cd'}; border-radius: 4px;">
                                <p style="margin: 0;"><strong>Entscheidungs-Status:</strong> <span style="font-weight: bold; color: ${decision.status === 'APPROVED' ? '#155724' : '#856404'};">${decision.status}</span></p>
                                ${decision.level1_uuid ? `<p style="margin: 4px 0 0 0;"><strong>Level 1 UUID:</strong> <code style="font-size: 0.85em;">${decision.level1_uuid}</code></p>` : ''}
                                ${decision.level2_uuid ? `<p style="margin: 4px 0 0 0;"><strong>Level 2 UUID:</strong> <code style="font-size: 0.85em;">${decision.level2_uuid}</code></p>` : ''}
                                ${decision.level3_uuid ? `<p style="margin: 4px 0 0 0;"><strong>Level 3 UUID:</strong> <code style="font-size: 0.85em;">${decision.level3_uuid}</code></p>` : ''}
                                ${decision.level3_action ? `<p style="margin: 4px 0 0 0;"><strong>Level 3 Aktion:</strong> ${decision.level3_action}</p>` : ''}
                                ${decision.level3_new_name ? `<p style="margin: 4px 0 0 0;"><strong>Neue Level 3:</strong> <strong style="color: #155724;">${decision.level3_new_name}</strong></p>` : ''}
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
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">${JSON.stringify(row.validation_errors, null, 2)}</pre>
                    </div>
                    ` : ''}
                    
                    ${row.duplicate_summary ? `
                    <div style="margin-top: 24px;">
                        <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Duplikat-Informationen</h3>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">${JSON.stringify(row.duplicate_summary, null, 2)}</pre>
                    </div>
                    ` : ''}
                    
                    <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #ddd;">
                        ${row.import_status !== 'imported' ? `
                        <button class="btn btn-primary" onclick="window.app.import.showCorrectionForm('${stagingUuid}')" style="margin-right: 8px;">
                            ✏️ Daten korrigieren
                        </button>
                        ` : ''}
                        <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
                            Schließen
                        </button>
                    </div>
                    
                    <details style="margin-top: 24px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #f5f5f5; border-radius: 4px;">Raw Data (Original Excel)</summary>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;">${JSON.stringify(row.raw_data, null, 2)}</pre>
                    </details>
                    
                    <details style="margin-top: 12px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #f5f5f5; border-radius: 4px;">Mapped Data</summary>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;">${JSON.stringify(row.mapped_data, null, 2)}</pre>
                    </details>
                    
                    <details style="margin-top: 12px;">
                        <summary style="cursor: pointer; font-weight: bold; padding: 8px; background: #f5f5f5; border-radius: 4px;">Industry Resolution (Vollständig)</summary>
                        <pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; margin-top: 8px;">${JSON.stringify(row.industry_resolution, null, 2)}</pre>
                    </details>
                    
                    <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #ddd; display: flex; gap: 8px; justify-content: flex-end;">
                        ${row.import_status !== 'imported' ? `
                        <button class="btn btn-primary" onclick="window.app.import.showCorrectionForm('${stagingUuid}')" style="margin-right: 8px;">
                            ✏️ Daten korrigieren
                        </button>
                        ` : ''}
                        <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
                            Schließen
                        </button>
                    </div>
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
            // Lade Row-Daten
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
                    
                    <form id="correction-form" onsubmit="event.preventDefault(); window.app.import.saveCorrections('${stagingUuid}');">
                        <div style="margin-top: 20px;">
                            <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Organisationsdaten</h3>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Name *</label>
                                <input type="text" id="corr-org-name" value="${this.escapeHtml(orgData.name || '')}" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Website</label>
                                <input type="url" id="corr-org-website" value="${this.escapeHtml(orgData.website || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">USt-IdNr.</label>
                                <input type="text" id="corr-org-vat-id" value="${this.escapeHtml(orgData.vat_id || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px;">
                            <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Adresse</h3>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Straße</label>
                                <input type="text" id="corr-addr-street" value="${this.escapeHtml(addressData.street || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px; display: grid; grid-template-columns: 1fr 2fr; gap: 12px;">
                                <div>
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">PLZ</label>
                                    <input type="text" id="corr-addr-postal-code" value="${this.escapeHtml(addressData.postal_code || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 4px; font-weight: 600;">Ort</label>
                                    <input type="text" id="corr-addr-city" value="${this.escapeHtml(addressData.city || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px;">
                            <h3 style="border-bottom: 2px solid #ddd; padding-bottom: 8px;">Kontaktdaten</h3>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">E-Mail</label>
                                <input type="email" id="corr-comm-email" value="${this.escapeHtml(communicationData.email || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="margin-top: 12px;">
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;">Telefon</label>
                                <input type="tel" id="corr-comm-phone" value="${this.escapeHtml(communicationData.phone || '')}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
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
            const response = await this.fetchWithToken(`/tom3/public/api/import/staging/${stagingUuid}/corrections`, {
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
            
            // Aktualisiere die betroffene Zeile in der Tabelle (oder lade ganze Seite neu, falls das nicht geht)
            await this.updateRowInTable(stagingUuid);
            
        } catch (error) {
            console.error('Error saving corrections:', error);
            Utils.showError('Fehler: ' + error.message);
        }
    }
    
    /**
     * Lädt Staging-Row für Kombination (um industry_resolution zu holen)
     * FALLBACK: Wenn keine Staging-Row vorhanden, erstelle Vorschläge direkt aus Excel-Werten
     */
    async loadStagingRowForCombination(comboId, stagingUuid) {
        try {
            // Entferne Loading-Hinweis
            const loadingEl = document.getElementById(`loading_${comboId}`);
            if (loadingEl) {
                loadingEl.remove();
            }
            
            let resolution = null;
            let suggestions = {};
            let decision = {};
            
            // Versuche Staging-Row zu laden (wenn bereits vorhanden)
            if (stagingUuid) {
                try {
                    const row = await window.API.request(`/import/staging/${stagingUuid}`);
                    if (row && row.industry_resolution) {
                        resolution = row.industry_resolution;
                        suggestions = resolution.suggestions || {};
                        decision = resolution.decision || {};
                    }
                } catch (error) {
                    console.log('Staging-Row noch nicht vorhanden, erstelle Vorschläge direkt aus Excel-Werten');
                }
            }
            
            // FALLBACK: Wenn keine industry_resolution vorhanden, erstelle Vorschläge direkt
            if (!resolution || !suggestions.level2_candidates || suggestions.level2_candidates.length === 0) {
                const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
                if (comboEl) {
                    // Extrahiere Excel Level 2 Wert aus verschiedenen möglichen Stellen
                    let excelLevel2 = comboEl.querySelector('.excel-value')?.textContent?.match(/Level 2:\s*([^/]+)/)?.[1]?.trim() || '';
                    if (!excelLevel2) {
                        // Fallback: Versuche aus excel-value-hint zu extrahieren
                        excelLevel2 = comboEl.querySelector('.excel-value-hint')?.textContent?.match(/Excel-Wert.*?:\s*<strong>([^<]+)<\/strong>/)?.[1]?.trim() || '';
                    }
                    if (!excelLevel2 && combo.excel_level2) {
                        // Fallback: Nutze combo.excel_level2 direkt
                        excelLevel2 = combo.excel_level2;
                    }
                    
                    if (excelLevel2) {
                        // Erstelle Vorschläge direkt aus Excel-Wert
                        suggestions = await this.createSuggestionsFromExcelValue(excelLevel2);
                        decision = {
                            level1_uuid: suggestions.derived_level1?.industry_uuid || null,
                            level2_uuid: suggestions.level2_candidates?.[0]?.industry_uuid || null,
                            level1_confirmed: false,
                            level2_confirmed: false
                        };
                    } else {
                        console.warn(`Kein Excel Level 2 Wert gefunden für combo ${comboId}`);
                    }
                }
            }
            
            if (suggestions && Object.keys(suggestions).length > 0) {
                
                // Update UI mit Vorschlägen
                const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
                if (!comboEl) return;
                
                // Level 1 Vorschlag - WICHTIG: Wird aus Level 2 abgeleitet!
                if (suggestions.derived_level1) {
                    const level1Select = comboEl.querySelector('.industry-level1-select');
                    const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                    
                    if (level1Select && !level1Select.value) {
                        // Setze Vorschlag im Dropdown
                        level1Select.value = suggestions.derived_level1.industry_uuid;
                        
                        // Zeige Vorschlag-Hinweis mit Erklärung
                        if (suggestionContainer) {
                            const excelLevel2 = comboEl.querySelector('.excel-value')?.textContent?.match(/Level 2: ([^/]+)/)?.[1]?.trim() || '';
                            const level2Best = suggestions.level2_candidates?.[0];
                            const level2Name = level2Best?.name || '';
                            
                            suggestionContainer.innerHTML = `
                                <p class="suggestion-hint">
                                    ✅ <strong>Automatisch abgeleitet:</strong> ${suggestions.derived_level1.name}
                                    <small>(aus Level 2 Match: "${level2Name}")</small>
                                </p>
                            `;
                        }
                        
                        // Aktiviere Bestätigen-Button
                        const confirmBtn = comboEl.querySelector('.confirm-level1-btn');
                        if (confirmBtn) {
                            confirmBtn.disabled = false;
                        }
                        
                        // WICHTIG: Wenn Level 1 aus Level 2 abgeleitet wurde, sollte es automatisch bestätigt werden können
                        // Aber nur wenn auch Level 2 ein Match hat
                        if (suggestions.level2_candidates && suggestions.level2_candidates.length > 0) {
                            // Level 1 ist bereits vorbelegt, Benutzer kann direkt bestätigen
                        }
                    } else if (suggestionContainer && !level1Select.value) {
                        // Zeige Hinweis, dass kein Vorschlag gefunden wurde
                        suggestionContainer.innerHTML = `
                            <p class="suggestion-hint no-suggestion">
                                ⚠️ Kein Level 2 Match gefunden. Level 1 kann nicht automatisch abgeleitet werden. Bitte wählen Sie manuell.
                            </p>
                        `;
                    }
                } else {
                    // Kein Vorschlag verfügbar - bedeutet: kein Level 2 Match gefunden
                    const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                    if (suggestionContainer) {
                        suggestionContainer.innerHTML = `
                            <p class="suggestion-hint no-suggestion">
                                ⚠️ Kein Level 2 Match gefunden. Level 1 kann nicht automatisch abgeleitet werden. Bitte wählen Sie manuell.
                            </p>
                        `;
                    }
                }
                
                // Level 2 Vorschlag - WICHTIG: Dies ist der primäre Matching-Schritt!
                if (suggestions.level2_candidates && suggestions.level2_candidates.length > 0) {
                    const best = suggestions.level2_candidates[0];
                    const level2Select = comboEl.querySelector('.industry-level2-select');
                    const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
                    
                    // Wenn Level 1 bereits abgeleitet wurde, lade Level 2 Optionen
                    if (suggestions.derived_level1) {
                        await this.loadLevel2Options(comboId, suggestions.derived_level1.industry_uuid);
                    }
                    
                    if (level2Select && !level2Select.value) {
                        // Setze besten Vorschlag
                        level2Select.value = best.industry_uuid;
                        
                        // Zeige Vorschlag-Hinweis
                        if (suggestionContainer2) {
                            // Hole Excel-Wert aus dem DOM
                            const excelValueHint = comboEl.querySelector('.excel-value-hint');
                            let excelLevel2 = '';
                            if (excelValueHint) {
                                const strongTag = excelValueHint.querySelector('strong');
                                excelLevel2 = strongTag ? strongTag.textContent.trim() : '';
                            }
                            // Fallback: Versuche aus textContent zu extrahieren
                            if (!excelLevel2 && excelValueHint) {
                                const match = excelValueHint.textContent.match(/Excel-Wert.*?:\s*(.+)/);
                                excelLevel2 = match ? match[1].trim() : '';
                            }
                            
                            suggestionContainer2.innerHTML = `
                                <p class="suggestion-hint">
                                    💡 <strong>Vorschlag:</strong> ${best.name}${best.code ? ` (${best.code})` : ''}
                                    ${excelLevel2 ? `<small>(${(best.score * 100).toFixed(0)}% ähnlich zu "${excelLevel2}")</small>` : `<small>(${(best.score * 100).toFixed(0)}% ähnlich)</small>`}
                                </p>
                            `;
                        }
                        
                        // Aktiviere Bestätigen-Button
                        const confirmBtn2 = comboEl.querySelector('.confirm-level2-btn');
                        if (confirmBtn2) {
                            confirmBtn2.disabled = false;
                        }
                    }
                } else {
                    // Kein Level 2 Match gefunden
                    const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
                    if (suggestionContainer2) {
                        suggestionContainer2.innerHTML = `
                            <p class="suggestion-hint no-suggestion">
                                ⚠️ Kein automatischer Match gefunden. Bitte wählen Sie manuell.
                            </p>
                        `;
                    }
                }
            } else {
                // Keine industry_resolution verfügbar
                const loadingEl = document.getElementById(`loading_${comboId}`);
                if (loadingEl) {
                    loadingEl.remove();
                }
                const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                if (suggestionContainer) {
                    suggestionContainer.innerHTML = `
                        <p class="suggestion-hint no-suggestion">
                            ⚠️ Keine Vorschläge verfügbar. Bitte wählen Sie manuell.
                        </p>
                    `;
                }
            }
        } catch (error) {
            console.error('Error loading staging row:', error);
            // Entferne Loading-Hinweis auch bei Fehler
            const loadingEl = document.getElementById(`loading_${comboId}`);
            if (loadingEl) {
                loadingEl.remove();
            }
            const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
            if (suggestionContainer) {
                suggestionContainer.innerHTML = `
                    <p class="suggestion-hint no-suggestion">
                        ⚠️ Fehler beim Laden der Vorschläge. Bitte wählen Sie manuell.
                    </p>
                `;
            }
        }
    }
    
    /**
     * Erstellt Vorschläge direkt aus Excel-Wert (wenn Staging-Row noch nicht existiert)
     */
    async createSuggestionsFromExcelValue(excelLevel2Label) {
        try {
            // Nutze IndustryResolver API, um Vorschläge zu holen
            // Für jetzt: Nutze getIndustries API und suche manuell
            // TODO: Erstelle dedizierte API-Endpoint für Industry-Vorschläge
            
            const allLevel2 = await window.API.getIndustries(null, false, 2);
            
            // Einfache Suche nach ähnlichen Namen
            const candidates = [];
            const searchTerm = excelLevel2Label.toLowerCase().trim();
            
            for (const industry of allLevel2) {
                const name = (industry.name || '').toLowerCase();
                const code = (industry.code || '').toLowerCase();
                
                // Einfache Ähnlichkeitsprüfung
                let score = 0;
                if (name.includes(searchTerm) || searchTerm.includes(name.split(' ')[0])) {
                    score = 0.8;
                } else if (name.includes(searchTerm.split(' ')[0]) || searchTerm.includes(name.split(' ')[0])) {
                    score = 0.6;
                } else if (code && code.includes(searchTerm.substring(0, 2))) {
                    score = 0.5;
                }
                
                if (score > 0.4) {
                    candidates.push({
                        industry_uuid: industry.industry_uuid,
                        code: industry.code,
                        name: industry.name,
                        score: score
                    });
                }
            }
            
            // Sortiere nach Score
            candidates.sort((a, b) => b.score - a.score);
            
            const best = candidates[0];
            let derivedLevel1 = null;
            
            // Leite Level 1 ab
            if (best) {
                // Hole Parent (Level 1)
                const level1Industries = await window.API.getIndustries(null, false, 1);
                // Finde Parent über Level 2 Industry
                try {
                    // Hole alle Level 2 Industries mit Parent-Info
                    const level2WithParent = await window.API.request(`/industries?level=2`);
                    const found = level2WithParent.find(i => i.industry_uuid === best.industry_uuid);
                    if (found && found.parent_industry_uuid) {
                        // Finde Parent in Level 1 Industries
                        const parent = level1Industries.find(i => i.industry_uuid === found.parent_industry_uuid);
                        if (parent) {
                            derivedLevel1 = {
                                industry_uuid: parent.industry_uuid,
                                code: parent.code,
                                name: parent.name
                            };
                        }
                    }
                } catch (error) {
                    console.error('Error deriving Level 1:', error);
                }
            }
            
            return {
                level2_candidates: candidates.slice(0, 5),
                derived_level1: derivedLevel1
            };
            
        } catch (error) {
            console.error('Error creating suggestions:', error);
            return {
                level2_candidates: [],
                derived_level1: null
            };
        }
    }
    
    /**
     * Committet Batch (Import in Produktion)
     */
    async commitBatch() {
        if (!this.currentBatch) {
            Utils.showError('Kein Batch vorhanden');
            return;
        }
        
        // Prüfe, ob es pending Rows gibt, die automatisch approved werden sollen
        const batchResponse = await fetch(`/tom3/public/api/import/batch/${this.currentBatch}/stats`);
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
            // Bestimme Commit-Mode
            // Wenn der Button "Alle freigeben & importieren" heißt, verwende PENDING_AUTO_APPROVE
            const commitBtn = document.getElementById('commit-btn');
            const buttonText = commitBtn ? commitBtn.textContent : '';
            let commitMode = 'APPROVED_ONLY';
            
            if (buttonText.includes('Alle freigeben')) {
                // Button sagt "Alle freigeben & importieren" - approve alle pending Rows
                commitMode = 'PENDING_AUTO_APPROVE';
            } else if (hasPendingRows && !hasApprovedRows) {
                // Nur pending Rows - verwende PENDING_AUTO_APPROVE
                commitMode = 'PENDING_AUTO_APPROVE';
            } else if (hasPendingRows && hasApprovedRows) {
                // Sowohl pending als auch approved - prüfe Button-Text
                // Wenn Button "Freigegebene importieren" heißt, nur approved
                // Wenn Button "Alle freigeben" heißt, alle pending auch approven
                if (buttonText.includes('Alle freigeben')) {
                    commitMode = 'PENDING_AUTO_APPROVE';
                } else {
                    commitMode = 'APPROVED_ONLY';
                }
            }
            
            Utils.showInfo('Import wird durchgeführt...');
            
            const response = await this.fetchWithToken(`/tom3/public/api/import/batch/${this.currentBatch}/commit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mode: commitMode,
                    start_workflows: true,  // Workflows automatisch starten
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
            this.currentBatch = null;
            this.currentStep = 1;
            
            // Navigiere zur Übersichtsseite
            // Entferne batch Parameter aus URL und navigiere
            const url = new URL(window.location.href);
            url.searchParams.delete('batch');
            
            // Verwende replaceState, um die URL zu ändern ohne Reload
            window.history.replaceState({}, '', url);
            
            // Rendere Übersichtsseite
            const page = document.getElementById('page-import');
            if (page) {
                await this.renderOverviewPage(page);
            }
            
        } catch (error) {
            console.error('Commit error:', error);
            Utils.showError('Fehler beim Import: ' + error.message);
        }
    }
}

