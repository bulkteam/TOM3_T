/**
 * TOM3 - Inside Sales Module (Koordinator)
 * Koordiniert alle Inside Sales Sub-Module
 */

import { Utils } from './utils.js';
import { InsideSalesQueueModule } from './inside-sales-queue.js';
import { InsideSalesDialerModule } from './inside-sales-dialer.js';
import { InsideSalesTimelineModule } from './inside-sales-timeline.js';
import { InsideSalesDispositionModule } from './inside-sales-disposition.js';

export class InsideSalesModule {
    constructor(app) {
        this.app = app;
        
        // Helper: Ermittelt Base-Path für API-Calls
        this.getApiUrl = (path) => {
            const basePath = window.location.pathname
                .replace(/\/index\.html$/, '')
                .replace(/\/login\.php$/, '')
                .replace(/\/monitoring\.html$/, '')
                .replace(/\/$/, '') || '';
            return `${basePath}/api${path.startsWith('/') ? path : '/' + path}`;
        };
        
        this.currentWorkItem = null;
        this.queue = [];
        this.timeline = [];
        this.isDispositionOpen = false;
        this.currentCall = null;
        this.callPollingInterval = null;
        this.currentActivityId = null;
        this.isInitializing = false;
        this.currentMode = null; // 'queue' oder 'dialer'
        this.currentSort = { field: 'stars', direction: 'desc' };
        this.currentTab = 'new';
        
        // Initialisiere Sub-Module
        this.queueModule = new InsideSalesQueueModule(this);
        this.dialerModule = new InsideSalesDialerModule(this);
        this.timelineModule = new InsideSalesTimelineModule(this);
        this.dispositionModule = new InsideSalesDispositionModule(this);
    }
    
    /**
     * Initialisiert Inside Sales Seite
     */
    async init() {
        const page = document.getElementById('page-inside-sales');
        if (!page) {
            console.error('[InsideSales] page-inside-sales nicht gefunden');
            return;
        }
        
        const hash = window.location.hash;
        
        let leadUuid = null;
        let tabParam = null;
        let sortFieldParam = null;
        let sortOrderParam = null;
        
        if (hash.includes('?')) {
            const hashParts = hash.split('?');
            const hashParams = new URLSearchParams(hashParts[1]);
            leadUuid = hashParams.get('lead');
            tabParam = hashParams.get('tab');
            sortFieldParam = hashParams.get('sort');
            sortOrderParam = hashParams.get('order');
        }
        
        if (!leadUuid) {
            const urlParams = new URLSearchParams(window.location.search);
            leadUuid = urlParams.get('lead');
            if (!tabParam) {
                tabParam = urlParams.get('tab');
            }
            if (!sortFieldParam) {
                sortFieldParam = urlParams.get('sort');
            }
            if (!sortOrderParam) {
                sortOrderParam = urlParams.get('order');
            }
        }
        
        const isDialerMode = hash.includes('dialer') || hash.includes('inside-sales/dialer');
        
        if (tabParam) {
            this.currentTab = tabParam;
        } else {
            if (isDialerMode) {
                if (!this.currentTab) {
                    this.currentTab = 'new';
                }
            } else {
                this.currentTab = 'new';
            }
        }
        
        if (sortFieldParam && sortOrderParam) {
            this.currentSort = { field: sortFieldParam, direction: sortOrderParam };
        }
        
        if (isDialerMode) {
            this.currentMode = 'dialer';
            await this.dialerModule.initDialer(page);
            
            if (leadUuid) {
                await this.dialerModule.loadSpecificLead(leadUuid);
            }
        } else {
            this.currentMode = 'queue';
            await this.queueModule.initQueue(page);
        }
    }
    
