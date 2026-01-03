/**
 * TOM3 - Import Module (Neu - Clean Slate)
 * Nutzt serverseitige State-Engine für Industry-Entscheidungen
 */

import { Utils } from './utils.js';

export class ImportModule {
    constructor(app) {
        this.app = app;
        this.currentBatch = null;
        this.currentStep = 1; // 1: Upload, 2: Mapping, 3: Review
        this.stagingRows = []; // Cache für Staging-Rows
    }
    
    /**
     * Initialisiert Import-Seite
     */
    async init() {
        const page = document.getElementById('page-import');
        if (!page) return;
        
        this.renderImportPage(page);
        this.setupEventHandlers();
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
                    <div class="wizard-step-indicator ${this.currentStep === 3 ? 'active' : ''}" data-step="3">
                        <div class="step-indicator-number">3</div>
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
                        <h3>Schritt 2 von 3: Mapping konfigurieren</h3>
                    </div>
                    <div class="step-content">
                        <div id="mapping-configurator">
                            <p>Lädt Mapping-Vorschlag...</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.app.import.goToStep(1)">
                                ← Zurück
                            </button>
                            <button type="button" class="btn btn-primary" id="save-mapping-btn" onclick="window.app.import.saveMapping()">
                                Mapping speichern →
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Schritt 3: Review -->
                <div class="wizard-step ${this.currentStep === 3 ? 'active' : ''}" data-step="3" style="display: none;">
                    <div class="step-header">
                        <h3>Schritt 3 von 3: Review & Freigabe</h3>
                    </div>
                    <div class="step-content">
                        <div id="review-content">
                            <p>Lädt Staging-Daten...</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.app.import.goToStep(2)">
                                ← Zurück
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
        
        // Load step content
        if (step === 2 && this.currentBatch) {
            this.renderMappingStep();
        } else if (step === 3 && this.currentBatch) {
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
            const response = await fetch('/tom3/public/api/import/upload', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Upload fehlgeschlagen');
            }
            
            const result = await response.json();
            this.currentBatch = result.batch_uuid;
            
            progressFill.style.width = '100%';
            progressText.textContent = 'Upload erfolgreich!';
            
            // Gehe zu Schritt 2
            setTimeout(() => {
                this.goToStep(2);
            }, 500);
            
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
            // Hole Batch-Details (enthält analysis)
            const batch = await window.API.request(`/import/batch/${this.currentBatch}`);
            
            if (!batch || !batch.analysis) {
                throw new Error('Keine Analyse-Daten gefunden');
            }
            
            const analysis = batch.analysis;
            const mapping = analysis.mapping_suggestion || {};
            
            // Rendere Mapping-UI
            container.innerHTML = this.renderMappingUI(mapping);
            
            // Rendere Industry-Warnings (wenn vorhanden)
            if (analysis.industry_validation) {
                const warningsContainer = document.createElement('div');
                warningsContainer.id = 'industry-warnings';
                warningsContainer.innerHTML = this.renderIndustryWarnings(analysis.industry_validation);
                container.appendChild(warningsContainer);
            }
            
        } catch (error) {
            console.error('Error loading mapping:', error);
            container.innerHTML = `<p class="error">Fehler beim Laden: ${error.message}</p>`;
        }
    }
    
