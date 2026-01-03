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
        const activeModals = document.querySelectorAll('.modal.active');
        activeModals.forEach((modal) => {
            modal.classList.remove('active');
        });
    },
    
    /**
     * Schließt ein spezifisches Modal anhand seiner ID
     */
    closeSpecificModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
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
        // TODO: Toast-Notification implementieren
    },
    
    /**
     * Zeigt eine Info-Meldung an
     */
    showInfo(message) {
        // TODO: Toast-Notification implementieren
    },
    
    /**
     * Formatiert ein Datum
     */
    formatDate(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('de-DE');
        } catch (e) {
            return dateString;
        }
    },
    
    /**
     * Zeigt ein Modal an
     */
    showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
        }
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
                const displayName = industry.display_name || industry.name_short || industry.name;
                option.textContent = displayName;
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
                    const displayName = industry.display_name || industry.name_short || industry.name;
                option.textContent = displayName;
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
     * Lädt Level 1 Branchen (Branchenbereiche)
     */
    /**
     * Lädt Level 1 Industries in ein Select-Element
     * @param {HTMLElement} level1SelectElement - Das Select-Element
     */
    async loadIndustryLevel1(level1SelectElement) {
        try {
            const industries = await window.API.getIndustries(null, null, 1);
            if (!level1SelectElement) {
                console.error('Level 1 select element not provided');
                return;
            }
            
            level1SelectElement.innerHTML = '<option value="">-- Bitte wählen --</option>';
            industries.forEach(industry => {
                const option = document.createElement('option');
                option.value = industry.industry_uuid;
                const displayName = industry.display_name || industry.name_short || industry.name;
                option.textContent = displayName;
                level1SelectElement.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading industry level 1:', error);
        }
    },
    
    /**
     * Lädt Level 2 Branchen basierend auf Level 1
     */
    async loadIndustryLevel2(parentUuid, level2SelectElement) {
        try {
            if (!parentUuid) {
                if (level2SelectElement) {
                    level2SelectElement.innerHTML = '<option value="">-- Zuerst Branchenbereich wählen --</option>';
                    level2SelectElement.disabled = true;
                }
                return;
            }
            
            const industries = await window.API.getIndustries(parentUuid, null, 2);
            if (!level2SelectElement) {
                console.error('Level 2 select element not provided');
                return;
            }
            
            level2SelectElement.innerHTML = '<option value="">-- Bitte wählen --</option>';
            level2SelectElement.disabled = false;
            level2SelectElement.required = true; // Level 2 ist required
            
            if (industries && industries.length > 0) {
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    const displayName = industry.display_name || industry.name_short || industry.name;
                option.textContent = displayName;
                    level2SelectElement.appendChild(option);
                });
            } else {
                level2SelectElement.innerHTML = '<option value="">Keine Branchen verfügbar</option>';
            }
        } catch (error) {
            console.error('Error loading industry level 2:', error);
            if (level2SelectElement) {
                level2SelectElement.innerHTML = '<option value="">Fehler beim Laden</option>';
                level2SelectElement.disabled = true;
            }
        }
    },
    
    /**
     * Lädt Level 3 Unterbranchen basierend auf Level 2
     */
    async loadIndustryLevel3(parentUuid, level3SelectElement) {
        try {
            if (!parentUuid) {
                if (level3SelectElement) {
                    level3SelectElement.innerHTML = '<option value="">-- Zuerst Branche wählen --</option>';
                    level3SelectElement.disabled = true;
                }
                return;
            }
            
            const industries = await window.API.getIndustries(parentUuid, null, 3);
            if (!level3SelectElement) {
                console.error('Level 3 select element not provided');
                return;
            }
            
            level3SelectElement.innerHTML = '<option value="">-- Bitte wählen --</option>';
            level3SelectElement.disabled = false;
            level3SelectElement.required = false; // Level 3 ist optional
            
            if (industries && industries.length > 0) {
                industries.forEach(industry => {
                    const option = document.createElement('option');
                    option.value = industry.industry_uuid;
                    const displayName = industry.display_name || industry.name_short || industry.name;
                    option.textContent = displayName;
                    level3SelectElement.appendChild(option);
                });
            } else {
                level3SelectElement.innerHTML = '<option value="">Keine Unterbranchen verfügbar</option>';
            }
        } catch (error) {
            console.error('Error loading industry level 3:', error);
            if (level3SelectElement) {
                level3SelectElement.innerHTML = '<option value="">Fehler beim Laden</option>';
                level3SelectElement.disabled = true;
            }
        }
    },
    
    /**
     * Setzt die 3-stufige Branchen-Abhängigkeit
     * @param {HTMLElement} level1SelectElement - Das Select-Element für Level 1 (Branchenbereich)
     * @param {HTMLElement} level2SelectElement - Das Select-Element für Level 2 (Branche)
     * @param {HTMLElement} level3SelectElement - Das Select-Element für Level 3 (Unterbranche)
     * @param {boolean} cloneElement - Ob das Element geklont werden soll (verhindert mehrfache Listener)
     */
    setupIndustry3LevelDependency(level1SelectElement, level2SelectElement, level3SelectElement, cloneElement = false) {
        if (!level1SelectElement || !level2SelectElement || !level3SelectElement) {
            console.error('[Utils] All three select elements must be provided');
            return null;
        }
        
        // Stelle sicher, dass Level 2 und 3 initial deaktiviert sind
        level2SelectElement.disabled = true;
        level2SelectElement.innerHTML = '<option value="">-- Zuerst Branchenbereich wählen --</option>';
        level2SelectElement.required = true; // Level 2 ist required
        level3SelectElement.disabled = true;
        level3SelectElement.innerHTML = '<option value="">-- Zuerst Branche wählen --</option>';
        level3SelectElement.required = false; // Level 3 ist optional
        
        let targetLevel1 = level1SelectElement;
        let targetLevel2 = level2SelectElement;
        
        // Entferne alte Event-Listener durch Klonen, wenn gewünscht
        if (cloneElement) {
            const newLevel1 = level1SelectElement.cloneNode(true);
            level1SelectElement.parentNode.replaceChild(newLevel1, level1SelectElement);
            targetLevel1 = newLevel1;
            
            const newLevel2 = level2SelectElement.cloneNode(true);
            level2SelectElement.parentNode.replaceChild(newLevel2, level2SelectElement);
            targetLevel2 = newLevel2;
        }
        
        // Event-Listener für Level 1 Änderung
        const level1Handler = async (e) => {
            const parentUuid = e.target.value;
            const currentLevel2 = document.getElementById(level2SelectElement.id);
            const currentLevel3 = document.getElementById(level3SelectElement.id);
            
            // Reset Level 3
            if (currentLevel3) {
                currentLevel3.innerHTML = '<option value="">-- Zuerst Branche wählen --</option>';
                currentLevel3.disabled = true;
            }
            
            // Lade Level 2
            if (currentLevel2) {
                await Utils.loadIndustryLevel2(parentUuid, currentLevel2);
            }
        };
        
        // Event-Listener für Level 2 Änderung
        const level2Handler = async (e) => {
            const parentUuid = e.target.value;
            const currentLevel3 = document.getElementById(level3SelectElement.id);
            
            if (currentLevel3) {
                await Utils.loadIndustryLevel3(parentUuid, currentLevel3);
            }
        };
        
        targetLevel1.addEventListener('change', level1Handler);
        targetLevel2.addEventListener('change', level2Handler);
        
        return { level1: targetLevel1, level2: targetLevel2 };
    },
    
    /**
     * Setzt die Branchen-Abhängigkeit zwischen Haupt- und Unterbranche (Rückwärtskompatibilität)
     * @param {HTMLElement} mainSelectElement - Das Select-Element für Hauptbranchen
     * @param {HTMLElement} subSelectElement - Das Select-Element für Unterbranchen
     * @param {boolean} cloneElement - Ob das Element geklont werden soll (verhindert mehrfache Listener)
     */
    setupIndustryDependency(mainSelectElement, subSelectElement, cloneElement = false) {
        if (!mainSelectElement || !subSelectElement) {
            console.error('[Utils] Both select elements must be provided');
            return null;
        }
        
        // Stelle sicher, dass das Sub-Select initial deaktiviert ist
        subSelectElement.disabled = true;
        subSelectElement.innerHTML = '<option value="">-- Zuerst Hauptbranche wählen --</option>';
        
        let targetElement = mainSelectElement;
        
        // Entferne alte Event-Listener durch Klonen, wenn gewünscht (verhindert mehrfache Listener)
        if (cloneElement) {
            const newMainSelect = mainSelectElement.cloneNode(true);
            mainSelectElement.parentNode.replaceChild(newMainSelect, mainSelectElement);
            targetElement = newMainSelect;
        }
        
        // Event-Listener für Hauptbranche-Änderung
        const handler = async (e) => {
            const parentUuid = e.target.value;
            const currentSubSelect = document.getElementById(subSelectElement.id);
            if (currentSubSelect) {
                await Utils.loadIndustrySubClasses(parentUuid, currentSubSelect);
            } else {
                console.error('[Utils] Sub select element not found after change event!');
            }
        };
        
        targetElement.addEventListener('change', handler);
        
        // Gib das Element zurück (geklont oder original)
        return targetElement;
    },
    
    /**
     * Konvertiert FormData zu einem Objekt
     * @param {HTMLFormElement} form - Das Formular-Element
     * @param {Object} options - Optionen für die Konvertierung
     * @param {boolean} options.filterEmpty - Ob leere Werte gefiltert werden sollen (default: true)
     * @param {string[]} options.excludeFields - Felder, die ausgeschlossen werden sollen
     * @returns {Object} Das konvertierte Objekt
     */
    formDataToObject(form, options = {}) {
        const formData = new FormData(form);
        const data = {};
        const filterEmpty = options.filterEmpty !== false; // default: true
        const excludeFields = options.excludeFields || [];
        
        for (const [key, value] of formData.entries()) {
            if (excludeFields.includes(key)) {
                continue;
            }
            if (filterEmpty && !value) {
                continue;
            }
            data[key] = value;
        }
        
        return data;
    },
    
    /**
     * Konvertiert einen Checkbox-Wert (String '1' → Number 1, sonst 0)
     * @param {string|number|boolean} value - Der zu konvertierende Wert
     * @returns {number} 1 oder 0
     */
    convertCheckboxValue(value) {
        if (value === '1' || value === 1 || value === true) {
            return 1;
        }
        return 0;
    },
    
    /**
     * Konvertiert mehrere Checkbox-Felder in einem Objekt
     * @param {Object} data - Das Datenobjekt
     * @param {string[]} fieldNames - Die Feldnamen, die konvertiert werden sollen
     * @returns {Object} Das modifizierte Datenobjekt
     */
    convertCheckboxes(data, fieldNames) {
        if (!Array.isArray(fieldNames)) {
            return data;
        }
        
        fieldNames.forEach(fieldName => {
            if (data.hasOwnProperty(fieldName)) {
                data[fieldName] = Utils.convertCheckboxValue(data[fieldName]);
            }
        });
        
        return data;
    },
    
    /**
     * Konvertiert leere Strings zu null für angegebene Felder
     * @param {Object} data - Das Datenobjekt
     * @param {string[]} fieldNames - Die Feldnamen, die konvertiert werden sollen
     * @returns {Object} Das modifizierte Datenobjekt
     */
    emptyStringToNull(data, fieldNames) {
        if (!Array.isArray(fieldNames)) {
            return data;
        }
        
        fieldNames.forEach(fieldName => {
            if (data.hasOwnProperty(fieldName) && data[fieldName] === '') {
                data[fieldName] = null;
            }
        });
        
        return data;
    },
    
    /**
     * Kombinierte Funktion: FormData zu Objekt + Checkboxen konvertieren + leere Strings zu null
     * @param {HTMLFormElement} form - Das Formular-Element
     * @param {Object} options - Optionen
     * @param {boolean} options.filterEmpty - Ob leere Werte gefiltert werden sollen
     * @param {string[]} options.excludeFields - Felder, die ausgeschlossen werden sollen
     * @param {string[]} options.checkboxFields - Checkbox-Felder, die konvertiert werden sollen
     * @param {string[]} options.nullFields - Felder, deren leere Strings zu null konvertiert werden sollen
     * @returns {Object} Das verarbeitete Datenobjekt
     */
    processFormData(form, options = {}) {
        let data = Utils.formDataToObject(form, {
            filterEmpty: options.filterEmpty,
            excludeFields: options.excludeFields
        });
        
        if (options.checkboxFields && options.checkboxFields.length > 0) {
            data = Utils.convertCheckboxes(data, options.checkboxFields);
        }
        
        if (options.nullFields && options.nullFields.length > 0) {
            data = Utils.emptyStringToNull(data, options.nullFields);
        }
        
        return data;
    }
};


