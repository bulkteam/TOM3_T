/**
 * TOM3 - Recent List Module (Zentral)
 * Zentrale Funktion für "Zuletzt angesehen" Listen
 * Eliminiert Code-Duplikation zwischen OrgSearch und PersonSearch
 */

import { Utils } from './utils.js';

export class RecentListModule {
    constructor(app) {
        this.app = app;
    }
    
    /**
     * Lädt und rendert eine "Zuletzt angesehen" Liste
     * 
     * @param {string} entityType 'org' | 'person'
     * @param {string} containerId ID des Container-Elements (z.B. 'org-recent-list' oder 'person-recent-list')
     * @param {Function} renderItem Callback-Funktion zum Rendern eines einzelnen Items
     * @param {Function} onClickHandler Callback-Funktion für Klick-Events
     * @param {number} limit Anzahl der Einträge (Standard: 10)
     */
    async loadRecentList(entityType, containerId, renderItem, onClickHandler, limit = 10) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        try {
            const user = await window.API.getCurrentUser();
            if (!user || !user.user_id) {
                container.innerHTML = '';
                return;
            }
            
            const recent = await window.API.getRecentEntities(entityType, user.user_id, limit);
            if (!recent || recent.length === 0) {
                container.innerHTML = '';
                return;
            }
            
            container.innerHTML = recent.map(item => renderItem(item)).join('');
            
            // Event-Listener für Klicks hinzufügen
            const uuidAttr = entityType === 'org' ? 'orgUuid' : 'personUuid';
            container.querySelectorAll(`[data-${entityType}-uuid]`).forEach(element => {
                element.addEventListener('click', () => {
                    const uuid = element.dataset[uuidAttr];
                    if (uuid && onClickHandler) {
                        onClickHandler(uuid);
                    }
                });
            });
        } catch (error) {
            console.error(`Error loading recent ${entityType}s:`, error);
        }
    }
}