    /**
     * Delegiert an queueModule
     */
    async initQueue(container) {
        return await this.queueModule.initQueue(container);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async initDialer(container) {
        return await this.dialerModule.initDialer(container);
    }
    
    /**
     * Delegiert an queueModule
     */
    async loadQueue(tab) {
        return await this.queueModule.loadQueue(tab);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async loadNextLead(tab = null, markAsInProgress = false) {
        return await this.dialerModule.loadNextLead(tab, markAsInProgress);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async loadSpecificLead(workItemUuid) {
        return await this.dialerModule.loadSpecificLead(workItemUuid);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async renderLeadCard(workItem) {
        return await this.dialerModule.renderLeadCard(workItem);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async setStars(stars) {
        return await this.dialerModule.setStars(stars);
    }
    
    /**
     * Delegiert an dispositionModule
     */
    openDisposition(outcome) {
        return this.dispositionModule.openDisposition(outcome);
    }
    
    /**
     * Delegiert an dispositionModule
     */
    closeDisposition() {
        return this.dispositionModule.closeDisposition();
    }
    
    /**
     * Delegiert an dispositionModule
     */
    setSnooze(offset) {
        return this.dispositionModule.setSnooze(offset);
    }
    
    /**
     * Delegiert an dispositionModule
     */
    openHandoverForm(type) {
        return this.dispositionModule.openHandoverForm(type);
    }
    
    /**
     * Delegiert an dispositionModule
     */
    closeHandoverForm() {
        return this.dispositionModule.closeHandoverForm();
    }
    
    /**
     * Delegiert an dispositionModule
     */
    async submitHandover(handoffType) {
        return await this.dispositionModule.submitHandover(handoffType);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async startCallWithNumber(phoneNumber) {
        return await this.dialerModule.startCallWithNumber(phoneNumber);
    }
    
    /**
     * Delegiert an dialerModule
     */
    startCallPolling() {
        return this.dialerModule.startCallPolling();
    }
    
    /**
     * Delegiert an dialerModule
     */
    stopCallPolling() {
        return this.dialerModule.stopCallPolling();
    }
    
    /**
     * Delegiert an dialerModule
     */
    async endCall() {
        return await this.dialerModule.endCall();
    }
    
    /**
     * Delegiert an dialerModule
     */
    getCallStatusText(state) {
        return this.dialerModule.getCallStatusText(state);
    }
    
    /**
     * Delegiert an dispositionModule
     */
    async saveDisposition() {
        return await this.dispositionModule.saveDisposition();
    }
    
    /**
     * Delegiert an timelineModule
     */
    async loadTimeline(workItemUuid) {
        return await this.timelineModule.loadTimeline(workItemUuid);
    }
    
    /**
     * Delegiert an queueModule
     */
    async markLeadAsInProgress(workItemUuid) {
        return await this.queueModule.markLeadAsInProgress(workItemUuid);
    }
    
    /**
     * Delegiert an dialerModule
     */
    openCompanyEdit() {
        return this.dialerModule.openCompanyEdit();
    }
    
    /**
     * Delegiert an dialerModule
     */
    setupOrgEditCloseListener(orgUuid) {
        return this.dialerModule.setupOrgEditCloseListener(orgUuid);
    }
    
    /**
     * Delegiert an dialerModule
     */
    async refreshCurrentLead() {
        return await this.dialerModule.refreshCurrentLead();
    }
    
    /**
     * Delegiert an dialerModule
     */
    openAddPerson() {
        return this.dialerModule.openAddPerson();
    }
    
    /**
     * Delegiert an dialerModule
     */
    setupPersonFormCloseListener() {
        return this.dialerModule.setupPersonFormCloseListener();
    }
    
    /**
     * Delegiert an dialerModule
     */
    async loadPersonsList(orgUuid) {
        return await this.dialerModule.loadPersonsList(orgUuid);
    }
    
    /**
     * Delegiert an queueModule
     */
    async loadDialerQueue(tab = null) {
        return await this.queueModule.loadDialerQueue(tab);
    }
    
    /**
     * Delegiert an dialerModule
     */
    renderEmptyDialer() {
        return this.dialerModule.renderEmptyDialer();
    }
    
    /**
     * Delegiert an queueModule
     */
    setupSortHandlers() {
        return this.queueModule.setupSortHandlers();
    }
    
    /**
     * Delegiert an queueModule
     */
    updateSortIndicator() {
        return this.queueModule.updateSortIndicator();
    }
    
    /**
     * Delegiert an dialerModule
     */
    setupDialerEvents() {
        return this.dialerModule.setupDialerEvents();
    }
}
