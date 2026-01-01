/**
 * TOM3 - Monitoring Dashboard
 */

class MonitoringDashboard {
    constructor() {
        this.autoRefreshInterval = null;
        this.charts = {};
        // Warte mit init, bis es explizit aufgerufen wird (für Frame-Integration)
    }

    init() {
        this.setupEventListeners();
        this.loadAllData();
        this.startAutoRefresh();
    }

    setupEventListeners() {
        // Manual refresh - unterstütze beide IDs (standalone und frame)
        const refreshBtn = document.getElementById('monitoring-btn-refresh') || document.getElementById('btn-refresh');
        refreshBtn?.addEventListener('click', () => {
            this.loadAllData();
        });

        // Auto-refresh toggle - unterstütze beide IDs (standalone und frame)
        const autoRefreshToggle = document.getElementById('monitoring-auto-refresh') || document.getElementById('auto-refresh');
        autoRefreshToggle?.addEventListener('change', (e) => {
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
                this.loadEventTypes(),
                this.loadDuplicateCheckResults(),
                this.loadActivityLog()
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
        const container = document.getElementById('outbox-metrics');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Outbox-Metriken...</div>';

        try {
            const metrics = await window.API.getOutboxMetrics();
            
            container.innerHTML = `
                <div class="metric-card">
                    <div class="metric-icon">⏳</div>
                    <div class="metric-content">
                        <div class="metric-value">${metrics.pending || 0}</div>
                        <div class="metric-label">Ausstehend</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">✅</div>
                    <div class="metric-content">
                        <div class="metric-value">${metrics.processed_24h || 0}</div>
                        <div class="metric-label">Verarbeitet (24h)</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">❌</div>
                    <div class="metric-content">
                        <div class="metric-value">${metrics.errors_24h || 0}</div>
                        <div class="metric-label">Fehler (24h)</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">⏱️</div>
                    <div class="metric-content">
                        <div class="metric-value">${this.formatLag(metrics.avg_lag_seconds || 0)}</div>
                        <div class="metric-label">Durchschnittliche Verzögerung</div>
                    </div>
                </div>
            `;

            // Update events chart
            this.updateEventsChart(metrics.hourly_data || []);
        } catch (error) {
            console.error('Error loading outbox metrics:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }

    async loadCaseStatistics() {
        const container = document.getElementById('case-statistics');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Vorgangs-Statistiken...</div>';

        try {
            const stats = await window.API.getCaseStatistics();
            
            container.innerHTML = `
                <div class="metric-card">
                    <div class="metric-icon">📋</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.total || 0}</div>
                        <div class="metric-label">Gesamt</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">🔄</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.active || 0}</div>
                        <div class="metric-label">Aktiv</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">⏸️</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.waiting || 0}</div>
                        <div class="metric-label">Wartend</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">🚫</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.blocked || 0}</div>
                        <div class="metric-label">Blockiert</div>
                    </div>
                </div>
            `;

            // Update cases chart
            this.updateCasesChart(stats.status_distribution || {});
        } catch (error) {
            console.error('Error loading case statistics:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }

    async loadSyncStatistics() {
        const container = document.getElementById('sync-statistics');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Sync-Statistiken...</div>';

        try {
            const stats = await window.API.getSyncStatistics();
            
            container.innerHTML = `
                <div class="metric-card">
                    <div class="metric-icon">🔄</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.total_synced || 0}</div>
                        <div class="metric-label">Gesamt synchronisiert</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">⚡</div>
                    <div class="metric-content">
                        <div class="metric-value">${(stats.events_per_minute || 0).toFixed(1)}</div>
                        <div class="metric-label">Events/Minute</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">🏢</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.orgs_count || 0}</div>
                        <div class="metric-label">Organisationen in Neo4j</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">👤</div>
                    <div class="metric-content">
                        <div class="metric-value">${stats.persons_count || 0}</div>
                        <div class="metric-label">Personen in Neo4j</div>
                    </div>
                </div>
            `;
        } catch (error) {
            console.error('Error loading sync statistics:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }

    async loadRecentErrors() {
        const container = document.getElementById('recent-errors-list');
        if (!container) return;
        
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
        const ctx = document.getElementById('chart-outbox');
        if (!ctx) return;

        // Zerstöre vorhandenen Chart
        if (this.charts.events) {
            this.charts.events.destroy();
            this.charts.events = null;
        }

        // Prüfe ob Canvas bereits verwendet wird
        if (Chart.getChart(ctx)) {
            Chart.getChart(ctx).destroy();
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
                        labels: { color: '#1e293b' }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#64748b' },
                        grid: { color: '#e2e8f0' }
                    },
                    y: {
                        ticks: { color: '#64748b' },
                        grid: { color: '#e2e8f0' }
                    }
                }
            }
        });
    }

    updateCasesChart(distribution) {
        const ctx = document.getElementById('chart-cases');
        if (!ctx) return;

        // Zerstöre vorhandenen Chart
        if (this.charts.cases) {
            this.charts.cases.destroy();
            this.charts.cases = null;
        }

        // Prüfe ob Canvas bereits verwendet wird
        if (Chart.getChart(ctx)) {
            Chart.getChart(ctx).destroy();
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
                        labels: { color: '#1e293b' }
                    }
                }
            }
        });
    }

    updateEventTypesChart(distribution) {
        const ctx = document.getElementById('chart-events');
        if (!ctx) return;

        // Zerstöre vorhandenen Chart
        if (this.charts.eventTypes) {
            this.charts.eventTypes.destroy();
            this.charts.eventTypes = null;
        }

        // Prüfe ob Canvas bereits verwendet wird
        if (Chart.getChart(ctx)) {
            Chart.getChart(ctx).destroy();
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
                        ticks: { color: '#64748b' },
                        grid: { color: '#e2e8f0' }
                    },
                    y: {
                        ticks: { color: '#64748b' },
                        grid: { color: '#e2e8f0' }
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

    async loadDuplicateCheckResults() {
        const container = document.getElementById('duplicates-list');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Duplikaten-Prüfung...</div>';

        try {
            const data = await window.API.getDuplicateCheckResults();
            
            if (data.error) {
                container.innerHTML = `<div class="empty-state">${this.escapeHtml(data.error)}</div>`;
                return;
            }
            
            const checks = data.checks || [];
            const currentDuplicates = data.current_duplicates || {};
            
            if (checks.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🔍</div><p>Keine Prüfungen durchgeführt</p></div>';
                return;
            }

            let html = '<div class="duplicates-summary">';
            if (data.latest_check) {
                const latest = checks[0];
                html += `
                    <div class="duplicate-check-item">
                        <div class="duplicate-check-header">
                            <span class="duplicate-check-date">${this.formatTime(latest.check_date)}</span>
                            <span class="duplicate-check-count">${latest.total_pairs || 0} Duplikat-Paare</span>
                        </div>
                        <div class="duplicate-check-details">
                            <div>Organisationen: ${latest.org_duplicates || 0}</div>
                            <div>Personen: ${latest.person_duplicates || 0}</div>
                        </div>
                    </div>
                `;
            }
            html += '</div>';
            
            container.innerHTML = html;
        } catch (error) {
            console.error('Error loading duplicate check results:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }

    async loadActivityLog() {
        const container = document.getElementById('activity-log-list');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Activity Log...</div>';

        try {
            const data = await window.API.getMonitoringActivityLog(100);
            
            if (data.error) {
                container.innerHTML = `<div class="empty-state">${this.escapeHtml(data.error)}</div>`;
                return;
            }
            
            const activities = data.activities || [];
            
            if (activities.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📋</div><p>Keine Activities gefunden</p></div>';
                return;
            }

            container.innerHTML = activities.map(activity => {
                const actionTypeLabels = {
                    'login': '🔐 Login',
                    'logout': '🚪 Logout',
                    'export': '📤 Export',
                    'upload': '📥 Upload',
                    'download': '⬇️ Download',
                    'entity_change': '✏️ Änderung',
                    'assignment': '👤 Zuweisung',
                    'system_action': '⚙️ System'
                };
                
                const actionLabel = actionTypeLabels[activity.action_type] || activity.action_type;
                const entityInfo = activity.entity_type && activity.entity_uuid 
                    ? `${activity.entity_type}: ${activity.entity_uuid.substring(0, 8)}...` 
                    : '';
                
                return `
                    <div class="activity-item">
                        <div class="activity-header">
                            <span class="activity-action">${this.escapeHtml(actionLabel)}</span>
                            <span class="activity-time">${this.formatTime(activity.created_at)}</span>
                        </div>
                        <div class="activity-content">
                            <div class="activity-user">👤 ${this.escapeHtml(activity.user_name || activity.user_id || 'Unbekannt')}</div>
                            ${entityInfo ? `<div class="activity-entity">📦 ${this.escapeHtml(entityInfo)}</div>` : ''}
                            ${activity.details && typeof activity.details === 'object' ? `
                                <div class="activity-details">
                                    ${activity.details.summary ? `<div>${this.escapeHtml(activity.details.summary)}</div>` : ''}
                                    ${activity.details.changed_fields ? `<div>Geänderte Felder: ${this.escapeHtml(activity.details.changed_fields.join(', '))}</div>` : ''}
                                    ${activity.details.file_name ? `<div>Datei: ${this.escapeHtml(activity.details.file_name)}</div>` : ''}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        } catch (error) {
            console.error('Error loading activity log:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Export für globale Verwendung
window.MonitoringDashboard = MonitoringDashboard;

// Initialize dashboard nur wenn nicht im Frame (standalone)
if (document.getElementById('btn-refresh') && !document.getElementById('monitoring-btn-refresh')) {
    const dashboard = new MonitoringDashboard();
    window.dashboard = dashboard;
}


