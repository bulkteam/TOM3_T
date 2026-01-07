/**
 * TOM3 - Sales Ops Module
 * Übersicht und Verwaltung für Sales Operations
 */

import { Utils } from './utils.js';

export class SalesOpsModule {
    constructor(app) {
        this.app = app;
        
        // Helper: Ermittelt Base-Path für API-Calls
        this.getApiUrl = (path) => {
            const basePath = window.location.pathname
                .replace(/\/index\.html$/, '')
                .replace(/\/login\.php$/, '')
                .replace(/\/monitoring\.html$/, '')
                .replace(/\/$/, '') || '';
            return `${basePath}/api${path.startsWith('/') ? path : '/' + path}`;
        };
    }
    
    /**
     * Initialisiert Sales Ops Seite
     */
    async init() {
        const page = document.getElementById('page-sales-ops');
        if (!page) return;
        
        this.render();
    }
    
    /**
     * Rendert die Sales Ops Seite
     */
    render() {
        const page = document.getElementById('page-sales-ops');
        if (!page) return;
        
        page.innerHTML = `
            <div class="page-header">
                <h2>Sales Ops</h2>
            </div>
            <div class="page-content">
                <p>In Entwicklung: Hier kommt die Übersicht für Sales Ops hin.</p>
            </div>
        `;
    }
}


