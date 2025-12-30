/**
 * TOM3 - Organization Detail Edit Module
 * Handles edit mode for organization details
 */

import { Utils } from './utils.js';

export class OrgDetailEditModule {
    constructor(app) {
        this.app = app;
    }
    
    async toggleOrgEditMode(orgUuid) {
        console.log('[OrgDetailEdit] ===== toggleOrgEditMode called =====', orgUuid);
        // Zeige Eingabefelder, verstecke Werte
        document.querySelectorAll(`[data-org-uuid="${orgUuid}"] .org-detail-value`).forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll(`[data-org-uuid="${orgUuid}"] .org-detail-input`).forEach(el => {
            el.style.display = 'block';
        });
        document.querySelectorAll(`[data-org-uuid="${orgUuid}"] .org-detail-edit-btn`).forEach(el => {
            el.style.display = 'none';
        });
        
        // Account Owner Select anzeigen
        const accountOwnerValue = document.getElementById('org-field-account_owner');
        const accountOwnerInput = document.getElementById('org-input-account_owner');
        if (accountOwnerValue && accountOwnerInput) {
            accountOwnerValue.style.display = 'none';
            accountOwnerInput.style.display = 'block';
        }
        
        // Branche-Felder anzeigen
        const industryValue = document.getElementById('org-field-industry');
        const industryMainInput = document.getElementById('org-input-industry_main');
        const industrySubInput = document.getElementById('org-input-industry_sub');
        console.log('[OrgDetailEdit] Industry inputs found:', {
            value: industryValue,
            main: industryMainInput,
            sub: industrySubInput,
            mainId: industryMainInput?.id,
            subId: industrySubInput?.id
        });
        
        if (industryValue && industryMainInput && industrySubInput) {
            console.log('[OrgDetailEdit] All industry elements found, setting up...');
            industryValue.style.display = 'none';
            const industryInputContainer = industryMainInput.parentElement;
            if (industryInputContainer) {
                industryInputContainer.style.display = 'block';
            }
            
            // Setze Abhängigkeit (ohne Klonen, da Element nur einmal erstellt wird)
            console.log('[OrgDetailEdit] Setting up dependency...');
            const mainInput = Utils.setupIndustryDependency(industryMainInput, industrySubInput, false);
            console.log('[OrgDetailEdit] Dependency setup completed, mainInput:', mainInput);
            
            // Lade Hauptbranchen
            if (mainInput) {
                console.log('[OrgDetailEdit] Loading main classes...');
                await Utils.loadIndustryMainClasses(mainInput);
                console.log('[OrgDetailEdit] Main classes loaded');
                
                // Setze aktuelle Werte
                const currentMainUuid = industryMainInput.dataset.currentMainUuid;
                const currentSubUuid = industrySubInput.dataset.currentSubUuid;
                console.log('[OrgDetailEdit] Current values:', { currentMainUuid, currentSubUuid });
                if (currentMainUuid) {
                    mainInput.value = currentMainUuid;
                    if (currentMainUuid) {
                        await Utils.loadIndustrySubClasses(currentMainUuid, industrySubInput);
                        if (currentSubUuid) {
                            industrySubInput.value = currentSubUuid;
                        }
                    }
                }
            } else {
                console.error('[OrgDetailEdit] mainInput is null!');
            }
        } else {
            console.error('[OrgDetailEdit] Industry elements not found!', {
                value: industryValue,
                main: industryMainInput,
                sub: industrySubInput
            });
        }
        
        const actions = document.getElementById('org-edit-actions');
        if (actions) actions.style.display = 'flex';
        
        // Website-URL-Normalisierung beim Blur
        const websiteInput = document.getElementById('org-input-website');
        if (websiteInput) {
            websiteInput.addEventListener('blur', () => {
                Utils.normalizeUrl(websiteInput);
            });
        }
    }
    
    
    cancelOrgEdit(orgUuid) {
        // Verstecke Eingabefelder, zeige Werte
        document.querySelectorAll(`[data-org-uuid="${orgUuid}"] .org-detail-value`).forEach(el => {
            el.style.display = 'block';
        });
        document.querySelectorAll(`[data-org-uuid="${orgUuid}"] .org-detail-input`).forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll(`[data-org-uuid="${orgUuid}"] .org-detail-edit-btn`).forEach(el => {
            el.style.display = 'block';
        });
        
        // Account Owner Select verstecken
        const accountOwnerValue = document.getElementById('org-field-account_owner');
        const accountOwnerInput = document.getElementById('org-input-account_owner');
        if (accountOwnerValue && accountOwnerInput) {
            accountOwnerValue.style.display = 'block';
            accountOwnerInput.style.display = 'none';
        }
        
        // Branche-Felder verstecken
        const industryValue = document.getElementById('org-field-industry');
        const industryMainInput = document.getElementById('org-input-industry_main');
        if (industryValue && industryMainInput) {
            industryValue.style.display = 'block';
            const industryInputContainer = industryMainInput.parentElement;
            if (industryInputContainer) {
                industryInputContainer.style.display = 'none';
            }
        }
        
        const actions = document.getElementById('org-edit-actions');
        if (actions) actions.style.display = 'none';
    }
    
