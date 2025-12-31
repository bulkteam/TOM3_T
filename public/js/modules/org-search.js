/**
 * TOM3 - Organization Search Module
 * Handles organization search, filtering, and recent organizations
 */

import { Utils } from './utils.js';

export class OrgSearchModule {
    constructor(app) {
        this.app = app;
        // Handler-Referenzen für sauberes Event-Listener-Management (ohne cloneNode)
        this._searchInputHandlers = new Map(); // eventType -> handler
        this._filterToggleHandler = null;
        this._applyFiltersHandler = null;
        this._resetFiltersHandler = null;
        this._createOrgHandler = null;
    }
    
    init() {
        console.log('[OrgSearch] ===== init() called =====');
        const searchInput = document.getElementById('org-search-input');
        const resultsContainer = document.getElementById('org-search-results');
        const filtersContainer = document.getElementById('org-filters-panel');
        
        console.log('[OrgSearch] Elements found:', {
            searchInput: !!searchInput,
            resultsContainer: !!resultsContainer,
            filtersContainer: !!filtersContainer
        });
        
        if (!searchInput) {
            console.warn('[OrgSearch] Org search input not found');
            return;
        }
        
        // Entferne alte Event-Listener, falls vorhanden
        const oldKeypressHandler = this._searchInputHandlers.get('keypress');
        const oldKeydownHandler = this._searchInputHandlers.get('keydown');
        const oldInputHandler = this._searchInputHandlers.get('input');
        if (oldKeypressHandler) {
            searchInput.removeEventListener('keypress', oldKeypressHandler);
        }
        if (oldKeydownHandler) {
            searchInput.removeEventListener('keydown', oldKeydownHandler);
        }
        if (oldInputHandler) {
            searchInput.removeEventListener('input', oldInputHandler);
        }
        
        // Setze Fokus nur, wenn kein anderes Element bereits fokussiert ist
        // (verhindert Autofocus-Warnung)
        setTimeout(() => {
            const activeElement = document.activeElement;
            const isInputFocused = activeElement && (
                activeElement.tagName === 'INPUT' || 
                activeElement.tagName === 'TEXTAREA' || 
                activeElement.tagName === 'SELECT' ||
                activeElement.isContentEditable
            );
            const isModalOpen = document.querySelector('.modal.active');
            
            // Nur fokussieren, wenn kein Input fokussiert ist und kein Modal offen ist
            if (!isInputFocused && !isModalOpen && searchInput) {
                try {
                    searchInput.focus();
                } catch (e) {
                    // Ignoriere Fokus-Fehler (z.B. wenn Element nicht sichtbar ist)
                }
            }
        }, 100);
        
        // Lade "Zuletzt verwendet"
        this.loadRecentOrgs();
        
        // Tastaturnavigation für Suchergebnisse
        this.selectedIndex = -1; // Index des aktuell ausgewählten Ergebnisses
        
        const keydownHandler = (e) => {
            const resultsContainer = document.getElementById('org-search-results');
            if (!resultsContainer) return;
            
            const results = resultsContainer.querySelectorAll('.org-search-result');
            if (results.length === 0) {
                // Wenn keine Ergebnisse, Enter führt Suche aus
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.performOrgSearch();
                }
                return;
            }
            
            // Pfeiltasten-Navigation
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, results.length - 1);
                this.highlightResult(results, this.selectedIndex);
                results[this.selectedIndex]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (this.selectedIndex > 0) {
                    this.selectedIndex--;
                    this.highlightResult(results, this.selectedIndex);
                    results[this.selectedIndex]?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } else if (this.selectedIndex === 0) {
                    // Zurück zum Input-Feld
                    this.selectedIndex = -1;
                    this.highlightResult(results, -1);
                    searchInput.focus();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.selectedIndex >= 0 && this.selectedIndex < results.length) {
                    // Wähle das ausgewählte Ergebnis aus
                    const selectedResult = results[this.selectedIndex];
                    const orgUuid = selectedResult.dataset.orgUuid;
                    if (orgUuid && window.app.orgDetail) {
                        window.app.orgDetail.showOrgDetail(orgUuid);
                        // Verstecke Ergebnisse nach Auswahl
                        if (resultsContainer) {
                            resultsContainer.innerHTML = '';
                        }
                        searchInput.value = '';
                        this.selectedIndex = -1;
                    }
                } else {
                    // Wenn nichts ausgewählt, führe Suche aus
                    this.performOrgSearch();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                // Verstecke Ergebnisse
                if (resultsContainer) {
                    resultsContainer.innerHTML = '';
                }
                this.selectedIndex = -1;
            }
        };
        this._searchInputHandlers.set('keydown', keydownHandler);
        searchInput.addEventListener('keydown', keydownHandler);
        
        // Enter-Taste für Suche (wenn keine Ergebnisse vorhanden)
        const keypressHandler = (e) => {
            if (e.key === 'Enter') {
                const resultsContainer = document.getElementById('org-search-results');
                const results = resultsContainer?.querySelectorAll('.org-search-result') || [];
                // Nur Suche ausführen, wenn keine Ergebnisse vorhanden oder nichts ausgewählt
                if (results.length === 0 || this.selectedIndex < 0) {
                    e.preventDefault();
                    this.performOrgSearch();
                }
            }
        };
        this._searchInputHandlers.set('keypress', keypressHandler);
        searchInput.addEventListener('keypress', keypressHandler);
        
        // Live-Suche während der Eingabe (mit Debounce)
        let searchTimeout;
        const inputHandler = (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.performOrgSearch();
            }, 300);
        };
        this._searchInputHandlers.set('input', inputHandler);
        searchInput.addEventListener('input', inputHandler);
        
        // Filter-Toggle-Button
        const filterToggle = document.getElementById('btn-toggle-filters');
        if (filterToggle && filtersContainer) {
            if (this._filterToggleHandler) {
                filterToggle.removeEventListener('click', this._filterToggleHandler);
            }
            
            this._filterToggleHandler = () => {
                const isVisible = filtersContainer.style.display !== 'none';
                filtersContainer.style.display = isVisible ? 'none' : 'block';
                const arrow = filterToggle.querySelector('.filter-arrow');
                if (arrow) {
                    arrow.textContent = isVisible ? '▾' : '▴';
                }
            };
            
            filterToggle.addEventListener('click', this._filterToggleHandler);
        }
        
        // Filter-Apply-Button
        const applyFiltersBtn = document.getElementById('btn-apply-filters');
        if (applyFiltersBtn) {
            if (this._applyFiltersHandler) {
                applyFiltersBtn.removeEventListener('click', this._applyFiltersHandler);
            }
            
            this._applyFiltersHandler = () => {
                this.performOrgSearch();
            };
            
            applyFiltersBtn.addEventListener('click', this._applyFiltersHandler);
        }
        
        // Filter-Reset-Button
        const resetFiltersBtn = document.getElementById('btn-reset-filters');
        if (resetFiltersBtn) {
            if (this._resetFiltersHandler) {
                resetFiltersBtn.removeEventListener('click', this._resetFiltersHandler);
            }
            
            this._resetFiltersHandler = () => {
                if (filtersContainer) {
                    filtersContainer.querySelectorAll('input, select').forEach(el => {
                        if (el.type === 'checkbox') {
                            el.checked = false;
                        } else {
                            el.value = '';
                        }
                    });
                }
                this.performOrgSearch();
            };
            
            resetFiltersBtn.addEventListener('click', this._resetFiltersHandler);
        }
        
        // Create-Org-Button
        console.log('[OrgSearch] Looking for create org button...');
        const createOrgButton = document.getElementById('btn-create-org');
        console.log('[OrgSearch] Create org button found:', !!createOrgButton, createOrgButton);
        if (createOrgButton) {
            console.log('[OrgSearch] Setting up create button event listener...');
            if (this._createOrgHandler) {
                createOrgButton.removeEventListener('click', this._createOrgHandler);
            }
            
            this._createOrgHandler = (e) => {
                console.log('[OrgSearch] ===== Create button clicked! =====');
                console.log('[OrgSearch] Event:', e);
                console.log('[OrgSearch] window.app:', window.app);
                console.log('[OrgSearch] window.app.orgForms:', window.app?.orgForms);
                e.preventDefault();
                e.stopPropagation();
                if (window.app && window.app.orgForms) {
                    console.log('[OrgSearch] Calling showCreateOrgModal...');
                    window.app.orgForms.showCreateOrgModal();
                } else {
                    console.error('[OrgSearch] orgForms not available!', {
                        app: window.app,
                        orgForms: window.app?.orgForms
                    });
                }
                return false;
            };
            
            createOrgButton.addEventListener('click', this._createOrgHandler, true); // useCapture = true für frühere Erfassung
            
            console.log('[OrgSearch] Create button event listener attached', {
                button: createOrgButton,
                hasEventListener: true,
                buttonId: createOrgButton.id
            });
        } else {
            console.error('[OrgSearch] Create org button NOT FOUND!');
        }
        console.log('[OrgSearch] ===== init() completed =====');
    }
    
    async loadRecentOrgs() {
        try {
            const user = await window.API.getCurrentUser();
            if (!user || !user.user_id) return;
            
            const recent = await window.API.getRecentOrgs(user.user_id, 10);
            const container = document.getElementById('org-recent-list');
            if (!container) return;
            
            if (recent && recent.length > 0) {
                container.innerHTML = recent.map(org => `
                    <div class="org-recent-item" data-org-uuid="${org.org_uuid || org.uuid}" style="padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'" onclick="app.orgDetail.showOrgDetail('${org.org_uuid || org.uuid}')">
                        <h4 style="margin: 0 0 0.25rem 0; font-size: 0.875rem; color: var(--text);">${Utils.escapeHtml(org.name || 'Unbekannt')}</h4>
                        ${org.city ? `<p style="margin: 0; color: var(--text-light); font-size: 0.75rem;">${Utils.escapeHtml(org.city)}</p>` : ''}
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-light); font-size: 0.875rem;">Keine zuletzt verwendeten Organisationen</div>';
            }
        } catch (error) {
            console.error('Error loading recent orgs:', error);
        }
    }
    
    async performOrgSearch() {
        const searchInput = document.getElementById('org-search-input');
        const resultsContainer = document.getElementById('org-search-results');
        const filtersContainer = document.getElementById('org-filters-panel');
        
        if (!searchInput) return;
        
        const query = searchInput.value.trim();
        
        // Sammle Filter
        const filters = {};
        if (filtersContainer) {
            // Branche
            const industryMain = filtersContainer.querySelector('#filter-industry-main');
            const industrySub = filtersContainer.querySelector('#filter-industry-sub');
            if (industrySub?.value) {
                filters.industry = industrySub.value;
            } else if (industryMain?.value) {
                filters.industry = industryMain.value;
            }
            
            // Status (Checkboxes)
            const statusCheckboxes = filtersContainer.querySelectorAll('#filter-status input[type="checkbox"]:checked');
            if (statusCheckboxes.length > 0) {
                filters.status = Array.from(statusCheckboxes).map(cb => cb.value).join(',');
            }
            
            // Typ (Checkboxes)
            const kindCheckboxes = filtersContainer.querySelectorAll('#filter-org-kind input[type="checkbox"]:checked');
            if (kindCheckboxes.length > 0) {
                filters.org_kind = Array.from(kindCheckboxes).map(cb => cb.value).join(',');
            }
            
            // Stadt
            const cityFilter = filtersContainer.querySelector('#filter-city');
            if (cityFilter?.value) {
                filters.city = cityFilter.value;
            }
            
            // Umsatz
            const revenueMin = filtersContainer.querySelector('#filter-revenue-min');
            if (revenueMin?.value) {
                filters.revenue_min = revenueMin.value;
            }
            
            // Mitarbeiter
            const employeesCheckboxes = filtersContainer.querySelectorAll('#filter-employees input[type="checkbox"]:checked');
            if (employeesCheckboxes.length > 0) {
                filters.employees_min = Array.from(employeesCheckboxes).map(cb => {
                    const val = cb.value;
                    if (val === '0-10') return 0;
                    if (val === '10-50') return 10;
                    if (val === '50-250') return 50;
                    if (val === '250+') return 250;
                    return 0;
                })[0]; // Nimm den niedrigsten Wert
            }
            
            // Archiviert
            const includeArchived = filtersContainer.querySelector('#filter-include-archived');
            if (includeArchived?.checked) {
                filters.include_archived = true;
            }
        }
        
        // Wenn keine Suche und keine Filter, zeige leere Liste
        if (!query && Object.keys(filters).length === 0) {
            if (resultsContainer) {
                resultsContainer.innerHTML = '';
            }
            return;
        }
        
        try {
            if (resultsContainer) {
                resultsContainer.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-light);">Suche läuft...</div>';
            }
            
            const results = await window.API.searchOrgs(query, filters, 50);
            
            if (!resultsContainer) return;
            
            if (results.length === 0) {
                resultsContainer.innerHTML = '<div class="empty-state"><p>Keine Organisationen gefunden</p></div>';
                return;
            }
            
            resultsContainer.innerHTML = results.map(org => this.renderOrgSearchResult(org)).join('');
            
            // Reset selected index bei neuen Ergebnissen
            this.selectedIndex = -1;
            
            // Event-Listener für Ergebnis-Klicks
            resultsContainer.querySelectorAll('.org-search-result').forEach(result => {
                result.addEventListener('click', () => {
                    const orgUuid = result.dataset.orgUuid;
                    if (orgUuid && window.app.orgDetail) {
                        window.app.orgDetail.showOrgDetail(orgUuid);
                        // Verstecke Ergebnisse nach Auswahl
                        if (resultsContainer) {
                            resultsContainer.innerHTML = '';
                        }
                        searchInput.value = '';
                        this.selectedIndex = -1;
                    }
                });
            });
        } catch (error) {
            console.error('Error searching orgs:', error);
            if (resultsContainer) {
                resultsContainer.innerHTML = '<div class="empty-state"><p style="color: var(--danger);">Fehler bei der Suche</p></div>';
            }
        }
    }
    
    highlightResult(results, index) {
        if (!results) return;
        results.forEach((result, i) => {
            const isArchived = result.dataset.archived === 'true';
            const h4 = result.querySelector('h4');
            const p = result.querySelector('p');
            
            if (i === index) {
                // Hervorhebung für ausgewähltes Element
                result.style.background = 'var(--primary)';
                result.style.borderColor = 'var(--primary)';
                result.classList.add('keyboard-selected');
                if (h4) h4.style.color = '#fff';
                if (p) p.style.color = 'rgba(255, 255, 255, 0.9)';
            } else {
                // Zurücksetzen
                result.style.background = isArchived ? '#f5f5f5' : 'transparent';
                result.style.borderColor = 'var(--border)';
                result.classList.remove('keyboard-selected');
                if (h4) h4.style.color = isArchived ? '#999' : '';
                if (p) p.style.color = '';
            }
        });
    }
    
    renderOrgSearchResult(org) {
        const isArchived = org.archived_at !== null && org.archived_at !== undefined;
        const archivedStyle = isArchived ? 'opacity: 0.6; background: #f5f5f5; border-left: 3px solid #999;' : '';
        return `
            <div class="org-search-result" data-org-uuid="${org.org_uuid || org.uuid}" data-archived="${isArchived}" style="padding: 1rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s, border-color 0.2s; ${archivedStyle}" onmouseover="if (!this.classList.contains('keyboard-selected')) { this.style.background='var(--bg)'; this.style.borderColor='var(--primary)'; }" onmouseout="if (!this.classList.contains('keyboard-selected')) { this.style.background='${isArchived ? '#f5f5f5' : 'transparent'}'; this.style.borderColor='var(--border)'; }">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 0.5rem 0; color: ${isArchived ? '#999' : 'var(--text)'};">
                            ${Utils.escapeHtml(org.name || 'Unbekannt')}
                            ${isArchived ? '<span style="font-size: 0.75rem; color: #999; margin-left: 0.5rem;">(Archiviert)</span>' : ''}
                        </h4>
                        ${org.city ? `<p style="margin: 0; color: var(--text-light); font-size: 0.875rem;">${Utils.escapeHtml(org.city)}</p>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
}



