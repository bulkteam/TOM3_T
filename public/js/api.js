/**
 * TOM3 - API Client
 */

// Helper: Ermittelt den Base-Path der Anwendung
function getBasePath() {
    const path = window.location.pathname;
    // Entferne index.html, login.php, monitoring.html oder trailing slash
    const base = path
        .replace(/\/index\.html$/, '')
        .replace(/\/login\.php$/, '')
        .replace(/\/monitoring\.html$/, '')
        .replace(/\/$/, '');
    return base || '';
}

// API Base URL - relativ zum aktuellen Pfad
const API_BASE = (() => {
    return getBasePath() + '/api';
})();

class TOM3API {
    constructor() {
        this.baseUrl = API_BASE;
    }

    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        const config = {
            headers: {
                ...options.headers
            },
            ...options
        };

        // FormData nicht als JSON senden
        if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
            if (!config.headers['Content-Type']) {
                config.headers['Content-Type'] = 'application/json';
            }
            config.body = JSON.stringify(config.body);
        } else if (config.body instanceof FormData) {
            // FormData setzt Content-Type automatisch (inkl. boundary)
            delete config.headers['Content-Type'];
        } else if (!config.headers['Content-Type']) {
            config.headers['Content-Type'] = 'application/json';
        }

        try {
            const response = await fetch(url, config);
            
            // Bei 401 (Unauthorized) -> weiterleiten zu Login
            if (response.status === 401) {
                const currentPath = window.location.pathname;
                if (!currentPath.includes('login.php')) {
                    const basePath = currentPath.replace(/\/index\.html$/, '').replace(/\/$/, '') || '';
                    window.location.href = (basePath ? basePath + '/' : '') + 'login.php';
                }
                throw new Error('Unauthorized - redirecting to login');
            }
            
            // Prüfe Content-Type
            const contentType = response.headers.get('content-type');
            let data;
            
            if (contentType && contentType.includes('application/json')) {
                data = await response.json();
            } else {
                // Wenn nicht JSON, versuche Text zu lesen
                const text = await response.text();
                console.error('Non-JSON response:', text);
                throw new Error(`Server returned non-JSON response: ${text.substring(0, 100)}`);
            }
            
            if (!response.ok) {
                throw new Error(data.error || data.message || `HTTP ${response.status}`);
            }
            
            return data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }

    // Cases
    async getCases(filters = {}) {
        const params = new URLSearchParams();
        if (filters.status) params.append('status', filters.status);
        if (filters.engine) params.append('engine', filters.engine);
        if (filters.search) params.append('search', filters.search);
        
        const query = params.toString();
        return this.request(`/cases${query ? '?' + query : ''}`);
    }

    async getCase(caseUuid) {
        return this.request(`/cases/${caseUuid}`);
    }

    async createCase(data) {
        return this.request('/cases', {
            method: 'POST',
            body: data
        });
    }

    async updateCase(caseUuid, data) {
        return this.request(`/cases/${caseUuid}`, {
            method: 'PUT',
            body: data
        });
    }

    async addCaseNote(caseUuid, note) {
        return this.request(`/cases/${caseUuid}/notes`, {
            method: 'POST',
            body: { note }
        });
    }

    async fulfillRequirement(caseUuid, requirementUuid, data) {
        return this.request(`/cases/${caseUuid}/requirements/${requirementUuid}/fulfill`, {
            method: 'POST',
            body: data
        });
    }

    async getCaseBlockers(caseUuid) {
        return this.request(`/cases/${caseUuid}/blockers`);
    }

    // Workflow
    async handover(caseUuid, data) {
        return this.request(`/workflow/handover`, {
            method: 'POST',
            body: { case_uuid: caseUuid, ...data }
        });
    }

    async returnCase(caseUuid, data) {
        return this.request(`/workflow/return`, {
            method: 'POST',
            body: { case_uuid: caseUuid, ...data }
        });
    }

    // Projects
    async getProjects() {
        return this.request('/projects');
    }

    async getProject(projectUuid) {
        return this.request(`/projects/${projectUuid}`);
    }

    async createProject(data) {
        return this.request('/projects', {
            method: 'POST',
            body: data
        });
    }

    async linkCaseToProject(projectUuid, caseUuid) {
        return this.request(`/projects/${projectUuid}/cases`, {
            method: 'POST',
            body: { case_uuid: caseUuid }
        });
    }

    // Orgs
    async getOrgs(filters = {}) {
        const params = new URLSearchParams();
        if (filters.search) params.append('search', filters.search);
        if (filters.org_kind) params.append('org_kind', filters.org_kind);
        if (filters.limit) params.append('limit', filters.limit);
        
        const query = params.toString();
        return this.request(`/orgs${query ? '?' + query : ''}`);
    }
    
    async searchOrgs(query = '', filters = {}, limit = 20) {
        if (!query && Object.keys(filters).length === 0) {
            return [];
        }
        
        const params = new URLSearchParams();
        if (query) {
            params.append('q', query);
        }
        if (filters.industry) params.append('industry', filters.industry);
        if (filters.status) params.append('status', filters.status);
        if (filters.tier) params.append('tier', filters.tier);
        if (filters.strategic !== undefined) params.append('strategic', filters.strategic ? '1' : '0');
        if (filters.org_kind) params.append('org_kind', filters.org_kind);
        if (filters.city) params.append('city', filters.city);
        if (filters.revenue_min) params.append('revenue_min', filters.revenue_min);
        if (filters.employees_min) params.append('employees_min', filters.employees_min);
        if (filters.include_archived !== undefined) params.append('include_archived', filters.include_archived ? 'true' : 'false');
        if (limit) params.append('limit', limit);
        
        const queryString = params.toString();
        return this.request(`/orgs/search?${queryString}`);
    }

    async getOrg(orgUuid, trackAccess = false) {
        const org = await this.request(`/orgs/${orgUuid}`);
        
        // Track Zugriff beim Abrufen (optional, um nicht zu viel zu tracken)
        if (trackAccess && org) {
            try {
                await this.trackOrgAccess(orgUuid, 'default_user', 'recent');
            } catch (error) {
                console.warn('Could not track org access:', error);
            }
        }
        
        return org;
    }
    
    async getOrgDetails(orgUuid) {
        return this.request(`/orgs/${orgUuid}/details`);
    }

    // Persons
    async searchPersons(query = '', limit = 50) {
        if (!query) {
            return this.request(`/persons`);
        }
        return this.request(`/persons/search?q=${encodeURIComponent(query)}`);
    }

    async getPerson(personUuid) {
        return this.request(`/persons/${personUuid}`);
    }

    async createPerson(data) {
        return this.request('/persons', {
            method: 'POST',
            body: data
        });
    }

    async updatePerson(personUuid, data) {
        return this.request(`/persons/${personUuid}`, {
            method: 'PUT',
            body: data
        });
    }

    async getPersonAffiliations(personUuid, includeInactive = false) {
        const params = includeInactive ? '?include_inactive=true' : '';
        return this.request(`/persons/${personUuid}/affiliations${params}`);
    }

    async createPersonAffiliation(personUuid, data) {
        return this.request(`/persons/${personUuid}/affiliations`, {
            method: 'POST',
            body: data
        });
    }

    async getPersonAuditTrail(personUuid, limit = 100) {
        return this.request(`/persons/${personUuid}/audit-trail?limit=${limit}`);
    }

    async getPersonRelationships(personUuid, includeInactive = false) {
        const params = includeInactive ? '?include_inactive=true' : '';
        return this.request(`/persons/${personUuid}/relationships${params}`);
    }

    async createPersonRelationship(personUuid, data) {
        return this.request(`/persons/${personUuid}/relationships`, {
            method: 'POST',
            body: data
        });
    }

    async deletePersonRelationship(relationshipUuid) {
        return this.request(`/persons/relationships?relationship_uuid=${relationshipUuid}`, {
            method: 'DELETE'
        });
    }

    async getOrgUnits(orgUuid) {
        return this.request(`/persons/org-units?org_uuid=${orgUuid}`);
    }

    async createOrgUnit(data) {
        return this.request('/persons/org-units', {
            method: 'POST',
            body: data
        });
    }
    
    // Addresses
    async getOrgAddresses(orgUuid, addressType = null) {
        const endpoint = addressType ? `/orgs/${orgUuid}/addresses?address_type=${addressType}` : `/orgs/${orgUuid}/addresses`;
        return this.request(endpoint);
    }
    
    async addOrgAddress(orgUuid, data) {
        return this.request(`/orgs/${orgUuid}/addresses`, {
            method: 'POST',
            body: data
        });
    }
    
    async updateOrgAddress(orgUuid, addressUuid, data) {
        return this.request(`/orgs/${orgUuid}/addresses/${addressUuid}`, {
            method: 'PUT',
            body: data
        });
    }
    
    async deleteOrgAddress(orgUuid, addressUuid) {
        return this.request(`/orgs/${orgUuid}/addresses/${addressUuid}`, {
            method: 'DELETE'
        });
    }
    
    async lookupPlz(plz) {
        return this.request(`/plz-lookup?plz=${encodeURIComponent(plz)}`);
    }
    
    async getAddressTypes() {
        return this.request('/address-types');
    }
    
    // Communication Channels
    async getOrgChannels(orgUuid, type = null) {
        const endpoint = type ? `/orgs/${orgUuid}/channels?type=${type}` : `/orgs/${orgUuid}/channels`;
        return this.request(endpoint);
    }
    
    async addOrgChannel(orgUuid, data) {
        return this.request(`/orgs/${orgUuid}/channels`, {
            method: 'POST',
            body: data
        });
    }
    
    async updateOrgChannel(orgUuid, channelUuid, data) {
        return this.request(`/orgs/${orgUuid}/channels/${channelUuid}`, {
            method: 'PUT',
            body: data
        });
    }
    
    async deleteOrgChannel(orgUuid, channelUuid) {
        return this.request(`/orgs/${orgUuid}/channels/${channelUuid}`, {
            method: 'DELETE'
        });
    }
    
    // Relations
    async getOrgRelations(orgUuid, direction = null) {
        const endpoint = direction ? `/orgs/${orgUuid}/relations?direction=${direction}` : `/orgs/${orgUuid}/relations`;
        return this.request(endpoint);
    }
    
    async getOrgRelation(relationUuid) {
        // Relation über beide möglichen Endpunkte abrufen (parent oder child)
        // Wir müssen die Relation über eine Org finden
        // Für jetzt: Verwende einen direkten Endpunkt oder suche über alle Orgs
        // TODO: API-Endpunkt für direkten Relation-Abruf hinzufügen
        throw new Error('Direct relation lookup not yet implemented. Use getOrgRelations instead.');
    }
    
    async addOrgRelation(orgUuid, data) {
        return this.request(`/orgs/${orgUuid}/relations`, {
            method: 'POST',
            body: data
        });
    }
    
    async updateOrgRelation(orgUuid, relationUuid, data) {
        return this.request(`/orgs/${orgUuid}/relations/${relationUuid}`, {
            method: 'PUT',
            body: data
        });
    }
    
    async deleteOrgRelation(orgUuid, relationUuid) {
        return this.request(`/orgs/${orgUuid}/relations/${relationUuid}`, {
            method: 'DELETE'
        });
    }
    
    // VAT Registration (USt-ID) Methods
    async getOrgVatRegistrations(orgUuid, onlyValid = true) {
        const params = onlyValid ? '' : '?all=true';
        return this.request(`/orgs/${orgUuid}/vat-registrations${params}`);
    }
    
    async getOrgAuditTrail(orgUuid, limit = 100) {
        return this.request(`/orgs/${orgUuid}/audit-trail?limit=${limit}`);
    }
    
    // Activity-Log API
    async getActivityLog(filters = {}, limit = 100, offset = 0) {
        const params = new URLSearchParams({ limit, offset, ...filters });
        return this.request(`/activity-log?${params.toString()}`);
    }
    
    async getUserActivities(userId, limit = 50, offset = 0) {
        return this.request(`/activity-log/user/${userId}?limit=${limit}&offset=${offset}`);
    }
    
    async getEntityActivities(entityType, entityUuid, limit = 50) {
        return this.request(`/activity-log/entity/${entityType}/${entityUuid}?limit=${limit}`);
    }
    
    async archiveOrg(orgUuid, userId = 'default_user') {
        return this.request(`/orgs/${orgUuid}/archive?user_id=${userId}`, {
            method: 'POST'
        });
    }
    
    async unarchiveOrg(orgUuid, userId = 'default_user') {
        return this.request(`/orgs/${orgUuid}/unarchive?user_id=${userId}`, {
            method: 'POST'
        });
    }
    
    async addOrgVatRegistration(orgUuid, data) {
        return this.request(`/orgs/${orgUuid}/vat-registrations`, {
            method: 'POST',
            body: data
        });
    }
    
    async updateOrgVatRegistration(orgUuid, vatUuid, data) {
        return this.request(`/orgs/${orgUuid}/vat-registrations/${vatUuid}`, {
            method: 'PUT',
            body: data
        });
    }
    
    async deleteOrgVatRegistration(orgUuid, vatUuid) {
        return this.request(`/orgs/${orgUuid}/vat-registrations/${vatUuid}`, {
            method: 'DELETE'
        });
    }
    
    async updateOrg(orgUuid, data) {
        const result = await this.request(`/orgs/${orgUuid}`, {
            method: 'PUT',
            body: data
        });
        
        // Track Zugriff beim Bearbeiten
        try {
            const user = await this.getCurrentUser();
            if (user && user.user_id) {
                await this.trackOrgAccess(orgUuid, user.user_id, 'recent');
            }
        } catch (error) {
            console.warn('Could not track org access:', error);
        }
        
        return result;
    }
    
    async trackOrgAccess(orgUuid, userId, accessType = 'recent') {
        return this.request(`/orgs/${orgUuid}/track-access?user_id=${userId}&access_type=${accessType}`, {
            method: 'POST'
        });
    }
    
    async getRecentOrgs(userId = 'default_user', limit = 10) {
        return this.request(`/orgs/recent?user_id=${userId}&limit=${limit}`);
    }
    
    async getIndustries(parentUuid = null, mainClassesOnly = false) {
        const params = new URLSearchParams();
        if (parentUuid) {
            params.append('parent_uuid', parentUuid);
        }
        if (mainClassesOnly) {
            params.append('main_classes_only', 'true');
        }
        const query = params.toString();
        return this.request(`/industries${query ? '?' + query : ''}`);
    }
    
    async getAccounts(userId = 'default_user') {
        return this.request(`/accounts?user_id=${userId}`);
    }
    
    async getOrgHealth(orgUuid) {
        return this.request(`/orgs/${orgUuid}/health`);
    }
    
    async getAvailableAccountOwners(withNames = true) {
        const endpoint = withNames ? '/orgs/owners?with_names=true' : '/orgs/owners';
        return this.request(endpoint);
    }
    
    // Admin - User Management
    async getAllUsers(includeInactive = false) {
        const endpoint = includeInactive ? '/users?include_inactive=true' : '/users';
        return this.request(endpoint);
    }
    
    async deactivateUser(userId) {
        return this.request(`/users/${userId}/deactivate`, {
            method: 'PUT'
        });
    }
    
    async activateUser(userId) {
        return this.request(`/users/${userId}/activate`, {
            method: 'PUT'
        });
    }
    
    async updateUser(userId, data) {
        return this.request(`/users/${userId}`, {
            method: 'PUT',
            body: data
        });
    }
    
    async getUser(userId, includeInactive = true) {
        const endpoint = includeInactive ? `/users/${userId}?include_inactive=true` : `/users/${userId}`;
        return this.request(endpoint);
    }
    
    async trackOrgAccess(orgUuid, userId = 'default_user', accessType = 'recent') {
        return this.request('/orgs/track', {
            method: 'POST',
            body: {
                org_uuid: orgUuid,
                user_id: userId,
                access_type: accessType
            }
        });
    }

    async createOrg(data) {
        return this.request('/orgs', {
            method: 'POST',
            body: data
        });
    }

    async getNextCustomerNumber() {
        return this.request('/orgs/next-customer-number');
    }

    // Auth
    async getCurrentUser() {
        return this.request('/auth/current');
    }

    async login(userId) {
        return this.request('/auth/login', {
            method: 'POST',
            body: { user_id: userId }
        });
    }

    async logout() {
        return this.request('/auth/logout', {
            method: 'POST'
        });
    }

    // Persons
    async getPersons() {
        return this.request('/persons');
    }

    async getPerson(personUuid) {
        return this.request(`/persons/${personUuid}`);
    }

    async createPerson(data) {
        return this.request('/persons', {
            method: 'POST',
            body: data
        });
    }

    // Tasks
    async getTasks(caseUuid = null) {
        const endpoint = caseUuid ? `/tasks?case_uuid=${caseUuid}` : '/tasks';
        return this.request(endpoint);
    }

    async createTask(data) {
        return this.request('/tasks', {
            method: 'POST',
            body: data
        });
    }

    async completeTask(taskUuid) {
        return this.request(`/tasks/${taskUuid}/complete`, {
            method: 'POST'
        });
    }

    // Monitoring
    async getMonitoringStatus() {
        return this.request('/monitoring/status');
    }

    async getOutboxMetrics() {
        return this.request('/monitoring/outbox');
    }

    async getCaseStatistics() {
        return this.request('/monitoring/cases');
    }

    async getSyncStatistics() {
        return this.request('/monitoring/sync');
    }

    async getRecentErrors() {
        return this.request('/monitoring/errors');
    }

    async getEventTypesDistribution() {
        return this.request('/monitoring/event-types');
    }

    async getDuplicateCheckResults() {
        return this.request('/monitoring/duplicates');
    }
    
    async getMonitoringActivityLog(limit = 100) {
        return this.request(`/monitoring/activity-log?limit=${limit}`);
    }

    async getClamAvStatus() {
        return this.request('/monitoring/clamav');
    }
    
    async getRecentPersons(userId = 'default_user', limit = 10) {
        return this.request(`/persons/recent?user_id=${userId}&limit=${limit}`);
    }
    
    async trackPersonAccess(personUuid, userId = 'default_user', accessType = 'recent') {
        return this.request('/persons/track', {
            method: 'POST',
            body: {
                person_uuid: personUuid,
                user_id: userId,
                access_type: accessType
            }
        });
    }
    
    /**
     * Zentrale API-Methoden für Access-Tracking (ersetzt getRecentOrgs/getRecentPersons)
     */
    async getRecentEntities(entityType, userId = 'default_user', limit = 10) {
        return this.request(`/access-tracking/${entityType}/recent?user_id=${userId}&limit=${limit}`);
    }
    
    async trackEntityAccess(entityType, entityUuid, userId = 'default_user', accessType = 'recent') {
        const uuidField = entityType === 'org' ? 'org_uuid' : 'person_uuid';
        return this.request(`/access-tracking/${entityType}/track`, {
            method: 'POST',
            body: {
                [uuidField]: entityUuid,
                user_id: userId,
                access_type: accessType
            }
        });
    }
    
    // Documents
    async uploadDocument(formData) {
        return this.request('/documents/upload', {
            method: 'POST',
            body: formData,
            headers: {} // FormData setzt Content-Type automatisch
        });
    }
    
    async getDocument(documentUuid) {
        return this.request(`/documents/${documentUuid}`);
    }
    
    async getEntityDocuments(entityType, entityUuid) {
        return this.request(`/documents/entity/${entityType}/${entityUuid}`);
    }
    
    async deleteDocument(documentUuid) {
        return this.request(`/documents/${documentUuid}`, {
            method: 'DELETE'
        });
    }
    
    async attachDocument(documentUuid, entityType, entityUuid, metadata = {}) {
        return this.request(`/documents/${documentUuid}/attach`, {
            method: 'POST',
            body: {
                entity_type: entityType,
                entity_uuid: entityUuid,
                ...metadata
            }
        });
    }
}

// Export für globale Verwendung
window.API = new TOM3API();

