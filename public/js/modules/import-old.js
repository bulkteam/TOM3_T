/**
 * TOM3 - Import Module
 * Nutzt zentralisierten DocumentService für Upload
 */

import { Utils } from './utils.js';

export class ImportModule {
    constructor(app) {
        this.app = app;
        this.currentBatch = null;
        this.currentStep = 1; // 1: Upload, 2: Mapping, 3: Review
        this.industryDecisions = {}; // Speichert Entscheidungen: {excel_value: {decision, industry_uuid}}
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
                <!-- Wizard-Navigation (oben) -->
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
                                Mapping speichern & Weiter →
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
                        <div id="staging-preview">
                            <p>Lädt Vorschau...</p>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.app.import.goToStep(2)">
                                ← Zurück
                            </button>
                            <button type="button" class="btn btn-primary" id="approve-btn" onclick="window.app.import.approveImport()">
                                ✅ Freigeben & Importieren
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Event-Handler einrichten
     */
    setupEventHandlers() {
        const form = document.getElementById('import-upload-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleUpload();
            });
        }
    }
    
    /**
     * Upload-Handler (nutzt zentralisierten DocumentService)
     */
    async handleUpload() {
        const fileInput = document.getElementById('import-file');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            Utils.showError('Bitte wählen Sie eine Datei aus');
            return;
        }
        
        const file = fileInput.files[0];
        const formData = new FormData();
        formData.append('file', file);
        
        // Zeige Progress
        const progressDiv = document.getElementById('upload-progress');
        const progressFill = document.getElementById('upload-progress-fill');
        const progressText = document.getElementById('upload-progress-text');
        const errorDiv = document.getElementById('upload-error');
        const uploadBtn = document.getElementById('upload-btn');
        
        progressDiv.style.display = 'block';
        errorDiv.style.display = 'none';
        uploadBtn.disabled = true;
        
        try {
            // Upload über zentralisierten DocumentService (via Import-API)
            const response = await fetch('/tom3/public/api/import/upload', {
                method: 'POST',
                body: formData,
                headers: {
                    // Kein Content-Type setzen - Browser setzt automatisch mit Boundary
                }
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Upload fehlgeschlagen');
            }
            
            const result = await response.json();
            
            // Speichere Batch-UUID und Document-UUID
            this.currentBatch = {
                batch_uuid: result.batch_uuid,
                document_uuid: result.document_uuid,
                blob_uuid: result.blob_uuid,
                analysis: result.analysis
            };
            
            // Prüfe Branchen-Konsistenz und zeige Warnung falls nötig
            if (result.analysis.industry_validation && result.analysis.industry_validation.consistency) {
                const consistency = result.analysis.industry_validation.consistency;
                if (!consistency.is_consistent && consistency.warning) {
                    // Zeige Warnung vor Mapping-Schritt
                    const warningHtml = `
                        <div class="alert alert-warning" style="margin: 1rem 0; padding: 1rem; border: 2px solid #ffc107; background-color: #fff3cd;">
                            <h5>⚠️ Warnung: Inkonsistente Branchendaten</h5>
                            <p><strong>${consistency.warning}</strong></p>
                            <p>Die Branchenfelder werden beim Import leer gelassen und müssen anschließend manuell nachgetragen werden.</p>
                            <button type="button" class="btn btn-primary" onclick="this.parentElement.style.display='none'; window.app.import.goToStep(2); window.app.import.renderMappingStep(window.app.import.currentBatch.analysis);">
                                Verstanden, weiter zum Mapping
                            </button>
                        </div>
                    `;
                    
                    // Zeige Warnung im Upload-Bereich
                    const uploadContainer = document.getElementById('import-step-1');
                    if (uploadContainer) {
                        const existingWarning = uploadContainer.querySelector('.alert-warning');
                        if (existingWarning) {
                            existingWarning.remove();
                        }
                        uploadContainer.insertAdjacentHTML('beforeend', warningHtml);
                    }
                }
            }
            
            // Weiter zu Schritt 2 (Mapping)
            this.goToStep(2);
            this.renderMappingStep(result.analysis);
            
            // Lade Hauptbranchen für Dropdowns (nur wenn konsistent)
            if (!result.analysis.industry_validation || 
                !result.analysis.industry_validation.consistency || 
                result.analysis.industry_validation.consistency.is_consistent) {
                this.loadMainIndustries();
            }
            
        } catch (error) {
            console.error('Upload error:', error);
            errorDiv.textContent = error.message || 'Upload fehlgeschlagen';
            errorDiv.style.display = 'block';
            progressDiv.style.display = 'none';
        } finally {
            uploadBtn.disabled = false;
        }
    }
    
    /**
     * Rendert Mapping-Schritt (neue Struktur mit Beispielen)
     */
    renderMappingStep(analysis) {
        const configurator = document.getElementById('mapping-configurator');
        if (!configurator) return;
        
        if (!analysis || !analysis.mapping_suggestion) {
            configurator.innerHTML = '<p class="error">Keine Mapping-Vorschläge verfügbar</p>';
            return;
        }
        
        const mapping = analysis.mapping_suggestion;
        const byField = mapping.by_field || {};
        const byColumn = mapping.by_column || {};
        
        let html = '';
        
        // Branchen-Warnungen anzeigen
        if (analysis.industry_validation) {
            html += this.renderIndustryWarnings(analysis.industry_validation);
            
            // Wenn inkonsistent, Branchenfelder aus Mapping entfernen
            if (analysis.industry_validation.consistency && !analysis.industry_validation.consistency.is_consistent) {
                // Branchenfelder werden nicht gemappt
                const industryFields = ['industry_level1', 'industry_level2', 'industry_level3', 'industry_main', 'industry_sub'];
                industryFields.forEach(field => {
                    if (byField[field]) {
                        delete byField[field];
                    }
                });
            }
        }
        
        // Mapping pro TOM-Feld (mit mehreren Kandidaten)
        html += '<div class="mapping-by-field">';
        html += '<h4>📋 Mapping nach TOM-Feld</h4>';
        
        const fieldOrder = ['name', 'website', 'industry_level1', 'industry_level2', 'industry_level3', 'address_street', 
                           'address_postal_code', 'address_city', 'address_state', 'phone', 
                           'fax', 'email', 'vat_id', 'employee_count', 'revenue_range', 'notes'];
        
        for (const field of fieldOrder) {
            if (!byField[field]) continue;
            
            const candidates = byField[field];
            html += this.renderFieldMapping(field, candidates);
        }
        
        html += '</div>';
        
        // Alle Spalten (Übersicht)
        html += '<div class="mapping-by-column">';
        html += '<h4>📊 Alle Spalten</h4>';
        html += '<table class="data-table">';
        html += '<thead><tr><th>Spalte</th><th>Header</th><th>TOM-Feld</th><th>Konfidenz</th><th>Beispiele</th></tr></thead><tbody>';
        
        for (const [col, suggestion] of Object.entries(byColumn)) {
            if (suggestion.ignore) continue;
            
            const confidence = suggestion.confidence || 0;
            const examples = suggestion.examples || [];
            const exampleStr = examples.length > 0 
                ? examples.slice(0, 3).join(', ') + (examples.length > 3 ? '...' : '')
                : '(keine)';
            
            html += `
                <tr>
                    <td><strong>${col}</strong></td>
                    <td>${suggestion.excel_header || '-'}</td>
                    <td>${suggestion.tom_field || '<em>(kein Mapping)</em>'}</td>
                    <td><span class="confidence-badge ${confidence >= 80 ? 'high' : confidence >= 50 ? 'medium' : 'low'}">${confidence}%</span></td>
                    <td><small>${exampleStr}</small></td>
                </tr>
            `;
        }
        
        html += '</tbody></table></div>';
        
        configurator.innerHTML = html;
        
        // Wenn Level 1 bereits vorbelegt ist, automatisch Level 2 aktivieren
        setTimeout(() => {
            const level1Selects = document.querySelectorAll('.industry-level1-select');
            level1Selects.forEach(select => {
                if (select.value) {
                    const comboId = select.getAttribute('data-combo-id');
                    const excelValue = select.getAttribute('data-excel-value');
                    if (comboId && excelValue) {
                        this.confirmLevel1(comboId, excelValue);
                    }
                }
            });
        }, 200);
    }
    
    /**
     * Rendert Mapping für ein TOM-Feld (mit mehreren Kandidaten)
     */
    renderFieldMapping(field, candidates) {
        const fieldLabels = {
            'name': 'Name',
            'website': 'Website',
            'industry_level1': 'Branchenbereich (Level 1)',
            'industry_level2': 'Branche (Level 2)',
            'industry_level3': 'Unterbranche (Level 3)',
            'industry_main': 'Hauptbranche',
            'industry_sub': 'Subbranche',
            'address_street': 'Straße',
            'address_postal_code': 'PLZ',
            'address_city': 'Ort',
            'address_state': 'Bundesland',
            'phone': 'Telefon',
            'fax': 'Fax',
            'email': 'E-Mail',
            'vat_id': 'USt-ID',
            'employee_count': 'Mitarbeiter',
            'revenue_range': 'Umsatz',
            'notes': 'Notizen'
        };
        
        let html = `<div class="field-mapping-group" data-field="${field}">`;
        html += `<h5>${fieldLabels[field] || field}</h5>`;
        
        if (candidates.length === 1) {
            // Nur ein Kandidat
            const candidate = candidates[0];
            const examples = candidate.examples || [];
            html += `
                <div class="candidate-single">
                    <label>
                        <input type="radio" name="field_${field}" value="${candidate.excel_column}" checked>
                        <strong>Spalte ${candidate.excel_column}</strong>: ${candidate.excel_header}
                        <span class="confidence-badge ${candidate.confidence >= 80 ? 'high' : 'medium'}">${candidate.confidence}%</span>
                    </label>
                    ${examples.length > 0 ? `<div class="examples"><small>Beispiele: ${examples.slice(0, 3).join(', ')}</small></div>` : ''}
                </div>
            `;
        } else {
            // Mehrere Kandidaten - Auswahl
            html += '<div class="candidates-multiple">';
            html += '<p class="info">⚠️ Mehrere Spalten passen zu diesem Feld. Bitte wählen Sie die richtige:</p>';
            
            for (const candidate of candidates) {
                const examples = candidate.examples || [];
                html += `
                    <div class="candidate-option">
                        <label>
                            <input type="radio" name="field_${field}" value="${candidate.excel_column}" ${candidate.confidence === candidates[0].confidence ? 'checked' : ''}>
                            <strong>Spalte ${candidate.excel_column}</strong>: ${candidate.excel_header}
                            <span class="confidence-badge ${candidate.confidence >= 80 ? 'high' : 'medium'}">${candidate.confidence}%</span>
                        </label>
                        ${examples.length > 0 ? `<div class="examples"><small>Beispiele: ${examples.slice(0, 3).join(', ')}</small></div>` : ''}
                    </div>
                `;
            }
            
            html += '</div>';
        }
        
        html += '</div>';
        return html;
    }
    
    /**
     * Rendert Branchen-Warnungen mit sequenziellem 3-Schritt-Prozess
     */
    renderIndustryWarnings(validation) {
        let html = '<div class="industry-warnings">';
        html += '<h4>⚠️ Branchen-Prüfung</h4>';
        
        // Konsistenz-Warnung
        if (validation.consistency && !validation.consistency.is_consistent) {
            html += '<div class="alert alert-warning" style="margin: 1rem 0; padding: 1rem; border: 2px solid #ffc107; background-color: #fff3cd;">';
            html += '<h5>⚠️ Inkonsistente Branchendaten</h5>';
            html += `<p><strong>${validation.consistency.warning}</strong></p>`;
            html += '<p>Die Branchenfelder werden beim Import leer gelassen und müssen anschließend manuell nachgetragen werden.</p>';
            html += '</div>';
            html += '</div>';
            return html;
        }
        
        html += '<p class="info">Bitte wählen Sie die Branchenhierarchie sequenziell aus:</p>';
        
        // Kombinations-Vorschläge (3-stufige Hierarchie) - Hauptfall
        if (validation.combinations && validation.combinations.length > 0) {
            for (const combo of validation.combinations) {
                const comboId = `combo_${combo.excel_level1.replace(/[^a-zA-Z0-9]/g, '_')}_${combo.excel_level2.replace(/[^a-zA-Z0-9]/g, '_')}`;
                
                html += `<div class="combination-item" id="${comboId}" style="border: 1px solid #ddd; padding: 1rem; margin: 1rem 0; border-radius: 4px;">`;
                html += `<div class="combination-header">`;
                html += `<h5>Excel-Daten: ${combo.excel_level1} / ${combo.excel_level2}${combo.excel_level3s && combo.excel_level3s.length > 0 ? ' / ' + combo.excel_level3s.join(', ') : ''}</h5>`;
                html += `</div>`;
                
                // Schritt 1: Branchenbereich (Level 1) - IMMER AKTIV
                html += `<div class="industry-step" data-step="1" data-combo-id="${comboId}" style="margin: 1rem 0; padding: 1rem; background: #f9f9f9; border-radius: 4px;">`;
                html += `<label><strong>1. Branchenbereich (Level 1) wählen:</strong></label>`;
                html += `<select class="industry-level1-select" data-combo-id="${comboId}" data-excel-value="${combo.excel_level1}" onchange="window.app.import.onLevel1Selected('${comboId}', this.value, '${combo.excel_level1}')" style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;">`;
                html += `<option value="">-- Bitte wählen --</option>`;
                
                // Vorauswahl: Wenn Level 1 gefunden wurde
                if (combo.db_level1) {
                    html += `<option value="${combo.db_level1.industry_uuid}" selected>${combo.db_level1.name}</option>`;
                }
                
                html += `</select>`;
                
                // Button zum Bestätigen von Level 1
                html += `<button type="button" class="btn btn-sm btn-primary confirm-level1-btn" data-combo-id="${comboId}" onclick="window.app.import.confirmLevel1('${comboId}', '${combo.excel_level1}')" style="margin-top: 0.5rem;" ${combo.db_level1 ? '' : 'disabled'}>`;
                html += `✅ Bestätigen`;
                html += `</button>`;
                html += `</div>`;
                
                // Schritt 2: Branche (Level 2) - INAKTIV bis Schritt 1 bestätigt
                html += `<div class="industry-step" data-step="2" data-combo-id="${comboId}" style="display: none; margin: 1rem 0; padding: 1rem; background: #f0f0f0; border-radius: 4px; opacity: 0.5;">`;
                html += `<label><strong>2. Branche (Level 2) wählen:</strong></label>`;
                html += `<p class="info" style="font-size: 0.9em; color: #666;">Excel-Wert: <strong>${combo.excel_level2}</strong></p>`;
                html += `<select class="industry-level2-select" data-combo-id="${comboId}" data-excel-value="${combo.excel_level2}" onchange="window.app.import.onLevel2Selected('${comboId}', this.value)" disabled style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;">`;
                html += `<option value="">-- Zuerst Branchenbereich bestätigen --</option>`;
                
                // Vorauswahl: Wenn Level 2 gefunden wurde
                if (combo.level2_matches && combo.level2_matches.length > 0) {
                    const level2Match = combo.level2_matches[0];
                    html += `<option value="${level2Match.db_industry.industry_uuid}" selected>${level2Match.db_industry.name} (${(level2Match.similarity * 100).toFixed(0)}% ähnlich zu "${combo.excel_level2}")</option>`;
                }
                
                html += `</select>`;
                
                // Button zum Bestätigen von Level 2
                html += `<button type="button" class="btn btn-sm btn-primary confirm-level2-btn" data-combo-id="${comboId}" onclick="window.app.import.confirmLevel2('${comboId}', '${combo.excel_level2}')" style="margin-top: 0.5rem;" disabled>`;
                html += `✅ Bestätigen`;
                html += `</button>`;
                html += `</div>`;
                
                // Schritt 3: Unterbranche (Level 3) - INAKTIV bis Schritt 2 bestätigt
                if (combo.excel_level3s && combo.excel_level3s.length > 0) {
                    for (const excelLevel3 of combo.excel_level3s) {
                        const level3Match = combo.level3_matches?.find(m => m.excel_value === excelLevel3);
                        const needsCreation = combo.level3_needs_creation?.find(m => m.excel_value === excelLevel3);
                        
                        html += `<div class="industry-step" data-step="3" data-combo-id="${comboId}" data-level3-value="${excelLevel3}" style="display: none; margin: 1rem 0; padding: 1rem; background: #f0f0f0; border-radius: 4px; opacity: 0.5;">`;
                        html += `<label><strong>3. Unterbranche (Level 3) - "${excelLevel3}":</strong></label>`;
                        
                        // Dropdown mit allen vorhandenen Level 3 Werten
                        html += `<select class="industry-level3-select" data-combo-id="${comboId}" data-level3-value="${excelLevel3}" onchange="window.app.import.useLevel3('${comboId}', '${excelLevel3}', this.value)" disabled style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;">`;
                        html += `<option value="">-- Zuerst Branche bestätigen --</option>`;
                        html += `</select>`;
                        
                        // Option: Als neuen Eintrag übernehmen
                        const parentLevel2Uuid = needsCreation?.parent_level2_uuid || combo.level2_matches?.[0]?.db_industry?.industry_uuid || '';
                        html += `<div class="level3-actions" style="margin-top: 0.5rem;">`;
                        html += `<button type="button" class="btn btn-sm btn-primary add-level3-btn" data-combo-id="${comboId}" data-level3-value="${excelLevel3}" data-parent-uuid="${parentLevel2Uuid}" onclick="window.app.import.addLevel3FromCombo('${comboId}', '${excelLevel3}', '${parentLevel2Uuid}')" disabled style="margin-top: 0.5rem;">`;
                        html += `➕ Als neue Unterbranche übernehmen`;
                        html += `</button>`;
                        html += `</div>`;
                        
                        html += `</div>`;
                    }
                }
                
                html += `</div>`;
            }
        } else {
            // Fallback: Wenn keine Kombinationen gefunden wurden
            // Prüfe, ob Kombinationen aus Level 2 abgeleitet wurden
            if (validation.combinations && validation.combinations.length > 0) {
                // Kombinationen werden weiter unten angezeigt
            } else {
                html += '<p class="info">Keine Branchenkombinationen in den Excel-Daten gefunden.</p>';
            }
        }
        
        html += '</div>';
        return html;
    }
    
    /**
     * Verwendet 3-stufige Kombination (Level 1 + Level 2 + Level 3 aus DB)
     */
    useCombination3Level(excelLevel1, dbLevel1Uuid, excelLevel2, dbLevel2Uuid, excelLevel3, dbLevel3Uuid) {
        // Speichere Entscheidung
        this.industryDecisions[excelLevel1] = {
            type: 'level1',
            decision: 'using_existing',
            industry_uuid: dbLevel1Uuid
        };
        
        this.industryDecisions[excelLevel2] = {
            type: 'level2',
            decision: 'using_existing',
            industry_uuid: dbLevel2Uuid
        };
        
        this.industryDecisions[excelLevel3] = {
            type: 'level3',
            decision: 'using_existing',
            industry_uuid: dbLevel3Uuid
        };
        
        Utils.showSuccess(`3-stufige Kombination verwendet: ${excelLevel1} / ${excelLevel2} / ${excelLevel3}`);
        
        // UI aktualisieren
        this.updateIndustryDecision(excelLevel1, 'level1', 'using_existing', dbLevel1Uuid);
        this.updateIndustryDecision(excelLevel2, 'level2', 'using_existing', dbLevel2Uuid);
        this.updateIndustryDecision(excelLevel3, 'level3', 'using_existing', dbLevel3Uuid);
    }
    
    /**
     * Fügt neue Level 3 Branche zur DB hinzu
     */
    async addLevel3Industry(excelValue, parentLevel2Uuid) {
        try {
            const response = await fetch('/tom3/public/api/industries', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name: excelValue,
                    code: null,
                    parent_industry_uuid: parentLevel2Uuid,
                    description: `Hinzugefügt durch Import am ${new Date().toLocaleDateString('de-DE')}`
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler beim Hinzufügen');
            }
            
            const result = await response.json();
            
            // Speichere Entscheidung
            this.industryDecisions[excelValue] = {
                type: 'level3',
                decision: 'added_new',
                industry_uuid: result.industry_uuid
            };
            
            Utils.showSuccess(`Unterbranche "${excelValue}" wurde als Level 3 hinzugefügt.`);
            this.updateIndustryDecision(excelValue, 'level3', 'added_new', result.industry_uuid);
            
        } catch (error) {
            console.error('Error adding level 3 industry:', error);
            Utils.showError('Fehler beim Hinzufügen: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Fügt neue Branche zur DB hinzu
     */
    async addIndustry(excelValue, type, parentUuid) {
        // Für Level 3: Verwende spezielle Funktion
        if (type === 'level3' && parentUuid) {
            return this.addLevel3Industry(excelValue, parentUuid);
        }
        
        try {
            const response = await fetch('/tom3/public/api/industries', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name: excelValue,
                    code: null,
                    parent_industry_uuid: parentUuid,
                    description: `Hinzugefügt durch Import am ${new Date().toLocaleDateString('de-DE')}`
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler beim Hinzufügen');
            }
            
            const result = await response.json();
            Utils.showSuccess(`Branche "${excelValue}" wurde hinzugefügt.`);
            
            // UI aktualisieren
            this.updateIndustryDecision(excelValue, type, 'added', result.industry_uuid);
            
        } catch (error) {
            console.error('Add industry error:', error);
            Utils.showError(error.message || 'Fehler beim Hinzufügen der Branche');
        }
    }
    
    /**
     * Verwendet Vorschlag (bestehende Branche)
     */
    async useSuggestion(excelValue, suggestionUuid, type) {
        Utils.showSuccess(`Branche "${excelValue}" wird mit "${suggestionUuid}" verknüpft.`);
        this.updateIndustryDecision(excelValue, type, 'using_suggestion', suggestionUuid);
    }
    
    /**
     * Ignoriert Branche
     */
    ignoreIndustry(excelValue, type) {
        Utils.showInfo(`Branche "${excelValue}" wird ignoriert.`);
        this.updateIndustryDecision(excelValue, type, 'ignored', null);
    }
    
    /**
     * Lädt Branchenbereiche (Level 1) für Dropdowns
     */
    async loadMainIndustries() {
        try {
            const industries = await window.API.getIndustries(null, false, 1);
            
            // Fülle alle Level 1 Dropdowns
            const level1Selects = document.querySelectorAll('.industry-level1-select');
            level1Selects.forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    option.textContent = industry.name;
                    select.appendChild(option);
                });
                
                if (currentValue) {
                    select.value = currentValue;
                }
            });
        } catch (error) {
            console.error('Error loading main industries:', error);
            Utils.showError('Fehler beim Laden der Branchenbereiche');
        }
    }
    
    /**
     * Wird aufgerufen, wenn Level 1 ausgewählt wird (vor Bestätigung)
     */
    async onLevel1Selected(comboId, level1Uuid, excelValue) {
        const step1 = document.querySelector(`.industry-step[data-step="1"][data-combo-id="${comboId}"]`);
        
        if (!level1Uuid) {
            // Wenn Level 1 zurückgesetzt wird, entferne Feedback und deaktiviere Level 2
            if (step1) {
                const existingFeedback = step1.querySelector('.level-confirmation-feedback');
                if (existingFeedback) {
                    existingFeedback.remove();
                }
                const level1Select = step1.querySelector('.industry-level1-select');
                if (level1Select) {
                    level1Select.style.background = '';
                }
            }
            
            // Deaktiviere Level 2
            const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
            if (step2) {
                step2.style.display = 'none';
                step2.style.opacity = '0.5';
                step2.style.background = '#f0f0f0';
                const level2Select = step2.querySelector('.industry-level2-select');
                const confirmBtn = step2.querySelector('.confirm-level2-btn');
                if (level2Select) {
                    level2Select.disabled = true;
                    level2Select.value = '';
                }
                if (confirmBtn) {
                    confirmBtn.disabled = true;
                }
                // Entferne Level 2 Feedback
                const level2Feedback = step2.querySelector('.level-confirmation-feedback');
                if (level2Feedback) {
                    level2Feedback.remove();
                }
            }
            
            // Entferne Level 1 Entscheidung
            delete this.industryDecisions[excelValue];
            return;
        }
        
        // Wenn Level 1 bereits bestätigt war, aber geändert wurde → Zurücksetzen
        const existingDecision = this.industryDecisions[excelValue];
        if (existingDecision && existingDecision.industry_uuid !== level1Uuid) {
            // Level 1 wurde geändert → Level 2 zurücksetzen
            const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
            if (step2) {
                const level2Select = step2.querySelector('.industry-level2-select');
                const confirmBtn = step2.querySelector('.confirm-level2-btn');
                if (level2Select) {
                    level2Select.value = '';
                    level2Select.disabled = true;
                }
                if (confirmBtn) {
                    confirmBtn.disabled = true;
                }
                
                // Entferne Level 2 Feedback
                const level2Feedback = step2.querySelector('.level-confirmation-feedback');
                if (level2Feedback) {
                    level2Feedback.remove();
                }
            }
            
            // Entferne Level 1 Feedback (muss neu bestätigt werden)
            if (step1) {
                const existingFeedback = step1.querySelector('.level-confirmation-feedback');
                if (existingFeedback) {
                    existingFeedback.remove();
                }
                const level1Select = step1.querySelector('.industry-level1-select');
                if (level1Select) {
                    level1Select.style.background = '';
                }
            }
            
            // Entferne Level 1 Entscheidung (muss neu bestätigt werden)
            delete this.industryDecisions[excelValue];
        }
        
        // Lade Level 2 Optionen basierend auf neuem Level 1 (auch wenn noch nicht bestätigt)
        await this.loadLevel2Options(comboId, level1Uuid);
    }
    
    /**
     * Lädt Level 2 Optionen basierend auf Level 1
     */
    async loadLevel2Options(comboId, level1Uuid) {
        try {
            const industries = await window.API.getIndustries(level1Uuid, false, 2);
            const level2Select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
            
            if (level2Select) {
                const currentValue = level2Select.value;
                level2Select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    option.textContent = industry.name;
                    level2Select.appendChild(option);
                });
                
                if (currentValue) {
                    level2Select.value = currentValue;
                }
            }
        } catch (error) {
            console.error('Error loading level 2 options:', error);
            Utils.showError('Fehler beim Laden der Branchen (Level 2)');
        }
    }
    
    /**
     * Wird aufgerufen, wenn Level 2 ausgewählt wird
     */
    onLevel2Selected(comboId, level2Uuid) {
        if (!level2Uuid) {
            return;
        }
        
        // Aktiviere Bestätigungs-Button
        const confirmBtn = document.querySelector(`.confirm-level2-btn[data-combo-id="${comboId}"]`);
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }
    }
    
    /**
     * Fügt Level 3 aus Kombination hinzu
     */
    async addLevel3FromCombo(comboId, excelValue, parentLevel2Uuid) {
        await this.addLevel3Industry(excelValue, parentLevel2Uuid);
        
        // Markiere als erledigt
        const step3 = document.querySelector(`.industry-step[data-step="3"][data-combo-id="${comboId}"][data-level3-value="${excelValue}"]`);
        if (step3) {
            step3.style.background = '#d4edda';
            step3.querySelectorAll('button, select').forEach(el => el.disabled = true);
        }
    }
    
    /**
     * Aktualisiert UI nach Entscheidung
     */
    updateIndustryDecision(excelValue, type, decision, industryUuid) {
        const decisionItem = document.querySelector(`[data-excel-value="${excelValue}"][data-type="${type}"]`);
        if (decisionItem) {
            decisionItem.classList.add('decision-made');
            const statusBadge = decisionItem.querySelector('.decision-status') || document.createElement('span');
            statusBadge.className = 'decision-status';
            statusBadge.textContent = decision === 'using_existing' ? '✅ Verwendet' : decision === 'added_new' ? '➕ Hinzugefügt' : '⏭️ Ignoriert';
            if (!decisionItem.querySelector('.decision-status')) {
                decisionItem.appendChild(statusBadge);
            }
        }
    }
    
    /**
     * Wird aufgerufen, wenn Parent-Industrie für Subbranche ausgewählt wird
     */
    onParentIndustrySelected(excelValue, parentUuid, level = 2) {
        const addBtn = document.querySelector(`.add-sub-industry-btn[data-excel-value="${excelValue}"], .add-level3-industry-btn[data-excel-value="${excelValue}"]`);
        if (addBtn) {
            addBtn.disabled = !parentUuid;
            addBtn.setAttribute('data-parent-uuid', parentUuid || '');
        }
    }
    
    
    /**
     * Verwendet 3-stufige Kombination (Level 1 + Level 2 + Level 3 aus DB)
     */
    useCombination3Level(excelLevel1, dbLevel1Uuid, excelLevel2, dbLevel2Uuid, excelLevel3, dbLevel3Uuid) {
        // Speichere Entscheidung
        this.industryDecisions[excelLevel1] = {
            type: 'level1',
            decision: 'using_existing',
            industry_uuid: dbLevel1Uuid
        };
        
        this.industryDecisions[excelLevel2] = {
            type: 'level2',
            decision: 'using_existing',
            industry_uuid: dbLevel2Uuid
        };
        
        this.industryDecisions[excelLevel3] = {
            type: 'level3',
            decision: 'using_existing',
            industry_uuid: dbLevel3Uuid
        };
        
        Utils.showSuccess(`3-stufige Kombination verwendet: ${excelLevel1} / ${excelLevel2} / ${excelLevel3}`);
        
        // UI aktualisieren
        this.updateIndustryDecision(excelLevel1, 'level1', 'using_existing', dbLevel1Uuid);
        this.updateIndustryDecision(excelLevel2, 'level2', 'using_existing', dbLevel2Uuid);
        this.updateIndustryDecision(excelLevel3, 'level3', 'using_existing', dbLevel3Uuid);
    }
    
    /**
     * Fügt neue Level 3 Unterbranche hinzu
     */
    async addLevel3Industry(excelValue, parentLevel2Uuid) {
        try {
            const response = await fetch('/tom3/public/api/industries', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name: excelValue,
                    parent_industry_uuid: parentLevel2Uuid
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Hinzufügen');
            }
            
            const result = await response.json();
            
            // Speichere Entscheidung
            this.industryDecisions[excelValue] = {
                type: 'level3',
                decision: 'added_new',
                industry_uuid: result.industry_uuid
            };
            
            Utils.showSuccess(`Unterbranche "${excelValue}" wurde als Level 3 hinzugefügt.`);
            this.updateIndustryDecision(excelValue, 'level3', 'added_new', result.industry_uuid);
            
        } catch (error) {
            console.error('Error adding level 3 industry:', error);
            Utils.showError('Fehler beim Hinzufügen: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Fügt neue Branche zur DB hinzu
     */
    async addIndustry(excelValue, type, parentUuid) {
        // Für Level 3: Verwende spezielle Funktion
        if (type === 'level3' && parentUuid) {
            return this.addLevel3Industry(excelValue, parentUuid);
        }
        
        try {
            const response = await fetch('/tom3/public/api/industries', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name: excelValue,
                    code: null,
                    parent_industry_uuid: parentUuid,
                    description: `Hinzugefügt durch Import am ${new Date().toLocaleDateString('de-DE')}`
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Fehler beim Hinzufügen');
            }
            
            const result = await response.json();
            Utils.showSuccess(`Branche "${excelValue}" wurde hinzugefügt.`);
            
            // UI aktualisieren
            this.updateIndustryDecision(excelValue, type, 'added', result.industry_uuid);
            
        } catch (error) {
            console.error('Add industry error:', error);
            Utils.showError(error.message || 'Fehler beim Hinzufügen der Branche');
        }
    }
    
    /**
     * Verwendet Vorschlag (bestehende Branche)
     */
    async useSuggestion(excelValue, suggestionUuid, type) {
        Utils.showSuccess(`Branche "${excelValue}" wird mit "${suggestionUuid}" verknüpft.`);
        this.updateIndustryDecision(excelValue, type, 'using_suggestion', suggestionUuid);
    }
    
    /**
     * Ignoriert Branche
     */
    ignoreIndustry(excelValue, type) {
        Utils.showInfo(`Branche "${excelValue}" wird ignoriert.`);
        this.updateIndustryDecision(excelValue, type, 'ignored', null);
    }
    
    /**
     * Lädt Branchenbereiche (Level 1) für Dropdowns
     */
    async loadMainIndustries() {
        try {
            const industries = await window.API.getIndustries(null, false, 1);
            
            // Fülle alle Level 1 Dropdowns
            document.querySelectorAll('.industry-level1-select, .industry-level1-select-single').forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    option.textContent = industry.name;
                    select.appendChild(option);
                });
                
                if (currentValue) {
                    select.value = currentValue;
                }
            });
            
        } catch (error) {
            console.error('Load level 1 industries error:', error);
            Utils.showError('Fehler beim Laden der Branchenbereiche (Level 1)');
        }
    }
    
    /**
     * Wird aufgerufen, wenn Level 1 (Branchenbereich) ausgewählt wird
     */
    async onLevel1Selected(comboId, level1Uuid, excelValue) {
        const confirmBtn = document.querySelector(`.confirm-level1-btn[data-combo-id="${comboId}"]`);
        if (confirmBtn) {
            confirmBtn.disabled = !level1Uuid;
        }
        
        // Wenn Level 1 ausgewählt, lade Level 2 Optionen
        if (level1Uuid) {
            await this.loadLevel2Options(comboId, level1Uuid);
        }
    }
    
    /**
     * Bestätigt Level 1 und aktiviert Level 2
     */
    async confirmLevel1(comboId, excelValue) {
        const level1Select = document.querySelector(`.industry-level1-select[data-combo-id="${comboId}"]`);
        const level1Uuid = level1Select?.value;
        
        if (!level1Uuid) {
            Utils.showError('Bitte wählen Sie zuerst einen Branchenbereich aus.');
            return;
        }
        
        // Speichere Entscheidung
        this.industryDecisions[excelValue] = {
            type: 'level1',
            decision: 'using_existing',
            industry_uuid: level1Uuid
        };
        
        // Zeige visuelles Feedback: Grüner Streifen
        const step1 = document.querySelector(`.industry-step[data-step="1"][data-combo-id="${comboId}"]`);
        if (step1) {
            // Entferne vorherige Feedback-Elemente
            const existingFeedback = step1.querySelector('.level-confirmation-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            // Füge grünen Streifen hinzu
            const feedback = document.createElement('div');
            feedback.className = 'level-confirmation-feedback';
            feedback.style.cssText = 'margin-top: 0.5rem; padding: 0.75rem; background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;';
            feedback.innerHTML = '<strong>✅ Branchenbereich ausgewählt</strong>';
            step1.appendChild(feedback);
            
            // Deaktiviere Level 1 Select (kann aber wieder aktiviert werden)
            level1Select.style.background = '#e9ecef';
        }
        
        // Aktiviere Schritt 2
        const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
        if (step2) {
            step2.style.display = 'block';
            step2.style.opacity = '1';
            step2.style.background = '#f9f9f9';
            
            const level2Select = step2.querySelector('.industry-level2-select');
            if (level2Select) {
                level2Select.disabled = false;
                
                // Lade Level 2 Optionen basierend auf Level 1
                await this.loadLevel2Options(comboId, level1Uuid);
                
                // Wenn Level 2 bereits vorbelegt ist (aus Kombination), aktiviere Bestätigungs-Button
                if (level2Select.value) {
                    const confirmBtn = step2.querySelector('.confirm-level2-btn');
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                    }
                }
            }
        }
        
        Utils.showSuccess('Branchenbereich bestätigt. Bitte wählen Sie nun die Branche (Level 2).');
    }
    
    /**
     * Lädt Level 2 Optionen basierend auf Level 1
     */
    async loadLevel2Options(comboId, level1Uuid) {
        try {
            const industries = await window.API.getIndustries(level1Uuid, false, 2);
            const level2Select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
            
            if (level2Select) {
                level2Select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    option.textContent = industry.name;
                    level2Select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Error loading level 2 options:', error);
            Utils.showError('Fehler beim Laden der Branchen (Level 2)');
        }
    }
    
    /**
     * Wird aufgerufen, wenn Level 2 ausgewählt wird (vor Bestätigung)
     */
    onLevel2Selected(comboId, level2Uuid) {
        const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
        
        if (!level2Uuid) {
            // Wenn Level 2 zurückgesetzt wird, entferne Feedback
            if (step2) {
                const existingFeedback = step2.querySelector('.level-confirmation-feedback');
                if (existingFeedback) {
                    existingFeedback.remove();
                }
                const level2Select = step2.querySelector('.industry-level2-select');
                if (level2Select) {
                    level2Select.style.background = '';
                }
            }
            
            // Entferne Level 2 Entscheidung
            const level2Select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
            const excelValue = level2Select?.getAttribute('data-excel-value');
            if (excelValue) {
                delete this.industryDecisions[excelValue];
            }
            
            const confirmBtn = document.querySelector(`.confirm-level2-btn[data-combo-id="${comboId}"]`);
            if (confirmBtn) {
                confirmBtn.disabled = true;
            }
            return;
        }
        
        // Wenn Level 2 bereits bestätigt war, aber geändert wurde → Zurücksetzen
        const level2Select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
        const excelValue = level2Select?.getAttribute('data-excel-value');
        if (excelValue) {
            const existingDecision = this.industryDecisions[excelValue];
            if (existingDecision && existingDecision.industry_uuid !== level2Uuid) {
                // Level 2 wurde geändert → Entferne Feedback
                if (step2) {
                    const existingFeedback = step2.querySelector('.level-confirmation-feedback');
                    if (existingFeedback) {
                        existingFeedback.remove();
                    }
                    level2Select.style.background = '';
                }
                
                // Entferne Level 2 Entscheidung (muss neu bestätigt werden)
                delete this.industryDecisions[excelValue];
            }
        }
        
        const confirmBtn = document.querySelector(`.confirm-level2-btn[data-combo-id="${comboId}"]`);
        if (confirmBtn) {
            confirmBtn.disabled = !level2Uuid;
        }
    }
    
    /**
     * Bestätigt Level 2 und aktiviert Level 3
     */
    async confirmLevel2(comboId, excelValue) {
        const level2Select = document.querySelector(`.industry-level2-select[data-combo-id="${comboId}"]`);
        const level2Uuid = level2Select?.value;
        
        if (!level2Uuid) {
            Utils.showError('Bitte wählen Sie zuerst eine Branche aus.');
            return;
        }
        
        // Speichere Entscheidung
        this.industryDecisions[excelValue] = {
            type: 'level2',
            decision: 'using_existing',
            industry_uuid: level2Uuid
        };
        
        // Zeige visuelles Feedback: Grüner Streifen
        const step2 = document.querySelector(`.industry-step[data-step="2"][data-combo-id="${comboId}"]`);
        if (step2) {
            // Entferne vorherige Feedback-Elemente
            const existingFeedback = step2.querySelector('.level-confirmation-feedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            // Füge grünen Streifen hinzu
            const feedback = document.createElement('div');
            feedback.className = 'level-confirmation-feedback';
            feedback.style.cssText = 'margin-top: 0.5rem; padding: 0.75rem; background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;';
            feedback.innerHTML = '<strong>✅ Branche ausgewählt</strong>';
            step2.appendChild(feedback);
            
            // Deaktiviere Level 2 Select (kann aber wieder aktiviert werden)
            level2Select.style.background = '#e9ecef';
        }
        
        // Lade alle Level 3 Optionen für dieses Level 2
        await this.loadLevel3Options(comboId, level2Uuid);
        
        // Aktiviere Schritt 3
        const step3Elements = document.querySelectorAll(`.industry-step[data-step="3"][data-combo-id="${comboId}"]`);
        step3Elements.forEach(step3 => {
            step3.style.display = 'block';
            step3.style.opacity = '1';
            step3.style.background = '#f9f9f9';
            
            const level3Select = step3.querySelector('.industry-level3-select');
            if (level3Select) {
                level3Select.disabled = false;
            }
            
            const addBtn = step3.querySelector('.add-level3-btn');
            if (addBtn) {
                addBtn.disabled = false;
                // Setze parent_uuid
                addBtn.setAttribute('data-parent-uuid', level2Uuid);
            }
        });
        
        Utils.showSuccess('Branche bestätigt. Bitte prüfen Sie die Unterbranchen (Level 3).');
    }
    
    /**
     * Lädt Level 3 Optionen basierend auf Level 2
     */
    async loadLevel3Options(comboId, level2Uuid) {
        try {
            const industries = await window.API.getIndustries(level2Uuid, false, 3);
            const level3Selects = document.querySelectorAll(`.industry-level3-select[data-combo-id="${comboId}"]`);
            
            level3Selects.forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    option.textContent = industry.name;
                    select.appendChild(option);
                });
                
                if (currentValue) {
                    select.value = currentValue;
                }
            });
        } catch (error) {
            console.error('Error loading level 3 options:', error);
            Utils.showError('Fehler beim Laden der Unterbranchen (Level 3)');
        }
    }
    
    /**
     * Verwendet gefundene Level 3 Branche
     */
    useLevel3(comboId, excelValue, level3Uuid) {
        this.industryDecisions[excelValue] = {
            type: 'level3',
            decision: 'using_existing',
            industry_uuid: level3Uuid
        };
        
        Utils.showSuccess(`Unterbranche "${excelValue}" wird verwendet.`);
    }
    
    /**
     * Fügt neue Level 3 Branche aus Kombination hinzu
     */
    async addLevel3FromCombo(comboId, excelValue, parentUuid) {
        // Hole aktuelle parent_uuid vom Button
        const addBtn = document.querySelector(`.add-level3-btn[data-combo-id="${comboId}"][data-level3-value="${excelValue}"]`);
        const actualParentUuid = addBtn?.getAttribute('data-parent-uuid') || parentUuid;
        
        if (!actualParentUuid) {
            Utils.showError('Bitte bestätigen Sie zuerst die Branche (Level 2).');
            return;
        }
        
        await this.addLevel3Industry(excelValue, actualParentUuid);
    }
    
    /**
     * Wird aufgerufen, wenn Hauptbranche für Subbranche ausgewählt wird (Rückwärtskompatibilität)
     */
    onParentIndustrySelected(excelValue, parentUuid, level = 2) {
        if (level === 3) {
            const btn = document.querySelector(`.add-level3-industry-btn[data-excel-value="${excelValue}"]`);
            if (btn) {
                btn.disabled = !parentUuid;
                if (parentUuid) {
                    btn.onclick = () => this.addLevel3Industry(excelValue, parentUuid);
                }
            }
        } else {
            const btn = document.querySelector(`.add-sub-industry-btn[data-excel-value="${excelValue}"]`);
            if (btn) {
                btn.disabled = !parentUuid;
                if (parentUuid) {
                    btn.onclick = () => this.addIndustry(excelValue, 'sub', parentUuid);
                }
            }
        }
    }
    
    /**
     * Aktualisiert UI nach Entscheidung
     */
    updateIndustryDecision(excelValue, type, decision, industryUuid) {
        const item = document.querySelector(`[data-excel-value="${excelValue}"][data-type="${type}"]`);
        if (!item) return;
        
        // Speichere Entscheidung
        this.industryDecisions[excelValue] = {
            type: type,
            decision: decision,
            industry_uuid: industryUuid
        };
        
        item.classList.add('decision-made');
        item.dataset.decision = decision;
        item.dataset.industryUuid = industryUuid || '';
        
        // Deaktiviere Buttons
        item.querySelectorAll('button').forEach(btn => btn.disabled = true);
        item.querySelectorAll('select').forEach(sel => sel.disabled = true);
        
        // Zeige Status
        const statusDiv = document.createElement('div');
        statusDiv.className = 'decision-status';
        if (decision === 'added') {
            statusDiv.innerHTML = `✅ Hinzugefügt (UUID: ${industryUuid})`;
            statusDiv.className += ' status-added';
        } else if (decision === 'using_suggestion') {
            statusDiv.innerHTML = `✅ Vorschlag verwendet`;
            statusDiv.className += ' status-using';
        } else {
            statusDiv.innerHTML = `⏭️ Ignoriert`;
            statusDiv.className += ' status-ignored';
        }
        item.appendChild(statusDiv);
    }
    
    /**
     * Speichert Mapping-Konfiguration
     */
    async saveMapping() {
        if (!this.currentBatch) {
            Utils.showError('Kein Batch vorhanden');
            return;
        }
        
        // Sammle Mapping aus Select-Feldern
        const mappingConfig = {
            header_row: 1, // TODO: Aus Analysis
            data_start_row: 2,
            columns: {}
        };
        
        // Sammle Mapping aus Radio-Buttons (neue Struktur)
        document.querySelectorAll('[name^="field_"]').forEach(radio => {
            if (!radio.checked) return;
            
            const field = radio.name.replace('field_', '');
            const col = radio.value;
            
            if (field && col) {
                mappingConfig.columns[field] = {
                    excel_column: col,
                    required: field === 'name' // Name ist Pflichtfeld
                };
            }
        });
        
        try {
            const response = await fetch(`/tom3/public/api/import/mapping/${this.currentBatch.batch_uuid}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    mapping_config: mappingConfig
                })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Speichern');
            }
            
            // Weiter zu Schritt 3 (Review)
            this.goToStep(3);
            await this.loadStagingPreview();
            
        } catch (error) {
            console.error('Save mapping error:', error);
            Utils.showError(error.message || 'Fehler beim Speichern des Mappings');
        }
    }
    
    /**
     * Lädt Staging-Vorschau
     */
    async loadStagingPreview() {
        const preview = document.getElementById('staging-preview');
        if (!preview) return;
        
        preview.innerHTML = '<p>Lädt Vorschau...</p>';
        
        try {
            const response = await fetch(`/tom3/public/api/import/staging/${this.currentBatch.batch_uuid}`);
            
            if (!response.ok) {
                throw new Error('Fehler beim Laden der Vorschau');
            }
            
            const data = await response.json();
            this.renderStagingPreview(data);
            
        } catch (error) {
            console.error('Load staging error:', error);
            preview.innerHTML = `<p class="error">${error.message}</p>`;
        }
    }
    
    /**
     * Rendert Staging-Vorschau
     */
    renderStagingPreview(data) {
        const preview = document.getElementById('staging-preview');
        if (!preview) return;
        
        // Prüfe, ob Branchendaten fehlen (inkonsistent)
        const hasInconsistentIndustries = this.currentBatch?.analysis?.industry_validation?.consistency && 
                                         !this.currentBatch.analysis.industry_validation.consistency.is_consistent;
        
        let html = '';
        
        // Warnung bei inkonsistenten Branchendaten
        if (hasInconsistentIndustries) {
            const consistency = this.currentBatch.analysis.industry_validation.consistency;
            html += '<div class="alert alert-warning" style="margin: 1rem 0; padding: 1rem; border: 2px solid #ffc107; background-color: #fff3cd;">';
            html += '<h5>⚠️ Branchendaten fehlen</h5>';
            html += `<p><strong>Die Importdatei enthält verschiedene Branchenwerte. Die Branchenfelder wurden nicht gemappt und müssen nach dem Import manuell nachgetragen werden.</strong></p>`;
            if (consistency.level1_values && consistency.level1_values.length > 0) {
                html += `<p>Gefundene Branchenbereiche (Level 1): ${consistency.level1_values.join(', ')}</p>`;
            }
            if (consistency.level2_values && consistency.level2_values.length > 0) {
                html += `<p>Gefundene Branchen (Level 2): ${consistency.level2_values.join(', ')}</p>`;
            }
            html += '</div>';
        }
        
        // Statistiken
        html += '<div class="staging-stats">';
        html += '<h4>Statistiken</h4>';
        html += `<p>Total: ${data.stats?.total_rows || 0}</p>`;
        html += `<p>Valid: ${data.stats?.valid || 0}</p>`;
        html += `<p>Warnings: ${data.stats?.warnings || 0}</p>`;
        html += `<p>Errors: ${data.stats?.errors || 0}</p>`;
        if (hasInconsistentIndustries) {
            html += '<p><strong style="color: #ffc107;">⚠️ Branchendaten fehlen (müssen nachgetragen werden)</strong></p>';
        }
        html += '</div>';
        
        preview.innerHTML = html;
    }
    
    /**
     * Wechselt zu Schritt
     */
    goToStep(step) {
        this.currentStep = step;
        
        // Update Wizard-Navigation
        document.querySelectorAll('.wizard-step-indicator').forEach((el, idx) => {
            const stepNum = idx + 1;
            el.classList.remove('active', 'completed');
            if (stepNum === step) {
                el.classList.add('active');
            } else if (stepNum < step) {
                el.classList.add('completed');
            }
        });
        
        // Zeige/Verstecke Schritte
        document.querySelectorAll('.wizard-step').forEach((el, idx) => {
            if (idx + 1 === step) {
                el.classList.add('active');
                el.style.display = 'block';
            } else {
                el.classList.remove('active');
                el.style.display = 'none';
            }
        });
    }
    
    /**
     * Freigeben & Importieren
     */
    async approveImport() {
        // TODO: Implementierung
        Utils.showInfo('Import-Freigabe wird implementiert...');
    }
}
