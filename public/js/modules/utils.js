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
        document.querySelectorAll('.modal.active').forEach(modal => {
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
        console.log(message);
        // TODO: Toast-Notification implementieren
    }
};


