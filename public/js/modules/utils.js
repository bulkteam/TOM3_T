/**
 * TOM3 - Utility Functions
 * Gemeinsame Helper-Funktionen für alle Module
 */

export const Utils = {
    /**
     * Escaped HTML-Sonderzeichen
     */
    escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    },
    
    /**
     * Schließt alle aktiven Modals
     */
    closeModal() {
        console.log('[Utils] closeModal() aufgerufen - schließe ALLE Modals');
        const activeModals = document.querySelectorAll('.modal.active');
        console.log('[Utils] Aktive Modals gefunden:', activeModals.length);
        activeModals.forEach((modal, index) => {
            console.log(`[Utils] Schließe Modal ${index + 1}:`, modal.id || 'ohne ID');
            modal.classList.remove('active');
        });
    },
    
    /**
     * Schließt ein spezifisches Modal anhand seiner ID
     */
    closeSpecificModal(modalId) {
        console.log('[Utils] closeSpecificModal() aufgerufen für:', modalId);
        const modal = document.getElementById(modalId);
        if (modal) {
            console.log('[Utils] Modal gefunden, schließe es:', modalId);
            const wasActive = modal.classList.contains('active');
            modal.classList.remove('active');
            console.log('[Utils] Modal war aktiv:', wasActive, 'jetzt aktiv:', modal.classList.contains('active'));
            
            // Prüfe alle anderen Modals
            const allModals = document.querySelectorAll('.modal');
            console.log('[Utils] Alle Modals Status:');
            allModals.forEach(m => {
                console.log(`  - ${m.id || 'ohne ID'}: ${m.classList.contains('active') ? 'AKTIV' : 'inaktiv'}`);
            });
        } else {
            console.warn('[Utils] Modal nicht gefunden:', modalId);
        }
    },
    
    /**
     * Erstellt oder holt ein Modal-Element
     */
    getOrCreateModal(modalId, title) {
        let modal = document.getElementById(modalId);
        if (!modal) {
            modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal';
            modal.innerHTML = `
                <div class="modal-content modal-large">
                    <div class="modal-header">
                        <h3 id="${modalId}-title">${title}</h3>
                        <button class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body" id="${modalId}-body">
                        <!-- Wird dynamisch gefüllt -->
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Close button handler
            modal.querySelector('.modal-close').onclick = () => Utils.closeModal();
            
            // Overlay click handler
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    Utils.closeModal();
                }
            });
        } else if (title) {
            const titleEl = document.getElementById(`${modalId}-title`);
            if (titleEl) {
                titleEl.textContent = title;
            }
        }
        
        return modal;
    },
    
    /**
     * Erstellt oder holt ein Formular innerhalb eines Modals
     */
    getOrCreateForm(formId, createFormFn, setupFormFn) {
        const modalId = formId.replace('form-', 'modal-');
        const modal = Utils.getOrCreateModal(modalId, '');
        const body = document.getElementById(`${modalId}-body`);
        
        let form = document.getElementById(formId);
        if (!form && body) {
            body.innerHTML = createFormFn();
            form = document.getElementById(formId);
        }
        
        // Setup form handlers wenn vorhanden
        if (form && setupFormFn) {
            setupFormFn(form);
        }
        
        return form;
    },
    
    /**
     * Normalisiert eine URL (fügt https:// hinzu falls nötig)
     */
    normalizeUrl(inputElement) {
        if (!inputElement || !inputElement.value) return;
        
        let url = inputElement.value.trim();
        if (!url) {
            inputElement.value = '';
            return;
        }
        
        // Entferne führende/trailing Slashes
        url = url.replace(/^\/+|\/+$/g, '');
        
        // Wenn bereits Protokoll vorhanden, nur doppelte Slashes bereinigen
        if (/^https?:\/\//i.test(url)) {
            url = url.replace(/([^:])\/\/+/g, '$1/');
            inputElement.value = url;
            return;
        }
        
        // Füge https:// hinzu wenn nicht vorhanden
        if (/^www\./i.test(url)) {
            inputElement.value = 'https://' + url;
        } else {
            inputElement.value = 'https://' + url;
        }
    },
    
    /**
     * Zeigt eine Fehlermeldung an
     */
    showError(message) {
        console.error(message);
        alert(message);
    },
    
    /**
     * Zeigt eine Erfolgsmeldung an
     */
    showSuccess(message) {
        console.log(message);
        // TODO: Toast-Notification implementieren
    },
    
    /**
     * Lädt Hauptbranchen in ein Select-Element
     */
    async loadIndustryMainClasses(mainSelectElement) {
        try {
            const industries = await window.API.getIndustries(null, true);
            if (!mainSelectElement) {
                console.error('Main select element not provided');
                return;
            }
            
            mainSelectElement.innerHTML = '<option value="">-- Bitte wählen --</option>';
            industries.forEach(industry => {
                const option = document.createElement('option');
                option.value = industry.industry_uuid;
                option.textContent = industry.name;
                mainSelectElement.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading industry main classes:', error);
        }
    },
    
    /**
     * Lädt Unterbranchen in ein Select-Element basierend auf der Hauptbranche
     */
    async loadIndustrySubClasses(parentUuid, subSelectElement) {
        try {
            if (!parentUuid) {
                if (subSelectElement) {
                    subSelectElement.innerHTML = '<option value="">-- Zuerst Hauptbranche wählen --</option>';
                    subSelectElement.disabled = true;
                }
                return;
            }
            
            const industries = await window.API.getIndustries(parentUuid, false);
            if (!subSelectElement) {
                console.error('Sub select element not provided');
                return;
            }
            
            subSelectElement.innerHTML = '<option value="">-- Bitte wählen --</option>';
            subSelectElement.disabled = false;
            
            if (industries && industries.length > 0) {
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    option.textContent = industry.name;
                    subSelectElement.appendChild(option);
                });
            } else {
                subSelectElement.innerHTML = '<option value="">Keine Unterklassen verfügbar</option>';
            }
        } catch (error) {
            console.error('Error loading industry sub classes:', error);
            if (subSelectElement) {
                subSelectElement.innerHTML = '<option value="">Fehler beim Laden</option>';
                subSelectElement.disabled = true;
            }
        }
    },
    
    /**
     * Setzt die Branchen-Abhängigkeit zwischen Haupt- und Unterbranche
     * @param {HTMLElement} mainSelectElement - Das Select-Element für Hauptbranchen
     * @param {HTMLElement} subSelectElement - Das Select-Element für Unterbranchen
     * @param {boolean} cloneElement - Ob das Element geklont werden soll (verhindert mehrfache Listener)
     */
    setupIndustryDependency(mainSelectElement, subSelectElement, cloneElement = false) {
        if (!mainSelectElement || !subSelectElement) {
            console.error('[Utils] Both select elements must be provided');
            return null;
        }
        
        console.log('[Utils] setupIndustryDependency called', {
            mainId: mainSelectElement.id,
            subId: subSelectElement.id,
            cloneElement: cloneElement,
            mainElement: mainSelectElement,
            subElement: subSelectElement
        });
        
        // Stelle sicher, dass das Sub-Select initial deaktiviert ist
        subSelectElement.disabled = true;
        subSelectElement.innerHTML = '<option value="">-- Zuerst Hauptbranche wählen --</option>';
        
        let targetElement = mainSelectElement;
        
        // Entferne alte Event-Listener durch Klonen, wenn gewünscht (verhindert mehrfache Listener)
        if (cloneElement) {
            console.log('[Utils] Cloning main select element');
            const newMainSelect = mainSelectElement.cloneNode(true);
            mainSelectElement.parentNode.replaceChild(newMainSelect, mainSelectElement);
            targetElement = newMainSelect;
            console.log('[Utils] Element cloned and replaced', {
                oldId: mainSelectElement.id,
                newId: targetElement.id,
                newElement: targetElement
            });
        }
        
        // Event-Listener für Hauptbranche-Änderung
        const handler = async (e) => {
            console.log('[Utils] ===== Industry main changed event triggered! =====', e.target.value);
            const parentUuid = e.target.value;
            const currentSubSelect = document.getElementById(subSelectElement.id);
            console.log('[Utils] Sub select element:', currentSubSelect);
            if (currentSubSelect) {
                await Utils.loadIndustrySubClasses(parentUuid, currentSubSelect);
            } else {
                console.error('[Utils] Sub select element not found after change event!');
            }
        };
        
        targetElement.addEventListener('change', handler);
        console.log('[Utils] Event listener attached to:', targetElement.id, targetElement);
        
        // Gib das Element zurück (geklont oder original)
        return targetElement;
    }
};


