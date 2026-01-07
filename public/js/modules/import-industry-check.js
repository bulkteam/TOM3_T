/**
 * TOM3 - Import Industry Check Module
 * Handles industry checking and resolution step
 */

import { Utils } from './utils.js';

export class ImportIndustryCheckModule {
    constructor(importModule) {
        this.importModule = importModule; // Referenz zum Haupt-Import-Modul
    }
    
    /**
     * Rendert Branchen-Prüfung Step (Schritt 3)
     */
    async renderIndustryCheckStep() {
        const container = document.getElementById('industry-check-content');
        if (!container || !this.importModule.currentBatch) return;
        
        container.innerHTML = '<p>Lädt Branchen-Vorschläge...</p>';
        
        try {
            // Prüfe, ob bereits in Staging importiert wurde
            if (!this.importModule.stagingImported) {
                // Falls nicht, importiere jetzt
                await this.importModule.importToStaging();
            }
            
            // Lade Staging-Rows mit industry_resolution
            const stagingRows = await this.importModule.loadStagingRows(this.importModule.currentBatch);
            
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
                    confirmBtn.onclick = () => this.importModule.goToStep(4);
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
     * Rendert Industry-Warnings
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
     * Rendert Industry-Kombination (sequenzielle 3-Level-Auswahl)
     */
    renderIndustryCombination(comboId, combo) {
        const stagingUuid = combo.staging_uuid || '';
        const suggestions = {};
        const decision = {};
        
        const level1Uuid = null;
        const level1Confirmed = false;
        const level2PreSelected = suggestions.level2_candidates?.[0] || null;
        const level2Uuid = decision.level2_uuid || level2PreSelected?.industry_uuid || null;
        const level2Confirmed = decision.level2_confirmed || false;
        const level3PreSelected = suggestions.level3_candidates?.[0] || null;
        const level3Uuid = decision.level3_uuid || null;
        const level3Action = decision.level3_action || 'UNDECIDED';
        
        let html = `<div class="industry-combination" data-combo-id="${comboId}" data-staging-uuid="${stagingUuid}">`;
        html += `<div class="combination-header">`;
        html += `<div class="combination-excel-data">`;
        html += `<span class="excel-label">Excel-Daten:</span> `;
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
        
        // Lade Vorschläge (async)
        html += `<div class="loading-suggestions" id="loading_${comboId}">`;
        html += `<small>💡 Lade Vorschläge...</small>`;
        html += `</div>`;
        this.loadStagingRowForCombination(comboId, stagingUuid);
        
        // Level 1: Branchenbereich
        html += `<div class="industry-step" data-step="1" data-combo-id="${comboId}" 
                 style="display: block; padding: 1rem; margin-top: 1rem; border-radius: 4px; background: #f9f9f9;">`;
        html += `<label class="step-label"><strong>1. Branchenbereich (Level 1):</strong></label>`;
        html += `<div class="step-help-text">`;
        html += `<small>Wird automatisch aus Level 2 abgeleitet, kann aber manuell geändert werden. Wenn Level 1 geändert wird, werden die Level 2 Optionen entsprechend gefiltert.</small>`;
        html += `</div>`;
        html += `<select class="industry-level1-select" data-combo-id="${comboId}" 
                 onchange="window.app.import.industryCheckModule.onLevel1Selected('${comboId}', this.value); window.app.import.industryCheckModule.updateConfirmIndustriesButton();" 
                 ${level1Confirmed ? 'disabled' : ''}>`;
        html += `<option value="">-- Bitte wählen --</option>`;
        html += `</select>`;
        html += `<div class="suggestion-container" id="suggestion_${comboId}_level1"></div>`;
        if (!level1Confirmed) {
            html += `<button type="button" class="btn btn-sm btn-primary confirm-level1-btn" 
                     data-combo-id="${comboId}" 
                     onclick="window.app.import.industryCheckModule.confirmLevel1('${comboId}')" 
                     ${level1Uuid ? '' : 'disabled'}>`;
            html += `<span class="btn-icon">✓</span> Bestätigen`;
            html += `</button>`;
        } else {
            html += `<div class="level-confirmation-feedback">✅ Branchenbereich ausgewählt</div>`;
        }
        html += `</div>`;
        
        // Level 2: Branche
        html += `<div class="industry-step" data-step="2" data-combo-id="${comboId}">`;
        html += `<div class="excel-value-hint">Excel-Wert (Spalte D): <strong>${combo.excel_level2 || 'N/A'}</strong></div>`;
        html += `<label class="step-label"><strong>2. Branche (Level 2) wählen:</strong></label>`;
        html += `<div class="step-help-text">`;
        html += `<small>Dieser Wert kommt direkt aus Ihrer Excel-Datei. Wenn ein Match gefunden wird, wird Level 1 (Branchenbereich) automatisch vorbelegt.</small>`;
        html += `</div>`;
        html += `<select class="industry-level2-select" data-combo-id="${comboId}" 
                 onchange="window.app.import.industryCheckModule.onLevel2Selected('${comboId}', this.value); window.app.import.industryCheckModule.updateConfirmIndustriesButton();" 
                 ${level2Confirmed ? 'disabled' : ''}>`;
        html += `<option value="">-- Bitte wählen --</option>`;
        html += `</select>`;
        html += `<div class="suggestion-container" id="suggestion_${comboId}_level2"></div>`;
        if (!level2Confirmed) {
            html += `<button type="button" class="btn btn-sm btn-primary confirm-level2-btn" 
                     data-combo-id="${comboId}" 
                     onclick="window.app.import.industryCheckModule.confirmLevel2('${comboId}')" 
                     ${level2Uuid ? '' : 'disabled'}>`;
            html += `<span class="btn-icon">✓</span> Bestätigen`;
            html += `</button>`;
        } else {
            html += `<div class="level-confirmation-feedback">✅ Branche ausgewählt</div>`;
        }
        html += `</div>`;
        
        // Level 3: Unterbranche
        html += `<div class="industry-step" data-step="3" data-combo-id="${comboId}" 
                 style="display: ${level2Confirmed ? 'block' : 'none'}; opacity: ${level2Confirmed ? '1' : '0.5'}; 
                 background: ${level2Confirmed ? '#f9f9f9' : '#f0f0f0'}; padding: 1rem; margin-top: 1rem; border-radius: 4px;">`;
        html += `<label><strong>3. Unterbranche (Level 3)</strong></label>`;
        
        if (level3PreSelected && level3PreSelected.industry_uuid) {
            html += `<select class="industry-level3-select" data-combo-id="${comboId}" 
                     onchange="window.app.import.industryCheckModule.onLevel3Selected('${comboId}', this.value); window.app.import.industryCheckModule.updateConfirmIndustriesButton();" 
                     style="width: 100%; padding: 0.5rem; margin: 0.5rem 0;">`;
            html += `<option value="">-- Bitte wählen --</option>`;
            html += `</select>`;
            html += `<p class="suggestion-hint">💡 Vorschlag: ${level3PreSelected.name}</p>`;
        } else {
            html += `<p class="info">Keine passende Unterbranche gefunden.</p>`;
            html += `<div class="form-group" style="margin-top: 0.5rem;">`;
            html += `<label>Neue Unterbranche anlegen:</label>`;
            html += `<input type="text" class="industry-level3-new-input" data-combo-id="${comboId}" 
                     placeholder="${combo.excel_level3 || 'Unterbranche'}" 
                     value="${combo.excel_level3 || ''}" style="width: 100%; padding: 0.5rem;">`;
            html += `<button type="button" class="btn btn-sm btn-success" 
                     onclick="window.app.import.industryCheckModule.addLevel3FromCombo('${comboId}')" 
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
        
        if (!level1Uuid) {
            const level2Select = comboEl.querySelector('.industry-level2-select');
            if (level2Select) {
                level2Select.innerHTML = '<option value="">-- Bitte wählen --</option>';
                level2Select.value = '';
            }
            const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
            if (suggestionContainer2) {
                suggestionContainer2.innerHTML = '';
            }
            return;
        }
        
        await this.loadLevel2Options(comboId, level1Uuid);
        
        const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
        if (suggestionContainer2) {
            suggestionContainer2.innerHTML = '';
        }
        
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
            const response = await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level1_uuid: level1Uuid,
                    confirm_level1: true
                })
            });
            
            if (response.dropdown_options) {
                this.updateDropdownOptions(comboId, response.dropdown_options);
            }
            
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
            
            this.activateLevel1(comboId);
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
            this.resetLevel1(comboId);
            this.resetLevel3(comboId);
            return;
        }
        
        const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
        
        try {
            const industries = await window.API.getIndustries(null, false, 2);
            const selectedLevel2 = industries.find(i => i.industry_uuid === level2Uuid);
            
            if (selectedLevel2 && selectedLevel2.parent_industry_uuid) {
                const level1Select = document.querySelector(`.industry-level1-select[data-combo-id="${comboId}"]`);
                if (level1Select) {
                    if (level1Select.options.length <= 1) {
                        await this.loadAllLevel1Options();
                    }
                    
                    level1Select.value = selectedLevel2.parent_industry_uuid;
                    
                    const level1Industries = await window.API.getIndustries(null, false, 1);
                    const parentLevel1 = level1Industries.find(i => i.industry_uuid === selectedLevel2.parent_industry_uuid);
                    
                    const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                    if (suggestionContainer) {
                        suggestionContainer.innerHTML = `
                            <p class="suggestion-hint">
                                ✅ <strong>Automatisch abgeleitet:</strong> ${parentLevel1?.name || 'Branchenbereich'}
                                <small>(aus Level 2: "${selectedLevel2.name}")</small>
                            </p>
                        `;
                    }
                    
                    const confirmBtn = document.querySelector(`.confirm-level1-btn[data-combo-id="${comboId}"]`);
                    if (confirmBtn) {
                        confirmBtn.disabled = false;
                    }
                    
                    this.activateLevel1(comboId);
                }
            }
        } catch (error) {
            console.error('Error deriving Level 1 from Level 2:', error);
        }
        
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
            const response = await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level2_uuid: level2Uuid,
                    confirm_level2: true
                })
            });
            
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
            
            this.activateLevel3(comboId);
            await this.loadLevel3Options(comboId, level2Uuid);
            
            const level1Select = comboEl.querySelector('.industry-level1-select');
            const level1Feedback = comboEl.querySelector('.industry-step[data-step="1"] .level-confirmation-feedback');
            if (level1Select && level1Select.value && !level1Select.disabled && !level1Feedback) {
                await this.confirmLevel1(comboId);
            }
            
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
            await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level3_uuid: level3Uuid,
                    level3_action: 'SELECT_EXISTING'
                })
            });
            
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
            await window.API.request(`/import/staging/${stagingUuid}/industry-decision`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    level3_new_name: level3Name,
                    level3_action: 'CREATE_NEW'
                })
            });
            
            const step3 = comboEl.querySelector('.industry-step[data-step="3"]');
            if (step3) {
                const existingFeedback = step3.querySelector('.level-confirmation-feedback');
                if (existingFeedback) existingFeedback.remove();
                
                const feedback = document.createElement('div');
                feedback.className = 'level-confirmation-feedback';
                feedback.style.cssText = 'background: #d4edda; padding: 0.5rem; margin-top: 0.5rem; border-radius: 4px;';
                feedback.textContent = `✅ Unterbranche "${level3Name}" wird beim Import erstellt.`;
                step3.appendChild(feedback);
                
                if (level3Input) {
                    level3Input.style.background = '#e9ecef';
                    level3Input.disabled = true;
                }
            }
            
            Utils.showSuccess(`Unterbranche "${level3Name}" wird beim Import erstellt.`);
            
            setTimeout(() => {
                this.updateConfirmIndustriesButton();
            }, 200);
            
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
                
                if (currentValue) {
                    select.value = currentValue;
                } else {
                    const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
                    if (comboEl) {
                        const preSelectedUuid = comboEl.dataset.level1Uuid;
                        if (preSelectedUuid) {
                            select.value = preSelectedUuid;
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Error loading Level 1 options:', error);
        }
    }
    
    /**
     * Prüft, ob alle Branchen-Level für alle Kombinationen bestätigt sind
     */
    checkAllIndustriesConfirmed() {
        const combinations = document.querySelectorAll('.industry-combination');
        if (combinations.length === 0) {
            return false;
        }
        
        for (let i = 0; i < combinations.length; i++) {
            const combo = combinations[i];
            
            const level1Select = combo.querySelector('.industry-level1-select');
            const level1Feedback = combo.querySelector('.industry-step[data-step="1"] .level-confirmation-feedback');
            const level1Ok = level1Select && level1Select.disabled && level1Feedback && level1Select.value;
            if (!level1Ok) {
                return false;
            }
            
            const level2Select = combo.querySelector('.industry-level2-select');
            const hasLevel2Options = level2Select && level2Select.options.length > 1;
            
            if (hasLevel2Options) {
                const level2Ok = level2Select && level2Select.disabled && 
                               combo.querySelector('.industry-step[data-step="2"] .level-confirmation-feedback') && 
                               level2Select.value;
                if (!level2Ok) {
                    return false;
                }
                
                const level3Step = combo.querySelector('.industry-step[data-step="3"]');
                if (level3Step) {
                    const computedStyle = window.getComputedStyle(level3Step);
                    const isVisible = computedStyle.display !== 'none' && 
                                     computedStyle.visibility !== 'hidden' &&
                                     computedStyle.opacity !== '0';
                    
                    if (isVisible) {
                        const level3Select = combo.querySelector('.industry-level3-select');
                        const level3Input = combo.querySelector('.industry-level3-new-input');
                        const level3Feedback = combo.querySelector('.industry-step[data-step="3"] .level-confirmation-feedback');
                        
                        let level3Confirmed = false;
                        
                        if (level3Select) {
                            level3Confirmed = level3Select.value && 
                                            level3Select.value !== '' &&
                                            level3Select.disabled && 
                                            !!level3Feedback;
                        } else if (level3Input) {
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
     * Aktualisiert den "Branchen bestätigen" Button
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
                        staging_uuid: row.staging_uuid,
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
            
            const stagingRows = await this.importModule.loadStagingRows(this.importModule.currentBatch);
            
            let updated = 0;
            for (const row of stagingRows) {
                const comboEl = document.querySelector(`[data-staging-uuid="${row.staging_uuid}"]`);
                if (!comboEl) continue;
                
                const level1Select = comboEl.querySelector('.industry-level1-select');
                const level2Select = comboEl.querySelector('.industry-level2-select');
                const level3Select = comboEl.querySelector('.industry-level3-select');
                
                const level1Uuid = level1Select?.value || null;
                const level2Uuid = level2Select?.value || null;
                const level3Uuid = level3Select?.value || null;
                
                if (level1Uuid && level2Uuid) {
                    await this.importModule.fetchWithToken(`/tom3/public/api/import/staging/${row.staging_uuid}/industry-decision`, {
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
            this.importModule.goToStep(4);
            
        } catch (error) {
            console.error('Error confirming industries:', error);
            Utils.showError('Fehler beim Anreichern der Daten: ' + error.message);
        }
    }
    
    /**
     * Lädt Staging-Row für Kombination (um industry_resolution zu holen)
     */
    async loadStagingRowForCombination(comboId, stagingUuid) {
        try {
            const loadingEl = document.getElementById(`loading_${comboId}`);
            if (loadingEl) {
                loadingEl.remove();
            }
            
            let resolution = null;
            let suggestions = {};
            let decision = {};
            
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
                    let excelLevel2 = comboEl.querySelector('.excel-value')?.textContent?.match(/Level 2:\s*([^/]+)/)?.[1]?.trim() || '';
                    if (!excelLevel2) {
                        excelLevel2 = comboEl.querySelector('.excel-value-hint')?.textContent?.match(/Excel-Wert.*?:\s*<strong>([^<]+)<\/strong>/)?.[1]?.trim() || '';
                    }
                    if (!excelLevel2) {
                        const comboElData = comboEl.dataset;
                        excelLevel2 = comboElData.excelLevel2 || '';
                    }
                    
                    if (excelLevel2) {
                        suggestions = await this.createSuggestionsFromExcelValue(excelLevel2);
                        decision = {
                            level1_uuid: suggestions.derived_level1?.industry_uuid || null,
                            level2_uuid: suggestions.level2_candidates?.[0]?.industry_uuid || null,
                            level1_confirmed: false,
                            level2_confirmed: false
                        };
                    }
                }
            }
            
            if (suggestions && Object.keys(suggestions).length > 0) {
                const comboEl = document.querySelector(`[data-combo-id="${comboId}"]`);
                if (!comboEl) return;
                
                // Level 1 Vorschlag
                if (suggestions.derived_level1) {
                    const level1Select = comboEl.querySelector('.industry-level1-select');
                    const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                    
                    if (level1Select && !level1Select.value) {
                        level1Select.value = suggestions.derived_level1.industry_uuid;
                        
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
                        
                        const confirmBtn = comboEl.querySelector('.confirm-level1-btn');
                        if (confirmBtn) {
                            confirmBtn.disabled = false;
                        }
                    } else if (suggestionContainer && !level1Select.value) {
                        suggestionContainer.innerHTML = `
                            <p class="suggestion-hint no-suggestion">
                                ⚠️ Kein Level 2 Match gefunden. Level 1 kann nicht automatisch abgeleitet werden. Bitte wählen Sie manuell.
                            </p>
                        `;
                    }
                } else {
                    const suggestionContainer = document.getElementById(`suggestion_${comboId}_level1`);
                    if (suggestionContainer) {
                        suggestionContainer.innerHTML = `
                            <p class="suggestion-hint no-suggestion">
                                ⚠️ Kein Level 2 Match gefunden. Level 1 kann nicht automatisch abgeleitet werden. Bitte wählen Sie manuell.
                            </p>
                        `;
                    }
                }
                
                // Level 2 Vorschlag
                if (suggestions.level2_candidates && suggestions.level2_candidates.length > 0) {
                    const best = suggestions.level2_candidates[0];
                    const level2Select = comboEl.querySelector('.industry-level2-select');
                    const suggestionContainer2 = document.getElementById(`suggestion_${comboId}_level2`);
                    
                    if (suggestions.derived_level1) {
                        await this.loadLevel2Options(comboId, suggestions.derived_level1.industry_uuid);
                    }
                    
                    if (level2Select && !level2Select.value) {
                        level2Select.value = best.industry_uuid;
                        
                        if (suggestionContainer2) {
                            const excelValueHint = comboEl.querySelector('.excel-value-hint');
                            let excelLevel2 = '';
                            if (excelValueHint) {
                                const strongTag = excelValueHint.querySelector('strong');
                                excelLevel2 = strongTag ? strongTag.textContent.trim() : '';
                            }
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
                        
                        const confirmBtn2 = comboEl.querySelector('.confirm-level2-btn');
                        if (confirmBtn2) {
                            confirmBtn2.disabled = false;
                        }
                    }
                } else {
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
     * Erstellt Vorschläge direkt aus Excel-Wert
     */
    async createSuggestionsFromExcelValue(excelLevel2Label) {
        try {
            const allLevel2 = await window.API.getIndustries(null, false, 2);
            
            const candidates = [];
            const searchTerm = excelLevel2Label.toLowerCase().trim();
            
            for (const industry of allLevel2) {
                const name = (industry.name || '').toLowerCase();
                const code = (industry.code || '').toLowerCase();
                
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
            
            candidates.sort((a, b) => b.score - a.score);
            
            const best = candidates[0];
            let derivedLevel1 = null;
            
            if (best) {
                const level1Industries = await window.API.getIndustries(null, false, 1);
                try {
                    const level2WithParent = await window.API.request(`/industries?level=2`);
                    const found = level2WithParent.find(i => i.industry_uuid === best.industry_uuid);
                    if (found && found.parent_industry_uuid) {
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
}


