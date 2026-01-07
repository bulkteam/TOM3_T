/**
 * TOM3 - Import Mapping Module
 * Handles column mapping step
 */

import { Utils } from './utils.js';

export class ImportMappingModule {
    constructor(importModule) {
        this.importModule = importModule;
    }
    
    /**
     * Rendert Mapping-Step
     */
    async renderMappingStep() {
        const container = document.getElementById('mapping-configurator');
        if (!container || !this.importModule.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Mapping-Vorschlag...</p>';
        
        try {
            let analysis = this.importModule.analysis;
            
            if (!analysis || !analysis.mapping_suggestion) {
                const batchResponse = await fetch(`/tom3/public/api/import/batch/${this.importModule.currentBatch}/stats`);
                if (!batchResponse.ok) {
                    throw new Error('Batch nicht gefunden');
                }
                const batch = await batchResponse.json();
                
                if (batch && batch.mapping_config) {
                    analysis = {
                        mapping_suggestion: this.convertMappingConfigToSuggestion(batch.mapping_config),
                        industry_validation: null
                    };
                    this.importModule.analysis = analysis;
                } else {
                    throw new Error('Keine Analyse-Daten gefunden. Bitte laden Sie die Datei erneut hoch.');
                }
            }
            
            const mapping = analysis.mapping_suggestion || {};
            let html = '<div class="mapping-section">';
            html += this.renderMappingUI(mapping);
            html += '</div>';
            
            container.innerHTML = html;
            
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
     * Rendert Mapping-UI
     */
    renderMappingUI(mapping) {
        let html = '<div class="mapping-configurator">';
        html += '<div class="mapping-header">';
        html += '<h4>📋 Spalten-Mapping</h4>';
        html += '<p class="mapping-description">Ordnen Sie die Excel-Spalten den Systemfeldern zu:</p>';
        html += '</div>';
        
        const byField = mapping.by_field || {};
        
        const fieldCategories = {
            'Basis-Informationen': ['name', 'website', 'notes'],
            'Adresse': ['address_street', 'address_postal_code', 'address_city', 'address_state'],
            'Kontakt': ['email', 'phone', 'fax'],
            'Weitere Daten': ['vat_id', 'revenue_range', 'employee_count']
        };
        
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
     * Holt Header-Name für Spalte aus Analysis
     */
    getHeaderNameForColumn(excelColumn) {
        if (!this.importModule.analysis || !this.importModule.analysis.columns) {
            return excelColumn;
        }
        
        return this.importModule.analysis.columns[excelColumn] || excelColumn;
    }
    
    /**
     * Speichert Mapping-Konfiguration
     */
    async saveMapping() {
        if (!this.importModule.currentBatch) {
            Utils.showError('Kein Batch vorhanden');
            return;
        }
        
        try {
            const mappingConfig = {
                header_row: this.importModule.analysis?.header_row || 1,
                data_start_row: (this.importModule.analysis?.header_row || 1) + 1,
                columns: {}
            };
            
            document.querySelectorAll('input[type="radio"][name^="mapping_"]:checked').forEach(radio => {
                const name = radio.name;
                const field = name.replace('mapping_', '');
                const excelColumn = radio.value;
                
                if (field === 'industry_level1' || field === 'industry_level2' || field === 'industry_level3' ||
                    field === 'industry_main' || field === 'industry_sub') {
                    return;
                }
                
                if (field && excelColumn) {
                    const headerName = this.getHeaderNameForColumn(excelColumn);
                    
                    mappingConfig.columns[field] = {
                        excel_column: excelColumn,
                        excel_header: headerName,
                        required: field === 'name'
                    };
                }
            });
            
            if (!mappingConfig.columns.name) {
                Utils.showError('Bitte mappen Sie mindestens das Feld "Name"');
                return;
            }
            
            const response = await this.importModule.fetchWithToken(`/tom3/public/api/import/mapping/${this.importModule.currentBatch}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mapping_config: mappingConfig })
            });
            
            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Fehler beim Speichern');
            }
            
            Utils.showSuccess('Mapping erfolgreich gespeichert!');
            
            Utils.showInfo('Importiere Daten in Staging und erstelle Branchen-Vorschläge...');
            await this.importToStaging();
            
            this.importModule.goToStep(3);
            
        } catch (error) {
            console.error('Save mapping error:', error);
            Utils.showError('Fehler beim Speichern des Mappings: ' + error.message);
        }
    }
    
    /**
     * Importiert in Staging
     */
    async importToStaging() {
        try {
            const batch = await window.API.request(`/import/batch/${this.importModule.currentBatch}`);
            
            if (!batch || !batch.mapping_config) {
                throw new Error('Kein Mapping gefunden. Bitte speichern Sie zuerst das Mapping.');
            }
            
            const stagingResponse = await this.importModule.fetchWithToken(`/tom3/public/api/import/staging/${this.importModule.currentBatch}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (!stagingResponse.ok) {
                const error = await stagingResponse.json();
                throw new Error(error.message || 'Fehler beim Import in Staging');
            }
            
            const stagingResult = await stagingResponse.json();
            
            if (stagingResult.error) {
                throw new Error(stagingResult.message || stagingResult.error);
            }
            
            if (stagingResult.stats && stagingResult.stats.imported === 0) {
                if (stagingResult.stats.total_rows > 0) {
                    const errorDetails = stagingResult.stats.errors_detail?.[0];
                    const errorMsg = errorDetails 
                        ? `Import fehlgeschlagen: ${stagingResult.stats.errors || 0} Fehler. Erster Fehler (Zeile ${errorDetails.row}): ${errorDetails.error}`
                        : `Import fehlgeschlagen: ${stagingResult.stats.errors || 0} Fehler. Bitte prüfen Sie die Mapping-Konfiguration.`;
                    throw new Error(errorMsg);
                } else {
                    throw new Error('Keine Datenzeilen in der Excel-Datei gefunden. Bitte prüfen Sie die Datei.');
                }
            }
            
            this.importModule.stagingImported = true;
            
            Utils.showSuccess(`Import erfolgreich! ${stagingResult.stats?.imported || 0} Zeilen importiert.`);
            
            return stagingResult;
            
        } catch (error) {
            console.error('Error importing to staging:', error);
            Utils.showError('Fehler beim Import in Staging: ' + error.message);
            throw error;
        }
    }
    
    /**
     * Lädt Analyse-Daten für einen Batch neu
     */
    async reloadAnalysisForBatch(batchUuid) {
        try {
            Utils.showInfo('Lade Analyse-Daten...');
            
            const response = await this.importModule.fetchWithToken(`/tom3/public/api/import/analyze`, {
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
            this.importModule.analysis = result.analysis || result;
            
            Utils.showSuccess('Analyse-Daten geladen.');
            
        } catch (error) {
            console.error('Error reloading analysis:', error);
            const batchResponse = await fetch(`/tom3/public/api/import/batch/${batchUuid}/stats`);
            if (batchResponse.ok) {
                const batch = await batchResponse.json();
                if (batch.mapping_config) {
                    this.importModule.analysis = {
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
}