    /**
     * Rendert Mapping-UI
     */
    renderMappingUI(mapping) {
        let html = '<div class="mapping-configurator">';
        html += '<h4>Spalten-Mapping</h4>';
        
        const byField = mapping.by_field || {};
        const byColumn = mapping.by_column || {};
        
        // Zeige pro TOM-Feld
        html += '<div class="mapping-by-field">';
        for (const [field, candidates] of Object.entries(byField)) {
            if (candidates.length === 0) continue;
            
            html += `<div class="mapping-field-group">`;
            html += `<label><strong>${this.getFieldLabel(field)}</strong></label>`;
            
            candidates.forEach((candidate, index) => {
                const isSelected = index === 0 && candidate.confidence >= 80;
                html += `<div class="mapping-candidate">`;
                html += `<input type="radio" name="mapping_${field}" value="${candidate.excel_column}" 
                         id="mapping_${field}_${index}" ${isSelected ? 'checked' : ''}>`;
                html += `<label for="mapping_${field}_${index}">`;
                html += `${candidate.excel_header} (${candidate.excel_column})`;
                if (candidate.examples && candidate.examples.length > 0) {
                    html += ` <small>Beispiele: ${candidate.examples.slice(0, 3).join(', ')}</small>`;
                }
                html += ` <span class="confidence-badge">${candidate.confidence}%</span>`;
                html += `</label>`;
                html += `</div>`;
            });
            
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
        html += '<h4>⚠️ Branchen-Prüfung</h4>';
        html += '<p>Bitte wählen Sie die Branchenhierarchie sequenziell aus:</p>';
        
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
        const resolution = combo.industry_resolution || {};
        const suggestions = resolution.suggestions || {};
        const decision = resolution.decision || {};
        
        // Level 1 (Branchenbereich)
        const level1PreSelected = suggestions.derived_level1 || null;
        const level1Uuid = decision.level1_uuid || level1PreSelected?.industry_uuid || null;
        const level1Confirmed = decision.level1_confirmed || false;
        
        // Level 2 (Branche)
        const level2PreSelected = suggestions.level2_candidates?.[0] || null;
        const level2Uuid = decision.level2_uuid || level2PreSelected?.industry_uuid || null;
        const level2Confirmed = decision.level2_confirmed || false;
        
        // Level 3 (Unterbranche)
        const level3PreSelected = suggestions.level3_candidates?.[0] || null;
        const level3Uuid = decision.level3_uuid || null;
        const level3Action = decision.level3_action || 'UNDECIDED';
        
        let html = `<div class="industry-combination" data-combo-id="${comboId}" data-staging-uuid="${combo.staging_uuid || ''}">`;
        html += `<h5>Kombination: ${combo.excel_level1 || 'N/A'} / ${combo.excel_level2 || 'N/A'} / ${combo.excel_level3 || 'N/A'}</h5>`;
        
        // Level 1: Branchenbereich
        html += `<div class="industry-step" data-step="1" data-combo-id="${comboId}">`;
        html += `<label><strong>1. Branchenbereich (Level 1) *</strong></label>`;
        html += `<select class="industry-level1-select" data-combo-id="${comboId}" 
                 onchange="window.app.import.onLevel1Selected('${comboId}', this.value)" 
                 style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;" ${level1Confirmed ? 'disabled' : ''}>`;
        html += `<option value="">-- Bitte wählen --</option>`;
        // Options werden dynamisch geladen
        html += `</select>`;
        if (level1PreSelected) {
            html += `<p class="suggestion-hint">💡 Vorschlag: ${level1PreSelected.name}</p>`;
        }
        if (!level1Confirmed) {
            html += `<button type="button" class="btn btn-sm btn-primary confirm-level1-btn" 
                     data-combo-id="${comboId}" 
                     onclick="window.app.import.confirmLevel1('${comboId}')" 
                     style="margin-top: 0.5rem;" ${level1Uuid ? '' : 'disabled'}>Bestätigen</button>`;
        } else {
            html += `<div class="level-confirmation-feedback" style="background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;">✅ Branchenbereich ausgewählt</div>`;
        }
        html += `</div>`;
        
        // Level 2: Branche (nur aktiv wenn Level 1 bestätigt)
        html += `<div class="industry-step" data-step="2" data-combo-id="${comboId}" 
                 style="display: ${level1Confirmed ? 'block' : 'none'}; opacity: ${level1Confirmed ? '1' : '0.5'}; 
                 background: ${level1Confirmed ? '#f9f9f9' : '#f0f0f0'}; padding: 1rem; margin-top: 1rem; border-radius: 4px;">`;
        html += `<label><strong>2. Branche (Level 2) *</strong></label>`;
        html += `<select class="industry-level2-select" data-combo-id="${comboId}" 
                 onchange="window.app.import.onLevel2Selected('${comboId}', this.value)" 
                 style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;" ${level2Confirmed ? 'disabled' : ''}>`;
        html += `<option value="">-- Bitte wählen --</option>`;
        // Options werden dynamisch geladen
        html += `</select>`;
        if (level2PreSelected) {
            html += `<p class="suggestion-hint">💡 Vorschlag: ${level2PreSelected.name} (Score: ${(level2PreSelected.score * 100).toFixed(0)}%)</p>`;
        }
        if (!level2Confirmed) {
            html += `<button type="button" class="btn btn-sm btn-primary confirm-level2-btn" 
                     data-combo-id="${comboId}" 
                     onclick="window.app.import.confirmLevel2('${comboId}')" 
                     style="margin-top: 0.5rem;" ${level2Uuid ? '' : 'disabled'}>Bestätigen</button>`;
        } else {
            html += `<div class="level-confirmation-feedback" style="background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;">✅ Branche ausgewählt</div>`;
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
                     onchange="window.app.import.onLevel3Selected('${comboId}', this.value)" 
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
     */
    async onLevel1Selected(comboId, level1Uuid) {
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        if (!comboEl) return;
        
        const stagingUuid = comboEl.dataset.stagingUuid;
        if (!stagingUuid) return;
        
        // Lade Level 2 Optionen sofort
        await this.loadLevel2Options(comboId, level1Uuid);
        
        // Reset Level 2 & 3
        this.resetLevel2(comboId);
        this.resetLevel3(comboId);
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
            
            // Aktiviere Level 2
            this.activateLevel2(comboId);
            
            // Lade Level 2 Optionen
            await this.loadLevel2Options(comboId, level1Uuid);
            
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
                option.textContent = industry.name;
                if (industry.code) {
                    option.textContent = `${industry.code} - ${industry.name}`;
                }
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
     */
    async onLevel2Selected(comboId, level2Uuid) {
        if (!level2Uuid) {
            this.resetLevel3(comboId);
            return;
        }
        
        // Lade Level 3 Optionen
        await this.loadLevel3Options(comboId, level2Uuid);
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
                option.textContent = industry.name;
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
            
            Utils.showSuccess('Unterbranche ausgewählt');
            
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
            await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level3_new_name: level3Name,
                    level3_action: 'CREATE_NEW'
                })
            });
            
            Utils.showSuccess(`Unterbranche "${level3Name}" wird beim Import erstellt.`);
            
        } catch (error) {
            console.error('Error adding Level 3:', error);
            Utils.showError('Fehler: ' + (error.message || 'Unbekannter Fehler'));
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
                    option.textContent = opt.name;
                    select.appendChild(option);
                });
                if (currentValue) select.value = currentValue;
            }
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
        // TODO: Implementierung
        Utils.showInfo('Mapping wird gespeichert...');
        this.goToStep(3);
    }
    
    /**
     * Rendert Review-Step
     */
    async renderReviewStep() {
        const container = document.getElementById('review-content');
        if (!container || !this.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Staging-Daten...</p>';
        
        // TODO: Lade Staging-Rows und zeige Review-UI
        container.innerHTML = '<p>Review-UI wird implementiert...</p>';
    }
    
    /**
     * Committet Batch
     */
    async commitBatch() {
        // TODO: Implementierung (wird in Phase 6 erstellt)
        Utils.showInfo('Commit wird in Phase 6 implementiert.');
    }
}
