/**
 * TOM3 - Inside Sales Disposition Module
 * Handles disposition buttons and handover forms
 */

import { Utils } from './utils.js';

export class InsideSalesDispositionModule {
    constructor(insideSalesModule) {
        this.insideSalesModule = insideSalesModule;
    }
    
    /**
     * Öffnet Disposition (setzt Fokus)
     */
    openDisposition(outcome) {
        this.insideSalesModule.isDispositionOpen = true;
        const sheet = document.getElementById('disposition-sheet');
        if (sheet) {
            document.getElementById('disposition-notes')?.focus();
            
            if (outcome === 'call_ended') {
                const reachedBtn = document.querySelector('.outcome-btn[data-outcome="erreicht"]');
                if (reachedBtn) {
                    document.querySelectorAll('.outcome-btn').forEach(b => b.classList.remove('active'));
                    reachedBtn.classList.add('active');
                }
            }
            
            if (outcome && outcome !== 'call_ended') {
                this.updateOutcomeText(outcome);
            }
        }
    }
    
    /**
     * Aktualisiert Outcome-Text in Notiz
     */
    updateOutcomeText(outcome) {
        const notesField = document.getElementById('disposition-notes');
        if (!notesField) return;
        
        const outcomeTexts = {
            'erreicht': 'Erreicht',
            'nicht_erreicht': 'Nicht erreicht',
            'rueckruf': 'Rückruf vereinbart',
            'falsche_nummer': 'Falsche Nummer',
            'kein_bedarf': 'Kein Bedarf',
            'qualifiziert': 'Qualifiziert'
        };
        const newText = outcomeTexts[outcome] || outcome;
        const currentText = notesField.value;
        
        if (!currentText.trim()) {
            notesField.value = newText;
        } else {
            const exactMatch = Object.values(outcomeTexts).find(text => currentText.trim() === text);
            if (exactMatch) {
                notesField.value = newText;
            } else {
                let foundOutcomeText = null;
                let remainingText = '';
                
                for (const outcomeText of Object.values(outcomeTexts)) {
                    const escapedText = outcomeText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const regex = new RegExp(`^${escapedText}(\\s*[-–—]\\s*|\\s+)(.*)$`, 'i');
                    const match = currentText.match(regex);
                    
                    if (match) {
                        foundOutcomeText = outcomeText;
                        remainingText = match[2] ? match[2].trim() : '';
                        break;
                    }
                    
                    if (currentText.trim().toLowerCase().startsWith(outcomeText.toLowerCase())) {
                        const afterText = currentText.substring(outcomeText.length).trim();
                        if (afterText.startsWith('-') || afterText.startsWith('–') || afterText.startsWith('—') || afterText.startsWith(' ')) {
                            foundOutcomeText = outcomeText;
                            remainingText = afterText.replace(/^[-–—\s]+/, '').trim();
                            break;
                        }
                    }
                }
                
                if (foundOutcomeText) {
                    if (remainingText) {
                        notesField.value = `${newText} - ${remainingText}`;
                    } else {
                        notesField.value = newText;
                    }
                } else {
                    notesField.value = `${newText} - ${currentText.trim()}`;
                }
            }
        }
    }
    
    /**
     * Schließt Disposition (leert Felder)
     */
    closeDisposition() {
        this.insideSalesModule.isDispositionOpen = false;
        const notesField = document.getElementById('disposition-notes');
        if (notesField) {
            notesField.value = '';
        }
        const snoozeField = document.getElementById('snooze-custom');
        if (snoozeField) {
            snoozeField.value = '';
        }
        document.querySelectorAll('.snooze-btn.active').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelectorAll('.outcome-btn.active').forEach(btn => {
            btn.classList.remove('active');
        });
    }
    
