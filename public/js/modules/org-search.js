/**
 * TOM3 - Organization Search Module
 * Handles organization search, filtering, and recent organizations
 */

import { Utils } from './utils.js';
import { RecentListModule } from './recent-list.js';
import { SearchKeyboardNavigationModule } from './search-keyboard-navigation.js';
import { SearchInputModule } from './search-input.js';

export class OrgSearchModule {
    constructor(app) {
        this.app = app;
        this.recentListModule = new RecentListModule(app);
        this.keyboardNav = new SearchKeyboardNavigationModule();
        this.searchInput = new SearchInputModule();
        // Handler-Referenzen für sauberes Event-Listener-Management (ohne cloneNode)
        this._searchInputHandlers = new Map(); // eventType -> handler
        this._keyboardNavCleanup = null; // Cleanup-Funktion für Keyboard-Navigation
        this._searchInputCleanup = null; // Cleanup-Funktion für Search-Input
        this._filterToggleHandler = null;
        this._applyFiltersHandler = null;
        this._resetFiltersHandler = null;
        this._createOrgHandler = null;
    }
    
    init() {
        const searchInput = document.getElementById('org-search-input');
        const resultsContainer = document.getElementById('org-search-results');
        const filtersContainer = document.getElementById('org-filters-panel');
        
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
        
        // Lade "Zuletzt verwendet" nach kurzer Verzögerung, damit User geladen ist
        setTimeout(() => {
            this.loadRecentOrgs();
        }, 200);
        
        // Debounced Search-Input (zentralisiert)
        if (this._searchInputCleanup) {
            this._searchInputCleanup(); // Entferne alte Listener
        }
        
        this._searchInputCleanup = this.searchInput.setupDebouncedSearch(
            searchInput,
            () => {
                this.performOrgSearch();
            },
            () => {
                // Wenn Input geleert wird, zeige leere Liste
                const resultsContainer = document.getElementById('org-search-results');
                if (resultsContainer) {
                    resultsContainer.innerHTML = '';
                }
            },
            300, // 300ms Delay
            1    // Mindestens 1 Zeichen (für sofortige Suche)
        );
        
        // Tastaturnavigation für Suchergebnisse (zentralisiert)
        if (this._keyboardNavCleanup) {
            this._keyboardNavCleanup(); // Entferne alte Listener
        }
        
        this._keyboardNavCleanup = this.keyboardNav.setupKeyboardNavigation(
            searchInput,
            'org-search-results',
            '.org-search-result',
            'orgUuid',
            (orgUuid) => {
                if (window.app.orgDetail) {
                    window.app.orgDetail.showOrgDetail(orgUuid);
                }
            },
            () => {
                this.performOrgSearch();
            }
        );
        
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
        const createOrgButton = document.getElementById('btn-create-org');
        if (createOrgButton) {
            if (this._createOrgHandler) {
                createOrgButton.removeEventListener('click', this._createOrgHandler);
            }
            
            this._createOrgHandler = (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (window.app && window.app.orgForms) {
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
        }
    }
    
    async loadRecentOrgs() {
        await this.recentListModule.loadRecentList(
            'org',
            'org-recent-list',
            (org) => `
                <div class="org-recent-item" data-org-uuid="${org.org_uuid || org.uuid}" style="padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                    <h4 style="margin: 0 0 0.25rem 0; font-size: 0.875rem; color: var(--text);">${Utils.escapeHtml(org.name || 'Unbekannt')}</h4>
                    ${org.city ? `<p style="margin: 0; color: var(--text-light); font-size: 0.75rem;">${Utils.escapeHtml(org.city)}</p>` : ''}
                </div>
            `,
            (orgUuid) => {
                if (window.app.orgDetail) {
                    window.app.orgDetail.showOrgDetail(orgUuid);
                }
            },
            10
        );
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
            this.keyboardNav.resetSelection();
            
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
                        this.keyboardNav.resetSelection();
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
        // Delegiere an zentrale Keyboard-Navigation
        this.keyboardNav.highlightResult(results, index);
    }
    
    renderOrgSearchResult(org) {
        const isArchived = org.archived_at !== null && org.archived_at !== undefined;
        const archivedStyle = isArchived ? 'opacity: 0.6; background: #f5f5f5; border-left: 3px solid #999;' : '';
        
        // Stadt aus cities (GROUP_CONCAT) oder city extrahieren
        let cityDisplay = '';
        if (org.cities) {
            // cities ist ein komma-separierter String von GROUP_CONCAT
            const cities = org.cities.split(',').map(c => c.trim()).filter(c => c);
            cityDisplay = cities[0] || ''; // Nimm die erste Stadt
        } else if (org.city) {
            cityDisplay = org.city;
        }
        
        return `
            <div class="org-search-result" data-org-uuid="${org.org_uuid || org.uuid}" data-archived="${isArchived}" style="padding: 1rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s, border-color 0.2s; ${archivedStyle}" onmouseover="if (!this.classList.contains('keyboard-selected')) { this.style.background='var(--bg)'; this.style.borderColor='var(--primary)'; }" onmouseout="if (!this.classList.contains('keyboard-selected')) { this.style.background='${isArchived ? '#f5f5f5' : 'transparent'}'; this.style.borderColor='var(--border)'; }">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 0.5rem 0; color: ${isArchived ? '#999' : 'var(--text)'};">
                            ${Utils.escapeHtml(org.name || 'Unbekannt')}
                            ${isArchived ? '<span style="font-size: 0.75rem; color: #999; margin-left: 0.5rem;">(Archiviert)</span>' : ''}
                        </h4>
                        ${cityDisplay ? `<p style="margin: 0; color: var(--text-light); font-size: 0.875rem;">${Utils.escapeHtml(cityDisplay)}</p>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
}



