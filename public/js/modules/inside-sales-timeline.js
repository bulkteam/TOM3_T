/**
 * TOM3 - Inside Sales Timeline Module
 * Handles timeline display
 */

import { Utils } from './utils.js';

export class InsideSalesTimelineModule {
    constructor(insideSalesModule) {
        this.insideSalesModule = insideSalesModule;
    }
    
    /**
     * Lädt Timeline für Work Item
     */
    async loadTimeline(workItemUuid) {
        try {
            const response = await fetch(this.insideSalesModule.getApiUrl(`/work-items/${workItemUuid}/timeline`));
            const timeline = await response.json();
            
            const container = document.getElementById('dialer-timeline-content');
            if (container) {
                if (timeline.length > 0) {
                    container.innerHTML = timeline.map(item => {
                        // Formatiere Wiedervorlage, falls vorhanden
                        let wvlDisplay = '';
                        if (item.next_action_at) {
                            const wvlDate = new Date(item.next_action_at);
                            wvlDisplay = `<div class="timeline-wvl" style="font-weight: 600; color: #0d6efd; margin-bottom: 8px;">
                                📅 WVL: ${wvlDate.toLocaleString('de-DE', { 
                                    weekday: 'short', 
                                    year: 'numeric', 
                                    month: '2-digit', 
                                    day: '2-digit', 
                                    hour: '2-digit', 
                                    minute: '2-digit' 
                                })}
                            </div>`;
                        }
                        
                        return `
                        <div class="timeline-item ${item.is_pinned ? 'pinned' : ''}">
                            <div class="timeline-header">
                                <span class="timeline-type">${item.activity_type}</span>
                                <span class="timeline-time">${new Date(item.occurred_at).toLocaleString('de-DE')}</span>
                            </div>
                            ${item.created_by === 'USER' ? `<div class="timeline-user">${item.user_name || 'User'}</div>` : ''}
                            ${wvlDisplay}
                            ${item.system_message ? `<div class="timeline-system">${Utils.escapeHtml(item.system_message)}</div>` : ''}
                            ${item.notes ? `<div class="timeline-notes">${Utils.escapeHtml(item.notes)}</div>` : ''}
                            ${item.outcome ? `<div class="timeline-outcome">Outcome: ${item.outcome}</div>` : ''}
                        </div>
                    `;
                    }).join('');
                } else {
                    container.innerHTML = '<div class="empty-state">Keine Timeline-Einträge</div>';
                }
            }
        } catch (error) {
            console.error('Error loading timeline:', error);
        }
    }
}

