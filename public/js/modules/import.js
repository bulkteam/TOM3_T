/**
 * TOM3 - Import Module (Koordinator)
 * Koordiniert alle Import-Sub-Module
 */

import { Utils } from './utils.js';
import { ImportOverviewModule } from './import-overview.js';
import { ImportUploadModule } from './import-upload.js';
import { ImportMappingModule } from './import-mapping.js';
import { ImportIndustryCheckModule } from './import-industry-check.js';
import { ImportReviewModule } from './import-review.js';

export class ImportModule {
    constructor(app) {
        this.app = app;
        this.currentBatch = null;
        this.currentStep = 1; // 1: Upload, 2: Mapping, 3: Branchen-Prüfung, 4: Review
        this.stagingRows = []; // Cache für Staging-Rows
        this.stagingImported = false; // Flag: Wurde bereits in Staging importiert?
        this.analysis = null; // Analyse-Daten aus Upload
        
        // Initialisiere Sub-Module
        this.overviewModule = new ImportOverviewModule(this);
        this.uploadModule = new ImportUploadModule(this);
        this.mappingModule = new ImportMappingModule(this);
        this.industryCheckModule = new ImportIndustryCheckModule(this);
        this.reviewModule = new ImportReviewModule(this);
    }
    
    /**
     * Helper-Methode für fetch mit CSRF-Token
     */
    async fetchWithToken(url, options = {}) {
        if (window.csrfTokenService && typeof window.csrfTokenService.fetchWithToken === 'function') {
            return await window.csrfTokenService.fetchWithToken(url, options);
        }
        
        const method = options.method || 'GET';
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
            try {
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
        
        const urlParams = new URLSearchParams(window.location.search);
        const batchUuid = urlParams.get('batch');
        
        if (batchUuid) {
            this.currentBatch = batchUuid;
            await this.loadExistingBatch(batchUuid);
        } else {
            await this.overviewModule.renderOverviewPage(page);
        }
    }
    
    /**
     * Delegiert an overviewModule
     */
    async renderOverviewPage(container) {
        return await this.overviewModule.renderOverviewPage(container);
    }
    
    /**
     * Delegiert an overviewModule
     */
    async showOverview() {
        const page = document.getElementById('page-import');
        this.currentBatch = null;
        await this.overviewModule.renderOverviewPage(page);
    }
    
    /**
     * Delegiert an overviewModule
     */
    async loadBatchesList() {
        return await this.overviewModule.loadBatchesList();
    }
    
    /**
     * Delegiert an overviewModule
     */
    async deleteBatch(batchUuid, filename) {
        return await this.overviewModule.deleteBatch(batchUuid, filename);
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
            
            const response = await fetch(`${window.API?.baseUrl || '/api'}/import/batch/${batchUuid}/stats`);
            if (!response.ok) {
                throw new Error('Batch nicht gefunden');
            }
            
            const batch = await response.json();
            const stats = batch.stats || {};
            
            // Setze stagingImported-Flag
            if (batch.status === 'STAGED' || batch.status === 'IN_REVIEW' || 
                batch.status === 'APPROVED' || batch.status === 'IMPORTED') {
                this.stagingImported = true;
            } else {
                this.stagingImported = false;
            }
            
            // IMPORTED-Batches: Zeige Zusammenfassung oder Review
            if (batch.status === 'IMPORTED') {
                const nonImportedRows = (stats.pending_rows || 0) + (stats.approved_rows || 0);
                
                if (nonImportedRows > 0) {
                    this.currentStep = 4;
                    this.renderImportPage(page);
                    this.setupEventHandlers();
                    await new Promise(resolve => setTimeout(resolve, 10));
                    this.goToStep(4);
                    await this.reviewModule.renderReviewStep();
                } else {
                    this.overviewModule.showImportSummary(batch);
                }
                return;
            }
            
            // Bestimme aktuellen Schritt
            let step = 1;
            if (batch.status === 'STAGED' || batch.status === 'IN_REVIEW' || batch.status === 'APPROVED') {
                step = 4;
            } else if (batch.status === 'DRAFT') {
                step = 2;
            }
            
            this.currentStep = step;
            this.renderImportPage(page);
            this.setupEventHandlers();
            
            await new Promise(resolve => setTimeout(resolve, 10));
            
            if (step === 4) {
                await this.reviewModule.renderReviewStep();
                this.goToStep(step);
            } else if (step === 2) {
                this.currentBatch = batchUuid;
                
                if (batch.mapping_config) {
                    this.analysis = {
                        mapping_suggestion: this.convertMappingConfigToSuggestion(batch.mapping_config),
                        industry_validation: null
                    };
                } else {
                    await this.reloadAnalysisForBatch(batchUuid);
                }
                
                await this.mappingModule.renderMappingStep();
                this.goToStep(step);
            } else {
                this.goToStep(step);
            }
            
        } catch (error) {
            console.error('Error loading batch:', error);
            Utils.showError('Fehler beim Laden des Batches: ' + error.message);
            const page = document.getElementById('page-import');
            await this.overviewModule.renderOverviewPage(page);
        }
    }
    
