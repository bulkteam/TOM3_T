/**
 * TOM3 - Document Upload Module
 * Handles file upload dialog and upload process
 */

import { Utils } from './utils.js';

export class DocumentUploadModule {
    constructor(app) {
        this.app = app;
    }
    
    /**
     * Zeigt Upload-Dialog
     */
    showUploadDialog(entityType, entityUuid, options = {}) {
        try {
            const modal = this.createUploadModal(entityType, entityUuid, options);
            document.body.appendChild(modal);
            
            // Modal anzeigen (verwende .active Klasse wie andere Modals)
            modal.classList.add('active');
            
            // Event-Handler
            this.setupUploadHandlers(modal, entityType, entityUuid);
        } catch (error) {
            console.error('Fehler beim Öffnen des Upload-Dialogs:', error);
        }
    }
    
    /**
     * Erstellt Upload-Modal
     */
    createUploadModal(entityType, entityUuid, options) {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.id = 'document-upload-modal';
        
        modal.innerHTML = `
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h2>Dokument hochladen</h2>
                    <button class="modal-close" onclick="this.closest('.modal').remove()">×</button>
                </div>
                <div class="modal-body">
                    <form id="document-upload-form" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="upload-file">Datei(en) *</label>
                            <input type="file" id="upload-file" name="file" required multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif,.webp,.tiff,.txt,.csv,.html,.zip,.rar">
                            <small class="form-hint">Max. 50MB pro Datei. Mehrere Dateien können gleichzeitig ausgewählt werden.</small>
                        </div>
                        
                        <div id="upload-files-preview" class="upload-files-preview" style="display: none;">
                            <div class="upload-files-list"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="upload-title">Titel (optional)</label>
                            <input type="text" id="upload-title" name="title" placeholder="Wird bei mehreren Dateien ignoriert, sonst: z.B. Rechnung Dezember 2025">
                            <small class="form-hint">Bei mehreren Dateien wird der Dateiname als Titel verwendet.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="upload-classification">Klassifikation</label>
                            <select id="upload-classification" name="classification">
                                <option value="other">Sonstiges</option>
                                <option value="invoice">Rechnung</option>
                                <option value="quote">Angebot</option>
                                <option value="contract">Vertrag</option>
                                <option value="email_attachment">E-Mail-Anhang</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="upload-role">Rolle (optional)</label>
                            <input type="text" id="upload-role" name="role" placeholder="z.B. Rechnung, Vertrag, etc.">
                        </div>
                        
                        <div class="form-group">
                            <label for="upload-description">Beschreibung (optional)</label>
                            <textarea id="upload-description" name="description" rows="3" placeholder="Zusätzliche Informationen..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="upload-tags">Tags (kommagetrennt, optional)</label>
                            <input type="text" id="upload-tags" name="tags" placeholder="wichtig, vertraulich, etc.">
                        </div>
                        
                        <div id="upload-progress" class="upload-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill" id="progress-fill"></div>
                            </div>
                            <div class="progress-text" id="progress-text">Wird hochgeladen...</div>
                        </div>
                        
                        <div id="upload-error" class="error-message" style="display: none;"></div>
                    </form>
                    
                    <!-- Erlaubte Formate - Unten links -->
                    <div class="upload-allowed-formats" style="margin-top: 1.5rem;">
                        <strong>Erlaubte Formate:</strong>
                        <div class="upload-formats-list">
                            <span class="format-category">Dokumente:</span> PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX<br>
                            <span class="format-category">Bilder:</span> PNG, JPG, JPEG, GIF, WEBP, TIFF<br>
                            <span class="format-category">Text:</span> TXT, CSV, HTML<br>
                            <span class="format-category">Archive:</span> ZIP, RAR
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Abbrechen</button>
                    <button type="submit" form="document-upload-form" class="btn btn-success" id="upload-submit-btn">Hochladen</button>
                </div>
            </div>
        `;
        
        return modal;
    }
    
