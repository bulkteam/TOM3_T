/**
 * TOM3 - Import Upload Module
 * Handles file upload step
 */

import { Utils } from './utils.js';

export class ImportUploadModule {
    constructor(importModule) {
        this.importModule = importModule; // Referenz zum Haupt-Import-Modul
    }
    
    /**
     * Rendert Upload-Schritt (Step 1)
     */
    renderUploadStep(container) {
        if (!container) return;
        
        container.innerHTML = `
            <div class="step-header">
                <h3>Schritt 1 von 4: Datei hochladen</h3>
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
        `;
        
        this.setupEventHandlers();
    }
    
    /**
     * Setup Event Handlers für Upload
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
            const response = await this.importModule.fetchWithToken('/tom3/public/api/import/upload', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Upload fehlgeschlagen');
            }
            
            const result = await response.json();
            this.importModule.currentBatch = result.batch_uuid;
            this.importModule.analysis = result.analysis || {};
            this.importModule.templateMatch = result.analysis?.template_match || null;
            
            progressFill.style.width = '100%';
            progressText.textContent = 'Upload erfolgreich!';
            
            // Prüfe Template-Vorschlag
            if (this.importModule.templateMatch && this.importModule.templateMatch.template && 
                this.importModule.templateMatch.decision !== 'NO_MATCH') {
                // Zeige Template-Vorschlag
                this.showTemplateSuggestion();
            } else {
                // Gehe direkt zu Schritt 2
                setTimeout(() => {
                    this.importModule.goToStep(2);
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
     * Zeigt Template-Vorschlag
     */
    showTemplateSuggestion() {
        const template = this.importModule.templateMatch.template;
        const decision = this.importModule.templateMatch.decision;
        
        if (decision === 'EXACT_MATCH' || decision === 'HIGH_CONFIDENCE') {
            const useTemplate = confirm(
                `Es wurde ein passendes Template gefunden: "${template.name}"\n\n` +
                `Möchten Sie dieses Template verwenden?`
            );
            
            if (useTemplate) {
                // Verwende Template
                this.applyTemplate(template);
            } else {
                // Gehe zu Mapping
                setTimeout(() => {
                    this.importModule.goToStep(2);
                }, 500);
            }
        } else {
            // Gehe zu Mapping
            setTimeout(() => {
                this.importModule.goToStep(2);
            }, 500);
        }
    }
    
    /**
     * Wendet Template an
     */
    async applyTemplate(template) {
        try {
            Utils.showInfo('Template wird angewendet...');
            
            const response = await this.importModule.fetchWithToken(
                `/tom3/public/api/import/batch/${this.importModule.currentBatch}/apply-template`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        template_uuid: template.template_uuid
                    })
                }
            );
            
            if (!response.ok) {
                throw new Error('Fehler beim Anwenden des Templates');
            }
            
            const result = await response.json();
            
            // Update analysis mit Template-Mapping
            if (result.mapping_config) {
                this.importModule.analysis = {
                    mapping_suggestion: this.importModule.mappingModule?.convertMappingConfigToSuggestion(result.mapping_config) || {},
                    industry_validation: null
                };
            }
            
            Utils.showSuccess('Template angewendet!');
            
            // Gehe zu Schritt 3 (Branchen-Prüfung) oder 4 (Review), je nachdem ob bereits gestaged
            setTimeout(() => {
                if (result.staged) {
                    this.importModule.goToStep(4);
                } else {
                    this.importModule.goToStep(3);
                }
            }, 500);
            
        } catch (error) {
            console.error('Error applying template:', error);
            Utils.showError('Fehler beim Anwenden des Templates: ' + error.message);
            // Gehe trotzdem zu Mapping
            setTimeout(() => {
                this.importModule.goToStep(2);
            }, 500);
        }
    }
}