    /**
     * Konvertiert mapping_config zu mapping_suggestion Format
     */
    convertMappingConfigToSuggestion(mappingConfig) {
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
     * Lädt Analyse-Daten für einen Batch neu
     */
    async reloadAnalysisForBatch(batchUuid) {
        try {
            Utils.showInfo('Lade Analyse-Daten...');
            
            const response = await this.fetchWithToken(`${window.API?.baseUrl || '/api'}/import/analyze`, {
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
            const batchResponse = await fetch(`${window.API?.baseUrl || '/api'}/import/batch/${batchUuid}/stats`);
            if (batchResponse.ok) {
                const batch = await batchResponse.json();
                if (batch.mapping_config) {
                    this.analysis = {
                        mapping_suggestion: this.convertMappingConfigToSuggestion(batch.mapping_config),
                        industry_validation: null
                    };
                } else {
                    throw error;
                }
            } else {
                throw error;
            }
        }
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
                    <div id="upload-container"></div>
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
                            <button type="button" class="btn btn-primary" id="save-mapping-btn" onclick="window.app.import.mappingModule.saveMapping(event)">
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
                            <button type="button" class="btn btn-primary" id="confirm-industries-btn" onclick="window.app.import.industryCheckModule.confirmAllIndustries()" style="display: none;">
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
                            <button type="button" class="btn btn-primary" id="commit-btn" onclick="window.app.import.reviewModule.commitBatch()" style="display: none;">
                                Importieren →
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Rendere Upload-Container
        if (this.currentStep === 1) {
            const uploadContainer = document.getElementById('upload-container');
            if (uploadContainer) {
                this.uploadModule.renderUploadStep(uploadContainer);
            }
        }
    }
    
    /**
     * Setup Event Handlers
     */
    setupEventHandlers() {
        // Event-Handler werden von den Sub-Modulen selbst verwaltet
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
        
        // Load step content
        if (step === 2 && this.currentBatch) {
            const container = document.getElementById('mapping-configurator');
            if (container && (!container.innerHTML || container.innerHTML.trim() === '' || container.innerHTML.includes('Lädt Mapping-Vorschlag'))) {
                this.mappingModule.renderMappingStep();
            }
        } else if (step === 3 && this.currentBatch) {
            this.industryCheckModule.renderIndustryCheckStep();
        } else if (step === 4 && this.currentBatch) {
            this.reviewModule.renderReviewStep();
        }
    }
    
    /**
     * Delegiert an uploadModule
     */
    async handleUpload() {
        // Wird von uploadModule selbst gehandhabt
        // Diese Methode wird für Kompatibilität beibehalten
    }
    
    /**
     * Delegiert an mappingModule
     */
    async renderMappingStep() {
        return await this.mappingModule.renderMappingStep();
    }
    
    /**
     * Delegiert an industryCheckModule
     */
    async renderIndustryCheckStep() {
        return await this.industryCheckModule.renderIndustryCheckStep();
    }
    
    /**
     * Delegiert an reviewModule
     */
    async renderReviewStep() {
        return await this.reviewModule.renderReviewStep();
    }
    
    /**
     * Delegiert an reviewModule
     */
    async commitBatch() {
        return await this.reviewModule.commitBatch();
    }
    
    /**
     * Delegiert an reviewModule
     */
    async setRowDisposition(stagingUuid, disposition) {
        return await this.reviewModule.setRowDisposition(stagingUuid, disposition);
    }
    
    /**
     * Delegiert an reviewModule
     */
    async showRowDetail(stagingUuid) {
        return await this.reviewModule.showRowDetail(stagingUuid);
    }
    
    /**
     * Delegiert an reviewModule
     */
    async showCorrectionForm(stagingUuid) {
        return await this.reviewModule.showCorrectionForm(stagingUuid);
    }
    
    /**
     * Delegiert an reviewModule
     */
    async saveCorrections(stagingUuid) {
        return await this.reviewModule.saveCorrections(stagingUuid);
    }
    
    /**
     * Delegiert an industryCheckModule
     */
    async confirmAllIndustries() {
        return await this.industryCheckModule.confirmAllIndustries();
    }
    
    /**
     * Lädt Staging-Rows für Batch
     */
    async loadStagingRows(batchUuid) {
        return await this.reviewModule.loadStagingRows(batchUuid);
    }
    
    /**
     * Importiert Daten in Staging
     */
    async importToStaging() {
        if (!this.currentBatch) {
            Utils.showError('Kein Batch vorhanden');
            return;
        }
        
        if (this.stagingImported) {
            return; // Bereits importiert
        }
        
        try {
            Utils.showInfo('Importiere Daten in Staging...');
            
            const response = await this.fetchWithToken(`${window.API?.baseUrl || '/api'}/import/batch/${this.currentBatch}/import-to-staging`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Import in Staging');
            }
            
            this.stagingImported = true;
            Utils.showSuccess('Daten wurden in Staging importiert.');
            
        } catch (error) {
            console.error('Error importing to staging:', error);
            Utils.showError('Fehler beim Import in Staging: ' + error.message);
            throw error;
        }
    }
    
    /**
     * Nächster Schritt
     */
    nextStep() {
        if (this.currentStep < 4) {
            this.goToStep(this.currentStep + 1);
        }
    }
    
    /**
     * Escaped HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Zeigt Loading
     */
    showLoading(message) {
        Utils.showInfo(message || 'Lädt...');
    }
    
    /**
     * Versteckt Loading
     */
    hideLoading() {
        // Wird von Utils gehandhabt
    }
    
    /**
     * Zeigt Success Toast
     */
    showSuccessToast(message) {
        Utils.showSuccess(message);
    }
    
    /**
     * Zeigt Error Toast
     */
    showErrorToast(message) {
        Utils.showError(message);
    }
}