    async loadAccountOwnersForEdit(orgUuid, currentOwnerId) {
        try {
            const availableOwners = await window.API.getAvailableAccountOwners(true);
            const select = document.getElementById('org-input-account_owner');
            if (!select) return;
            
            select.innerHTML = '<option value="">-- Kein Owner --</option>';
            
            if (typeof availableOwners === 'object' && !Array.isArray(availableOwners)) {
                // Map mit Namen
                Object.entries(availableOwners).forEach(([userId, userName]) => {
                    const option = document.createElement('option');
                    option.value = userId;
                    option.textContent = userName;
                    if (currentOwnerId && (userId === String(currentOwnerId) || userId === currentOwnerId)) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            } else if (Array.isArray(availableOwners)) {
                // Array von User-IDs
                for (const userId of availableOwners) {
                    const option = document.createElement('option');
                    option.value = userId;
                    option.textContent = userId;
                    if (currentOwnerId && (userId === String(currentOwnerId) || userId === currentOwnerId)) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                }
            }
            
            // Wenn aktueller Owner nicht in Liste, füge ihn hinzu
            if (currentOwnerId) {
                const currentOwnerStr = String(currentOwnerId);
                const exists = Array.from(select.options).some(opt => opt.value === currentOwnerStr);
                if (!exists) {
                    const option = document.createElement('option');
                    option.value = currentOwnerStr;
                    option.textContent = `${currentOwnerStr} (nicht mehr aktiv)`;
                    option.selected = true;
                    select.insertBefore(option, select.firstChild.nextSibling);
                }
            }
        } catch (error) {
            console.error('Error loading account owners:', error);
            // Nicht kritisch, weiter machen
        }
    }
    
    async saveOrgChanges(orgUuid) {
        const orgDetailModule = this.app.orgDetail;
        
        // Hole Account Owner Name aus dem Select-Element
        const accountOwnerSelect = document.getElementById('org-input-account_owner');
        const selectedAccountOwnerId = accountOwnerSelect?.value || null;
        const selectedAccountOwnerName = accountOwnerSelect?.selectedOptions[0]?.textContent || null;
        
        const data = {
            name: document.getElementById('org-input-name')?.value || null,
            org_kind: document.getElementById('org-input-org_kind')?.value || null,
            status: document.getElementById('org-input-status')?.value || null,
            website: document.getElementById('org-input-website')?.value || null,
            notes: document.getElementById('org-input-notes')?.value || null,
            account_owner_user_id: selectedAccountOwnerId,
            industry_main_uuid: document.getElementById('org-input-industry_main')?.value || null,
            industry_sub_uuid: document.getElementById('org-input-industry_sub')?.value || null
        };
        
        // Entferne leere Werte
        Object.keys(data).forEach(key => {
            if (data[key] === null || data[key] === '') {
                delete data[key];
            }
        });
        
        try {
            const updatedOrg = await window.API.updateOrg(orgUuid, data);
            Utils.showSuccess('Organisation wurde erfolgreich aktualisiert');
            this.cancelOrgEdit(orgUuid);
            
            // Aktualisiere Account Owner Name direkt aus dem Select-Element
            if (selectedAccountOwnerName && selectedAccountOwnerId) {
                const accountOwnerValue = document.getElementById('org-field-account_owner');
                if (accountOwnerValue) {
                    // Entferne "(nicht mehr aktiv)" aus dem Namen falls vorhanden
                    const cleanName = selectedAccountOwnerName.replace(/\s*\(nicht mehr aktiv\)\s*$/, '');
                    // Hole das "(seit ...)" Datum aus der aktuellen Anzeige oder aus updatedOrg
                    let dateHtml = '';
                    if (updatedOrg && updatedOrg.account_owner_since) {
                        dateHtml = ` <span class="text-light">(seit ${new Date(updatedOrg.account_owner_since).toLocaleDateString('de-DE')})</span>`;
                    } else {
                        const existingContent = accountOwnerValue.innerHTML;
                        const dateMatch = existingContent.match(/(<span class="text-light">.*?<\/span>)/);
                        if (dateMatch) {
                            dateHtml = ' ' + dateMatch[1];
                        }
                    }
                    accountOwnerValue.innerHTML = Utils.escapeHtml(cleanName) + dateHtml;
                }
            } else if (!selectedAccountOwnerId) {
                // Kein Owner ausgewählt
                const accountOwnerValue = document.getElementById('org-field-account_owner');
                if (accountOwnerValue) {
                    accountOwnerValue.innerHTML = '<span class="org-detail-empty">Nicht zugeordnet</span>';
                }
            }
            
            // Aktualisiere Typ/Status Anzeige mit Übersetzungen
            const orgKindValue = document.getElementById('org-field-org_kind');
            const orgKindInput = document.getElementById('org-input-org_kind');
            if (orgKindValue && orgKindInput) {
                const selectedOption = orgKindInput.options[orgKindInput.selectedIndex];
                if (selectedOption) {
                    orgKindValue.textContent = selectedOption.textContent;
                }
            }
            
            const statusValue = document.getElementById('org-field-status');
            const statusInput = document.getElementById('org-input-status');
            if (statusValue && statusInput) {
                const selectedOption = statusInput.options[statusInput.selectedIndex];
                if (selectedOption) {
                    statusValue.textContent = selectedOption.textContent;
                }
            }
            
            // Aktualisiere die Anzeige (lädt die komplette Ansicht neu)
            if (orgDetailModule && orgDetailModule.showOrgDetail) {
                await orgDetailModule.showOrgDetail(orgUuid);
            }
        } catch (error) {
            console.error('Error updating org:', error);
            Utils.showError('Fehler beim Aktualisieren: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}

