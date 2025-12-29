/**
 * TOM3 - Admin Module
 * Handles admin area, user management, and role management
 */

import { Utils } from './utils.js';

export class AdminModule {
    constructor(app) {
        this.app = app;
    }
    
    async load() {
        // Prüfe ob User Admin ist
        try {
            const user = await window.API.getCurrentUser();
            if (!user || !user.roles || !user.roles.includes('admin')) {
                Utils.showError('Zugriff verweigert: Admin-Berechtigung erforderlich');
                if (window.app && window.app.navigateTo) {
                    window.app.navigateTo('dashboard');
                }
                return;
            }

            // Speichere aktuellen User für später
            this.currentUser = user;

            // Lade User-Liste
            await this.loadAdminUsers();
            
            // Setup Event-Handler
            this.setupAdminHandlers();
        } catch (error) {
            console.error('Error loading admin:', error);
            Utils.showError('Fehler beim Laden des Admin-Bereichs');
        }
    }
    
    async loadAdminUsers(showInactive = false) {
        try {
            const users = await window.API.getAllUsers(showInactive);
            const container = document.getElementById('admin-users-list');
            if (!container) return;
        
            if (users.length === 0) {
                container.innerHTML = '<div class="empty-state"><p>Keine User gefunden</p></div>';
                return;
            }
            
            container.innerHTML = users.map(user => this.renderAdminUser(user)).join('');
            
            // Re-attach Event-Handler nach Rendering
            this.setupAdminUserHandlers();
        } catch (error) {
            console.error('Error loading users:', error);
            Utils.showError('Fehler beim Laden der User');
        }
    }
    
