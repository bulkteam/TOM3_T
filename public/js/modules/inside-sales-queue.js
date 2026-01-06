/**
 * TOM3 - Inside Sales Queue Module
 * Handles queue display and management
 */

import { Utils } from './utils.js';

export class InsideSalesQueueModule {
    constructor(insideSalesModule) {
        this.insideSalesModule = insideSalesModule;
    }
    
    /**
     * Initialisiert Queue-Ansicht
     */
    async initQueue(container) {
        if (!container) {
            console.error('[InsideSales] initQueue: Container ist null oder undefined');
            return;
        }
        
        container.innerHTML = '';
        
        // Prüfe Hash-Parameter für Tab und Sortierung
        const hash = window.location.hash;
        let tabFromHash = null;
        let sortFromHash = null;
        
        if (hash.includes('?')) {
            const hashParts = hash.split('?');
            const hashParams = new URLSearchParams(hashParts[1]);
            tabFromHash = hashParams.get('tab');
            const sortField = hashParams.get('sort');
            const sortOrder = hashParams.get('order');
            if (sortField && sortOrder) {
                sortFromHash = { field: sortField, direction: sortOrder };
            }
        }
        
        // Setze Tab aus Hash
        if (tabFromHash) {
            this.insideSalesModule.currentTab = tabFromHash;
        }
        
        // Setze Sortierung aus Hash
        if (sortFromHash) {
            this.insideSalesModule.currentSort = sortFromHash;
        }
        
        container.innerHTML = `
            <div class="page-header">
                <h2>📞 Inside Sales</h2>
                <p class="page-description">Verwalten Sie Ihre Leads und arbeiten Sie sie ab</p>
            </div>
            
            <div style="margin-bottom: 24px;">
                <a href="#inside-sales/dialer" class="btn btn-primary">
                    🎯 Lead-Player starten
                </a>
            </div>
            
            <div id="inside-sales-queue-tabs" class="tabs">
                <button class="tab-btn active" data-tab="new">Neu (<span id="count-new">0</span>)</button>
                <button class="tab-btn" data-tab="due">Fällig (<span id="count-due">0</span>)</button>
                <button class="tab-btn" data-tab="in_progress">In Arbeit (<span id="count-in_progress">0</span>)</button>
                <button class="tab-btn" data-tab="snoozed">Wiedervorlage (<span id="count-snoozed">0</span>)</button>
                <button class="tab-btn" data-tab="qualified">Qualifiziert (<span id="count-qualified">0</span>)</button>
            </div>
            
            <div class="work-item-table-container">
                <div class="work-item-table-header">
                    <div class="work-item-header-cell sortable" data-sort="name">
                        <span>Firma</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="city">
                        <span>Ort</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="stars">
                        <span>Priorität</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="next_action">
                        <span>Wiedervorlage</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell sortable" data-sort="last_touch">
                        <span>Letzter Touch</span>
                        <span class="sort-indicator"></span>
                    </div>
                    <div class="work-item-header-cell">
                        <span>Aktionen</span>
                    </div>
                </div>
                <div id="inside-sales-queue-list" class="work-item-list">
                    <!-- Wird dynamisch geladen -->
                </div>
            </div>
        `;
        
        // Setze aktiven Tab-Button
        setTimeout(() => {
            document.querySelectorAll('#inside-sales-queue-tabs .tab-btn').forEach(btn => {
                if (btn.dataset.tab === this.insideSalesModule.currentTab) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }, 50);
        
        // Tab-Event-Listener
        document.querySelectorAll('#inside-sales-queue-tabs .tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const tab = btn.dataset.tab;
                this.insideSalesModule.currentTab = tab;
                this.loadQueue(tab);
                
                // Update "Lead-Player starten" Button
                const startButton = document.querySelector('a[href="#inside-sales/dialer"], a[href*="inside-sales/dialer"]');
                if (startButton) {
                    const sortParam = `sort=${this.insideSalesModule.currentSort.field}&order=${this.insideSalesModule.currentSort.direction}`;
                    startButton.href = `#inside-sales/dialer?tab=${tab}&${sortParam}`;
                }
            });
        });
        
        // Update "Lead-Player starten" Button
        const startButton = document.querySelector('a[href*="inside-sales/dialer"]');
        if (startButton) {
            const sortParam = `sort=${this.insideSalesModule.currentSort.field}&order=${this.insideSalesModule.currentSort.direction}`;
            startButton.href = `#inside-sales/dialer?tab=${this.insideSalesModule.currentTab}&${sortParam}`;
        }
        
        // Sortier-Event-Listener
        setTimeout(() => {
            this.setupSortHandlers();
        }, 100);
        
        // Setze initiale Sortier-Anzeige
        setTimeout(() => {
            this.updateSortIndicator();
        }, 150);
        
