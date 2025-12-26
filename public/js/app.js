/**
 * TOM3 - Main Application
 */

class TOM3App {
    constructor() {
        // Lade zuletzt genutzte Seite aus localStorage oder verwende Dashboard als Default
        this.currentPage = this.getStoredPage() || 'dashboard';
        this.init();
    }

    init() {
        this.setupNavigation();
        this.setupEventListeners();
        this.initModals();
        
        // Prüfe URL-Hash für direkte Links
        const hash = window.location.hash.replace('#', '');
        if (hash) {
            this.currentPage = hash;
        }
        
        // Navigiere zur aktuellen Seite (wird automatisch geladen)
        this.navigateTo(this.currentPage, false); // false = kein localStorage Update beim Init
    }
    
    getStoredPage() {
        try {
            return localStorage.getItem('tom3_last_page') || null;
        } catch (e) {
            return null;
        }
    }
    
    storePage(page) {
        try {
            localStorage.setItem('tom3_last_page', page);
            // Aktualisiere auch URL-Hash für direkte Links
            window.location.hash = page;
        } catch (e) {
            console.warn('Could not store page in localStorage:', e);
        }
    }
    
    initModals() {
        // Schließe Modal beim Klick auf X oder außerhalb
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.target.closest('.modal');
                if (modal) {
                    modal.classList.remove('active');
                    
                    // Wenn das Org-Detail-Modal geschlossen wird, räume Suchergebnisse auf
                    if (modal.id === 'modal-org-detail') {
                        this.clearSearchResults();
                    }
                }
            });
        });
        
        // Schließe Modal auch bei Klick außerhalb
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                    if (modal.id === 'modal-org-detail') {
                        this.clearSearchResults();
                    }
                }
            });
        });
        
        // Schließe Modal beim Klick außerhalb
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    }

    setupNavigation() {
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = link.getAttribute('data-page');
                if (page) {
                    this.navigateTo(page);
                }
            });
        });
        
        // Behandle Browser-Zurück/Vor-Buttons (Hash-Änderungen)
        window.addEventListener('hashchange', () => {
            const hash = window.location.hash.replace('#', '');
            if (hash && hash !== this.currentPage) {
                this.navigateTo(hash);
            }
        });
    }

    navigateTo(page, storePage = true) {
        // Update navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        const navLink = document.querySelector(`[data-page="${page}"]`);
        if (navLink) {
            navLink.classList.add('active');
        }

        // Update pages
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        const targetPage = document.getElementById(`page-${page}`);
        if (targetPage) {
            targetPage.classList.add('active');
        } else {
            console.warn(`Page "${page}" not found, falling back to dashboard`);
            page = 'dashboard';
            document.getElementById('page-dashboard').classList.add('active');
            const dashboardLink = document.querySelector('[data-page="dashboard"]');
            if (dashboardLink) dashboardLink.classList.add('active');
        }

        this.currentPage = page;
        
        // Speichere die aktuelle Seite im localStorage
        if (storePage) {
            this.storePage(page);
        }

        // Load page data
        switch(page) {
            case 'dashboard':
                this.loadDashboard();
                break;
            case 'cases':
                this.loadCases();
                break;
            case 'projects':
                this.loadProjects();
                break;
            case 'orgs':
                this.initOrgSearchPage();
                break;
            case 'accounts':
                this.loadAccounts();
                break;
            case 'persons':
                this.loadPersons();
                break;
        }
    }

    setupEventListeners() {
        // Create buttons
        document.getElementById('btn-create-case')?.addEventListener('click', () => this.showCreateCaseModal());
        document.getElementById('btn-create-project')?.addEventListener('click', () => this.showCreateProjectModal());
        document.getElementById('btn-create-org')?.addEventListener('click', () => this.showCreateOrgModal());
        document.getElementById('btn-create-person')?.addEventListener('click', () => this.showCreatePersonModal());

        // Filters
        document.getElementById('filter-status')?.addEventListener('change', () => this.loadCases());
        document.getElementById('filter-engine')?.addEventListener('change', () => this.loadCases());
        document.getElementById('filter-search')?.addEventListener('input', () => this.loadCases());

        // Modal close
        document.querySelector('.modal-close')?.addEventListener('click', () => this.closeModal());
        document.getElementById('modal-case-detail')?.addEventListener('click', (e) => {
            if (e.target.id === 'modal-case-detail') {
                this.closeModal();
            }
        });
    }

    async loadDashboard() {
        try {
            const cases = await window.API.getCases();
            const projects = await window.API.getProjects();

            // Sicherstellen, dass wir Arrays haben (nicht null)
            const casesArray = Array.isArray(cases) ? cases : [];
            const projectsArray = Array.isArray(projects) ? projects : [];

            // Calculate stats
            const totalCases = casesArray.length || 0;
            const activeCases = casesArray.filter(c => c.status === 'in_bearbeitung').length;
            const waitingCases = casesArray.filter(c => c.status === 'wartend_intern' || c.status === 'wartend_extern').length;
            const totalProjects = projectsArray.length || 0;

            // Update stats
            document.getElementById('stat-cases-total').textContent = totalCases;
            document.getElementById('stat-cases-active').textContent = activeCases;
            document.getElementById('stat-cases-waiting').textContent = waitingCases;
            document.getElementById('stat-projects').textContent = totalProjects;

            // Show recent cases
            const recentCases = casesArray.slice(0, 5);
            this.renderCasesList(recentCases, 'dashboard-recent-cases');
        } catch (error) {
            console.error('Error loading dashboard:', error);
            const errorMsg = error.message || 'Fehler beim Laden des Dashboards';
            this.showError(`Fehler beim Laden des Dashboards: ${errorMsg}`);
        }
    }

    async loadCases() {
        const container = document.getElementById('cases-list');
        container.innerHTML = '<div class="loading">Lade Vorgänge...</div>';

        try {
            const status = document.getElementById('filter-status')?.value || '';
            const engine = document.getElementById('filter-engine')?.value || '';
            const search = document.getElementById('filter-search')?.value || '';

            const cases = await window.API.getCases({ status, engine, search });
            this.renderCasesList(cases, 'cases-list');
        } catch (error) {
            console.error('Error loading cases:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden der Vorgänge</div>';
        }
    }

    renderCasesList(cases, containerId) {
        const container = document.getElementById(containerId);
        
        if (!cases || cases.length === 0) {
            container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📋</div><p>Keine Vorgänge gefunden</p></div>';
            return;
        }

        container.innerHTML = cases.map(caseItem => `
            <div class="case-card" data-case-uuid="${caseItem.case_uuid}" onclick="app.showCaseDetail('${caseItem.case_uuid}')">
                <div class="case-header">
                    <div>
                        <div class="case-title">${this.escapeHtml(caseItem.title || 'Unbenannter Vorgang')}</div>
                        <div class="case-meta">
                            <span class="status-badge status-${caseItem.status}">${this.formatStatus(caseItem.status)}</span>
                            <span class="engine-badge">${this.formatEngine(caseItem.engine)}</span>
                            ${caseItem.priority ? `<span>Priorität: ${caseItem.priority}</span>` : ''}
                        </div>
                    </div>
                </div>
                ${caseItem.description ? `<p style="color: var(--text-light); margin-top: 0.5rem;">${this.escapeHtml(caseItem.description.substring(0, 100))}${caseItem.description.length > 100 ? '...' : ''}</p>` : ''}
            </div>
        `).join('');
    }

    async showCaseDetail(caseUuid) {
        try {
            const caseItem = await window.API.getCase(caseUuid);
            const blockers = await window.API.getCaseBlockers(caseUuid);

            const modal = document.getElementById('modal-case-detail');
            document.getElementById('modal-case-title').textContent = caseItem.title || 'Vorgang Details';
            
            document.getElementById('modal-case-body').innerHTML = `
                <div class="modal-section">
                    <h4>Status</h4>
                    <div class="modal-field">
                        <span class="status-badge status-${caseItem.status}">${this.formatStatus(caseItem.status)}</span>
                        <span class="engine-badge" style="margin-left: 0.5rem;">${this.formatEngine(caseItem.engine)}</span>
                    </div>
                </div>
                <div class="modal-section">
                    <h4>Beschreibung</h4>
                    <div class="modal-field">
                        <p>${this.escapeHtml(caseItem.description || 'Keine Beschreibung')}</p>
                    </div>
                </div>
                ${blockers && blockers.length > 0 ? `
                <div class="modal-section">
                    <h4>Blocker</h4>
                    <div class="modal-field">
                        <ul>
                            ${blockers.map(b => `<li>${this.escapeHtml(b.description || b.requirement_type)}</li>`).join('')}
                        </ul>
                    </div>
                </div>
                ` : ''}
                <div class="modal-actions">
                    <button class="btn btn-secondary" onclick="app.handoverCase('${caseUuid}')">Übergabe</button>
                    <button class="btn btn-danger" onclick="app.returnCase('${caseUuid}')">Rückläufer</button>
                </div>
            `;

            modal.classList.add('active');
        } catch (error) {
            console.error('Error loading case detail:', error);
            this.showError('Fehler beim Laden der Vorgangsdetails');
        }
    }

    async handoverCase(caseUuid) {
        const targetRole = prompt('Ziel-Rolle:');
        if (!targetRole) return;

        try {
            await window.API.handover(caseUuid, { target_role: targetRole });
            this.closeModal();
            this.loadCases();
            this.showSuccess('Vorgang erfolgreich übergeben');
        } catch (error) {
            this.showError('Fehler bei der Übergabe');
        }
    }

    async returnCase(caseUuid) {
        const reason = prompt('Grund für Rückläufer:');
        if (!reason) return;

        try {
            await window.API.returnCase(caseUuid, { reason });
            this.closeModal();
            this.loadCases();
            this.showSuccess('Vorgang als Rückläufer markiert');
        } catch (error) {
            this.showError('Fehler beim Rückläufer');
        }
    }

    async loadProjects() {
        const container = document.getElementById('projects-list');
        container.innerHTML = '<div class="loading">Lade Projekte...</div>';

        try {
            const projects = await window.API.getProjects();
            
            // Sicherstellen, dass wir ein Array haben
            const projectsArray = Array.isArray(projects) ? projects : [];
            
            if (projectsArray.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📁</div><p>Keine Projekte gefunden</p></div>';
                return;
            }

            container.innerHTML = projectsArray.map(project => `
                <div class="project-card">
                    <div class="case-header">
                        <div>
                            <div class="case-title">${this.escapeHtml(project.name || 'Unbenanntes Projekt')}</div>
                            <div class="case-meta">
                                <span class="status-badge status-${project.status}">${this.formatProjectStatus(project.status)}</span>
                                ${project.priority ? `<span>Priorität: ${project.priority}</span>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        } catch (error) {
            console.error('Error loading projects:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden der Projekte</div>';
        }
    }

    async initOrgSearchPage() {
        // Lade "Zuletzt verwendet"
        await this.loadRecentOrgs();
        
        // Initialisiere Suche
        this.initOrgSearch();
        
        // Initialisiere Filter
        this.initOrgFilters();
        
        // Lade Industries für Filter
        await this.loadIndustries();
    }
    
    async loadRecentOrgs() {
        const container = document.getElementById('org-recent-list');
        const section = document.getElementById('org-recent-section');
        if (!container || !section) {
            console.warn('Recent orgs container or section not found');
            return;
        }
        
        try {
            const recent = await window.API.getRecentOrgs('default_user', 10);
            
            if (!recent || recent.length === 0) {
                // Zeige Sektion auch wenn leer (mit Hinweis)
                section.style.display = 'block';
                container.innerHTML = '<div class="empty-state-small">Noch keine zuletzt verwendeten Organisationen. Suche nach einer Organisation, um sie hier zu sehen.</div>';
                return;
            }
            
            section.style.display = 'block';
            container.innerHTML = recent.map(org => {
                const isArchived = org.archived_at !== null && org.archived_at !== undefined && org.archived_at !== '';
                return `
                <div class="org-recent-item ${isArchived ? 'org-archived-item' : ''}" data-org-uuid="${org.org_uuid}">
                    <div class="org-recent-item-content">
                        <div>
                            <div class="org-recent-item-name">
                                ${this.escapeHtml(org.name || 'Unbenannte Organisation')}
                                ${isArchived ? ' <span class="org-archive-badge-small">📦 Archiv</span>' : ''}
                            </div>
                            <div class="org-recent-item-location">${this.formatOrgLocation(org)}</div>
                        </div>
                        <div class="org-result-card-menu">
                            <button class="org-card-menu-btn" data-org-uuid="${org.org_uuid}" onclick="app.showOrgCardMenu(event, '${org.org_uuid}')">
                                <span>⋮</span>
                            </button>
                            <div class="org-card-menu-dropdown" id="menu-${org.org_uuid}" style="display: none;">
                                <button onclick="app.showAuditTrail('${org.org_uuid}')">Audit-Trail anzeigen</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            }).join('');
            
            // Klick-Handler (nur auf Item, nicht auf Menü)
            container.querySelectorAll('.org-recent-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    // Ignoriere Klicks auf das Menü
                    if (e.target.closest('.org-result-card-menu')) {
                        return;
                    }
                    const uuid = item.dataset.orgUuid;
                    this.selectOrg(uuid);
                });
            });
        } catch (error) {
            console.error('Error loading recent orgs:', error);
            section.style.display = 'block';
            container.innerHTML = '<div class="empty-state-small">Fehler beim Laden der zuletzt verwendeten Organisationen.</div>';
        }
    }
    
    async loadIndustries() {
        try {
            const industries = await window.API.getIndustries();
            const container = document.getElementById('filter-industries');
            if (!container) return;
            
            container.innerHTML = industries.map(ind => `
                <label>
                    <input type="checkbox" value="${ind.industry_uuid}" data-filter="industry">
                    ${this.escapeHtml(ind.name)}
                </label>
            `).join('');
        } catch (error) {
            console.error('Error loading industries:', error);
        }
    }

    initOrgSearch() {
        const searchInput = document.getElementById('org-search-input');
        const resultsContainer = document.getElementById('org-search-results');
        const orgsList = document.getElementById('orgs-list');
        const filterKind = document.getElementById('filter-org-kind');
        let searchTimeout = null;
        let selectedIndex = -1;
        
        if (!searchInput) return;
        
        // Autocomplete-Suche
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.trim();
            
            // Verstecke "Zuletzt verwendet" wenn Suche aktiv ist
            const recentSection = document.getElementById('org-recent-section');
            if (recentSection) {
                if (query.length >= 2) {
                    recentSection.style.display = 'none';
                } else {
                    // Zeige wieder, wenn Suche leer ist
                    recentSection.style.display = 'block';
                }
            }
            
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                resultsContainer.innerHTML = '';
                resultsContainer.style.display = 'none';
                selectedIndex = -1;
                // Verstecke Ergebnisliste wenn keine Suche
                const orgsList = document.getElementById('orgs-list');
                if (orgsList) {
                    orgsList.innerHTML = '';
                }
                // Zeige "Zuletzt verwendet" wieder wenn Suche leer ist
                if (recentSection) {
                    recentSection.style.display = 'block';
                    this.loadRecentOrgs();
                }
                return;
            }
            
            searchTimeout = setTimeout(async () => {
                try {
                    const filters = this.getActiveFilters();
                    // Autocomplete-Suche zeigt standardmäßig auch archivierte Organisationen
                    filters.include_archived = true;
                    const results = await window.API.searchOrgs(query, filters, 20);
                    
                    // Gruppiere Ergebnisse nach Relevanz
                    const exact = results.filter(o => 
                        o.name.toLowerCase() === query.toLowerCase() ||
                        o.name.toLowerCase().startsWith(query.toLowerCase())
                    );
                    const similar = results.filter(o => !exact.includes(o));
                    
                    if (results.length === 0) {
                        resultsContainer.innerHTML = '';
                        resultsContainer.style.display = 'none';
                        await this.showEmptyState(query);
                        return;
                    }
                    
                    // Zeige Autocomplete-Dropdown
                    let html = '';
                    if (exact.length > 0) {
                        html += '<div class="search-result-group">';
                        exact.forEach((org, index) => {
                            html += this.renderSearchResultItem(org, index === selectedIndex);
                        });
                        html += '</div>';
                    }
                    if (similar.length > 0) {
                        html += '<div class="search-result-group">';
                        if (exact.length > 0) {
                            html += '<div class="search-result-group-title">Ähnliche Treffer</div>';
                        }
                        similar.forEach((org, index) => {
                            html += this.renderSearchResultItem(org, (exact.length + index) === selectedIndex);
                        });
                        html += '</div>';
                    }
                    
                    resultsContainer.innerHTML = html;
                    resultsContainer.style.display = 'block';
                    
                    // Klick-Handler
                    resultsContainer.querySelectorAll('.search-result-item').forEach(item => {
                        item.addEventListener('click', () => {
                            const uuid = item.dataset.orgUuid;
                            this.selectOrg(uuid);
                            searchInput.value = '';
                            resultsContainer.innerHTML = '';
                            resultsContainer.style.display = 'none';
                        });
                    });
                    
                    // Lade auch Ergebnisliste (wenn Enter gedrückt oder Filter aktiv)
                    if (filters && Object.keys(filters).length > 0) {
                        this.loadOrgResults(query, filters);
                    }
                    
                } catch (error) {
                    console.error('Search error:', error);
                    resultsContainer.innerHTML = '<div class="search-error">Fehler bei der Suche</div>';
                    resultsContainer.style.display = 'block';
                }
            }, 300); // Debounce 300ms
        });
        
        // Tastatur-Navigation
        searchInput.addEventListener('keydown', (e) => {
            const items = resultsContainer.querySelectorAll('.search-result-item');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                this.updateSearchSelection(items, selectedIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                this.updateSearchSelection(items, selectedIndex);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedIndex >= 0) {
                    const selected = items[selectedIndex];
                    if (selected) {
                        const uuid = selected.dataset.orgUuid;
                        this.selectOrg(uuid);
                        searchInput.value = '';
                        resultsContainer.innerHTML = '';
                        resultsContainer.style.display = 'none';
                    }
                } else {
                    // Enter ohne Auswahl = Suche ausführen
                    const query = searchInput.value.trim();
                    const filters = this.getActiveFilters();
                    // Autocomplete-Suche zeigt standardmäßig auch archivierte Organisationen
                    filters.include_archived = true;
                    this.loadOrgResults(query, filters);
                    resultsContainer.style.display = 'none';
                }
            } else if (e.key === 'Escape') {
                resultsContainer.innerHTML = '';
                resultsContainer.style.display = 'none';
                selectedIndex = -1;
            }
        });
        
        // Klick außerhalb schließt Dropdown
        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                resultsContainer.style.display = 'none';
            }
        });
    }
    
    initOrgFilters() {
        const toggleBtn = document.getElementById('btn-toggle-filters');
        const filterPanel = document.getElementById('org-filters-panel');
        const resetBtn = document.getElementById('btn-reset-filters');
        
        if (toggleBtn && filterPanel) {
            toggleBtn.addEventListener('click', () => {
                const isVisible = filterPanel.style.display !== 'none';
                filterPanel.style.display = isVisible ? 'none' : 'block';
                toggleBtn.classList.toggle('active', !isVisible);
                toggleBtn.querySelector('.filter-text').textContent = isVisible ? 'Filter einblenden' : 'Filter ausblenden';
                toggleBtn.querySelector('.filter-arrow').textContent = isVisible ? '▾' : '▴';
            });
        }
        
        // Filter live anwenden
        const filterInputs = document.querySelectorAll('[data-filter]');
        filterInputs.forEach(input => {
            input.addEventListener('change', () => {
                const query = document.getElementById('org-search-input')?.value.trim() || '';
                const filters = this.getActiveFilters();
                this.loadOrgResults(query, filters);
            });
        });
        
        // Reset Button
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                this.resetFilters();
                const query = document.getElementById('org-search-input')?.value.trim() || '';
                this.loadOrgResults(query, {});
            });
        }
    }
    
    getActiveFilters() {
        const filters = {};
        
        // Industries
        const industries = Array.from(document.querySelectorAll('#filter-industries input:checked'))
            .map(cb => cb.value);
        if (industries.length > 0) {
            filters.industry = industries[0]; // Erste für jetzt
        }
        
        // Status
        const statuses = Array.from(document.querySelectorAll('#filter-status input:checked'))
            .map(cb => cb.value);
        if (statuses.length > 0) {
            filters.status = statuses[0];
        }
        
        // Tiers
        const tiers = Array.from(document.querySelectorAll('#filter-tiers input:checked'))
            .map(cb => cb.value);
        if (tiers.length > 0) {
            filters.tier = tiers[0];
        }
        
        // Strategic
        if (tiers.includes('S')) {
            filters.strategic = true;
        }
        
        // Org Kind
        const orgKinds = Array.from(document.querySelectorAll('#filter-org-kind input:checked'))
            .map(cb => cb.value);
        if (orgKinds.length > 0) {
            filters.org_kind = orgKinds[0];
        }
        
        // City
        const city = document.getElementById('filter-city')?.value.trim();
        if (city) {
            filters.city = city;
        }
        
        // Revenue
        const revenueMin = document.getElementById('filter-revenue-min')?.value;
        if (revenueMin) {
            filters.revenue_min = parseFloat(revenueMin) * 1000000; // Mio zu absoluter Wert
        }
        
        // Employees
        const employees = Array.from(document.querySelectorAll('#filter-employees input:checked'))
            .map(cb => cb.value);
        if (employees.length > 0) {
            const ranges = employees.map(e => {
                if (e === '0-10') return { min: 0, max: 10 };
                if (e === '10-50') return { min: 10, max: 50 };
                if (e === '50-250') return { min: 50, max: 250 };
                if (e === '250+') return { min: 250, max: 999999 };
            });
            filters.employees_min = Math.min(...ranges.map(r => r.min));
        }
        
        // Archivierte einbeziehen
        const includeArchived = document.getElementById('filter-include-archived')?.checked || false;
        if (includeArchived) {
            filters.include_archived = true;
        }
        
        return filters;
    }
    
    resetFilters() {
        document.querySelectorAll('[data-filter]').forEach(cb => cb.checked = false);
        document.getElementById('filter-city').value = '';
        document.getElementById('filter-radius').value = '';
        document.getElementById('filter-revenue-min').value = '';
        document.getElementById('filter-revenue-max').value = '';
    }
    
    renderSearchResultItem(org, isSelected) {
        const cities = org.cities ? org.cities.split(',')[0] : '';
        const industries = org.industries ? org.industries.split(',')[0] : '';
        const revenue = org.last_revenue ? `~${Math.round(org.last_revenue / 1000000)} Mio €` : '';
        const isArchived = org.archived_at !== null && org.archived_at !== undefined && org.archived_at !== '';
        
        return `
            <div class="search-result-item ${isSelected ? 'selected' : ''} ${isArchived ? 'search-result-archived' : ''}" 
                 data-org-uuid="${org.org_uuid}">
                <div class="search-result-left">
                    <div class="search-result-name">
                        <span class="search-result-check">✔</span>
                        ${this.escapeHtml(org.name || 'Unbenannte Organisation')}
                        ${isArchived ? '<span class="search-result-archive-badge">📦 Archiv</span>' : ''}
                    </div>
                    <div class="search-result-meta">
                        ${cities ? `<span class="search-result-meta-item">📍 ${this.escapeHtml(cities)}</span>` : ''}
                        ${industries ? `<span class="search-result-meta-item">🏭 ${this.escapeHtml(industries)}</span>` : ''}
                        ${revenue ? `<span class="search-result-meta-item">💰 ${revenue}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
    
    async loadOrgResults(query = '', filters = {}) {
        const container = document.getElementById('orgs-list');
        const emptyState = document.getElementById('org-empty-state');
        const recentSection = document.getElementById('org-recent-section');
        
        if (!container) return;
        
        // Verstecke "Zuletzt verwendet" sofort, wenn eine Suche gestartet wird
        if (recentSection && (query || Object.keys(filters).length > 0)) {
            recentSection.style.display = 'none';
        }
        
        container.innerHTML = '<div class="loading">Suche...</div>';
        emptyState.style.display = 'none';
        
        try {
            // Autocomplete-Suche zeigt standardmäßig auch archivierte Organisationen
            if (filters.include_archived === undefined) {
                filters.include_archived = true;
            }
            const results = await window.API.searchOrgs(query, filters, 50);
            
            if (!results || results.length === 0) {
                container.innerHTML = '';
                // Zeige "Zuletzt verwendet" wieder, wenn keine Ergebnisse
                if (recentSection && !query) {
                    recentSection.style.display = 'block';
                    await this.loadRecentOrgs();
                }
                await this.showEmptyState(query);
                return;
            }
            
            // Verstecke "Zuletzt verwendet" wenn Ergebnisse da sind
            if (recentSection) {
                recentSection.style.display = 'none';
            }
            
            container.innerHTML = results.map(org => this.renderOrgResultCard(org)).join('');
            
            // Klick-Handler (nur auf Karte, nicht auf Menü)
            container.querySelectorAll('.org-result-card').forEach(card => {
                card.addEventListener('click', (e) => {
                    // Ignoriere Klicks auf das Menü
                    if (e.target.closest('.org-result-card-menu')) {
                        return;
                    }
                    const uuid = card.dataset.orgUuid;
                    this.selectOrg(uuid);
                });
            });
            
        } catch (error) {
            console.error('Error loading org results:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden</div>';
        }
    }
    
    renderOrgResultCard(org) {
        const cities = org.cities ? org.cities.split(',')[0] : '';
        const industries = org.industries ? org.industries.split(',')[0] : '';
        const revenue = org.last_revenue ? `~${Math.round(org.last_revenue / 1000000)} Mio €` : '';
        const status = org.status || 'lead';
        const tier = org.current_tier || '';
        const isStrategic = org.is_strategic == 1;
        const isArchived = org.archived_at !== null && org.archived_at !== undefined && org.archived_at !== '';
        
        return `
            <div class="org-result-card status-${status} ${isArchived ? 'org-archived-card' : ''}" data-org-uuid="${org.org_uuid}">
                <div class="org-result-card-content">
                    <div class="org-result-card-header">
                        <div class="org-result-card-title">
                            ${this.escapeHtml(org.name || 'Unbenannte Organisation')}
                            ${isArchived ? '<span class="org-archive-badge">📦 Archiv</span>' : ''}
                            ${tier ? `<span class="org-result-badge badge-tier-${tier.toLowerCase()}">${tier}</span>` : ''}
                            ${isStrategic ? `<span class="org-result-badge badge-strategic">S</span>` : ''}
                        </div>
                        <div class="org-result-card-menu">
                            <button class="org-card-menu-btn" data-org-uuid="${org.org_uuid}" onclick="app.showOrgCardMenu(event, '${org.org_uuid}')">
                                <span>⋮</span>
                            </button>
                            <div class="org-card-menu-dropdown" id="menu-${org.org_uuid}" style="display: none;">
                                <button onclick="app.showAuditTrail('${org.org_uuid}')">Audit-Trail anzeigen</button>
                            </div>
                        </div>
                    </div>
                    <div class="org-result-card-meta">
                        ${cities ? `<span class="org-result-card-meta-item">📍 ${this.escapeHtml(cities)}</span>` : ''}
                        ${industries ? `<span class="org-result-card-meta-item">🏭 ${this.escapeHtml(industries)}</span>` : ''}
                        ${revenue ? `<span class="org-result-card-meta-item">💰 ${revenue}</span>` : ''}
                    </div>
                    <div class="org-result-card-footer">
                        <span>Status: ${this.formatOrgStatus(status)}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    async showEmptyState(query) {
        const emptyState = document.getElementById('org-empty-state');
        const suggestions = document.getElementById('org-suggestions');
        
        if (!emptyState) return;
        
        emptyState.style.display = 'block';
        
        // "Meintest du...?" - ähnliche Organisationen
        if (query && query.length >= 2) {
            try {
                const similar = await window.API.searchOrgs(query, {}, 5);
                if (similar && similar.length > 0) {
                    suggestions.innerHTML = `
                        <h4>Meintest du vielleicht:</h4>
                        ${similar.map(org => `
                            <div class="org-suggestion-item" data-org-uuid="${org.org_uuid}">
                                ${this.escapeHtml(org.name)} ${org.cities ? `(${org.cities.split(',')[0]})` : ''}
                            </div>
                        `).join('')}
                    `;
                    
                    // Klick-Handler für Vorschläge
                    suggestions.querySelectorAll('.org-suggestion-item').forEach(item => {
                        item.addEventListener('click', () => {
                            const uuid = item.dataset.orgUuid;
                            this.selectOrg(uuid);
                        });
                    });
                } else {
                    suggestions.innerHTML = '';
                }
            } catch (error) {
                console.error('Error loading suggestions:', error);
                suggestions.innerHTML = '';
            }
        } else {
            suggestions.innerHTML = '';
        }
    }
    
    async selectOrg(orgUuid) {
        // Track Zugriff
        try {
            await window.API.trackOrgAccess(orgUuid, 'default_user', 'recent');
        } catch (error) {
            console.warn('Could not track org access:', error);
        }
        
        // Öffne Stammdatenblatt (Modal)
        await this.showOrgDetail(orgUuid);
        
        // Aktualisiere "Zuletzt verwendet"
        await this.loadRecentOrgs();
    }
    
    async showOrgDetail(orgUuid) {
        const modal = document.getElementById('modal-org-detail');
        const modalTitle = document.getElementById('modal-org-title');
        const modalBody = document.getElementById('modal-org-body');
        
        // Speichere orgUuid für späteren Zugriff
        window.currentOrgUuid = orgUuid;
        if (modal) {
            modal.dataset.orgUuid = orgUuid;
        }
        
        modalTitle.textContent = 'Lade Organisation...';
        modalBody.innerHTML = '<div class="loading">Lade Details...</div>';
        modal.classList.add('active');
        
        try {
            // Lade vollständige Org-Details (mit Adressen, Relationen, etc.)
            const org = await window.API.getOrgDetails(orgUuid);
            
            if (!org) {
                modalTitle.textContent = 'Organisation nicht gefunden';
                modalBody.innerHTML = '<div class="empty-state">Die angefragte Organisation existiert nicht.</div>';
                return;
            }
            
            // Lade verfügbare Account Owners
            let availableOwners = [];
            try {
                availableOwners = await window.API.getAvailableAccountOwners();
            } catch (error) {
                console.warn('Could not load available owners:', error);
            }
            
            modalTitle.textContent = this.escapeHtml(org.name || 'Organisation Details');
            
            // Rendere Stammdatenblatt
            modalBody.innerHTML = this.renderOrgDetail(org, availableOwners);
            
            // Lade Industries für Dropdowns
            await this.loadIndustryDropdowns(org);
            
            // Initialisiere Event-Handler für Bearbeitung
            this.initOrgDetailHandlers(orgUuid);
            
        } catch (error) {
            console.error('Error loading org detail:', error);
            modalTitle.textContent = 'Fehler';
            modalBody.innerHTML = `<div class="error-message">Fehler beim Laden der Organisationsdetails: ${error.message}</div>`;
        }
    }
    
    renderOrgDetail(org, availableOwners = []) {
        const isEditMode = false; // Wird später per Toggle umgeschaltet
        const health = org.health || { status: 'unknown', reasons: [] };
        const healthIcon = health.status === 'red' ? '🔴' : health.status === 'yellow' ? '🟡' : '🟢';
        const healthText = health.status === 'red' ? 'Aufmerksamkeit nötig' : health.status === 'yellow' ? 'Risiken' : 'Alles OK';
        
        // Erstelle Options für Account Owner Select
        // Sicherstellen, dass availableOwners ein Array ist
        const ownersList = Array.isArray(availableOwners) ? availableOwners : [];
        let ownerOptions = '';
        
        if (ownersList.length > 0) {
            ownerOptions = ownersList.map(owner => {
                const userId = typeof owner === 'string' ? owner : owner;
                return `<option value="${this.escapeHtml(userId)}" ${org.account_owner_user_id === userId ? 'selected' : ''}>${this.escapeHtml(userId)}</option>`;
            }).join('');
        }
        
        // Füge aktuellen Owner hinzu, falls nicht in Liste
        const currentOwner = org.account_owner_user_id;
        if (currentOwner && !ownersList.includes(currentOwner)) {
            ownerOptions = `<option value="${this.escapeHtml(currentOwner)}" selected>${this.escapeHtml(currentOwner)}</option>` + ownerOptions;
        }
        
        // Prüfe ob Organisation archiviert ist
        const isArchived = org.archived_at !== null && org.archived_at !== undefined && org.archived_at !== '';
        
        return `
            <div class="org-detail-container ${isArchived ? 'org-archived' : ''}">
                <!-- Account-Verantwortung & Health -->
                <div class="org-detail-section org-account-section">
                    <div class="org-detail-section-header">
                        <h4>Account-Verantwortung</h4>
                        <button class="btn btn-sm btn-secondary" id="btn-edit-account">Bearbeiten</button>
                    </div>
                    <div class="org-detail-fields" id="org-account-fields">
                        <div class="org-detail-field">
                            <label>Account Owner <span class="text-warning">${(org.status === 'prospect' || org.status === 'customer') ? '*' : ''}</span></label>
                            <div class="org-detail-value" id="org-field-account_owner">
                                ${org.account_owner_user_id ? this.escapeHtml(org.account_owner_user_id) : '<span class="text-warning">Nicht zugeordnet</span>'}
                                ${org.account_owner_since ? ` <span class="text-light">(seit ${new Date(org.account_owner_since).toLocaleDateString('de-DE')})</span>` : ''}
                            </div>
                            <select class="org-detail-input" id="org-input-account_owner" style="display: none;">
                                <option value="">-- Kein Owner --</option>
                                ${ownerOptions}
                            </select>
                            ${currentOwner && !ownersList.includes(currentOwner) ? `
                                <div class="org-detail-warning" style="margin-top: 0.5rem; padding: 0.5rem; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; font-size: 0.875rem;">
                                    ⚠️ Der aktuelle Account Owner "${this.escapeHtml(currentOwner)}" ist nicht in der User-Config definiert. 
                                    Bitte füge diesen User zu <code>config/users.php</code> hinzu.
                                </div>
                            ` : ''}
                        </div>
                        <div class="org-detail-field">
                            <label>Kundengesundheit</label>
                            <div class="org-detail-value" id="org-field-health">
                                <span class="health-indicator health-${health.status}">
                                    ${healthIcon} ${healthText}
                                </span>
                                ${health.reasons && health.reasons.length > 0 ? `
                                    <div class="health-reasons" style="margin-top: 0.5rem;">
                                        ${health.reasons.map(r => this.renderHealthReason(r)).join('')}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="org-detail-actions" id="org-account-actions" style="display: none;">
                        <button class="btn btn-primary" id="btn-save-account">Speichern</button>
                        <button class="btn btn-secondary" id="btn-cancel-account">Abbrechen</button>
                    </div>
                </div>
                
                <!-- Grunddaten -->
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Grunddaten</h4>
                        <button class="btn btn-sm btn-secondary" id="btn-edit-basic">Bearbeiten</button>
                    </div>
                    <div class="org-detail-fields" id="org-basic-fields">
                        <div class="org-detail-field">
                            <label>Name</label>
                            <div class="org-detail-value" id="org-field-name">${this.escapeHtml(org.name || '')}</div>
                            <input type="text" class="org-detail-input" id="org-input-name" value="${this.escapeHtml(org.name || '')}" style="display: none;">
                        </div>
                        <div class="org-detail-field">
                            <label>Typ</label>
                            <div class="org-detail-value" id="org-field-org_kind">${this.formatOrgKind(org.org_kind || '')}</div>
                            <select class="org-detail-input" id="org-input-org_kind" style="display: none;">
                                <option value="customer" ${org.org_kind === 'customer' ? 'selected' : ''}>Kunde</option>
                                <option value="supplier" ${org.org_kind === 'supplier' ? 'selected' : ''}>Lieferant</option>
                                <option value="consultant" ${org.org_kind === 'consultant' ? 'selected' : ''}>Berater</option>
                                <option value="engineering_firm" ${org.org_kind === 'engineering_firm' ? 'selected' : ''}>Ingenieurbüro</option>
                                <option value="internal" ${org.org_kind === 'internal' ? 'selected' : ''}>Intern</option>
                                <option value="other" ${org.org_kind === 'other' ? 'selected' : ''}>Sonstiges</option>
                            </select>
                        </div>
                        <div class="org-detail-field">
                            <label>Status</label>
                            <div class="org-detail-value" id="org-field-status">
                                <span class="status-badge status-${org.status || 'lead'}">${this.formatOrgStatus(org.status || 'lead')}</span>
                            </div>
                            <select class="org-detail-input" id="org-input-status" style="display: none;">
                                <option value="lead" ${org.status === 'lead' ? 'selected' : ''}>Lead</option>
                                <option value="prospect" ${org.status === 'prospect' ? 'selected' : ''}>Interessent</option>
                                <option value="customer" ${org.status === 'customer' ? 'selected' : ''}>Kunde</option>
                                <option value="inactive" ${org.status === 'inactive' ? 'selected' : ''}>Inaktiv</option>
                            </select>
                        </div>
                        <div class="org-detail-field">
                            <label>Externe Referenz</label>
                            <div class="org-detail-value" id="org-field-external_ref">${this.escapeHtml(org.external_ref || '-')}</div>
                            <input type="text" class="org-detail-input" id="org-input-external_ref" value="${this.escapeHtml(org.external_ref || '')}" style="display: none;">
                        </div>
                        <div class="org-detail-field">
                            <label>Branche (Hauptklasse)</label>
                            <div class="org-detail-value" id="org-field-industry_main">
                                ${org.industry_main_name || '-'}
                            </div>
                            <select class="org-detail-input" id="org-input-industry_main" style="display: none;">
                                <option value="">-- Keine Auswahl --</option>
                            </select>
                        </div>
                        <div class="org-detail-field">
                            <label>Branche (Unterklasse)</label>
                            <div class="org-detail-value" id="org-field-industry_sub">
                                ${org.industry_sub_name || '-'}
                            </div>
                            <select class="org-detail-input" id="org-input-industry_sub" style="display: none;" disabled>
                                <option value="">-- Bitte zuerst Hauptklasse wählen --</option>
                            </select>
                        </div>
                        <div class="org-detail-field">
                            <label>Website</label>
                            <div class="org-detail-value" id="org-field-website">
                                ${org.website ? `<a href="${this.escapeHtml(org.website)}" target="_blank">${this.escapeHtml(org.website)}</a>` : '-'}
                            </div>
                            <input type="url" class="org-detail-input" id="org-input-website" value="${this.escapeHtml(org.website || '')}" style="display: none;">
                        </div>
                        <div class="org-detail-field">
                            <label>Notizen</label>
                            <div class="org-detail-value" id="org-field-notes">${this.escapeHtml(org.notes || '-')}</div>
                            <textarea class="org-detail-input" id="org-input-notes" style="display: none;">${this.escapeHtml(org.notes || '')}</textarea>
                        </div>
                    </div>
                    <div class="org-detail-actions" id="org-basic-actions" style="display: none;">
                        <button class="btn btn-primary" id="btn-save-basic">Speichern</button>
                        <button class="btn btn-secondary" id="btn-cancel-basic">Abbrechen</button>
                    </div>
                </div>
                
                <!-- Kommunikation -->
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Kommunikation</h4>
                        <button class="btn btn-sm btn-primary" id="btn-add-channel">+ Kanal hinzufügen</button>
                    </div>
                    <div id="org-channels-list" class="org-detail-list">
                        ${this.renderCommunicationChannels(org.communication_channels || [], org.org_uuid)}
                    </div>
                </div>
                
                <!-- Adressen -->
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Adressen</h4>
                        <button class="btn btn-sm btn-primary" id="btn-add-address">+ Adresse hinzufügen</button>
                    </div>
                    <div id="org-addresses-list" class="org-detail-list">
                        ${this.renderAddresses(org.addresses || [])}
                    </div>
                </div>
                
                <!-- USt-Registrierungen -->
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>USt-Registrierungen</h4>
                        <button class="btn btn-sm btn-primary" id="btn-add-vat">+ USt-ID hinzufügen</button>
                    </div>
                    <div id="org-vat-list" class="org-detail-list">
                        ${this.renderVatRegistrations(org.vat_registrations || [])}
                    </div>
                </div>

                <!-- Relationen -->
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Organisationsstruktur</h4>
                        <button class="btn btn-sm btn-primary" id="btn-add-relation">+ Relation hinzufügen</button>
                    </div>
                    <div id="org-relations-list" class="org-detail-list">
                        ${this.renderRelations(org.relations || [])}
                    </div>
                </div>
                
                <!-- Archivierung -->
                ${isArchived ? `
                    <div class="org-detail-section">
                        <div class="org-archive-banner">
                            <span class="archive-icon">📦</span>
                            <span class="archive-text">Diese Organisation ist archiviert</span>
                            ${org.archived_at ? `<span class="archive-date">(seit ${new Date(org.archived_at).toLocaleDateString('de-DE')})</span>` : ''}
                            <button class="btn btn-sm btn-primary" id="btn-unarchive-org" style="margin-left: auto;">Reaktivieren</button>
                        </div>
                    </div>
                ` : `
                    <div class="org-detail-section">
                        <div class="org-archive-section" style="padding: 1rem; background: var(--bg); border-radius: 0.5rem; border: 1px solid var(--border);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong>Archivierung</strong>
                                    <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem; color: var(--text-light);">
                                        Archivierte Organisationen erscheinen nicht mehr in aktiven Listen und Reports.
                                    </p>
                                </div>
                                <button class="btn btn-sm btn-secondary" id="btn-archive-org">Archivieren</button>
                            </div>
                        </div>
                    </div>
                `}
            </div>
        `;
    }
    
    renderCommunicationChannels(channels, orgUuid) {
        if (!channels || channels.length === 0) {
            return '<div class="empty-state-small">Keine Kommunikationskanäle vorhanden</div>';
        }
        
        const channelIcons = {
            'email': '📧',
            'phone_main': '📞',
            'fax': '📠',
            'other': '📱'
        };
        
        const channelLabels = {
            'email': 'E-Mail',
            'phone_main': 'Telefon',
            'fax': 'Fax',
            'other': 'Sonstiges'
        };
        
        return channels.map(channel => {
            const icon = channelIcons[channel.channel_type] || '📱';
            const typeLabel = channelLabels[channel.channel_type] || channel.channel_type;
            let displayValue = '';
            
            if (channel.channel_type === 'email') {
                displayValue = channel.email_address || '';
            } else {
                // Telefon/Fax: Formatiere aus den Einzelteilen
                const parts = [];
                if (channel.country_code) parts.push(channel.country_code);
                if (channel.area_code) parts.push(channel.area_code);
                if (channel.number) parts.push(channel.number);
                displayValue = parts.join(' ');
                if (channel.extension) {
                    displayValue += ` Durchwahl ${channel.extension}`;
                }
            }
            
            // Erstelle Dialer-Link nur für Telefon (nicht für Fax)
            let dialerLink = '';
            if (channel.channel_type === 'phone_main' && displayValue) {
                // Formatiere für tel: Protokoll (nur Ziffern, + am Anfang)
                let telNumber = '';
                if (channel.country_code) {
                    // Entferne + oder 00 am Anfang und füge + hinzu
                    let countryCode = channel.country_code.replace(/^00/, '').replace(/^\+/, '');
                    telNumber = '+' + countryCode;
                }
                if (channel.area_code) {
                    telNumber += channel.area_code.replace(/\D/g, ''); // Nur Ziffern
                }
                if (channel.number) {
                    telNumber += channel.number.replace(/\D/g, ''); // Nur Ziffern
                }
                if (channel.extension) {
                    telNumber += ',' + channel.extension.replace(/\D/g, ''); // Durchwahl mit Komma
                }
                
                if (telNumber) {
                    dialerLink = `<a href="tel:${telNumber}" class="dialer-link">${this.escapeHtml(displayValue)}</a>`;
                }
            }
            
            return `
                <div class="org-detail-item" data-channel-uuid="${channel.channel_uuid}">
                    <div class="org-detail-item-content">
                        <div class="org-detail-item-header">
                            <span>${icon} ${this.escapeHtml(typeLabel)}</span>
                            ${channel.label ? `<span class="text-light">(${this.escapeHtml(channel.label)})</span>` : ''}
                            ${channel.is_primary ? '<span class="badge badge-default">Primär</span>' : ''}
                            ${!channel.is_public ? '<span class="badge" style="background: #e5e7eb; color: #374151;">Privat</span>' : ''}
                        </div>
                        <div class="org-detail-item-body">
                            ${dialerLink || (displayValue ? `<strong>${this.escapeHtml(displayValue)}</strong>` : '<span class="text-light">Nicht angegeben</span>')}
                            ${channel.notes ? `<div class="text-light">${this.escapeHtml(channel.notes)}</div>` : ''}
                        </div>
                    </div>
                    <div class="org-detail-item-actions">
                        <button class="btn btn-sm btn-secondary" onclick="app.editChannel('${channel.channel_uuid}', '${orgUuid}')">Bearbeiten</button>
                        <button class="btn btn-sm btn-danger" onclick="app.deleteChannel('${channel.channel_uuid}', '${orgUuid}')">Löschen</button>
                    </div>
                </div>
            `;
        }).join('');
    }
    
    renderAddresses(addresses) {
        if (!addresses || addresses.length === 0) {
            return '<div class="empty-state-small">Keine Adressen vorhanden</div>';
        }
        
        return addresses.map(addr => `
            <div class="org-detail-item" data-address-uuid="${addr.address_uuid}">
                <div class="org-detail-item-content">
                    <div class="org-detail-item-header">
                        <strong>${this.escapeHtml(addr.address_type || 'other')}</strong>
                        ${addr.is_default ? '<span class="badge badge-default">Standard</span>' : ''}
                    </div>
                    <div class="org-detail-item-body">
                        ${addr.street ? `<div>${this.escapeHtml(addr.street)}</div>` : ''}
                        <div>${[addr.postal_code, addr.city].filter(Boolean).join(' ')}</div>
                        ${addr.country ? `<div>${this.escapeHtml(addr.country)}</div>` : ''}
                        ${addr.location_type ? `<div class="text-light"><small>Typ: ${this.escapeHtml(addr.location_type)}</small></div>` : ''}
                        ${addr.vat_id ? `<div class="vat-id-display"><strong>USt-ID:</strong> ${this.escapeHtml(addr.vat_id.vat_id)} (${this.escapeHtml(addr.vat_id.country_code)})</div>` : ''}
                        ${addr.notes ? `<div class="text-light">${this.escapeHtml(addr.notes)}</div>` : ''}
                    </div>
                </div>
                <div class="org-detail-item-actions">
                    <button class="btn btn-sm btn-secondary" onclick="app.editAddress('${addr.address_uuid}')">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="app.deleteAddress('${addr.address_uuid}')">Löschen</button>
                </div>
            </div>
        `).join('');
    }
    
    renderVatRegistrations(vatRegs) {
        if (!vatRegs || vatRegs.length === 0) {
            return '<div class="empty-state-small">Keine USt-Registrierungen vorhanden</div>';
        }
        
        return vatRegs.map(vat => `
            <div class="org-detail-item" data-vat-uuid="${vat.vat_registration_uuid}">
                <div class="org-detail-item-content">
                    <div class="org-detail-item-header">
                        <strong>${this.escapeHtml(vat.vat_id)}</strong>
                        ${vat.is_primary_for_country ? '<span class="badge badge-primary">Primär</span>' : ''}
                    </div>
                    <div class="org-detail-item-body">
                        <div><strong>Land:</strong> ${this.escapeHtml(vat.country_code)}</div>
                        ${vat.street && vat.city ? `<div><strong>Standort:</strong> ${this.escapeHtml(vat.street)}, ${this.escapeHtml(vat.city)}</div>` : ''}
                        ${vat.location_type ? `<div><small>Typ: ${this.escapeHtml(vat.location_type)}</small></div>` : ''}
                        <div><small>Gültig ab: ${vat.valid_from || '-'}${vat.valid_to ? ` bis ${vat.valid_to}` : ' (aktuell)'}</small></div>
                        ${vat.notes ? `<div class="text-light">${this.escapeHtml(vat.notes)}</div>` : ''}
                    </div>
                </div>
                <div class="org-detail-item-actions">
                    <button class="btn btn-sm btn-secondary" onclick="app.editVatRegistration('${vat.vat_registration_uuid}')">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="app.deleteVatRegistration('${vat.vat_registration_uuid}')">Löschen</button>
                </div>
            </div>
        `).join('');
    }
    
    renderRelations(relations) {
        if (!relations || relations.length === 0) {
            return '<div class="empty-state-small">Keine Relationen vorhanden</div>';
        }
        
        return relations.map(rel => `
            <div class="org-detail-item" data-relation-uuid="${rel.relation_uuid}">
                <div class="org-detail-item-content">
                    <div class="org-detail-item-header">
                        <strong>${this.escapeHtml(rel.relation_type || 'other')}</strong>
                    </div>
                    <div class="org-detail-item-body">
                        <div>Von: ${rel.parent_org_uuid}</div>
                        <div>Zu: ${rel.child_org_uuid}</div>
                        ${rel.ownership_percent ? `<div>Beteiligung: ${rel.ownership_percent}%</div>` : ''}
                        ${rel.notes ? `<div class="text-light">${this.escapeHtml(rel.notes)}</div>` : ''}
                    </div>
                </div>
                <div class="org-detail-item-actions">
                    <button class="btn btn-sm btn-secondary" onclick="app.editRelation('${rel.relation_uuid}')">Bearbeiten</button>
                    <button class="btn btn-sm btn-danger" onclick="app.deleteRelation('${rel.relation_uuid}')">Löschen</button>
                </div>
            </div>
        `).join('');
    }
    
    initOrgDetailHandlers(orgUuid) {
        // Entferne alte Event-Listener (falls vorhanden) um Duplikate zu vermeiden
        // Erstelle neue Elemente mit eindeutigen IDs oder verwende einmalige Handler
        
        // Bearbeiten-Button für Grunddaten
        const btnEditBasic = document.getElementById('btn-edit-basic');
        if (btnEditBasic) {
            // Entferne alte Listener (durch Klon)
            const newBtnEditBasic = btnEditBasic.cloneNode(true);
            btnEditBasic.parentNode.replaceChild(newBtnEditBasic, btnEditBasic);
            newBtnEditBasic.addEventListener('click', () => {
                this.toggleOrgEditMode('basic', true);
            });
        }
        
        // Bearbeiten-Button für Account-Verantwortung
        const btnEditAccount = document.getElementById('btn-edit-account');
        if (btnEditAccount) {
            const newBtnEditAccount = btnEditAccount.cloneNode(true);
            btnEditAccount.parentNode.replaceChild(newBtnEditAccount, btnEditAccount);
            newBtnEditAccount.addEventListener('click', () => {
                this.toggleOrgEditMode('account', true);
            });
        }
        
        // Speichern-Button für Grunddaten
        const btnSaveBasic = document.getElementById('btn-save-basic');
        if (btnSaveBasic) {
            const newBtnSaveBasic = btnSaveBasic.cloneNode(true);
            btnSaveBasic.parentNode.replaceChild(newBtnSaveBasic, btnSaveBasic);
            newBtnSaveBasic.addEventListener('click', async () => {
                await this.saveOrgBasic(orgUuid);
            });
        }
        
        // Speichern-Button für Account - WICHTIG: orgUuid muss korrekt sein
        const btnSaveAccount = document.getElementById('btn-save-account');
        if (btnSaveAccount) {
            const newBtnSaveAccount = btnSaveAccount.cloneNode(true);
            btnSaveAccount.parentNode.replaceChild(newBtnSaveAccount, btnSaveAccount);
            // Speichere orgUuid im Closure
            const currentOrgUuid = orgUuid; // Closure-Variable
            newBtnSaveAccount.addEventListener('click', async () => {
                await this.saveOrgAccount(currentOrgUuid);
            });
        }
        
        // Abbrechen-Button für Grunddaten
        const btnCancelBasic = document.getElementById('btn-cancel-basic');
        if (btnCancelBasic) {
            const newBtnCancelBasic = btnCancelBasic.cloneNode(true);
            btnCancelBasic.parentNode.replaceChild(newBtnCancelBasic, btnCancelBasic);
            const currentOrgUuid = orgUuid;
            newBtnCancelBasic.addEventListener('click', () => {
                this.toggleOrgEditMode('basic', false);
                this.showOrgDetail(currentOrgUuid);
            });
        }
        
        // Abbrechen-Button für Account
        const btnCancelAccount = document.getElementById('btn-cancel-account');
        if (btnCancelAccount) {
            const newBtnCancelAccount = btnCancelAccount.cloneNode(true);
            btnCancelAccount.parentNode.replaceChild(newBtnCancelAccount, btnCancelAccount);
            const currentOrgUuid = orgUuid;
            newBtnCancelAccount.addEventListener('click', () => {
                this.toggleOrgEditMode('account', false);
                this.showOrgDetail(currentOrgUuid);
            });
        }
        
        // Kanal hinzufügen
        const btnAddChannel = document.getElementById('btn-add-channel');
        if (btnAddChannel) {
            const newBtnAddChannel = btnAddChannel.cloneNode(true);
            btnAddChannel.parentNode.replaceChild(newBtnAddChannel, btnAddChannel);
            const currentOrgUuid = orgUuid;
            newBtnAddChannel.addEventListener('click', () => {
                this.showAddChannelDialog(currentOrgUuid);
            });
        }
        
        // Adresse hinzufügen
        const btnAddAddress = document.getElementById('btn-add-address');
        if (btnAddAddress) {
            const newBtnAddAddress = btnAddAddress.cloneNode(true);
            btnAddAddress.parentNode.replaceChild(newBtnAddAddress, btnAddAddress);
            const currentOrgUuid = orgUuid;
            newBtnAddAddress.addEventListener('click', () => {
                this.showAddAddressDialog(currentOrgUuid);
            });
        }
        
        // USt-ID hinzufügen
        const btnAddVat = document.getElementById('btn-add-vat');
        if (btnAddVat) {
            const newBtnAddVat = btnAddVat.cloneNode(true);
            btnAddVat.parentNode.replaceChild(newBtnAddVat, btnAddVat);
            const currentOrgUuid = orgUuid;
            newBtnAddVat.addEventListener('click', () => {
                this.showAddVatDialog(currentOrgUuid);
            });
        }
        
        // Relation hinzufügen
        const btnAddRelation = document.getElementById('btn-add-relation');
        if (btnAddRelation) {
            const newBtnAddRelation = btnAddRelation.cloneNode(true);
            btnAddRelation.parentNode.replaceChild(newBtnAddRelation, btnAddRelation);
            const currentOrgUuid = orgUuid;
            newBtnAddRelation.addEventListener('click', () => {
                this.showAddRelationDialog(currentOrgUuid);
            });
        }
        
        // Archivieren-Button
        const btnArchiveOrg = document.getElementById('btn-archive-org');
        if (btnArchiveOrg) {
            const newBtnArchiveOrg = btnArchiveOrg.cloneNode(true);
            btnArchiveOrg.parentNode.replaceChild(newBtnArchiveOrg, btnArchiveOrg);
            const currentOrgUuid = orgUuid;
            newBtnArchiveOrg.addEventListener('click', () => {
                this.archiveOrg(currentOrgUuid);
            });
        }
        
        // Reaktivieren-Button
        const btnUnarchiveOrg = document.getElementById('btn-unarchive-org');
        if (btnUnarchiveOrg) {
            const newBtnUnarchiveOrg = btnUnarchiveOrg.cloneNode(true);
            btnUnarchiveOrg.parentNode.replaceChild(newBtnUnarchiveOrg, btnUnarchiveOrg);
            const currentOrgUuid = orgUuid;
            newBtnUnarchiveOrg.addEventListener('click', () => {
                this.unarchiveOrg(currentOrgUuid);
            });
        }
        
        // Health Filter im Accounts Dashboard
        document.getElementById('account-filter-health')?.addEventListener('change', () => {
            this.loadAccounts();
        });
        
        // Industry Main Dropdown - lade Unterklassen wenn Hauptklasse gewählt wird
        const industryMainSelect = document.getElementById('org-input-industry_main');
        if (industryMainSelect) {
            industryMainSelect.addEventListener('change', async (e) => {
                const mainUuid = e.target.value;
                await this.loadIndustrySubClasses(mainUuid);
            });
        }
    }
    
    async loadIndustryDropdowns(org) {
        try {
            // Lade Hauptklassen
            const mainClasses = await window.API.getIndustries(null, true);
            const mainSelect = document.getElementById('org-input-industry_main');
            if (mainSelect) {
                mainSelect.innerHTML = '<option value="">-- Keine Auswahl --</option>' +
                    mainClasses.map(industry => 
                        `<option value="${industry.industry_uuid}" ${org.industry_main_uuid === industry.industry_uuid ? 'selected' : ''}>${this.escapeHtml(industry.name)}</option>`
                    ).join('');
                
                // Wenn bereits eine Hauptklasse gesetzt ist, lade Unterklassen
                if (org.industry_main_uuid) {
                    await this.loadIndustrySubClasses(org.industry_main_uuid, org.industry_sub_uuid);
                }
            }
        } catch (error) {
            console.error('Error loading industries:', error);
        }
    }
    
    async loadIndustrySubClasses(mainUuid, selectedSubUuid = null) {
        const subSelect = document.getElementById('org-input-industry_sub');
        if (!subSelect) return;
        
        if (!mainUuid) {
            subSelect.innerHTML = '<option value="">-- Bitte zuerst Hauptklasse wählen --</option>';
            subSelect.disabled = true;
            return;
        }
        
        try {
            const subClasses = await window.API.getIndustries(mainUuid, false);
            subSelect.disabled = false;
            subSelect.innerHTML = '<option value="">-- Keine Auswahl --</option>' +
                subClasses.map(industry => 
                    `<option value="${industry.industry_uuid}" ${selectedSubUuid === industry.industry_uuid ? 'selected' : ''}>${this.escapeHtml(industry.name)}</option>`
                ).join('');
        } catch (error) {
            console.error('Error loading sub industries:', error);
            subSelect.innerHTML = '<option value="">Fehler beim Laden</option>';
        }
    }
    
    toggleOrgEditMode(section, isEdit) {
        const fields = document.getElementById(`org-${section}-fields`);
        const actions = document.getElementById(`org-${section}-actions`);
        const editBtn = document.getElementById(`btn-edit-${section}`);
        
        if (!fields) return;
        
        // Zeige/Verstecke Input-Felder
        fields.querySelectorAll('.org-detail-value').forEach(el => {
            el.style.display = isEdit ? 'none' : 'block';
        });
        fields.querySelectorAll('.org-detail-input').forEach(el => {
            el.style.display = isEdit ? 'block' : 'none';
        });
        
        // Zeige/Verstecke Warnung (für Account Owner ohne Config)
        if (section === 'account') {
            const warning = fields.querySelector('.org-detail-warning');
            if (warning) warning.style.display = isEdit ? 'block' : 'none';
        }
        
        // Zeige/Verstecke Actions
        if (actions) {
            actions.style.display = isEdit ? 'block' : 'none';
        }
        if (editBtn) {
            editBtn.style.display = isEdit ? 'none' : 'block';
        }
    }
    
    async saveOrgAccount(orgUuid) {
        if (!orgUuid) {
            alert('Fehler: Organisation-UUID fehlt');
            return;
        }
        
        const select = document.getElementById('org-input-account_owner');
        
        if (!select) {
            alert('Fehler: Account Owner Select-Feld nicht gefunden');
            return;
        }
        
        const accountOwner = select.value.trim() || null;
        
        // Hole aktuellen Status für Validierung
        const org = await window.API.getOrg(orgUuid);
        if (!org) {
            alert('Fehler: Organisation nicht gefunden');
            return;
        }
        
        const status = org?.status || 'lead';
        
        // Validierung: Account Owner Pflicht ab Prospect
        if ((status === 'prospect' || status === 'customer') && !accountOwner) {
            alert('Account Owner ist ab Status "Interessent" Pflicht!');
            return;
        }
        
        // Validierung: Nur User aus der Liste erlauben (aus Config oder bereits verwendet)
        if (accountOwner && !select.querySelector(`option[value="${accountOwner}"]`)) {
            alert(`Fehler: Der User "${accountOwner}" ist nicht verfügbar. Bitte wähle einen User aus der Liste oder füge den User zu config/users.php hinzu.`);
            return;
        }
        
        const data = {
            account_owner_user_id: accountOwner
        };
        
        try {
            await window.API.updateOrg(orgUuid, data);
            this.toggleOrgEditMode('account', false);
            // Lade Org neu
            await this.showOrgDetail(orgUuid);
            // Zeige Toast-Benachrichtigung
            this.showSuccess('Account Owner erfolgreich aktualisiert');
        } catch (error) {
            console.error('Error saving account owner:', error);
            this.showError('Fehler beim Speichern: ' + error.message);
        }
    }
    
    async saveOrgBasic(orgUuid) {
        const status = document.getElementById('org-input-status')?.value || 'lead';
        const accountOwner = document.getElementById('org-input-account_owner')?.value.trim() || null;
        
        // Validierung: Account Owner Pflicht ab Prospect
        if ((status === 'prospect' || status === 'customer') && !accountOwner) {
            alert('Account Owner ist ab Status "Interessent" Pflicht!');
            return;
        }
        
        const data = {
            name: document.getElementById('org-input-name')?.value || '',
            org_kind: document.getElementById('org-input-org_kind')?.value || 'other',
            status: status,
            external_ref: document.getElementById('org-input-external_ref')?.value || null,
            industry_main_uuid: document.getElementById('org-input-industry_main')?.value || null,
            industry_sub_uuid: document.getElementById('org-input-industry_sub')?.value || null,
            website: document.getElementById('org-input-website')?.value || null,
            notes: document.getElementById('org-input-notes')?.value || null,
            account_owner_user_id: accountOwner
        };
        
        try {
            await window.API.updateOrg(orgUuid, data);
            this.toggleOrgEditMode('basic', false);
            // Lade Org neu
            await this.showOrgDetail(orgUuid);
            // Zeige Toast-Benachrichtigung
            this.showSuccess('Organisation erfolgreich aktualisiert');
        } catch (error) {
            console.error('Error saving org:', error);
            this.showError('Fehler beim Speichern: ' + error.message);
        }
    }
    
    renderHealthReason(reason) {
        const messages = {
            'no_contact': `Kein Kontakt seit ${reason.days} Tagen`,
            'no_contact_recorded': 'Kein Kontakt erfasst',
            'offer_stale': `${reason.count} Angebot(e) ohne Reaktion (ältestes: ${reason.days} Tage)`,
            'project_waiting': `${reason.count} Projekt(e) wartend (ältestes: ${reason.days} Tage)`,
            'escalation': `${reason.count} Eskalation(en) offen`
        };
        
        return `<div class="health-reason health-reason-${reason.severity}">${messages[reason.type] || reason.type}</div>`;
    }
    
    async loadAccounts() {
        const container = document.getElementById('accounts-list');
        const attentionSection = document.getElementById('account-attention-section');
        const attentionList = document.getElementById('account-attention-list');
        const emptyState = document.getElementById('accounts-empty-state');
        const healthFilter = document.getElementById('account-filter-health')?.value || '';
        
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Kunden...</div>';
        if (attentionSection) attentionSection.style.display = 'none';
        if (emptyState) emptyState.style.display = 'none';
        
        try {
            const accounts = await window.API.getAccounts('default_user');
            
            if (!accounts || accounts.length === 0) {
                container.innerHTML = '';
                if (emptyState) emptyState.style.display = 'block';
                return;
            }
            
            // Filter nach Health-Status
            let filteredAccounts = accounts;
            if (healthFilter) {
                filteredAccounts = accounts.filter(acc => acc.health?.status === healthFilter);
            }
            
            // Trenne nach Health-Status
            const attentionAccounts = filteredAccounts.filter(acc => acc.health?.status === 'red' || acc.health?.status === 'yellow');
            const otherAccounts = filteredAccounts.filter(acc => acc.health?.status === 'green' || !acc.health);
            
            // Zeige "Aufmerksamkeit nötig" Block
            if (attentionAccounts.length > 0 && !healthFilter) {
                if (attentionSection) attentionSection.style.display = 'block';
                if (attentionList) {
                    attentionList.innerHTML = attentionAccounts.map(acc => this.renderAccountCard(acc)).join('');
                    attentionList.querySelectorAll('.account-card').forEach(card => {
                        card.addEventListener('click', () => {
                            const uuid = card.dataset.orgUuid;
                            this.selectOrg(uuid);
                        });
                    });
                }
            } else {
                if (attentionSection) attentionSection.style.display = 'none';
            }
            
            // Zeige restliche Accounts
            container.innerHTML = otherAccounts.map(acc => this.renderAccountCard(acc)).join('');
            container.querySelectorAll('.account-card').forEach(card => {
                card.addEventListener('click', () => {
                    const uuid = card.dataset.orgUuid;
                    this.selectOrg(uuid);
                });
            });
            
        } catch (error) {
            console.error('Error loading accounts:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden der Kunden</div>';
        }
    }
    
    renderAccountCard(account) {
        const health = account.health || { status: 'unknown', reasons: [] };
        const healthIcon = health.status === 'red' ? '🔴' : health.status === 'yellow' ? '🟡' : '🟢';
        const healthText = health.status === 'red' ? 'Aufmerksamkeit nötig' : health.status === 'yellow' ? 'Risiken' : 'Alles OK';
        const lastContact = health.last_contact ? new Date(health.last_contact).toLocaleDateString('de-DE') : 'Nie';
        const daysSinceContact = health.last_contact ? Math.floor((new Date() - new Date(health.last_contact)) / 86400000) : null;
        
        return `
            <div class="account-card account-health-${health.status}" data-org-uuid="${account.org_uuid}">
                <div class="account-card-header">
                    <div class="account-card-title">
                        <h3>${this.escapeHtml(account.name || 'Unbenannte Organisation')}</h3>
                        <span class="health-indicator health-${health.status}">${healthIcon} ${healthText}</span>
                    </div>
                    <div class="account-card-status">
                        <span class="status-badge status-${account.status || 'lead'}">${this.formatOrgStatus(account.status || 'lead')}</span>
                    </div>
                </div>
                <div class="account-card-body">
                    <div class="account-card-meta">
                        <div class="account-meta-item">
                            <span class="meta-label">Letzter Kontakt:</span>
                            <span class="meta-value">${lastContact}${daysSinceContact ? ` (vor ${daysSinceContact} Tagen)` : ''}</span>
                        </div>
                        ${account.account_owner_since ? `
                        <div class="account-meta-item">
                            <span class="meta-label">Owner seit:</span>
                            <span class="meta-value">${new Date(account.account_owner_since).toLocaleDateString('de-DE')}</span>
                        </div>
                        ` : ''}
                    </div>
                    ${health.reasons && health.reasons.length > 0 ? `
                    <div class="account-health-reasons">
                        ${health.reasons.map(r => this.renderHealthReason(r)).join('')}
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    showAddChannelDialog(orgUuid) {
        const channelType = prompt('Kanaltyp wählen:\n1 = E-Mail\n2 = Telefon (Hauptnummer)\n3 = Fax\n4 = Sonstiges\n\n(Abbrechen = Dialog schließen)', '1');
        if (channelType === null) return; // Benutzer hat Abbrechen geklickt
        
        const types = { '1': 'email', '2': 'phone_main', '3': 'fax', '4': 'other' };
        const type = types[channelType] || 'other';
        
        let data = { channel_type: type };
        
        if (type === 'email') {
            // E-Mail: Nur relevante Felder
            const email = prompt('E-Mail-Adresse:\n\n(Abbrechen = Dialog schließen)', '');
            if (email === null) return;
            if (!email.trim()) {
                alert('E-Mail-Adresse ist erforderlich');
                return;
            }
            data.email_address = email.trim();
            
            const label = prompt('Bezeichnung (optional, z.B. "Info", "Support"):\n\n(Abbrechen = Dialog schließen)', '');
            if (label === null) return;
            if (label && label.trim()) data.label = label.trim();
            
            const isPrimary = confirm('Als primäre E-Mail-Adresse markieren?');
            if (isPrimary === null) return;
            data.is_primary = isPrimary ? 1 : 0;
            
            // E-Mail ist standardmäßig öffentlich (wird nicht abgefragt)
            data.is_public = 1;
            
        } else if (type === 'phone_main') {
            // Telefon: Alle relevanten Felder
            const countryCode = prompt('Ländervorwahl (z.B. +49):\n\n(Abbrechen = Dialog schließen)', '+49');
            if (countryCode === null) return;
            
            const areaCode = prompt('Ortsvorwahl (z.B. 030):\n\n(Abbrechen = Dialog schließen)', '');
            if (areaCode === null) return;
            
            const number = prompt('Hauptnummer (ohne Vorwahlen):\n\n(Abbrechen = Dialog schließen)', '');
            if (number === null) return;
            
            if (!number.trim()) {
                alert('Hauptnummer ist erforderlich');
                return;
            }
            
            const extension = prompt('Durchwahl (optional, Enter zum Überspringen):\n\n(Abbrechen = Dialog schließen)', '');
            if (extension === null) return;
            
            if (countryCode) data.country_code = countryCode.trim();
            if (areaCode) data.area_code = areaCode.trim();
            if (number) data.number = number.trim();
            if (extension && extension.trim()) data.extension = extension.trim();
            
            const label = prompt('Bezeichnung (optional, z.B. "Zentrale", "Hotline"):\n\n(Abbrechen = Dialog schließen)', '');
            if (label === null) return;
            if (label && label.trim()) data.label = label.trim();
            
            const isPrimary = confirm('Als primäre Telefonnummer markieren?');
            if (isPrimary === null) return;
            data.is_primary = isPrimary ? 1 : 0;
            
            const isPublic = confirm('Öffentlich verfügbar (z.B. auf Website)?');
            if (isPublic === null) return;
            data.is_public = isPublic ? 1 : 0;
            
            const notes = prompt('Hinweise (optional, z.B. "Mo-Fr 9-17 Uhr"):\n\n(Abbrechen = Dialog schließen)', '');
            if (notes === null) return;
            if (notes && notes.trim()) data.notes = notes.trim();
            
        } else if (type === 'fax') {
            // Fax: Keine Öffentlichkeit, keine Öffnungszeiten
            const countryCode = prompt('Ländervorwahl (z.B. +49):\n\n(Abbrechen = Dialog schließen)', '+49');
            if (countryCode === null) return;
            
            const areaCode = prompt('Ortsvorwahl (z.B. 030):\n\n(Abbrechen = Dialog schließen)', '');
            if (areaCode === null) return;
            
            const number = prompt('Hauptnummer (ohne Vorwahlen):\n\n(Abbrechen = Dialog schließen)', '');
            if (number === null) return;
            
            if (!number.trim()) {
                alert('Hauptnummer ist erforderlich');
                return;
            }
            
            const extension = prompt('Durchwahl (optional, Enter zum Überspringen):\n\n(Abbrechen = Dialog schließen)', '');
            if (extension === null) return;
            
            if (countryCode) data.country_code = countryCode.trim();
            if (areaCode) data.area_code = areaCode.trim();
            if (number) data.number = number.trim();
            if (extension && extension.trim()) data.extension = extension.trim();
            
            const label = prompt('Bezeichnung (optional, z.B. "Zentrale"):\n\n(Abbrechen = Dialog schließen)', '');
            if (label === null) return;
            if (label && label.trim()) data.label = label.trim();
            
            const isPrimary = confirm('Als primäre Faxnummer markieren?');
            if (isPrimary === null) return;
            data.is_primary = isPrimary ? 1 : 0;
            
            // Fax ist standardmäßig nicht öffentlich
            data.is_public = 0;
            
        } else {
            // Sonstiges: Ähnlich wie Telefon
            const countryCode = prompt('Ländervorwahl (optional, z.B. +49):\n\n(Abbrechen = Dialog schließen)', '+49');
            if (countryCode === null) return;
            
            const areaCode = prompt('Ortsvorwahl (optional):\n\n(Abbrechen = Dialog schließen)', '');
            if (areaCode === null) return;
            
            const number = prompt('Nummer/Wert:\n\n(Abbrechen = Dialog schließen)', '');
            if (number === null) return;
            
            if (!number.trim()) {
                alert('Nummer/Wert ist erforderlich');
                return;
            }
            
            const extension = prompt('Durchwahl/Erweiterung (optional):\n\n(Abbrechen = Dialog schließen)', '');
            if (extension === null) return;
            
            if (countryCode) data.country_code = countryCode.trim();
            if (areaCode) data.area_code = areaCode.trim();
            if (number) data.number = number.trim();
            if (extension && extension.trim()) data.extension = extension.trim();
            
            const label = prompt('Bezeichnung (optional):\n\n(Abbrechen = Dialog schließen)', '');
            if (label === null) return;
            if (label && label.trim()) data.label = label.trim();
            
            const isPrimary = confirm('Als primären Kanal markieren?');
            if (isPrimary === null) return;
            data.is_primary = isPrimary ? 1 : 0;
            
            const isPublic = confirm('Öffentlich verfügbar?');
            if (isPublic === null) return;
            data.is_public = isPublic ? 1 : 0;
        }
        
        this.addChannel(orgUuid, data);
    }
    
    async addChannel(orgUuid, data) {
        try {
            await window.API.addOrgChannel(orgUuid, data);
            this.showSuccess('Kommunikationskanal hinzugefügt');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error adding channel:', error);
            this.showError('Fehler beim Hinzufügen: ' + error.message);
        }
    }
    
    async deleteChannel(channelUuid, orgUuid) {
        if (!confirm('Möchten Sie diesen Kommunikationskanal wirklich löschen?')) return;
        
        if (!orgUuid) {
            // Fallback: Versuche orgUuid aus Modal zu holen
            const modal = document.getElementById('modal-org-detail');
            orgUuid = modal?.dataset?.orgUuid || window.currentOrgUuid;
        }
        
        if (!orgUuid) {
            this.showError('Organisation nicht gefunden. Bitte Seite neu laden.');
            return;
        }
        
        try {
            await window.API.deleteOrgChannel(orgUuid, channelUuid);
            this.showSuccess('Kommunikationskanal gelöscht');
            // Warte kurz, damit der Toast sichtbar ist
            setTimeout(async () => {
                await this.showOrgDetail(orgUuid);
            }, 500);
        } catch (error) {
            console.error('Error deleting channel:', error);
            this.showError('Fehler beim Löschen: ' + error.message);
        }
    }
    
    async editChannel(channelUuid, orgUuid) {
        if (!orgUuid) {
            const modal = document.getElementById('modal-org-detail');
            orgUuid = modal?.dataset?.orgUuid || window.currentOrgUuid;
        }
        
        // TODO: Implementiere Edit-Dialog
    }
    
    // ============================================================================
    // VAT REGISTRATION (USt-ID) MANAGEMENT
    // ============================================================================
    
    async showAddVatDialog(orgUuid) {
        try {
            const vatId = prompt('USt-ID eingeben (z.B. DE123456789):');
            if (vatId === null || !vatId.trim()) return;
            
            const countryCode = prompt('Ländercode (ISO 2-stellig, z.B. DE, AT, FR):');
            if (countryCode === null || !countryCode.trim()) return;
            
            const locationType = prompt('Standort-Typ (optional, z.B. HQ, Branch, Subsidiary) - Leer lassen wenn nicht relevant:');
            const notes = prompt('Hinweise (optional, z.B. "Ausland", "Primär") - Leer lassen wenn nicht relevant:');
            const isPrimary = confirm('Als primäre USt-ID für dieses Land markieren?');
            
            const data = {
                vat_id: vatId.trim(),
                country_code: countryCode.trim().toUpperCase(),
                is_primary_for_country: isPrimary ? 1 : 0,
                location_type: locationType && locationType.trim() ? locationType.trim() : null,
                notes: notes && notes.trim() ? notes.trim() : null,
                valid_from: new Date().toISOString().split('T')[0]
            };
            
            this.addVatRegistration(orgUuid, data);
        } catch (error) {
            console.error('Error in showAddVatDialog:', error);
            this.showError('Fehler beim Hinzufügen: ' + error.message);
        }
    }
    
    async addVatRegistration(orgUuid, data) {
        try {
            await window.API.addOrgVatRegistration(orgUuid, data);
            this.showSuccess('USt-ID hinzugefügt');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error adding VAT registration:', error);
            this.showError('Fehler beim Hinzufügen: ' + error.message);
        }
    }
    
    async deleteVatRegistration(vatUuid) {
        if (!confirm('Möchten Sie diese USt-ID-Registrierung wirklich löschen?')) return;
        
        const modal = document.getElementById('modal-org-detail');
        const orgUuid = modal?.dataset?.orgUuid || window.currentOrgUuid;
        
        if (!orgUuid) {
            this.showError('Organisation nicht gefunden. Bitte Seite neu laden.');
            return;
        }
        
        try {
            await window.API.deleteOrgVatRegistration(orgUuid, vatUuid);
            this.showSuccess('USt-ID-Registrierung gelöscht');
            setTimeout(async () => {
                await this.showOrgDetail(orgUuid);
            }, 500);
        } catch (error) {
            console.error('Error deleting VAT registration:', error);
            this.showError('Fehler beim Löschen: ' + error.message);
        }
    }
    
    async editVatRegistration(vatUuid) {
        try {
            // Hole aktuelle USt-ID-Daten
            const modal = document.getElementById('modal-org-detail');
            const orgUuid = modal?.dataset?.orgUuid || window.currentOrgUuid;
            
            if (!orgUuid) {
                this.showError('Organisation nicht gefunden. Bitte Seite neu laden.');
                return;
            }
            
            // Lade alle USt-Registrierungen
            const vatRegs = await window.API.getOrgVatRegistrations(orgUuid);
            const currentVat = vatRegs.find(v => v.vat_registration_uuid === vatUuid);
            
            if (!currentVat) {
                this.showError('USt-ID-Registrierung nicht gefunden');
                return;
            }
            
            // Dialog zum Bearbeiten
            const vatId = prompt('USt-ID:', currentVat.vat_id || '');
            if (vatId === null) return;
            
            const countryCode = prompt('Ländercode (ISO 2-stellig):', currentVat.country_code || '');
            if (countryCode === null) return;
            
            const locationType = prompt('Standort-Typ (optional, z.B. HQ, Branch):', currentVat.location_type || '');
            const notes = prompt('Hinweise (optional):', currentVat.notes || '');
            const isPrimary = confirm('Als primäre USt-ID für dieses Land markieren?');
            
            // Optional: Gültigkeitsdaten
            const validFrom = prompt('Gültig ab (YYYY-MM-DD):', currentVat.valid_from || new Date().toISOString().split('T')[0]);
            if (validFrom === null) return;
            
            const validToInput = prompt('Gültig bis (YYYY-MM-DD, leer lassen wenn aktuell gültig):', currentVat.valid_to || '');
            
            const data = {
                vat_id: vatId.trim(),
                country_code: countryCode.trim().toUpperCase(),
                is_primary_for_country: isPrimary ? 1 : 0
            };
            
            // Optionale Felder nur hinzufügen, wenn sie einen Wert haben
            if (locationType && locationType.trim()) {
                data.location_type = locationType.trim();
            }
            if (notes && notes.trim()) {
                data.notes = notes.trim();
            }
            if (validFrom && validFrom.trim()) {
                data.valid_from = validFrom.trim();
            }
            if (validToInput && validToInput.trim()) {
                data.valid_to = validToInput.trim();
            } else {
                // Explizit null setzen, wenn leer (für "aktuell gültig")
                data.valid_to = null;
            }
            
            await window.API.updateOrgVatRegistration(orgUuid, vatUuid, data);
            this.showSuccess('USt-ID-Registrierung aktualisiert');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error editing VAT registration:', error);
            this.showError('Fehler beim Bearbeiten: ' + error.message);
        }
    }
    
    showAddAddressDialog(orgUuid) {
        // TODO: Implementiere Dialog zum Hinzufügen von Adressen
        alert('Adresse hinzufügen - wird noch implementiert');
    }
    
    showAddRelationDialog(orgUuid) {
        // TODO: Implementiere Dialog zum Hinzufügen von Relationen
        alert('Relation hinzufügen - wird noch implementiert');
    }
    
    async editAddress(addressUuid) {
        // TODO: Implementiere Bearbeitung von Adressen
        alert('Adresse bearbeiten - wird noch implementiert');
    }
    
    async deleteAddress(addressUuid) {
        if (!confirm('Adresse wirklich löschen?')) return;
        
        // TODO: Implementiere Löschen von Adressen
        alert('Adresse löschen - wird noch implementiert');
    }
    
    async editRelation(relationUuid) {
        // TODO: Implementiere Bearbeitung von Relationen
        alert('Relation bearbeiten - wird noch implementiert');
    }
    
    async deleteRelation(relationUuid) {
        if (!confirm('Relation wirklich löschen?')) return;
        
        // TODO: Implementiere Löschen von Relationen
        alert('Relation löschen - wird noch implementiert');
    }
    
    formatOrgLocation(org) {
        if (org.cities) {
            return org.cities.split(',')[0];
        }
        return '';
    }
    
    formatOrgStatus(status) {
        const statusMap = {
            'lead': 'Lead',
            'prospect': 'Interessent',
            'customer': 'Kunde',
            'inactive': 'Inaktiv'
        };
        return statusMap[status] || status;
    }
    
    updateSearchSelection(items, index) {
        items.forEach((item, i) => {
            item.classList.toggle('selected', i === index);
        });
        if (index >= 0 && items[index]) {
            items[index].scrollIntoView({ block: 'nearest' });
        }
    }
    
    async showOrgDetails(orgUuid) {
        try {
            // Track Zugriff beim Öffnen der Detailseite
            try {
                await window.API.trackOrgAccess(orgUuid, 'default_user', 'recent');
            } catch (error) {
                console.warn('Could not track org access:', error);
            }
            
            const org = await window.API.getOrg(orgUuid);
            if (!org) {
                alert('Organisation nicht gefunden');
                return;
            }
            
            // Öffne Detail-Ansicht (kann später als Modal erweitert werden)
            this.loadOrgs({ search: org.name, org_kind: '' });
            
            // Scroll zu gefundener Organisation
            setTimeout(() => {
                const orgCard = document.querySelector(`[data-org-uuid="${orgUuid}"]`);
                if (orgCard) {
                    orgCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    orgCard.style.backgroundColor = 'var(--highlight, #e3f2fd)';
                    setTimeout(() => {
                        orgCard.style.backgroundColor = '';
                    }, 2000);
                }
            }, 100);
            
            // Aktualisiere "Zuletzt verwendet"
            await this.loadRecentOrgs();
        } catch (error) {
            console.error('Error loading org details:', error);
            alert('Fehler beim Laden der Organisation');
        }
    }
    
    async loadOrgs(filters = {}) {
        const container = document.getElementById('orgs-list');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">Lade Organisationen...</div>';

        try {
            const orgs = await window.API.getOrgs(filters);
            
            // Sicherstellen, dass wir ein Array haben
            const orgsArray = Array.isArray(orgs) ? orgs : [];
            
            if (orgsArray.length === 0) {
                const searchText = filters.search ? ` für "${filters.search}"` : '';
                container.innerHTML = `<div class="empty-state">
                    <div class="empty-state-icon">🏢</div>
                    <p>Keine Organisationen gefunden${searchText}</p>
                    <p style="font-size: 0.9em; color: var(--text-light); margin-top: 0.5rem;">
                        Verwende die Suchleiste oben, um nach Organisationen zu suchen
                    </p>
                </div>`;
                return;
            }

            container.innerHTML = orgsArray.map(org => `
                <div class="org-card" data-org-uuid="${org.org_uuid}" style="cursor: pointer;">
                    <div class="case-header">
                        <div>
                            <div class="case-title">${this.escapeHtml(org.name || 'Unbenannte Organisation')}</div>
                            <div class="case-meta">
                                <span>${this.formatOrgKind(org.org_kind)}</span>
                                ${org.external_ref ? `<span>• ${this.escapeHtml(org.external_ref)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
            
            // Klick-Handler für Org-Cards
            container.querySelectorAll('.org-card').forEach(card => {
                card.addEventListener('click', () => {
                    const uuid = card.dataset.orgUuid;
                    this.showOrgDetails(uuid);
                });
            });
        } catch (error) {
            console.error('Error loading orgs:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden der Organisationen</div>';
        }
    }

    async loadPersons() {
        const container = document.getElementById('persons-list');
        container.innerHTML = '<div class="loading">Lade Personen...</div>';

        try {
            const persons = await window.API.getPersons();
            
            // Sicherstellen, dass wir ein Array haben
            const personsArray = Array.isArray(persons) ? persons : [];
            
            if (personsArray.length === 0) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">👤</div><p>Keine Personen gefunden</p></div>';
                return;
            }

            container.innerHTML = personsArray.map(person => `
                <div class="person-card">
                    <div class="case-header">
                        <div>
                            <div class="case-title">${this.escapeHtml(person.display_name || 'Unbenannte Person')}</div>
                            <div class="case-meta">
                                ${person.email ? `<span>${this.escapeHtml(person.email)}</span>` : ''}
                                ${person.phone ? `<span>${this.escapeHtml(person.phone)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        } catch (error) {
            console.error('Error loading persons:', error);
            container.innerHTML = '<div class="empty-state">Fehler beim Laden der Personen</div>';
        }
    }

    showCreateCaseModal() {
        // TODO: Implement create case modal
        alert('Create Case - Coming soon');
    }

    showCreateProjectModal() {
        // TODO: Implement create project modal
        alert('Create Project - Coming soon');
    }

    showCreateOrgModal() {
        // TODO: Implement create org modal
        alert('Create Org - Coming soon');
    }

    showCreatePersonModal() {
        // TODO: Implement create person modal
        alert('Create Person - Coming soon');
    }

    closeModal() {
        document.getElementById('modal-case-detail').classList.remove('active');
    }

    formatStatus(status) {
        const statusMap = {
            'neu': 'Neu',
            'in_bearbeitung': 'In Bearbeitung',
            'wartend_intern': 'Wartend (intern)',
            'wartend_extern': 'Wartend (extern)',
            'blockiert': 'Blockiert',
            'eskaliert': 'Eskaliert',
            'abgeschlossen': 'Abgeschlossen'
        };
        return statusMap[status] || status;
    }

    formatEngine(engine) {
        const engineMap = {
            'customer_inbound': 'Customer Inbound',
            'ops': 'OPS',
            'inside_sales': 'Inside Sales',
            'outside_sales': 'Outside Sales',
            'order_admin': 'Order Admin'
        };
        return engineMap[engine] || engine;
    }

    formatProjectStatus(status) {
        const statusMap = {
            'active': 'Aktiv',
            'on_hold': 'Pausiert',
            'closed': 'Geschlossen'
        };
        return statusMap[status] || status;
    }

    formatOrgKind(kind) {
        const kindMap = {
            'customer': 'Kunde',
            'supplier': 'Lieferant',
            'consultant': 'Berater',
            'engineering_firm': 'Ingenieurbüro',
            'internal': 'Intern',
            'other': 'Sonstiges'
        };
        return kindMap[kind] || kind;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showError(message) {
        this.showToast(message, 'error');
    }

    showSuccess(message) {
        this.showToast(message, 'success');
    }

    showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        if (!container) {
            console.error('Toast container not found!');
            // Fallback: Verwende alert wenn Container nicht gefunden
            alert(type === 'error' ? 'Fehler: ' + message : message);
            return;
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠'
        };
        
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || 'ℹ'}</span>
            <div class="toast-content">
                <p class="toast-message">${this.escapeHtml(message)}</p>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Automatisch nach 3 Sekunden entfernen
        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, 3000);
    }
    
    showOrgCardMenu(event, orgUuid) {
        event.stopPropagation();
        
        // Schließe alle anderen Menüs
        document.querySelectorAll('.org-card-menu-dropdown').forEach(menu => {
            if (menu.id !== `menu-${orgUuid}`) {
                menu.style.display = 'none';
            }
        });
        
        const menu = document.getElementById(`menu-${orgUuid}`);
        if (menu) {
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }
        
        // Schließe Menü beim Klick außerhalb
        setTimeout(() => {
            document.addEventListener('click', function closeMenu(e) {
                if (!e.target.closest('.org-result-card-menu')) {
                    document.querySelectorAll('.org-card-menu-dropdown').forEach(m => m.style.display = 'none');
                    document.removeEventListener('click', closeMenu);
                }
            }, { once: true });
        }, 0);
    }
    
    async showAuditTrail(orgUuid) {
        try {
            const auditTrail = await window.API.getOrgAuditTrail(orgUuid);
            this.renderAuditTrailModal(orgUuid, auditTrail);
        } catch (error) {
            console.error('Error loading audit trail:', error);
            this.showError('Fehler beim Laden des Audit-Trails: ' + error.message);
        }
    }
    
    renderAuditTrailModal(orgUuid, auditTrail) {
        let modal = document.getElementById('modal-audit-trail');
        if (!modal) {
            // Erstelle Modal falls nicht vorhanden
            const modalHtml = `
                <div id="modal-audit-trail" class="modal">
                    <div class="modal-content" style="max-width: 900px;">
                        <div class="modal-header">
                            <h3>Audit-Trail</h3>
                            <button class="modal-close" onclick="app.closeAuditTrailModal()">&times;</button>
                        </div>
                        <div class="modal-body" id="audit-trail-content">
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            modal = document.getElementById('modal-audit-trail');
        }
        
        const content = document.getElementById('audit-trail-content');
        if (!content) return;
        
        if (!auditTrail || auditTrail.length === 0) {
            content.innerHTML = '<p>Keine Änderungen protokolliert.</p>';
        } else {
            const fieldLabels = {
                'name': 'Name',
                'org_kind': 'Organisationsart',
                'external_ref': 'Kundennummer',
                'industry': 'Branche',
                'industry_main_uuid': 'Branche (Hauptklasse)',
                'industry_sub_uuid': 'Branche (Unterklasse)',
                'revenue_range': 'Umsatzspanne',
                'employee_count': 'Mitarbeiteranzahl',
                'website': 'Website',
                'notes': 'Notizen',
                'status': 'Status',
                'account_owner_user_id': 'Account Owner',
                'account_owner_since': 'Account Owner seit'
            };
            
            content.innerHTML = `
                <div class="audit-trail-list">
                    ${auditTrail.map(entry => {
                        const fieldLabel = fieldLabels[entry.field_name] || entry.field_name || 'Organisation erstellt';
                        const date = new Date(entry.created_at);
                        const dateStr = date.toLocaleString('de-DE', { 
                            year: 'numeric', 
                            month: '2-digit', 
                            day: '2-digit', 
                            hour: '2-digit', 
                            minute: '2-digit' 
                        });
                        
                        let changeText = '';
                        if (entry.action === 'create') {
                            changeText = 'Organisation erstellt';
                        } else if (entry.field_name) {
                            const oldVal = entry.old_value || '(leer)';
                            const newVal = entry.new_value || '(leer)';
                            changeText = `${fieldLabel}: "${oldVal}" → "${newVal}"`;
                        }
                        
                        return `
                            <div class="audit-trail-entry">
                                <div class="audit-trail-entry-header">
                                    <span class="audit-trail-user">${this.escapeHtml(entry.user_id)}</span>
                                    <span class="audit-trail-date">${dateStr}</span>
                                </div>
                                <div class="audit-trail-entry-body">
                                    ${changeText}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }
        
        modal.style.display = 'flex';
    }
    
    closeAuditTrailModal() {
        const modal = document.getElementById('modal-audit-trail');
        if (modal) {
            modal.style.display = 'none';
        }
    }
    
    clearSearchResults() {
        // Lösche Suchergebnisse
        const orgsList = document.getElementById('orgs-list');
        if (orgsList) {
            orgsList.innerHTML = '';
        }
        
        // Zeige "Zuletzt verwendet" wieder an
        const recentSection = document.getElementById('org-recent-section');
        if (recentSection) {
            recentSection.style.display = 'block';
            this.loadRecentOrgs();
        }
        
        // Verstecke Empty State
        const emptyState = document.getElementById('org-empty-state');
        if (emptyState) {
            emptyState.style.display = 'none';
        }
    }
    
    async archiveOrg(orgUuid) {
        if (!confirm('Möchten Sie diese Organisation wirklich archivieren?\n\nArchivierte Organisationen erscheinen nicht mehr in aktiven Listen und Reports, sind aber weiterhin in der Suche auffindbar.')) {
            return;
        }
        
        try {
            await window.API.archiveOrg(orgUuid);
            this.showSuccess('Organisation wurde archiviert');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error archiving org:', error);
            this.showError('Fehler beim Archivieren: ' + error.message);
        }
    }
    
    async unarchiveOrg(orgUuid) {
        if (!confirm('Möchten Sie diese Organisation reaktivieren?\n\nDie Organisation wird wieder in aktiven Listen und Reports angezeigt.')) {
            return;
        }
        
        try {
            await window.API.unarchiveOrg(orgUuid);
            this.showSuccess('Organisation wurde reaktiviert');
            await this.showOrgDetail(orgUuid);
        } catch (error) {
            console.error('Error unarchiving org:', error);
            this.showError('Fehler beim Reaktivieren: ' + error.message);
        }
    }
}

// Initialize app
const app = new TOM3App();
window.app = app;

// Test-Funktion für Toasts (kann in der Browser-Konsole aufgerufen werden)
window.testToast = function() {
    app.showSuccess('Test: Toast-Benachrichtigung funktioniert!');
    setTimeout(() => app.showError('Test: Fehler-Toast'), 1000);
    setTimeout(() => app.showToast('Test: Info-Toast', 'warning'), 2000);
};


