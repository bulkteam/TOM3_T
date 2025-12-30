/**
 * TOM3 - Organization Detail View Module
 * Handles rendering of organization detail view
 */

import { Utils } from './utils.js';

export class OrgDetailViewModule {
    constructor(app) {
        this.app = app;
    }
    
    translateOrgKind(orgKind) {
        const translations = {
            'customer': 'Kunde',
            'supplier': 'Lieferant',
            'consultant': 'Berater',
            'engineering_firm': 'Ingenieurbüro',
            'internal': 'Intern',
            'other': 'Sonstiges'
        };
        return translations[orgKind] || orgKind || '-';
    }
    
    translateStatus(status) {
        const translations = {
            'lead': 'Lead',
            'prospect': 'Interessent',
            'customer': 'Kunde',
            'inactive': 'Inaktiv'
        };
        return translations[status] || status || '-';
    }
    
    translateRelationType(relationType) {
        const relationTypeMap = {
            // Konzern & Struktur
            'parent_of': 'Muttergesellschaft von',
            'subsidiary_of': 'Tochtergesellschaft von',
            'sister_company': 'Schwestergesellschaft',
            'holding_of': 'Holding von',
            'operating_company_of': 'Operative Gesellschaft von',
            'branch_of': 'Niederlassung von',
            'location_of': 'Standort von',
            'division_of': 'Zweigstelle von',
            // Beteiligungen
            'owns_stake_in': 'Beteiligung an',
            'joint_venture_with': 'Joint Venture mit',
            'ubo_of': 'Ultimate Beneficial Owner von',
            // Transaktionen
            'acquired_from': 'Übernommen von',
            'merged_with': 'Fusioniert mit',
            'spun_off_from': 'Abgespalten von',
            'legal_successor_of': 'Rechtsnachfolger von',
            'in_liquidation_by': 'In Liquidation durch',
            // Geschäftliche Beziehungen
            'customer_of': 'Kunde von',
            'supplier_of': 'Lieferant von',
            'distributor_of': 'Distributor von',
            'reseller_of': 'Reseller von',
            'partner_of': 'Partner von',
            'service_provider_of': 'Service Provider von',
            'logistics_partner_of': 'Logistikpartner von',
            'franchise_giver_of': 'Franchisegeber von',
            'franchise_taker_of': 'Franchisenehmer von',
            'contract_partner_of': 'Vertragspartner von',
            'framework_contract_for': 'Rahmenvertrag für',
            'implementation_partner_for': 'Implementierungspartner für'
        };
        return relationTypeMap[relationType] || relationType || '-';
    }
    
