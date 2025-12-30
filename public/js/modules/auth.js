/**
 * TOM3 - Authentication Module
 * Handles user authentication, session management, and user info display
 */

import { Utils } from './utils.js';

export class AuthModule {
    constructor(app) {
        this.app = app;
    }
    
    async loadCurrentUser() {
        try {
            const user = await window.API.getCurrentUser();
            if (user) {
                this.displayUserInfo(user);
                return user;
            } else {
                this.redirectToLogin();
                return null;
            }
        } catch (error) {
            console.error('Error loading current user:', error);
            if (error.message && error.message.includes('Unauthorized')) {
                this.redirectToLogin();
            }
            return null;
        }
    }
    
    redirectToLogin() {
        const currentPath = window.location.pathname;
        if (!currentPath.includes('login.php')) {
            const basePath = currentPath.replace(/\/index\.html$/, '').replace(/\/$/, '') || '';
            window.location.href = (basePath ? basePath + '/' : '') + 'login.php';
        }
    }
    
    displayUserInfo(user) {
        const userInfo = document.getElementById('user-info');
        const userName = document.getElementById('user-name');
        const userEmail = document.getElementById('user-email');
        const userRoles = document.getElementById('user-roles');
        
        if (userInfo && userName && userEmail && userRoles) {
            userName.textContent = user.name || user.email;
            userEmail.textContent = user.email;
            
            if (user.roles && user.roles.length > 0) {
                userRoles.textContent = 'Rollen: ' + user.roles.join(', ');
            } else {
                userRoles.textContent = '';
            }
            
            userInfo.style.display = 'block';
            
            // Zeige Admin-Menüpunkt nur für Admins
            const adminMenuItem = document.getElementById('admin-menu-item');
            if (adminMenuItem) {
                const isAdmin = user.roles && user.roles.includes('admin');
                adminMenuItem.style.display = isAdmin ? 'block' : 'none';
            }
        }
    }
}



