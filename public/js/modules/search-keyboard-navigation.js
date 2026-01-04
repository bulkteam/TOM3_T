/**
 * TOM3 - Search Keyboard Navigation Module (Zentral)
 * Zentrale Keyboard-Navigation für Suchfelder
 * Eliminiert Code-Duplikation zwischen OrgSearch und PersonSearch
 */

export class SearchKeyboardNavigationModule {
    constructor() {
        this.selectedIndex = -1;
    }
    
    /**
     * Setzt die Keyboard-Navigation für ein Suchfeld auf
     * 
     * @param {HTMLElement} searchInput Das Suchfeld-Element
     * @param {string} resultsContainerId ID des Container-Elements für Ergebnisse
     * @param {string} resultSelector CSS-Selektor für einzelne Ergebnisse (z.B. '.org-search-result')
     * @param {string} uuidAttribute Das data-Attribut für die UUID (z.B. 'orgUuid' oder 'personUuid')
     * @param {Function} onSelect Callback wenn ein Ergebnis ausgewählt wird (uuid)
     * @param {Function} onSearch Callback wenn Enter ohne Auswahl gedrückt wird
     * @param {Function} onEscape Callback wenn Escape gedrückt wird (optional)
     * @returns {Function} Cleanup-Funktion zum Entfernen der Event-Listener
     */
    setupKeyboardNavigation(
        searchInput,
        resultsContainerId,
        resultSelector,
        uuidAttribute,
        onSelect,
        onSearch,
        onEscape = null
    ) {
        if (!searchInput) {
            console.warn('[SearchKeyboardNav] Search input not found');
            return () => {}; // Leere Cleanup-Funktion
        }
        
        this.selectedIndex = -1;
        
        const keydownHandler = (e) => {
            const resultsContainer = document.getElementById(resultsContainerId);
            if (!resultsContainer) return;
            
            const results = resultsContainer.querySelectorAll(resultSelector);
            
            if (results.length === 0) {
                // Wenn keine Ergebnisse, Enter führt Suche aus
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (onSearch) {
                        onSearch();
                    }
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
                    const uuid = selectedResult.dataset[uuidAttribute];
                    if (uuid && onSelect) {
                        onSelect(uuid);
                        // Verstecke Ergebnisse nach Auswahl
                        if (resultsContainer) {
                            resultsContainer.innerHTML = '';
                        }
                        searchInput.value = '';
                        this.selectedIndex = -1;
                    }
                } else {
                    // Wenn nichts ausgewählt, führe Suche aus
                    if (onSearch) {
                        onSearch();
                    }
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                // Verstecke Ergebnisse
                if (resultsContainer) {
                    resultsContainer.innerHTML = '';
                }
                this.selectedIndex = -1;
                if (onEscape) {
                    onEscape();
                }
            }
        };
        
        // Enter-Taste für Suche (wenn keine Ergebnisse vorhanden)
        const keypressHandler = (e) => {
            if (e.key === 'Enter') {
                const resultsContainer = document.getElementById(resultsContainerId);
                const results = resultsContainer?.querySelectorAll(resultSelector) || [];
                // Nur Suche ausführen, wenn keine Ergebnisse vorhanden oder nichts ausgewählt
                if (results.length === 0 || this.selectedIndex < 0) {
                    e.preventDefault();
                    if (onSearch) {
                        onSearch();
                    }
                }
            }
        };
        
        searchInput.addEventListener('keydown', keydownHandler);
        searchInput.addEventListener('keypress', keypressHandler);
        
        // Cleanup-Funktion
        return () => {
            searchInput.removeEventListener('keydown', keydownHandler);
            searchInput.removeEventListener('keypress', keypressHandler);
        };
    }
    
    /**
     * Highlight ein Ergebnis in der Liste
     * 
     * @param {NodeList} results Liste der Ergebnis-Elemente
     * @param {number} index Index des zu highlightenden Elements (-1 = kein Highlight)
     */
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
    
    /**
     * Setzt den selectedIndex zurück
     */
    resetSelection() {
        this.selectedIndex = -1;
    }
}


