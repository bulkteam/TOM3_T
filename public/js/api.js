/**
 * TOM3 - API Client
 */

// API Base URL - relativ zum aktuellen Pfad
const API_BASE = (() => {
    const path = window.location.pathname;
    // Entferne index.html oder trailing slash
    const base = path.replace(/\/index\.html$/, '').replace(/\/$/, '');
    return base + '/api';
})();

class TOM3API {
    constructor() {
        this.baseUrl = API_BASE;
    }

    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        const config = {
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        };

        if (config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }

        try {
            const response = await fetch(url, config);
            
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
    
    // Addresses
    async getOrgAddresses(orgUuid, addressType = null) {
        const endpoint = addressType ? `/orgs/${orgUuid}/addresses?address_type=${addressType}` : `/orgs/${orgUuid}/addresses`;
        return this.request(endpoint);
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
    
    // VAT Registration (USt-ID) Methods
    async getOrgVatRegistrations(orgUuid, onlyValid = true) {
        const params = onlyValid ? '' : '?all=true';
        return this.request(`/orgs/${orgUuid}/vat-registrations${params}`);
    }
    
    async getOrgAuditTrail(orgUuid, limit = 100) {
        return this.request(`/orgs/${orgUuid}/audit-trail?limit=${limit}`);
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
            await this.trackOrgAccess(orgUuid, 'default_user', 'recent');
        } catch (error) {
            console.warn('Could not track org access:', error);
        }
        
        return result;
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
    
    async getAvailableAccountOwners() {
        return this.request('/orgs/owners');
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
}

// Export für globale Verwendung
window.API = new TOM3API();