    /**
     * Setup Event-Handler für Upload
     */
    setupUploadHandlers(modal, entityType, entityUuid) {
        const form = modal.querySelector('#document-upload-form');
        const fileInput = modal.querySelector('#upload-file');
        const titleInput = modal.querySelector('#upload-title');
        const submitBtn = modal.querySelector('#upload-submit-btn');
        const progressDiv = modal.querySelector('#upload-progress');
        const progressFill = modal.querySelector('#progress-fill');
        const progressText = modal.querySelector('#progress-text');
        const errorDiv = modal.querySelector('#upload-error');
        
        // Datei-Vorschau bei Auswahl
        const filesPreview = modal.querySelector('#upload-files-preview');
        const filesList = filesPreview.querySelector('.upload-files-list');
        
        fileInput.addEventListener('change', (e) => {
            const files = Array.from(e.target.files);
            
            if (files.length === 0) {
                filesPreview.style.display = 'none';
                return;
            }
            
            // Vorschau anzeigen
            filesPreview.style.display = 'block';
            filesList.innerHTML = '';
            
            files.forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'upload-file-item';
                fileItem.innerHTML = `
                    <div class="upload-file-info">
                        <span class="upload-file-name">${this.escapeHtml(file.name)}</span>
                        <span class="upload-file-size">${this.formatFileSize(file.size)}</span>
                    </div>
                `;
                filesList.appendChild(fileItem);
            });
            
            // Auto-Fill Titel nur bei einer Datei
            if (files.length === 1 && !titleInput.value) {
                const filename = files[0].name;
                const nameWithoutExt = filename.replace(/\.[^/.]+$/, '');
                titleInput.value = nameWithoutExt;
            } else if (files.length > 1) {
                // Titel-Feld bei mehreren Dateien optional machen
                titleInput.required = false;
            }
        });
        
        // Form Submit
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const files = Array.from(fileInput.files);
            if (files.length === 0) {
                this.showError(errorDiv, 'Bitte wählen Sie mindestens eine Datei aus');
                return;
            }
            
            // Validierung aller Dateien
            const maxSize = 50 * 1024 * 1024; // 50MB
            for (const file of files) {
                if (file.size > maxSize) {
                    this.showError(errorDiv, `Datei "${file.name}" ist zu groß (max. 50MB)`);
                    return;
                }
            }
            
            // UI: Upload starten
            submitBtn.disabled = true;
            submitBtn.textContent = files.length > 1 ? `Lade ${files.length} Dateien hoch...` : 'Wird hochgeladen...';
            progressDiv.style.display = 'block';
            errorDiv.style.display = 'none';
            progressFill.style.width = '0%';
            progressText.textContent = 'Wird hochgeladen...';
            
            const classification = modal.querySelector('#upload-classification').value;
            const role = modal.querySelector('#upload-role').value;
            const description = modal.querySelector('#upload-description').value;
            const tags = modal.querySelector('#upload-tags').value;
            const tagsArray = tags ? tags.split(',').map(t => t.trim()).filter(t => t) : [];
            
            try {
                let successCount = 0;
                let errorCount = 0;
                const errors = [];
                
                // Sequenzieller Upload (um Server nicht zu überlasten)
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileTitle = files.length === 1 && titleInput.value 
                        ? titleInput.value 
                        : file.name.replace(/\.[^/.]+$/, ''); // Dateiname ohne Extension
                    
                    progressText.textContent = `Lade ${i + 1} von ${files.length}: ${file.name}...`;
                    progressFill.style.width = `${((i / files.length) * 100)}%`;
                    
                    try {
                        // FormData für diese Datei erstellen
                        const formData = new FormData();
                        formData.append('file', file);
                        formData.append('title', fileTitle);
                        formData.append('classification', classification);
                        formData.append('entity_type', entityType);
                        formData.append('entity_uuid', entityUuid);
                        
                        if (role) formData.append('role', role);
                        if (description) formData.append('description', description);
                        if (tagsArray.length > 0) {
                            formData.append('tags', JSON.stringify(tagsArray));
                        }
                        
                        await window.API.uploadDocument(formData);
                        successCount++;
                    } catch (error) {
                        errorCount++;
                        errors.push(`${file.name}: ${error.message || 'Unbekannter Fehler'}`);
                    }
                }
                
                progressFill.style.width = '100%';
                
                // Ergebnis anzeigen
                if (errorCount === 0) {
                    progressText.textContent = `Alle ${successCount} Datei(en) erfolgreich hochgeladen!`;
                    
                    // Dokumenten-Liste neu laden BEVOR Modal geschlossen wird
                    const documentListModule = this.app.modules?.documentList;
                    if (documentListModule) {
                        // Bestimme die richtigen Selektoren basierend auf entityType
                        let containerSelector, badgeSelector;
                        if (entityType === 'org') {
                            containerSelector = '#org-documents-list';
                            badgeSelector = '#org-documents-count-badge';
                        } else if (entityType === 'person') {
                            containerSelector = '#person-documents-list';
                            badgeSelector = '#person-documents-count-badge';
                        } else {
                            containerSelector = '#documents-container';
                            badgeSelector = null;
                        }
                        
                        // Dokumente laden und auf Abschluss warten
                        try {
                            await documentListModule.loadDocuments(entityType, entityUuid, containerSelector, badgeSelector);
                        } catch (error) {
                            console.error('Fehler beim Neuladen der Dokumente:', error);
                        }
                    }
                    
                    // Erfolg: Modal schließen nach kurzer Verzögerung
                    setTimeout(() => {
                        modal.remove();
                        
                        // Erfolgs-Meldung
                        const message = successCount === 1 
                            ? 'Dokument erfolgreich hochgeladen' 
                            : `${successCount} Dokumente erfolgreich hochgeladen`;
                        this.app.showNotification(message, 'success');
                    }, 500);
                } else {
                    // Teilweise erfolgreich oder Fehler
                    let errorMsg = '';
                    if (successCount > 0) {
                        errorMsg = `${successCount} Datei(en) erfolgreich, ${errorCount} Fehler:\n${errors.join('\n')}`;
                    } else {
                        errorMsg = `Upload fehlgeschlagen:\n${errors.join('\n')}`;
                    }
                    this.showError(errorDiv, errorMsg);
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Hochladen';
                    progressDiv.style.display = 'none';
                }
                
            } catch (error) {
                console.error('Upload-Fehler:', error);
                this.showError(errorDiv, error.message || 'Fehler beim Hochladen');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Hochladen';
                progressDiv.style.display = 'none';
            }
        });
    }
    
    /**
     * Fehler anzeigen
     */
    showError(errorDiv, message) {
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
    }
    
    /**
     * Formatiert Dateigröße in lesbares Format
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    
    /**
     * Escaped HTML (XSS-Schutz)
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
