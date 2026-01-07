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
     * @param {string} entityType 'org' | 'person' | 'document'
     * @param {string} containerId ID des Container-Elements (z.B. 'org-recent-list' oder 'person-recent-list')
     * @param {Function} renderItem Callback-Funktion zum Rendern eines einzelnen Items
     * @param {Function} onClickHandler Callback-Funktion für Klick-Events
     * @param {number} limit Anzahl der Einträge (Standard: 10)
     */
    async loadRecentList(entityType, containerId, renderItem, onClickHandler, limit = 10) {
        const container = document.getElementById(containerId);
        if (!container) {
            return;
        }
        
        try {
            // Warte kurz, falls User noch geladen wird
            let user = await window.API.getCurrentUser();
            if (!user || !user.user_id) {
                // Versuche es nochmal nach kurzer Verzögerung (User könnte noch geladen werden)
                await new Promise(resolve => setTimeout(resolve, 500));
                user = await window.API.getCurrentUser();
            }
            
            if (!user || !user.user_id) {
                // Versuche es mit dem aktuellen User aus der App (falls vorhanden)
                if (window.app && window.app.currentUser) {
                    user = window.app.currentUser;
                }
            }
            
            if (!user || !user.user_id) {
                container.innerHTML = '';
                return;
            }
            
            // Verwende spezifische API für Dokumente, generische für Org/Person
            let recent;
            if (entityType === 'document') {
                recent = await window.API.getRecentDocuments(user.user_id, limit);
            } else {
                recent = await window.API.getRecentEntities(entityType, user.user_id, limit);
            }
            
            if (!recent || recent.length === 0) {
                container.innerHTML = '';
                return;
            }
            
            container.innerHTML = recent.map(item => renderItem(item)).join('');
            
            // Event-Listener für Klicks hinzufügen
            const uuidAttrMap = {
                'org': 'orgUuid',
                'person': 'personUuid',
                'document': 'documentUuid'
            };
            const uuidAttr = uuidAttrMap[entityType] || `${entityType}Uuid`;
            container.querySelectorAll(`[data-${entityType}-uuid]`).forEach(element => {
                element.addEventListener('click', () => {
                    const uuid = element.dataset[uuidAttr] || element.dataset[`${entityType}Uuid`];
                    if (uuid && onClickHandler) {
                        onClickHandler(uuid);
                    }
                });
            });
        } catch (error) {
            // Bei Fehlern einfach leeren Container anzeigen (keine Fehlermeldung)
            console.warn(`Could not load recent ${entityType}s:`, error);
            container.innerHTML = '';
        }
    }
}