    /**
     * Setzt Snooze
     */
    setSnooze(offset) {
        const input = document.getElementById('snooze-custom');
        let date;
        
        if (offset === 'today-16') {
            date = new Date();
            date.setHours(16, 0, 0, 0);
        } else if (offset === 'tomorrow-10') {
            date = new Date();
            date.setDate(date.getDate() + 1);
            date.setHours(10, 0, 0, 0);
        } else if (offset === '+3d') {
            date = new Date();
            date.setDate(date.getDate() + 3);
        } else if (offset === '+1w') {
            date = new Date();
            date.setDate(date.getDate() + 7);
        } else {
            return;
        }
        
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        input.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    /**
     * Öffnet Handover Form
     */
    openHandoverForm(type) {
        this.closeDisposition();
        
        if (type === 'quote') {
            const form = document.getElementById('handover-form-quote');
            if (form) {
                form.style.display = 'block';
                document.getElementById('handover-need-summary').focus();
            }
        } else if (type === 'data_check') {
            const form = document.getElementById('handover-form-data-check');
            if (form) {
                form.style.display = 'block';
                document.getElementById('data-check-issue').focus();
            }
        }
    }
    
    /**
     * Schließt Handover Form
     */
    closeHandoverForm() {
        const formQuote = document.getElementById('handover-form-quote');
        const formDataCheck = document.getElementById('handover-form-data-check');
        
        if (formQuote) {
            formQuote.style.display = 'none';
            document.getElementById('handover-need-summary').value = '';
            document.getElementById('handover-contact-hint').value = '';
            document.getElementById('handover-next-step').value = '';
        }
        
        if (formDataCheck) {
            formDataCheck.style.display = 'none';
            document.getElementById('data-check-issue').value = '';
            document.getElementById('data-check-request').value = '';
            document.getElementById('data-check-contact-hint').value = '';
            document.getElementById('data-check-next-step').value = '';
            document.getElementById('data-check-links').value = '';
        }
    }
    
    /**
     * Submit Handover
     */
    async submitHandover(handoffType) {
        if (!this.insideSalesModule.currentWorkItem) return;
        
        try {
            let handoverData = {};
            
            if (handoffType === 'QUOTE_REQUEST') {
                const needSummary = document.getElementById('handover-need-summary')?.value;
                const contactHint = document.getElementById('handover-contact-hint')?.value;
                const nextStep = document.getElementById('handover-next-step')?.value;
                
                if (!needSummary || !contactHint || !nextStep) {
                    Utils.showError('Bitte füllen Sie alle Pflichtfelder aus');
                    return;
                }
                
                handoverData = {
                    handoff_type: 'QUOTE_REQUEST',
                    need_summary: needSummary,
                    contact_hint: contactHint,
                    next_step: nextStep
                };
            } else if (handoffType === 'DATA_CHECK') {
                const issue = document.getElementById('data-check-issue')?.value;
                const request = document.getElementById('data-check-request')?.value;
                const contactHint = document.getElementById('data-check-contact-hint')?.value;
                const nextStep = document.getElementById('data-check-next-step')?.value;
                const links = document.getElementById('data-check-links')?.value;
                
                if (!issue || !request) {
                    Utils.showError('Bitte füllen Sie alle Pflichtfelder aus');
                    return;
                }
                
                handoverData = {
                    handoff_type: 'DATA_CHECK',
                    issue: issue,
                    request: request,
                    contact_hint: contactHint || null,
                    next_step: nextStep || null,
                    links: links ? links.split('\n').filter(l => l.trim()) : []
                };
            }
            
            await window.API.request(`/work-items/${this.insideSalesModule.currentWorkItem.case_uuid}/handover`, {
                method: 'POST',
                body: handoverData
            });
            
            Utils.showSuccess('Übergabe erfolgreich');
            this.closeHandoverForm();
            
            // Lade nächsten Lead
            await this.insideSalesModule.dialerModule.loadNextLead();
            
        } catch (error) {
            console.error('Error submitting handover:', error);
            Utils.showError('Fehler bei der Übergabe: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
    
    /**
     * Speichert Disposition
     * Setzt Stage auf IN_PROGRESS wenn noch NEW (echte Aktion)
     */
    async saveDisposition() {
        if (!this.insideSalesModule.currentWorkItem) {
            Utils.showError('Kein Lead geladen');
            return;
        }
        
        try {
            const notes = document.getElementById('disposition-notes')?.value || '';
            const snoozeInput = document.getElementById('snooze-custom');
            const snoozeValue = snoozeInput?.value;
            
            let nextActionAt = null;
            if (snoozeValue) {
                nextActionAt = new Date(snoozeValue).toISOString();
            }
            
            const activeOutcomeBtn = document.querySelector('.outcome-btn.active');
            const outcome = activeOutcomeBtn ? activeOutcomeBtn.dataset.outcome : null;
            
            // Setze IN_PROGRESS wenn noch NEW (Disposition speichern = echte Aktion)
            if (this.insideSalesModule.currentWorkItem.stage === 'NEW') {
                await this.insideSalesModule.dialerModule.applyStageTransition('IN_PROGRESS');
            }
            
            const activityData = {
                notes: notes,
                outcome: outcome,
                next_action_at: nextActionAt
            };
            
            // Wenn Activity-ID vorhanden (z.B. nach Call), aktualisiere diese
            if (this.insideSalesModule.currentActivityId) {
                await window.API.request(`/telephony/activities/${this.insideSalesModule.currentActivityId}/finalize`, {
                    method: 'POST',
                    body: activityData
                });
            } else {
                // Erstelle neue Activity
                await window.API.request(`/work-items/${this.insideSalesModule.currentWorkItem.case_uuid}/activities`, {
                    method: 'POST',
                    body: activityData
                });
            }
            
            Utils.showSuccess('Disposition gespeichert');
            this.closeDisposition();
            
            // Lade Timeline neu
            await this.insideSalesModule.timelineModule.loadTimeline(this.insideSalesModule.currentWorkItem.case_uuid);
            
            // Lade nächsten Lead
            await this.insideSalesModule.dialerModule.loadNextLead();
            
        } catch (error) {
            console.error('Error saving disposition:', error);
            Utils.showError('Fehler beim Speichern: ' + (error.message || 'Unbekannter Fehler'));
        }
    }
}