    renderAdminUser(user) {
        const isActive = user.is_active === 1 || user.is_active === true;
        const roles = user.roles ? (Array.isArray(user.roles) ? user.roles : user.roles.split(', ')) : [];
        const isAdmin = roles.includes('admin');
        const workflowRoles = user.workflow_roles ? (Array.isArray(user.workflow_roles) ? user.workflow_roles : user.workflow_roles.split(', ')) : [];
        
        // Prüfe ob aktueller User ein Admin ist (für Button-Ausblendung)
        const currentUserIsAdmin = this.currentUser && this.currentUser.roles && this.currentUser.roles.includes('admin');
        const currentUserId = this.currentUser ? this.currentUser.user_id : null;
        const isCurrentUser = currentUserId && parseInt(user.user_id) === parseInt(currentUserId);
        
        // Deaktivieren-Button nur anzeigen wenn User kein Admin ist
        const showDeactivateButton = isActive && !isAdmin;
        const showActivateButton = !isActive;
        
        return `
            <div class="admin-user-card ${!isActive ? 'user-inactive' : ''}" data-user-id="${user.user_id}">
                <div class="admin-user-header">
                    <div class="admin-user-info">
                        <h4>${Utils.escapeHtml(user.name)}</h4>
                        <p class="admin-user-email">${Utils.escapeHtml(user.email)}</p>
                        ${!isActive ? '<span class="badge badge-warning">Inaktiv</span>' : ''}
                        ${isAdmin ? '<span class="badge badge-danger">Admin</span>' : ''}
                    </div>
                    <div class="admin-user-actions">
                        ${showDeactivateButton ? 
                            `<button class="btn btn-sm btn-warning btn-deactivate-user" data-user-id="${user.user_id}">Deaktivieren</button>` : ''
                        }
                        ${showActivateButton ? 
                            `<button class="btn btn-sm btn-success btn-activate-user" data-user-id="${user.user_id}">Aktivieren</button>` : ''
                        }
                        <button class="btn btn-sm btn-secondary btn-edit-user" data-user-id="${user.user_id}">Bearbeiten</button>
                    </div>
                </div>
                <div class="admin-user-details">
                    <div class="admin-user-detail-item">
                        <strong>Systemrolle:</strong> ${roles.length > 0 ? roles.map(r => `<span class="badge">${Utils.escapeHtml(r)}</span>`).join(' ') : '<span class="text-muted">Keine</span>'}
                    </div>
                    ${workflowRoles.length > 0 ? `
                        <div class="admin-user-detail-item">
                            <strong>Teamrolle:</strong> ${workflowRoles.map(r => `<span class="badge badge-info">${Utils.escapeHtml(r)}</span>`).join(' ')}
                        </div>
                    ` : ''}
                    <div class="admin-user-detail-item">
                        <strong>Erstellt:</strong> ${user.created_at ? new Date(user.created_at).toLocaleDateString('de-DE') : '-'}
                        ${user.created_by_user_id ? ` <span class="text-muted">(von User-ID: ${Utils.escapeHtml(user.created_by_user_id)})</span>` : ''}
                    </div>
                    ${user.disabled_at ? `
                        <div class="admin-user-detail-item">
                            <strong>Deaktiviert:</strong> ${new Date(user.disabled_at).toLocaleDateString('de-DE')} ${new Date(user.disabled_at).toLocaleTimeString('de-DE')}
                            ${user.disabled_by_user_id ? ` <span class="text-muted">(von User-ID: ${Utils.escapeHtml(user.disabled_by_user_id)})</span>` : ''}
                        </div>
                    ` : ''}
                    ${user.last_login_at ? `
                        <div class="admin-user-detail-item">
                            <strong>Letzter Login:</strong> ${new Date(user.last_login_at).toLocaleDateString('de-DE')} ${new Date(user.last_login_at).toLocaleTimeString('de-DE')}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    setupAdminHandlers() {
        // Tab-Wechsel
        document.querySelectorAll('.admin-tab').forEach(tab => {
            const newTab = tab.cloneNode(true);
            tab.parentNode.replaceChild(newTab, tab);
            
            newTab.addEventListener('click', (e) => {
                const targetTab = e.target.dataset.tab;
                
                // Update Tabs
                document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
                e.target.classList.add('active');
                
                // Update Content
                document.querySelectorAll('.admin-tab-content').forEach(c => c.classList.remove('active'));
                const content = document.getElementById(`admin-tab-${targetTab}`);
                if (content) {
                    content.classList.add('active');
                }
                
                if (targetTab === 'users') {
                    this.loadAdminUsers();
                } else if (targetTab === 'roles') {
                    this.loadAdminRoles();
                }
            });
        });
        
        // User-Handler werden in setupAdminUserHandlers() gesetzt
        this.setupAdminUserHandlers();
    }
    
    setupAdminUserHandlers() {
        // User deaktivieren
        document.querySelectorAll('.btn-deactivate-user').forEach(btn => {
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            newBtn.addEventListener('click', async (e) => {
                const userId = e.target.dataset.userId;
                if (!confirm(`Möchten Sie diesen User wirklich deaktivieren?\n\nDer User kann nicht mehr auf das System zugreifen, aber alle zugeordneten Daten bleiben erhalten.`)) return;
                
                try {
                    await window.API.deactivateUser(userId);
                    Utils.showSuccess('User wurde deaktiviert');
                    await this.loadAdminUsers();
                } catch (error) {
                    Utils.showError('Fehler beim Deaktivieren: ' + (error.message || 'Unbekannter Fehler'));
                }
            });
        });
        
        // User aktivieren
        document.querySelectorAll('.btn-activate-user').forEach(btn => {
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            newBtn.addEventListener('click', async (e) => {
                const userId = e.target.dataset.userId;
                
                try {
                    await window.API.activateUser(userId);
                    Utils.showSuccess('User wurde aktiviert');
                    await this.loadAdminUsers();
                } catch (error) {
                    Utils.showError('Fehler beim Aktivieren: ' + (error.message || 'Unbekannter Fehler'));
                }
            });
        });
        
        // User bearbeiten
        document.querySelectorAll('.btn-edit-user').forEach(btn => {
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            newBtn.addEventListener('click', async (e) => {
                const userId = e.target.dataset.userId;
                await this.editUser(userId);
            });
        });
        
        // Inaktive User anzeigen
        const btnShowInactive = document.getElementById('btn-show-inactive');
        if (btnShowInactive) {
            let showInactive = false;
            const newBtn = btnShowInactive.cloneNode(true);
            btnShowInactive.parentNode.replaceChild(newBtn, btnShowInactive);
            
            newBtn.addEventListener('click', async () => {
                showInactive = !showInactive;
                newBtn.textContent = showInactive ? 'Nur aktive User' : 'Inaktive User anzeigen';
                await this.loadAdminUsers(showInactive);
            });
        }
    }
    
    async editUser(userId) {
        try {
            // Lade User-Daten
            let user = await window.API.getUser(userId, true);
            
            // Falls die API ein Array zurückgibt (was nicht sein sollte), nimm das erste Element
            if (Array.isArray(user)) {
                console.warn('API returned array instead of single user! Taking first element.');
                user = user.length > 0 ? user[0] : null;
            }
            
            if (!user) {
                Utils.showError('User nicht gefunden');
                return;
            }
            
            // Erstelle Modal für User-Bearbeitung
            const modal = Utils.getOrCreateModal('modal-edit-user', 'User bearbeiten');
            
            // Warte kurz, damit das Modal im DOM ist
            await new Promise(resolve => setTimeout(resolve, 10));
            
            const modalBody = document.getElementById('modal-edit-user-body');
            
            if (!modalBody) {
                console.error('Modal body not found!');
                Utils.showError('Fehler beim Öffnen des Bearbeitungsdialogs');
                return;
            }
            
            // Normalisiere roles - kann String oder Array sein
            let roles = [];
            if (user.roles) {
                if (Array.isArray(user.roles)) {
                    roles = user.roles;
                } else if (typeof user.roles === 'string') {
                    // Kann "admin" oder "admin, user" sein
                    roles = user.roles.split(',').map(r => r.trim()).filter(r => r);
                }
            }
            
            const isAdmin = roles.includes('admin');
            const hasUserRole = roles.includes('user');
            const hasManagerRole = roles.includes('manager');
            const hasReadonlyRole = roles.includes('readonly');
            
            // Sicherstellen, dass Werte vorhanden sind
            const userName = user.name || '';
            const userEmail = user.email || '';
            const isActive = user.is_active === 1 || user.is_active === true || user.is_active === '1';
            
            // Erstelle Formular-HTML
            const formHtml = `
                <form id="edit-user-form">
                    <div class="form-group">
                        <label for="edit-user-name">Name *</label>
                        <input type="text" id="edit-user-name" class="form-control" value="${Utils.escapeHtml(userName)}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-user-email">E-Mail *</label>
                        <input type="email" id="edit-user-email" class="form-control" value="${Utils.escapeHtml(userEmail)}" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Systemrolle</label>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="roles" value="admin" ${isAdmin ? 'checked' : ''} ${isAdmin ? 'disabled' : ''}>
                                <span>Admin${isAdmin ? ' (kann nicht entfernt werden)' : ''}</span>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="roles" value="user" ${hasUserRole ? 'checked' : ''}>
                                <span>User</span>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="roles" value="manager" ${hasManagerRole ? 'checked' : ''}>
                                <span>Manager</span>
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="roles" value="readonly" ${hasReadonlyRole ? 'checked' : ''}>
                                <span>Readonly</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <div class="checkbox-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="edit-user-active" ${isActive ? 'checked' : ''}>
                                <span>Aktiv</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-primary">Speichern</button>
                        <button type="button" class="btn btn-secondary btn-cancel-edit-user">Abbrechen</button>
                    </div>
                </form>
            `;
            
            // Setze HTML
            modalBody.innerHTML = formHtml;
            
            // Warte kurz, damit das HTML gerendert ist
            await new Promise(resolve => setTimeout(resolve, 50));
            
            // Event-Handler für Abbrechen-Button
            const cancelBtn = modalBody.querySelector('.btn-cancel-edit-user');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    Utils.closeModal();
                });
            }
            
