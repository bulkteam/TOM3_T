/**
 * TOM3 - Person Search Module
 * Handles person search with keyboard navigation
 */

import { Utils } from './utils.js';
import { RecentListModule } from './recent-list.js';
import { SearchKeyboardNavigationModule } from './search-keyboard-navigation.js';
import { SearchInputModule } from './search-input.js';

export class PersonSearchModule {
    constructor(app) {
        this.app = app;
        this.recentListModule = new RecentListModule(app);
        this.keyboardNav = new SearchKeyboardNavigationModule();
        this.searchInput = new SearchInputModule();
        this._searchInputHandlers = new Map();
        this._keyboardNavCleanup = null; // Cleanup-Funktion für Keyboard-Navigation
        this._searchInputCleanup = null; // Cleanup-Funktion für Search-Input
    }
    
    init() {
        const searchInput = document.getElementById('person-search-input');
        const resultsContainer = document.getElementById('person-search-results');
        
        if (!searchInput) {
            console.warn('[PersonSearch] Person search input not found');
            return;
        }
        
        // Lade "Zuletzt angesehen"
        this.loadRecentPersons();
        
        // Entferne alte Event-Listener
        if (this._searchInputCleanup) {
            this._searchInputCleanup();
        }
        
        // Entferne alte Keyboard-Navigation
        if (this._keyboardNavCleanup) {
            this._keyboardNavCleanup();
        }
        
        // Input-Handler (Debounced Search) - zentralisiert
        this._searchInputCleanup = this.searchInput.setupDebouncedSearch(
            searchInput,
            (query) => {
                this.performPersonSearch();
            },
            () => {
                if (resultsContainer) {
                    resultsContainer.innerHTML = '';
                }
                this.keyboardNav.resetSelection();
            },
            300, // delay
            2 // minLength
        );
        
        // Keyboard Navigation (zentralisiert)
        this._keyboardNavCleanup = this.keyboardNav.setupKeyboardNavigation(
            searchInput,
            'person-search-results',
            '.person-search-result',
            'personUuid',
            (personUuid) => {
                if (window.app.personDetail) {
                    window.app.personDetail.showPersonDetail(personUuid);
                }
            },
            () => {
                this.performPersonSearch();
            }
        );
        
        // Create Person Button
        const createBtn = document.getElementById('btn-create-person');
        if (createBtn) {
            createBtn.addEventListener('click', () => {
                if (window.app.personForms) {
                    window.app.personForms.showCreatePersonForm();
                }
            });
        }
    }
    
    highlightResult(results, index) {
        if (!results) return;
        results.forEach((result, i) => {
            const isArchived = result.dataset.archived === 'true';
            const nameElement = result.querySelector('h4');
            const emailElement = result.querySelector('p');
            
            if (i === index) {
                result.style.background = 'var(--primary)';
                result.style.borderColor = 'var(--primary)';
                if (nameElement) nameElement.style.color = '#fff';
                if (emailElement) emailElement.style.color = '#fff';
                result.classList.add('keyboard-selected');
            } else {
                result.style.background = isArchived ? '#f5f5f5' : 'transparent';
                result.style.borderColor = 'var(--border)';
                if (nameElement) nameElement.style.color = isArchived ? '#999' : 'var(--text)';
                if (emailElement) emailElement.style.color = 'var(--text-light)';
                result.classList.remove('keyboard-selected');
            }
        });
    }
    
    renderPersonSearchResult(person) {
        const isArchived = person.is_active === 0 || person.archived_at;
        const displayName = person.display_name || `${person.first_name || ''} ${person.last_name || ''}`.trim() || 'Unbekannt';
        const email = person.email || '';
        
        return `
            <div class="person-search-result ${isArchived ? 'archived' : ''}" 
                 data-person-uuid="${person.person_uuid}" 
                 data-archived="${isArchived}">
                <h4>${Utils.escapeHtml(displayName)}</h4>
                ${email ? `<p>${Utils.escapeHtml(email)}</p>` : ''}
            </div>
        `;
    }
    
    async performPersonSearch() {
        const searchInput = document.getElementById('person-search-input');
        const resultsContainer = document.getElementById('person-search-results');
        
        if (!searchInput || !resultsContainer) return;
        
        const query = searchInput.value.trim();
        
        if (query.length < 2) {
            resultsContainer.innerHTML = '';
            return;
        }
        
        try {
            resultsContainer.innerHTML = '<div style="padding: 1rem; text-align: center; color: var(--text-light);">Suche läuft...</div>';
            
            const results = await window.API.searchPersons(query, 50);
            
            // Stelle sicher, dass results ein Array ist
            if (!results || !Array.isArray(results)) {
                resultsContainer.innerHTML = '<div class="empty-state"><p>Keine Personen gefunden</p></div>';
                return;
            }
            
            if (results.length === 0) {
                resultsContainer.innerHTML = '<div class="empty-state"><p>Keine Personen gefunden</p></div>';
                return;
            }
            
            // Speichere Ergebnisse für Event-Handler
            const searchResults = results;
            resultsContainer.innerHTML = searchResults.map(person => this.renderPersonSearchResult(person)).join('');
            
            this.keyboardNav.resetSelection();
            
            // Event-Listener für Ergebnis-Klicks
            resultsContainer.querySelectorAll('.person-search-result').forEach(result => {
                result.addEventListener('click', () => {
                    const personUuid = result.dataset.personUuid;
                    if (!personUuid) {
                        console.error('Person UUID nicht gefunden in search result');
                        return;
                    }
                    if (window.app.personDetail) {
                        // Finde die vollständigen Person-Daten aus den Suchergebnissen
                        const personData = searchResults.find(p => p.person_uuid === personUuid);
                        if (personData) {
                            // Übergebe die vollständigen Person-Daten, um erneutes Laden zu vermeiden
                            window.app.personDetail.showPersonDetail(personData);
                        } else {
                            // Fallback: Nur UUID übergeben
                            window.app.personDetail.showPersonDetail(personUuid);
                        }
                        if (resultsContainer) {
                            resultsContainer.innerHTML = '';
                        }
                        searchInput.value = '';
                        this.keyboardNav.resetSelection();
                    }
                });
            });
        } catch (error) {
            console.error('Error searching persons:', error);
            resultsContainer.innerHTML = '<div class="empty-state"><p style="color: var(--danger);">Fehler bei der Suche</p></div>';
        }
    }
    
    async loadRecentPersons() {
        await this.recentListModule.loadRecentList(
            'person',
            'person-recent-list',
            (person) => {
                const displayName = person.display_name || `${person.first_name || ''} ${person.last_name || ''}`.trim() || 'Unbekannt';
                const company = person.company_name || '';
                return `
                    <div class="person-recent-item" data-person-uuid="${person.person_uuid || person.uuid}" style="padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 0.5rem; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'">
                        <div style="font-weight: 600; color: var(--text); margin-bottom: 0.25rem;">${Utils.escapeHtml(displayName)}</div>
                        ${company ? `<div style="font-size: 0.875rem; color: var(--text-light);">${Utils.escapeHtml(company)}</div>` : ''}
                    </div>
                `;
            },
            (personUuid) => {
                if (window.app.personDetail) {
                    window.app.personDetail.showPersonDetail(personUuid);
                }
            },
            10
        );
    }
}


