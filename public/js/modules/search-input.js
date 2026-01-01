/**
 * TOM3 - Search Input Module (Zentral)
 * Zentrale Funktion für Debounced Search-Inputs
 * Eliminiert Code-Duplikation zwischen OrgSearch und PersonSearch
 */

export class SearchInputModule {
    constructor() {
        this._timeouts = new Map(); // inputId -> timeout
    }
    
    /**
     * Setzt einen Debounced Input-Handler auf
     * 
     * @param {HTMLElement} inputElement Das Input-Element
     * @param {Function} onSearch Callback-Funktion, die aufgerufen wird (query)
     * @param {Function} onClear Optional: Callback wenn Input geleert wird
     * @param {number} delay Delay in Millisekunden (Standard: 300)
     * @param {number} minLength Minimale Länge für Suche (Standard: 2)
     * @returns {Function} Cleanup-Funktion zum Entfernen des Event-Listeners
     */
    setupDebouncedSearch(
        inputElement,
        onSearch,
        onClear = null,
        delay = 300,
        minLength = 2
    ) {
        if (!inputElement) {
            console.warn('[SearchInput] Input element not found');
            return () => {}; // Leere Cleanup-Funktion
        }
        
        const inputId = inputElement.id || `input-${Date.now()}`;
        
        // Entferne alten Timeout, falls vorhanden
        if (this._timeouts.has(inputId)) {
            clearTimeout(this._timeouts.get(inputId));
        }
        
        const inputHandler = (e) => {
            clearTimeout(this._timeouts.get(inputId));
            const query = e.target.value.trim();
            
            if (query.length < minLength) {
                if (onClear) {
                    onClear();
                }
                return;
            }
            
            const timeout = setTimeout(() => {
                onSearch(query);
            }, delay);
            
            this._timeouts.set(inputId, timeout);
        };
        
        inputElement.addEventListener('input', inputHandler);
        
        // Cleanup-Funktion
        return () => {
            inputElement.removeEventListener('input', inputHandler);
            if (this._timeouts.has(inputId)) {
                clearTimeout(this._timeouts.get(inputId));
                this._timeouts.delete(inputId);
            }
        };
    }
    
    /**
     * Bereinigt alle Timeouts
     */
    clearAllTimeouts() {
        this._timeouts.forEach(timeout => clearTimeout(timeout));
        this._timeouts.clear();
    }
}