    renderOrgDetail(org) {
        try {
            const orgUuid = org.org_uuid || org.uuid;
            if (!orgUuid) {
                throw new Error('orgUuid fehlt in den Daten');
            }
            
            const health = org.health || { status: 'unknown', reasons: [] };
            const healthStatusClass = `health-${health.status || 'unknown'}`;
            const healthStatusLabel = {
                'green': 'Gesund',
                'yellow': 'Auffällig',
                'red': 'Kritisch',
                'unknown': 'Unbekannt'
            }[health.status] || 'Unbekannt';
            
            // Sicherstellen, dass Arrays existieren
            const addresses = Array.isArray(org.addresses) ? org.addresses : [];
            const communication_channels = Array.isArray(org.communication_channels) ? org.communication_channels : [];
            const vat_registrations = Array.isArray(org.vat_registrations) ? org.vat_registrations : [];
            const relations = Array.isArray(org.relations) ? org.relations : [];
            
            return `
            <div class="org-detail" data-org-uuid="${orgUuid}">
                <!-- Sticky Header mit Firmenname und Close-Button -->
                <div class="org-detail-header-sticky">
                    <div class="org-detail-header-content">
                        <h2 class="org-detail-title">${Utils.escapeHtml(org.name || 'Unbekannt')}</h2>
                        <div class="org-detail-header-right">
                            <div class="org-detail-header-meta">
                                ${org.external_ref ? `<span class="org-detail-ref">${Utils.escapeHtml(org.external_ref)}</span>` : ''}
                                ${org.org_kind ? `<span class="org-detail-badge">${Utils.escapeHtml(this.translateOrgKind(org.org_kind))}</span>` : ''}
                                ${org.status ? `<span class="org-detail-badge">${Utils.escapeHtml(this.translateStatus(org.status))}</span>` : ''}
                            </div>
                            <div class="org-detail-header-actions">
                                <div class="org-detail-menu">
                                    <button class="org-detail-menu-toggle" type="button" aria-label="Menü öffnen">
                                        <span class="org-detail-menu-dots">&#8230;</span>
                                    </button>
                                    <div class="org-detail-menu-dropdown">
                                        <button class="org-detail-menu-item" data-action="audit-trail">
                                            <span class="org-detail-menu-icon">📋</span>
                                            <span>Audit-Trail</span>
                                        </button>
                                    </div>
                                </div>
                                <button class="modal-close org-detail-close">&times;</button>
                            </div>
                        </div>
                    </div>
                    <div class="org-detail-header-divider"></div>
                </div>
                
                <!-- Account Owner & Gesundheit -->
                <div class="org-detail-section org-detail-account-section">
                    <div class="org-detail-section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h4 style="margin: 0;">Account & Grunddaten</h4>
                        <button class="org-detail-edit-btn" onclick="app.orgDetail.editModule.toggleOrgEditMode('${orgUuid}')">Bearbeiten</button>
                    </div>
                    <div class="org-detail-account-info">
                        <div class="org-detail-account-owner">
                            <strong>Account-Verantwortung:</strong>
                            <div class="org-detail-account-owner-value" id="org-field-account_owner">
                                ${org.account_owner_user_id ? `
                                    ${Utils.escapeHtml(org.account_owner_name || org.account_owner_user_id || '-')}
                                    ${org.account_owner_since ? `<span class="text-light">(seit ${new Date(org.account_owner_since).toLocaleDateString('de-DE')})</span>` : ''}
                                ` : '<span class="org-detail-empty">Nicht zugeordnet</span>'}
                            </div>
                            <select class="org-detail-input" id="org-input-account_owner" style="display: none;">
                                <option value="">-- Kein Owner --</option>
                                <!-- Wird dynamisch geladen -->
                            </select>
                        </div>
                        <div class="org-detail-health">
                            <strong>Kundengesundheit:</strong>
                            <div class="org-detail-health-status ${healthStatusClass}">
                                <span class="health-indicator"></span>
                                <span class="health-label">${healthStatusLabel}</span>
                            </div>
                            ${health.reasons && health.reasons.length > 0 ? `
                                <div class="org-detail-health-reasons">
                                    ${health.reasons.map(reason => {
                                        const reasonText = {
                                            'no_contact': `Kein Kontakt seit ${reason.days} Tagen`,
                                            'no_contact_recorded': 'Kein Kontakt erfasst',
                                            'stale_offers': `${reason.count} veraltete Angebote`,
                                            'open_escalations': `${reason.count} offene Eskalationen`,
                                            'missing_data': reason.field ? `Fehlende Daten: ${reason.field}` : 'Fehlende Daten'
                                        }[reason.type] || reason.type;
                                        return `<span class="health-reason health-reason-${reason.severity || 'yellow'}">${Utils.escapeHtml(reasonText)}</span>`;
                                    }).join('')}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </div>
                
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Grunddaten</h4>
                    </div>
                    <div class="org-detail-grid">
                        <div class="org-detail-item">
                            <strong>Name:</strong>
                            <div class="org-detail-value" id="org-field-name">${Utils.escapeHtml(org.name || '-')}</div>
                            <input type="text" class="org-detail-input" id="org-input-name" value="${Utils.escapeHtml(org.name || '')}" style="display: none;">
                        </div>
                        <div class="org-detail-item">
                            <strong>Kundennummer:</strong>
                            <div class="org-detail-value">${Utils.escapeHtml(org.external_ref || '-')}</div>
                        </div>
                        <div class="org-detail-item">
                            <strong>Typ:</strong>
                            <div class="org-detail-value" id="org-field-org_kind">${Utils.escapeHtml(this.translateOrgKind(org.org_kind))}</div>
                            <select class="org-detail-input" id="org-input-org_kind" style="display: none;">
                                <option value="customer" ${org.org_kind === 'customer' ? 'selected' : ''}>Kunde</option>
                                <option value="supplier" ${org.org_kind === 'supplier' ? 'selected' : ''}>Lieferant</option>
                                <option value="consultant" ${org.org_kind === 'consultant' ? 'selected' : ''}>Berater</option>
                                <option value="engineering_firm" ${org.org_kind === 'engineering_firm' ? 'selected' : ''}>Ingenieurbüro</option>
                                <option value="internal" ${org.org_kind === 'internal' ? 'selected' : ''}>Intern</option>
                                <option value="other" ${org.org_kind === 'other' ? 'selected' : ''}>Sonstiges</option>
                            </select>
                        </div>
                        <div class="org-detail-item">
                            <strong>Status:</strong>
                            <div class="org-detail-value" id="org-field-status">${Utils.escapeHtml(this.translateStatus(org.status))}</div>
                            <select class="org-detail-input" id="org-input-status" style="display: none;">
                                <option value="lead" ${org.status === 'lead' ? 'selected' : ''}>Lead</option>
                                <option value="prospect" ${org.status === 'prospect' ? 'selected' : ''}>Interessent</option>
                                <option value="customer" ${org.status === 'customer' ? 'selected' : ''}>Kunde</option>
                                <option value="inactive" ${org.status === 'inactive' ? 'selected' : ''}>Inaktiv</option>
                            </select>
                        </div>
                        <div class="org-detail-item">
                            <strong>Website:</strong>
                            <div class="org-detail-value" id="org-field-website">
                                ${org.website ? `<a href="${Utils.escapeHtml(org.website)}" target="_blank" class="org-detail-link">${Utils.escapeHtml(org.website)}</a>` : '<span class="org-detail-empty">-</span>'}
                            </div>
                            <input type="text" class="org-detail-input" id="org-input-website" value="${Utils.escapeHtml(org.website || '')}" style="display: none;">
                        </div>
                        <div class="org-detail-item">
                            <strong>Branche:</strong>
                            <div class="org-detail-value" id="org-field-industry">
                                ${org.industry_main_name ? `${Utils.escapeHtml(org.industry_main_name)}${org.industry_sub_name ? ' / ' + Utils.escapeHtml(org.industry_sub_name) : ''}` : '<span class="org-detail-empty">-</span>'}
                            </div>
                            <div class="org-detail-input" style="display: none;">
                                <select id="org-input-industry_main" style="width: 100%; margin-bottom: 0.5rem;">
                                    <option value="">-- Bitte wählen --</option>
                                    <!-- Wird dynamisch geladen -->
                                </select>
                                <select id="org-input-industry_sub" style="width: 100%;">
                                    <option value="">-- Zuerst Hauptbranche wählen --</option>
                                    <!-- Wird dynamisch geladen -->
                                </select>
                            </div>
                        </div>
                        ${org.revenue_range ? `
                            <div class="org-detail-item">
                                <strong>Umsatzgröße:</strong>
                                <div class="org-detail-value">${Utils.escapeHtml(org.revenue_range || '-')}</div>
                            </div>
                        ` : ''}
                        ${org.employee_count ? `
                            <div class="org-detail-item">
                                <strong>Mitarbeiter (ca.):</strong>
                                <div class="org-detail-value">${Utils.escapeHtml(String(org.employee_count || '-'))}</div>
                            </div>
                        ` : ''}
                    </div>
                    
                    <!-- Notizen in Grunddaten-Sektion (vor Adressen, im Edit-Modus oberhalb der Buttons) -->
                    <div class="org-detail-item" style="grid-column: 1 / -1; margin-top: 1rem;">
                        <strong>Notizen:</strong>
                        <div class="org-detail-value" id="org-field-notes">${org.notes ? Utils.escapeHtml(org.notes) : '<span class="org-detail-empty">Keine Notizen</span>'}</div>
                        <textarea class="org-detail-input" id="org-input-notes" rows="4" style="display: none; width: 100%;">${Utils.escapeHtml(org.notes || '')}</textarea>
                    </div>
                    
                    <!-- Speichern/Abbrechen Buttons nach Notizen -->
                    <div class="org-detail-actions" id="org-edit-actions" style="display: none; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border); grid-column: 1 / -1;">
                        <button class="org-detail-save-btn" onclick="app.orgDetail.editModule.saveOrgChanges('${orgUuid}')">Speichern</button>
                        <button class="org-detail-cancel-btn" onclick="app.orgDetail.editModule.cancelOrgEdit('${orgUuid}')">Abbrechen</button>
                    </div>
                </div>
                
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Adressen${addresses.length > 0 ? ` (${addresses.length})` : ''}</h4>
                        <button class="btn btn-sm btn-primary" onclick="app.orgDetail.addressModule.showAddAddressModal('${orgUuid}')">+ Neue Adresse</button>
                    </div>
                    ${addresses.length > 0 ? `
                        <div class="org-detail-list">
                            ${addresses.map(addr => `
                                <div class="org-detail-item">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div>
                                            <strong>${Utils.escapeHtml(addr.address_type || 'Adresse')}:</strong><br>
                                            ${Utils.escapeHtml(addr.street || '')} ${Utils.escapeHtml(addr.house_number || '')}<br>
                                            ${Utils.escapeHtml(addr.postal_code || '')} ${Utils.escapeHtml(addr.city || '')}
                                            ${addr.country_code ? `<br>${Utils.escapeHtml(addr.country_code)}` : ''}
                                            ${addr.vat_id ? `<br><small>USt-ID: ${Utils.escapeHtml(addr.vat_id)}</small>` : ''}
                                        </div>
                                        <button class="btn btn-sm btn-secondary" onclick="app.orgDetail.addressModule.editAddress('${orgUuid}', '${addr.address_uuid}')">Bearbeiten</button>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : `
                        <div class="org-detail-empty">Keine Adressen vorhanden</div>
                    `}
                </div>
                
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Kommunikationskanäle${communication_channels.length > 0 ? ` (${communication_channels.length})` : ''}</h4>
                        <button class="btn btn-sm btn-primary" onclick="app.orgDetail.channelModule.showAddChannelModal('${orgUuid}')">+ Neuer Kanal</button>
                    </div>
                    ${communication_channels.length > 0 ? `
                        <div class="org-detail-list">
                            ${communication_channels.map(channel => {
                                // Bestimme den Wert je nach Kanaltyp
                                let channelValue = '-';
                                if (channel.channel_type === 'email' && channel.email_address) {
                                    channelValue = channel.email_address;
                                } else if (['phone', 'mobile', 'fax', 'phone_main'].includes(channel.channel_type) && channel.number) {
                                    const parts = [];
                                    if (channel.country_code) parts.push(channel.country_code);
                                    if (channel.area_code) parts.push(channel.area_code);
                                    if (channel.number) parts.push(channel.number);
                                    if (channel.extension) parts.push('Durchwahl: ' + channel.extension);
                                    channelValue = parts.join(' ');
                                } else if (channel.value) {
                                    channelValue = channel.value;
                                }
                                
                                return `
                                <div class="org-detail-item">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div>
                                            <strong>${Utils.escapeHtml(channel.channel_type || 'Kanal')}:</strong>
                                            ${channel.label ? `<span style="color: var(--text-light);">${Utils.escapeHtml(channel.label)}</span> - ` : ''}
                                            ${Utils.escapeHtml(channelValue)}
                                            ${channel.is_primary ? '<span class="org-detail-badge org-detail-badge-primary">Primär</span>' : ''}
                                        </div>
                                        <button class="btn btn-sm btn-secondary" onclick="app.orgDetail.channelModule.editChannel('${orgUuid}', '${channel.channel_uuid}')">Bearbeiten</button>
                                    </div>
                                </div>
                            `;
                            }).join('')}
                        </div>
                    ` : `
                        <div class="org-detail-empty">Keine Kommunikationskanäle vorhanden</div>
                    `}
                </div>
                
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>USt-IDs${vat_registrations.length > 0 ? ` (${vat_registrations.length})` : ''}</h4>
                        <button class="btn btn-sm btn-primary" onclick="app.orgDetail.vatModule.showAddVatRegistrationModal('${orgUuid}')">+ Neue USt-ID</button>
                    </div>
                    ${vat_registrations.length > 0 ? `
                        <div class="org-detail-list">
                            ${vat_registrations.map(vat => {
                                // Prüfe ob USt-ID aktuell gültig ist (valid_to ist null oder in der Zukunft)
                                const isCurrentlyValid = !vat.valid_to || new Date(vat.valid_to) >= new Date();
                                
                                return `
                                <div class="org-detail-item">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div>
                                            <strong>${Utils.escapeHtml(vat.country_code || '')}:</strong> ${Utils.escapeHtml(vat.vat_id || '-')}
                                            ${isCurrentlyValid ? '<span class="org-detail-badge org-detail-badge-success">Gültig</span>' : '<span class="org-detail-badge org-detail-badge-warning">Abgelaufen</span>'}
                                            ${vat.is_primary_for_country === 1 ? '<span class="org-detail-badge org-detail-badge-primary">Primär</span>' : ''}
                                            ${vat.notes ? `<br><small style="color: var(--text-light);">${Utils.escapeHtml(vat.notes)}</small>` : ''}
                                        </div>
                                        <button class="btn btn-sm btn-secondary" onclick="app.orgDetail.vatModule.editVatRegistration('${orgUuid}', '${vat.vat_registration_uuid}')">Bearbeiten</button>
                                    </div>
                                </div>
                            `;
                            }).join('')}
                        </div>
                    ` : `
                        <div class="org-detail-empty">Keine USt-IDs vorhanden</div>
                    `}
                </div>
                
                <div class="org-detail-section">
                    <div class="org-detail-section-header">
                        <h4>Relationen${relations.length > 0 ? ` (${relations.length})` : ''}</h4>
                        <button class="btn btn-sm btn-primary" onclick="app.orgDetail.relationModule.showAddRelationDialog('${orgUuid}')">+ Neue Relation</button>
                    </div>
                    ${relations.length > 0 ? `
                        <div class="org-detail-list">
                            ${relations.map(rel => {
                                const relationTypeLabel = this.translateRelationType(rel.relation_type || '');
                                return `
                                <div class="org-detail-item">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div>
                                            <strong>${Utils.escapeHtml(relationTypeLabel)}:</strong> ${Utils.escapeHtml(rel.child_org_name || rel.parent_org_name || 'Unbekannt')}
                                            ${rel.percentage ? ` (${Utils.escapeHtml(String(rel.percentage))}%)` : ''}
                                        </div>
                                        <button class="btn btn-sm btn-secondary" onclick="app.orgDetail.relationModule.editRelation('${orgUuid}', '${rel.relation_uuid}')">Bearbeiten</button>
                                    </div>
                                </div>
                            `;
                            }).join('')}
                        </div>
                    ` : `
                        <div class="org-detail-empty">Keine Relationen vorhanden</div>
                    `}
                </div>
                
                <div class="org-detail-actions" style="margin-top: 2rem; padding-top: 2rem; border-top: 2px solid var(--border);">
                    ${org.archived_at ? `
                        <button class="btn btn-success" onclick="app.orgDetail.unarchiveOrg('${orgUuid}')">📦 Reaktivieren</button>
                        <span style="color: var(--text-light); margin-left: 1rem;">Archiviert am ${new Date(org.archived_at).toLocaleDateString('de-DE')}</span>
                    ` : `
                        <button class="btn btn-warning" onclick="app.orgDetail.archiveOrg('${orgUuid}')">📦 Archivieren</button>
                    `}
                </div>
            </div>
        `;
        } catch (error) {
            console.error('Error in renderOrgDetail:', error);
            console.error('Org data:', org);
            throw error; // Re-throw für besseres Error-Handling
        }
    }
}

