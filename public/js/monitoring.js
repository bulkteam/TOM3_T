/**
 * TOM3 - Monitoring Dashboard
 */

class MonitoringDashboard {
    constructor() {
        this.autoRefreshInterval = null;
        this.charts = {};
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadAllData();
        this.startAutoRefresh();
    }

    setupEventListeners() {
        // Manual refresh
        document.getElementById('btn-refresh')?.addEventListener('click', () => {
            this.loadAllData();
        });

        // Auto-refresh toggle
        document.getElementById('auto-refresh')?.addEventListener('change', (e) => {
            if (e.target.checked) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        });
    }

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.autoRefreshInterval = setInterval(() => {
            this.loadAllData();
        }, 30000); // 30 seconds
    }

    stopAutoRefresh() {
        if (this.autoRefreshInterval) {
            clearInterval(this.autoRefreshInterval);
            this.autoRefreshInterval = null;
        }
    }

    async loadAllData() {
        try {
            await Promise.all([
                this.checkSystemStatus(),
                this.loadOutboxMetrics(),
                this.loadCaseStatistics(),
                this.loadSyncStatistics(),
                this.loadRecentErrors(),
                this.loadEventTypes()
            ]);
        } catch (error) {
            console.error('Error loading monitoring data:', error);
        }
    }

    async checkSystemStatus() {
        try {
            const status = await window.API.getMonitoringStatus();
            
            // Database status
            this.updateStatusCard('status-database', status.database?.status || 'unknown', status.database?.message || 'Unbekannt');
            
            // Neo4j status
            this.updateStatusCard('status-neo4j', status.neo4j?.status || 'unknown', status.neo4j?.message || 'Unbekannt');
            
            // Sync worker status
            this.updateStatusCard('status-sync', status.sync_worker?.status || 'unknown', status.sync_worker?.message || 'Unbekannt');
        } catch (error) {
            console.error('Error checking system status:', error);
            this.updateStatusCard('status-database', 'error', 'Fehler beim Prüfen');
            this.updateStatusCard('status-neo4j', 'error', 'Fehler beim Prüfen');
            this.updateStatusCard('status-sync', 'error', 'Fehler beim Prüfen');
        }
    }

    updateStatusCard(cardId, status, message) {
        const card = document.getElementById(cardId);
        if (!card) return;

        const indicator = card.querySelector('.status-indicator');
        const valueElement = card.querySelector('.status-value');

        if (indicator) {
            indicator.setAttribute('data-status', status);
        }
        if (valueElement) {
            valueElement.textContent = message;
        }
    }

    async loadOutboxMetrics() {
        try {
            const metrics = await window.API.getOutboxMetrics();
            
            document.getElementById('metric-pending').textContent = metrics.pending || 0;
            document.getElementById('metric-processed').textContent = metrics.processed_24h || 0;
            document.getElementById('metric-errors').textContent = metrics.errors_24h || 0;
            document.getElementById('metric-lag').textContent = this.formatLag(metrics.avg_lag_seconds || 0);

            // Update events chart
            this.updateEventsChart(metrics.hourly_data || []);
        } catch (error) {
            console.error('Error loading outbox metrics:', error);
        }
    }

    async loadCaseStatistics() {
        try {
            const stats = await window.API.getCaseStatistics();
            
            document.getElementById('metric-cases-total').textContent = stats.total || 0;
            document.getElementById('metric-cases-active').textContent = stats.active || 0;
            document.getElementById('metric-cases-waiting').textContent = stats.waiting || 0;
            document.getElementById('metric-cases-blocked').textContent = stats.blocked || 0;

            // Update cases chart
            this.updateCasesChart(stats.status_distribution || {});
        } catch (error) {
            console.error('Error loading case statistics:', error);
        }
    }

    async loadSyncStatistics() {
        try {
            const stats = await window.API.getSyncStatistics();
            
            document.getElementById('metric-sync-total').textContent = stats.total_synced || 0;
            document.getElementById('metric-sync-rate').textContent = (stats.events_per_minute || 0).toFixed(1);
            document.getElementById('metric-sync-orgs').textContent = stats.orgs_count || 0;
            document.getElementById('metric-sync-persons').textContent = stats.persons_count || 0;
        } catch (error) {
            console.error('Error loading sync statistics:', error);
        }
    }

    async loadRecentErrors() {
        const container = document.getElementById('errors-list');
        container.innerHTML = '<div class="loading">Lade Fehler...</div>';

        try {
            const errors = await window.API.getRecentErrors();
            
            if (!errors || errors.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">✅</div><p>Keine Fehler in den letzten 24 Stunden</p></div>';
                return;
            }

            container.innerHTML = errors.map(error => `
                <div class="error-item">
                    <div class="error-header">
                        <span class="error-type">${this.escapeHtml(error.type || 'Unknown')}</span>
                        <span class="error-time">${this.formatTime(error.created_at)}</span>
                    </div>
                    <div class="error-message">${this.escapeHtml(error.message || 'No message')}</div>
                    ${error.details ? `<div class="error-details">${this.escapeHtml(error.details)}</div>` : ''}
                </div>
            `).join('');
        } catch (error) {
            console.error('Error loading recent errors:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }

    async loadEventTypes() {
        try {
            const distribution = await window.API.getEventTypesDistribution();
            this.updateEventTypesChart(distribution || {});
        } catch (error) {
            console.error('Error loading event types:', error);
        }
    }

    updateEventsChart(data) {
        const ctx = document.getElementById('chart-events');
        if (!ctx) return;

        if (this.charts.events) {
            this.charts.events.destroy();
        }

        const labels = data.map(d => d.hour);
        const processed = data.map(d => d.processed || 0);
        const errors = data.map(d => d.errors || 0);

        this.charts.events = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Processed',
                        data: processed,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Errors',
                        data: errors,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#f1f5f9' }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    },
                    y: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    }
                }
            }
        });
    }

    updateCasesChart(distribution) {
        const ctx = document.getElementById('chart-cases');
        if (!ctx) return;

        if (this.charts.cases) {
            this.charts.cases.destroy();
        }

        const labels = Object.keys(distribution);
        const data = Object.values(distribution);

        this.charts.cases = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: [
                        '#3b82f6', // neu
                        '#10b981', // in_bearbeitung
                        '#f59e0b', // wartend
                        '#ef4444', // blockiert
                        '#dc2626', // eskaliert
                        '#059669'  // abgeschlossen
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: '#f1f5f9' }
                    }
                }
            }
        });
    }

    updateEventTypesChart(distribution) {
        const ctx = document.getElementById('chart-event-types');
        if (!ctx) return;

        if (this.charts.eventTypes) {
            this.charts.eventTypes.destroy();
        }

        const labels = Object.keys(distribution);
        const data = Object.values(distribution);

        this.charts.eventTypes = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Events',
                    data: data,
                    backgroundColor: '#2563eb'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    },
                    y: {
                        ticks: { color: '#94a3b8' },
                        grid: { color: '#334155' }
                    }
                }
            }
        });
    }

    formatLag(seconds) {
        if (seconds < 60) {
            return `${Math.round(seconds)}s`;
        } else if (seconds < 3600) {
            return `${Math.round(seconds / 60)}m`;
        } else {
            return `${Math.round(seconds / 3600)}h`;
        }
    }

    formatTime(timestamp) {
        if (!timestamp) return 'Unknown';
        const date = new Date(timestamp);
        return date.toLocaleString('de-DE');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize dashboard
const dashboard = new MonitoringDashboard();
window.dashboard = dashboard;


