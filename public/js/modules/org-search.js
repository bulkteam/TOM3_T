/**
 * TOM3 - Organization Search Module
 * Handles organization search, filtering, and recent organizations
 */

import { Utils } from './utils.js';

export class OrgSearchModule {
    constructor(app) {
        this.app = app;
    }
    
    init() {
        const searchInput = document.getElementById('org-search-input');
        const resultsContainer = document.getElementById('org-search-results');
        const filtersContainer = document.getElementById('org-filters-panel');
        
        if (!searchInput) {
            console.warn('Org search input not found');
            return;
        }
        
        // Entferne alte Event-Listener, falls vorhanden (durch Klonen des Inputs)
        const currentValue = searchInput.value; // Speichere aktuellen Wert
        const newSearchInput = searchInput.cloneNode(true);
        newSearchInput.value = currentValue; // Stelle Wert wieder her
        searchInput.parentNode.replaceChild(newSearchInput, searchInput);
        
        // Lade "Zuletzt verwendet"
        this.loadRecentOrgs();
        
        // Such-Event-Listener für Enter-Taste
        newSearchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.performOrgSearch();
            }
        });
        
        // Live-Suche während der Eingabe (mit Debounce)
        let searchTimeout;
        newSearchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.performOrgSearch();
            }, 300);
        });
        
        // Filter-Toggle-Button
        const filterToggle = document.getElementById('btn-toggle-filters');
        if (filterToggle && filtersContainer) {
            // Entferne alte Event-Listener
            const newFilterToggle = filterToggle.cloneNode(true);
            filterToggle.parentNode.replaceChild(newFilterToggle, filterToggle);
            
            newFilterToggle.addEventListener('click', () => {
                const isVisible = filtersContainer.style.display !== 'none';
                filtersContainer.style.display = isVisible ? 'none' : 'block';
                const arrow = newFilterToggle.querySelector('.filter-arrow');
                if (arrow) {
                    arrow.textContent = isVisible ? '▾' : '▴';
                }
            });
        }
        
        // Filter-Apply-Button
        const applyFiltersBtn = document.getElementById('btn-apply-filters');
        if (applyFiltersBtn) {
            const newApplyBtn = applyFiltersBtn.cloneNode(true);
            applyFiltersBtn.parentNode.replaceChild(newApplyBtn, applyFiltersBtn);
            newApplyBtn.addEventListener('click', () => {
                this.performOrgSearch();
            });
        }
        
        // Filter-Reset-Button
        const resetFiltersBtn = document.getElementById('btn-reset-filters');
        if (resetFiltersBtn) {
            const newResetBtn = resetFiltersBtn.cloneNode(true);
            resetFiltersBtn.parentNode.replaceChild(newResetBtn, resetFiltersBtn);
            newResetBtn.addEventListener('click', () => {
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
            });
        }
        
        // Create-Org-Button
        const createOrgButton = document.getElementById('btn-create-org');
        if (createOrgButton) {
            const newCreateBtn = createOrgButton.cloneNode(true);
            createOrgButton.parentNode.replaceChild(newCreateBtn, createOrgButton);
            newCreateBtn.addEventListener('click', () => {
                if (window.app.orgForms) {
                    window.app.orgForms.showCreateOrgModal();
                }
            });
        }
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
            
            // Event-Listener für Ergebnis-Klicks
            resultsContainer.querySelectorAll('.org-search-result').forEach(result => {
                result.addEventListener('click', () => {
                    const orgUuid = result.dataset.orgUuid;
                    if (orgUuid && window.app.orgDetail) {
                        window.app.orgDetail.showOrgDetail(orgUuid);
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
    
    renderOrgSearchResult(org) {
        const isArchived = org.archived_at !== null && org.archived_at !== undefined;
        const archivedStyle = isArchived ? 'opacity: 0.6; background: #f5f5f5; border-left: 3px solid #999;' : '';
        return `
            <div class="org-search-result" data-org-uuid="${org.org_uuid || org.uuid}" style="padding: 1rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s; ${archivedStyle}" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='${isArchived ? '#f5f5f5' : 'transparent'}'">
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