        // Lade initial mit aktuellem Tab
        const initialTab = this.insideSalesModule.currentTab || 'new';
        try {
            await this.loadQueue(initialTab);
        } catch (error) {
            console.error('[InsideSales] Fehler beim Laden der Queue:', error);
            Utils.showError('Fehler beim Laden der Queue: ' + error.message);
        }
    }
    
    /**
     * Lädt Queue für Tab
     */
    async loadQueue(tab) {
        try {
            const sortParam = `sort=${this.insideSalesModule.currentSort.field}&order=${this.insideSalesModule.currentSort.direction}`;
            const apiUrl = this.insideSalesModule.getApiUrl(`/work-items?type=LEAD&tab=${tab}&${sortParam}`);
            const response = await fetch(apiUrl);
            if (!response.ok) {
                const errorText = await response.text();
                console.error('[InsideSales] API Error Response:', errorText);
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const data = await response.json();
            
            // Update Counts
            if (data.counts) {
                Object.keys(data.counts).forEach(key => {
                    const el = document.getElementById(`count-${key}`);
                    if (el) el.textContent = data.counts[key];
                });
            }
            
            // Render List
            const listContainer = document.getElementById('inside-sales-queue-list');
            if (listContainer) {
                if (data.items && data.items.length > 0) {
                    listContainer.innerHTML = data.items.map(item => `
                        <div class="work-item-row" data-uuid="${item.case_uuid}">
                            <div class="work-item-name">${Utils.escapeHtml(item.company_name || '-')}</div>
                            <div class="work-item-location">${Utils.escapeHtml(item.company_city || '-')}</div>
                            <div class="work-item-stars">${'⭐'.repeat(item.priority_stars || 0)}</div>
                            <div class="work-item-next">${item.next_action_at ? new Date(item.next_action_at).toLocaleString('de-DE', { 
                                year: 'numeric', 
                                month: '2-digit', 
                                day: '2-digit', 
                                hour: '2-digit', 
                                minute: '2-digit' 
                            }) : '-'}</div>
                            <div class="work-item-last-touch">${item.last_touch_at ? new Date(item.last_touch_at).toLocaleDateString('de-DE') : 'Nie'}</div>
                            <div class="work-item-actions">
                                <a href="#inside-sales/dialer?lead=${item.case_uuid}&tab=${this.insideSalesModule.currentTab}&sort=${this.insideSalesModule.currentSort.field}&order=${this.insideSalesModule.currentSort.direction}" class="btn btn-sm">Öffnen</a>
                            </div>
                        </div>
                    `).join('');
                } else {
                    listContainer.innerHTML = '<div class="empty-state">Keine Leads gefunden</div>';
                }
            }
            
            // Aktualisiere Sortier-Indikatoren
            this.updateSortIndicator();
        } catch (error) {
            console.error('Error loading queue:', error);
            Utils.showError('Fehler beim Laden der Queue');
        }
    }
    
    /**
     * Setup Sortier-Handler
     */
    setupSortHandlers() {
        document.querySelectorAll('.work-item-header-cell.sortable').forEach(cell => {
            cell.addEventListener('click', () => {
                const sortField = cell.dataset.sort;
                if (!sortField) return;
                
                // Toggle direction wenn gleiches Feld
                if (this.insideSalesModule.currentSort.field === sortField) {
                    this.insideSalesModule.currentSort.direction = 
                        this.insideSalesModule.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    this.insideSalesModule.currentSort.field = sortField;
                    this.insideSalesModule.currentSort.direction = 'asc';
                }
                
                // Lade Queue neu
                this.loadQueue(this.insideSalesModule.currentTab);
            });
        });
    }
    
    /**
     * Aktualisiert Sortier-Indikator
     */
    updateSortIndicator() {
        document.querySelectorAll('.work-item-header-cell.sortable').forEach(cell => {
            const indicator = cell.querySelector('.sort-indicator');
            if (!indicator) return;
            
            const sortField = cell.dataset.sort;
            if (this.insideSalesModule.currentSort.field === sortField) {
                indicator.textContent = this.insideSalesModule.currentSort.direction === 'asc' ? '↑' : '↓';
                indicator.style.opacity = '1';
            } else {
                indicator.textContent = '';
                indicator.style.opacity = '0';
            }
        });
    }
    
    /**
     * Lädt Mini-Queue für Dialer
     */
    async loadDialerQueue(tab = null) {
        const container = document.getElementById('dialer-queue-list');
        if (!container) return;
        
        try {
            const targetTab = tab || this.insideSalesModule.currentTab || 'new';
            const sortField = this.insideSalesModule.currentSort?.field || 'stars';
            const sortOrder = this.insideSalesModule.currentSort?.direction || 'desc';
            const sortParam = `sort=${sortField}&order=${sortOrder}`;
            const data = await window.API.request(`/work-items?type=LEAD&tab=${targetTab}&${sortParam}`);
            
            if (data && data.items && data.items.length > 0) {
                const items = data.items;
                
                container.innerHTML = items.map(item => {
                    const isActive = this.insideSalesModule.currentWorkItem && 
                                    this.insideSalesModule.currentWorkItem.case_uuid === item.case_uuid;
                    const activeClass = isActive ? 'active' : '';
                    return `
                        <div class="dialer-queue-item ${activeClass}" data-uuid="${item.case_uuid}">
                            <div class="dialer-queue-item-name">${Utils.escapeHtml(item.company_name || '-')}</div>
                            <div class="dialer-queue-item-info">
                                <span class="dialer-queue-item-city">${Utils.escapeHtml(item.company_city || '-')}</span>
                                <span class="dialer-queue-item-stars">${'⭐'.repeat(item.priority_stars || 0)}</span>
                            </div>
                        </div>
                    `;
                }).join('');
                
                // Event-Listener für Klicks
                container.querySelectorAll('.dialer-queue-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const uuid = item.dataset.uuid;
                        if (uuid) {
                            window.location.hash = `inside-sales/dialer?lead=${uuid}`;
                        }
                    });
                });
            } else {
                container.innerHTML = '<div class="dialer-queue-empty">Keine Leads verfügbar</div>';
            }
        } catch (error) {
            console.error('Error loading dialer queue:', error);
            container.innerHTML = '<div class="dialer-queue-empty">Fehler beim Laden</div>';
        }
    }
    
    /**
     * Markiert Lead als IN_PROGRESS
     */
    async markLeadAsInProgress(workItemUuid) {
        try {
            await window.API.request(`/work-items/${workItemUuid}`, {
                method: 'PATCH',
                body: {
                    stage: 'IN_PROGRESS'
                }
            });
        } catch (error) {
            console.error('Error marking lead as IN_PROGRESS:', error);
        }
    }
}

