/**
 * TOM3 - Monitoring Module
 * Integriert das Monitoring Dashboard in die Hauptanwendung
 */

export class MonitoringModule {
    constructor(app) {
        this.app = app;
        this.dashboard = null;
    }

    async init() {
        // Lade monitoring.js dynamisch, falls noch nicht geladen
        if (typeof window.MonitoringDashboard === 'undefined') {
            await this.loadMonitoringScript();
            // Warte kurz, damit das Script vollständig geladen ist
            await new Promise(resolve => setTimeout(resolve, 100));
        }

        // Initialisiere das Monitoring Dashboard
        if (!this.dashboard && window.MonitoringDashboard) {
            this.dashboard = new window.MonitoringDashboard();
            // Rufe init() auf, um das Dashboard zu starten
            if (this.dashboard.init) {
                this.dashboard.init();
            }
        }
    }

    loadMonitoringScript() {
        return new Promise((resolve, reject) => {
            // Prüfe ob bereits geladen
            if (window.MonitoringDashboard) {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = 'js/monitoring.js';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load monitoring.js'));
            document.head.appendChild(script);
        });
    }
}