            // Event-Handler für Form-Submit
            const form = document.getElementById('edit-user-form');
            if (!form) {
                console.error('Form not found after setting innerHTML!');
                return;
            }
            
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                const name = document.getElementById('edit-user-name').value.trim();
                const email = document.getElementById('edit-user-email').value.trim();
                const activeCheckbox = document.getElementById('edit-user-active');
                const isActive = activeCheckbox ? activeCheckbox.checked : true;
                
                // Sammle Rollen
                const selectedRoles = [];
                form.querySelectorAll('input[name="roles"]:checked').forEach(cb => {
                    if (!cb.disabled) {
                        selectedRoles.push(cb.value);
                    }
                });
                // Admin-Rolle beibehalten wenn bereits vorhanden
                if (isAdmin) {
                    selectedRoles.push('admin');
                }
                
                try {
                    await window.API.updateUser(userId, {
                        name: name,
                        email: email,
                        is_active: isActive ? 1 : 0,
                        roles: selectedRoles
                    });
                    
                    Utils.showSuccess('User wurde aktualisiert');
                    Utils.closeModal();
                    await this.loadAdminUsers();
                } catch (error) {
                    Utils.showError('Fehler beim Aktualisieren: ' + (error.message || 'Unbekannter Fehler'));
                }
            });
            
            // Zeige Modal
            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading user for edit:', error);
            Utils.showError('Fehler beim Laden der User-Daten');
        }
    }
    
    async loadAdminRoles() {
        // TODO: Systemrollen-Verwaltung implementieren
        const container = document.getElementById('admin-roles-list');
        if (container) {
            container.innerHTML = '<div class="empty-state"><p>Systemrollen-Verwaltung wird noch implementiert</p></div>';
        }
    }
}


