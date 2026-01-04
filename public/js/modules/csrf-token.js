/**
 * CSRF Token Service
 * 
 * Verwaltet CSRF-Token für API-Requests
 */
class CsrfTokenService {
    constructor() {
        this.token = null;
        this.tokenPromise = null;
    }

    /**
     * Holt den CSRF-Token vom Server
     * @returns {Promise<string>} CSRF-Token
     */
    async fetchToken() {
        // Wenn bereits ein Token vorhanden ist, verwende diesen
        if (this.token) {
            return this.token;
        }

        // Wenn bereits ein Request läuft, warte auf diesen
        if (this.tokenPromise) {
            return this.tokenPromise;
        }

        // Starte neuen Request
        this.tokenPromise = (async () => {
            try {
                const response = await fetch(`${window.API?.baseUrl || '/api'}/auth/csrf-token`);
                if (!response.ok) {
                    throw new Error(`Failed to fetch CSRF token: ${response.status}`);
                }
                const data = await response.json();
                this.token = data.token;
                return this.token;
            } catch (error) {
                console.error('Error fetching CSRF token:', error);
                // In Dev-Mode: Token ist optional, daher kein Fehler werfen
                // In Production würde das einen Fehler geben
                return null;
            } finally {
                this.tokenPromise = null;
            }
        })();

        return this.tokenPromise;
    }

    /**
     * Gibt den aktuellen CSRF-Token zurück
     * @returns {string|null} CSRF-Token oder null
     */
    getToken() {
        return this.token;
    }

    /**
     * Setzt den CSRF-Token (für Tests oder manuelles Setzen)
     * @param {string} token CSRF-Token
     */
    setToken(token) {
        this.token = token;
    }

    /**
     * Löscht den CSRF-Token (z.B. nach Logout)
     */
    clearToken() {
        this.token = null;
        this.tokenPromise = null;
    }

    /**
     * Prüft ob ein Token vorhanden ist
     * @returns {boolean} True wenn Token vorhanden
     */
    hasToken() {
        return this.token !== null;
    }

    /**
     * Wrapper für fetch() der automatisch CSRF-Token hinzufügt
     * @param {string} url URL
     * @param {object} options Fetch-Optionen
     * @returns {Promise<Response>} Fetch-Response
     */
    async fetchWithToken(url, options = {}) {
        const method = options.method || 'GET';
        
        // CSRF-Token für state-changing Requests hinzufügen
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
            // Hole CSRF-Token (wenn noch nicht vorhanden)
            const token = await this.fetchToken();
            if (token) {
                if (!options.headers) {
                    options.headers = {};
                }
                options.headers['X-CSRF-Token'] = token;
            }
        }
        
        return fetch(url, options);
    }
}

// Exportiere Singleton-Instanz
window.csrfTokenService = new CsrfTokenService();

