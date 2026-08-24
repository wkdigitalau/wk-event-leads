/* WK Event Leads — kanban.js
   Drag-and-drop kanban, lead detail panel, stage updates.
   Requires: SortableJS (loaded via CDN), wkelKanban (localized data)
*/
(function () {
    'use strict';

    const cfg = window.wkelKanban || {};

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        initSortable();
        initCardClicks();
        initDetailPanel();
        initFilters();
        initAddLead();
    });

    // -------------------------------------------------------------------------
    // Drag-and-drop (SortableJS)
    // -------------------------------------------------------------------------

    function initSortable() {
        document.querySelectorAll('.wkel-column-cards').forEach(function (container) {
            Sortable.create(container, {
                group:     'wkel-kanban',
                animation: 150,
                ghostClass: 'sortable-ghost',
                dragClass:  'sortable-drag',
                onEnd: function (evt) {
                    const cardEl    = evt.item;
                    const leadId    = parseInt(cardEl.dataset.id, 10);
                    const newStage  = evt.to.closest('.wkel-kanban-column').dataset.stage;
                    const oldStage  = evt.from.closest('.wkel-kanban-column').dataset.stage;

                    if (newStage === oldStage) return;

                    updateColumnCounts();

                    updateStage(leadId, newStage).catch(function () {
                        // Rollback — move card back
                        evt.from.insertBefore(cardEl, evt.from.children[evt.oldIndex] || null);
                        updateColumnCounts();
                        alert('Failed to update stage. Please try again.');
                    });
                },
            });
        });
    }

    function updateColumnCounts() {
        document.querySelectorAll('.wkel-kanban-column').forEach(function (col) {
            const count = col.querySelectorAll('.wkel-card').length;
            const countEl = col.querySelector('.wkel-column-count');
            if (countEl) countEl.textContent = count;
        });
    }

    async function updateStage(leadId, stage) {
        const res = await apiFetch('lead/' + leadId + '/stage', 'PATCH', { stage });
        if (!res.ok) throw new Error('Stage update failed');
    }

    // -------------------------------------------------------------------------
    // Card clicks → detail panel
    // -------------------------------------------------------------------------

    function initCardClicks() {
        document.getElementById('wkel-kanban-board').addEventListener('click', function (e) {
            const card = e.target.closest('.wkel-card');
            if (!card) return;
            const leadId = parseInt(card.dataset.id, 10);
            openDetailPanel(leadId);
        });
    }

    // -------------------------------------------------------------------------
    // Detail panel
    // -------------------------------------------------------------------------

    function initDetailPanel() {
        document.getElementById('wkel-detail-close').addEventListener('click', closeDetailPanel);
        document.getElementById('wkel-detail-backdrop').addEventListener('click', closeDetailPanel);
    }

    function openDetailPanel(leadId) {
        const panel = document.getElementById('wkel-detail-panel');
        const content = document.getElementById('wkel-detail-content');

        content.innerHTML = '<p style="color:#9ca3af;padding:20px 0;">Loading…</p>';
        panel.style.display = 'flex';

        apiFetch('lead/' + leadId, 'GET').then(async function (res) {
            const data = await res.json();
            renderDetailPanel(content, data);
        }).catch(function () {
            content.innerHTML = '<p>Failed to load lead.</p>';
        });
    }

    function closeDetailPanel() {
        document.getElementById('wkel-detail-panel').style.display = 'none';
    }

    function renderDetailPanel(container, lead) {
        const stages = cfg.allStages || [];

        let html = '<h2 style="margin-top:0;">' + esc(lead.fields.find(f => f.id === 'wkel_name')?.value || 'Lead') + '</h2>';

        // Meta section
        html += '<div class="wkel-detail-meta">'
            + '<div><strong>Stage:</strong> ' + esc(lead.stage_label) + '</div>'
            + '<div><strong>Type:</strong> ' + esc((lead.lead_type || 'sales').replace(/_/g, ' ')) + '</div>'
            + '<div><strong>Email:</strong> ' + esc(lead.email_status) + (lead.email_sent_at ? ' — ' + formatDate(lead.email_sent_at) : '') + '</div>'
            + '<div><strong>Event:</strong> ' + esc(lead.event) + '</div>'
            + '<div><strong>Campaign:</strong> ' + esc(lead.campaign || '—') + '</div>'
            + '<div><strong>List:</strong> ' + esc((lead.list_type || '—').replace(/_/g, ' ')) + '</div>'
            + '<div><strong>Marketing:</strong> ' + esc(lead.marketing_status || 'subscribed') + (lead.unsubscribed_at ? ' — ' + formatDate(lead.unsubscribed_at) : '') + '</div>'
            + '<div><strong>Submitted:</strong> ' + (lead.submitted_at ? formatDate(lead.submitted_at) : '—') + '</div>'
            + '</div>';

        // Stage select
        html += '<div class="wkel-detail-field"><label>Pipeline Stage</label>'
            + '<select id="wkel-detail-stage" data-lead-id="' + lead.id + '">';
        stages.forEach(function (s) {
            html += '<option value="' + esc(s.id) + '"' + (s.id === lead.stage ? ' selected' : '') + '>' + esc(s.label) + '</option>';
        });
        html += '</select></div>';

        // Classification is separate from the sales pipeline so support,
        // client-request and telemarketer records remain trackable without
        // being forced through sales stages.
        html += '<div class="wkel-detail-field"><label>Lead Type</label>'
            + '<select name="lead_type" data-lead-id="' + lead.id + '" class="wkel-detail-input">'
            + '<option value="sales"' + (lead.lead_type === 'sales' ? ' selected' : '') + '>Sales enquiry</option>'
            + '<option value="support"' + (lead.lead_type === 'support' ? ' selected' : '') + '>Support</option>'
            + '<option value="client_request"' + (lead.lead_type === 'client_request' ? ' selected' : '') + '>Client request</option>'
            + '<option value="telemarketer"' + (lead.lead_type === 'telemarketer' ? ' selected' : '') + '>Telemarketer</option>'
            + '<option value="partner"' + (lead.lead_type === 'partner' ? ' selected' : '') + '>Partner / referral</option>'
            + '<option value="other"' + (lead.lead_type === 'other' ? ' selected' : '') + '>Other</option>'
            + '</select></div>';

        html += '<div class="wkel-detail-field"><label>Next Action</label>'
            + '<input type="text" name="next_action" value="' + esc(lead.next_action || '') + '" data-lead-id="' + lead.id + '" class="wkel-detail-input">'
            + '</div>';

        // Schema fields
        lead.fields.forEach(function (field) {
            html += '<div class="wkel-detail-field"><label>' + esc(field.label) + '</label>';
            if (field.type === 'textarea') {
                html += '<textarea name="' + esc(field.id) + '" data-lead-id="' + lead.id + '" class="wkel-detail-input">' + esc(field.value) + '</textarea>';
            } else if (field.type === 'dropdown') {
                html += '<select name="' + esc(field.id) + '" data-lead-id="' + lead.id + '" class="wkel-detail-input">';
                field.options.forEach(function (opt) {
                    html += '<option value="' + esc(opt) + '"' + (opt === field.value ? ' selected' : '') + '>' + esc(opt) + '</option>';
                });
                html += '</select>';
            } else {
                html += '<input type="text" name="' + esc(field.id) + '" value="' + esc(field.value) + '" data-lead-id="' + lead.id + '" class="wkel-detail-input">';
            }
            html += '</div>';
        });

        // Admin notes
        html += '<div class="wkel-detail-field"><label>Admin Notes (private)</label>'
            + '<textarea name="admin_notes" data-lead-id="' + lead.id + '" class="wkel-detail-input" rows="3">' + esc(lead.admin_notes || '') + '</textarea>'
            + '</div>';

        html += '<div class="wkel-detail-field"><label>Log Activity</label>'
            + '<select id="wkel-activity-type"><option value="note">Note</option><option value="email_received">Email received</option><option value="call">Phone call</option><option value="meeting">Meeting</option><option value="task">Task</option></select>'
            + '<textarea id="wkel-activity-message" rows="2" placeholder="What happened?"></textarea>'
            + '<button class="button" id="wkel-activity-add" data-lead-id="' + lead.id + '">Add Activity</button>'
            + '</div>';

        // Actions
        html += '<div class="wkel-detail-actions">'
            + '<button class="button button-primary" id="wkel-detail-save" data-lead-id="' + lead.id + '">Save Changes</button>'
            + '<button class="button" id="wkel-detail-resend" data-lead-id="' + lead.id + '"' + (lead.marketing_status === 'unsubscribed' ? ' disabled' : '') + '>Resend Email</button>'
            + '<button class="button button-link-delete" id="wkel-detail-delete" data-lead-id="' + lead.id + '">Delete</button>'
            + '</div>';

        // Activity log
        if (lead.activity_log && lead.activity_log.length) {
            html += '<div class="wkel-activity-log"><h3>Activity</h3>';
            lead.activity_log.slice().reverse().forEach(function (entry) {
                html += '<div class="wkel-activity-entry">'
                    + '<span class="wkel-activity-time">' + formatDate(entry.at) + '</span>'
                    + '<span>' + esc(entry.message) + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        container.innerHTML = html;

        // Stage change from panel
        document.getElementById('wkel-detail-stage').addEventListener('change', function () {
            const id    = parseInt(this.dataset.leadId, 10);
            const stage = this.value;
            updateStage(id, stage).then(function () {
                // Move card on board
                const card = document.querySelector('.wkel-card[data-id="' + id + '"]');
                if (card) {
                    const targetContainer = document.getElementById('wkel-cards-' + stage);
                    if (targetContainer) {
                        targetContainer.appendChild(card);
                        card.dataset.stage = stage;
                        updateColumnCounts();
                    }
                }
            });
        });

        // Save
        document.getElementById('wkel-detail-save').addEventListener('click', function () {
            const id      = parseInt(this.dataset.leadId, 10);
            const payload = { stage: document.getElementById('wkel-detail-stage').value };
            container.querySelectorAll('.wkel-detail-input').forEach(function (el) {
                payload[el.name] = el.value;
            });
            apiFetch('lead/' + id, 'PATCH', payload).then(function () {
                showDetailNotice(container, 'Saved.', 'success');
            }).catch(function () {
                showDetailNotice(container, 'Save failed.', 'error');
            });
        });

        // Resend email
        document.getElementById('wkel-detail-resend').addEventListener('click', function () {
            const id = parseInt(this.dataset.leadId, 10);
            apiFetch('lead/' + id + '/resend-email', 'POST').then(function () {
                showDetailNotice(container, 'Email queued for resend.', 'success');
            });
        });

        // Delete
        document.getElementById('wkel-detail-delete').addEventListener('click', function () {
            if (!confirm('Delete this lead permanently? This cannot be undone.')) return;
            const id = parseInt(this.dataset.leadId, 10);
            apiFetch('lead/' + id, 'DELETE').then(function () {
                closeDetailPanel();
                const card = document.querySelector('.wkel-card[data-id="' + id + '"]');
                if (card) card.remove();
                updateColumnCounts();
            });
        });
    }

    function showDetailNotice(container, message, type) {
        let el = container.querySelector('.wkel-detail-notice');
        if (!el) {
            el = document.createElement('div');
            el.className = 'wkel-detail-notice';
            container.prepend(el);
        }
        el.textContent = message;
        el.style.cssText = 'padding:8px;margin-bottom:12px;border-radius:4px;background:' + (type === 'success' ? '#d1fae5' : '#fee2e2') + ';color:' + (type === 'success' ? '#065f46' : '#991b1b') + ';';
        setTimeout(function () { el.remove(); }, 3000);
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    function initFilters() {
        document.getElementById('wkel-apply-filters').addEventListener('click', function () {
            const search = document.getElementById('wkel-kanban-search').value;
            const event  = document.getElementById('wkel-filter-event').value;
            const status = document.getElementById('wkel-filter-email-status').value;

            const url = new URL(window.location.href);
            if (search) url.searchParams.set('wkel_search', search);
            else url.searchParams.delete('wkel_search');
            if (event) url.searchParams.set('wkel_event', event);
            else url.searchParams.delete('wkel_event');
            if (status) url.searchParams.set('wkel_email_status', status);
            else url.searchParams.delete('wkel_email_status');

            window.location.href = url.toString();
        });

        // Live search — filter cards in DOM without reload
        document.getElementById('wkel-kanban-search').addEventListener('input', function () {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.wkel-card').forEach(function (card) {
                const name = card.querySelector('.wkel-card-name')?.textContent.toLowerCase() || '';
                const org  = card.querySelector('.wkel-card-org')?.textContent.toLowerCase() || '';
                card.style.display = (!q || name.includes(q) || org.includes(q)) ? '' : 'none';
            });
            updateColumnCounts();
        });
    }

    // -------------------------------------------------------------------------
    // Add lead modal
    // -------------------------------------------------------------------------

    function initAddLead() {
        const modal     = document.getElementById('wkel-add-lead-modal');
        const formWrap  = document.getElementById('wkel-add-lead-form-wrap');
        const submitBtn = document.getElementById('wkel-add-lead-submit');
        const cancelBtn = document.getElementById('wkel-add-lead-cancel');

        document.getElementById('wkel-add-lead-btn').addEventListener('click', function () {
            formWrap.innerHTML = buildAddLeadForm();
            modal.style.display = 'flex';
        });

        cancelBtn.addEventListener('click', function () { modal.style.display = 'none'; });
        modal.addEventListener('click', function (e) { if (e.target === modal) modal.style.display = 'none'; });

        submitBtn.addEventListener('click', function () {
            const payload = { source: 'admin', wkel_privacy: '1' };
            formWrap.querySelectorAll('[data-field]').forEach(function (el) {
                payload[el.dataset.field] = el.value;
            });

            apiFetch('submit', 'POST', payload, true).then(async function (res) {
                const data = await res.json();
                if (!res.ok || !data.success) {
                    const firstError = data.errors ? Object.values(data.errors)[0] : '';
                    throw new Error(data.message || firstError || 'Submission failed.');
                } else {
                    modal.style.display = 'none';
                    window.location.reload();
                }
            }).catch(async function (error) {
                let message = error?.message || 'Could not save the lead. Please try again.';
                if (error && typeof error.json === 'function') {
                    try {
                        const data = await error.json();
                        const firstError = data.errors ? Object.values(data.errors)[0] : '';
                        message = data.message || firstError || message;
                    } catch (ignored) {
                        // Keep the generic message when the response is not JSON.
                    }
                }
                alert(message);
            });
        });

        document.getElementById('wkel-activity-add').addEventListener('click', function () {
            const id = parseInt(this.dataset.leadId, 10);
            const message = document.getElementById('wkel-activity-message').value.trim();
            if (!message) return;
            apiFetch('lead/' + id + '/activities', 'POST', {
                type: document.getElementById('wkel-activity-type').value,
                message: message,
            }).then(function () {
                showDetailNotice(container, 'Activity added.', 'success');
                openDetailPanel(id);
            }).catch(function () {
                showDetailNotice(container, 'Activity could not be added.', 'error');
            });
        });
    }

    function buildAddLeadForm() {
        const fields = cfg.schemaFields || [];
        let html = '';
        fields.filter(f => f.show_form).forEach(function (field) {
            html += '<div style="margin-bottom:12px;">';
            html += '<label style="display:block;font-weight:600;margin-bottom:4px;">' + esc(field.label) + (field.required ? ' *' : '') + '</label>';
            if (field.type === 'textarea') {
                html += '<textarea data-field="' + esc(field.id) + '" style="width:100%;" rows="3"></textarea>';
            } else {
                html += '<input type="' + esc(field.type === 'email' ? 'email' : 'text') + '" data-field="' + esc(field.id) + '" style="width:100%;" ' + (field.required ? 'required' : '') + '>';
            }
            html += '</div>';
        });
        // Event select
        html += '<div style="margin-bottom:12px;"><label style="display:block;font-weight:600;margin-bottom:4px;">Event</label>'
            + '<input type="text" data-field="event" style="width:100%;" placeholder="e.g. aged-care-summit-2026"></div>';
        return html;
    }

    // -------------------------------------------------------------------------
    // API helper
    // -------------------------------------------------------------------------

    function apiFetch(path, method, body, isFormSubmit) {
        const url     = cfg.restBase.replace(/\/$/, '') + '/' + path;
        const options = {
            method:  method || 'GET',
            headers: { 'X-WP-Nonce': cfg.nonce },
        };
        if (body && method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(url, options).then(function (res) {
            if (!res.ok && res.status !== 200) {
                return Promise.reject(res);
            }
            return res;
        });
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    function esc(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function formatDate(ts) {
        const d = new Date(ts * 1000);
        return d.toLocaleDateString('en-AU', { day: '2-digit', month: 'short', year: 'numeric' })
             + ' ' + d.toLocaleTimeString('en-AU', { hour: '2-digit', minute: '2-digit' });
    }
}());
