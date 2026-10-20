// ---------------------------------------------------------
// Live updates: ONE Server-Sent Events stream per page (ajax/stream.php)
// replaces all the old setInterval polling. Feature code below registers
// handlers with Live.on(); the stream itself is opened at the bottom of
// this file once every handler is registered.
// ---------------------------------------------------------
// ---------------------------------------------------------
// Messenger page: 100dvh should already track the mobile on-screen
// keyboard, but on some browsers it doesn't recompute reliably through
// repeated open/close cycles (exactly what happens while chatting back and
// forth), and the page visibly shrinks a little more each time. This sets
// an explicit, freshly-measured height as a CSS variable the stylesheet
// prefers over dvh when it's present (see body.messenger-page).
// ---------------------------------------------------------
if (document.body.classList.contains('messenger-page')) {
    const setAppHeight = () => {
        const h = window.visualViewport ? window.visualViewport.height : window.innerHeight;
        document.documentElement.style.setProperty('--app-100vh', h + 'px');
    };
    setAppHeight();
    window.addEventListener('resize', setAppHeight);
    window.addEventListener('orientationchange', setAppHeight);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', setAppHeight);
}

const Live = {
    handlers: {},
    on(event, fn) { (this.handlers[event] = this.handlers[event] || []).push(fn); },
    emit(event, data) { (this.handlers[event] || []).forEach((fn) => fn(data)); },
};

// Keep the page underneath a modal from moving on touch devices. Modal
// overlays are created lazily, so observe their hidden state rather than
// duplicating lock/unlock calls in every modal implementation.
const modalScrollLock = (() => {
    const modalSelector = [
        '#modal-overlay',
        '#discard-modal-overlay',
        '#verify-modal-overlay',
        '#forgot-modal-overlay',
        '#item-modal-overlay',
        '#report-modal-overlay',
        '#guest-login-overlay',
        '#image-modal',
    ].join(',');
    let locked = false;
    let bodyLocked = false;
    let scrollY = 0;
    const originalBodyStyles = {};
    const bodyStyleProperties = ['position', 'top', 'left', 'right', 'width'];

    const sync = () => {
        const open = Array.from(document.querySelectorAll(modalSelector))
            .some((modal) => !modal.hidden);
        if (open === locked) return;

        if (open) {
            bodyLocked = window.matchMedia('(max-width: 900px)').matches;
            if (bodyLocked) {
                scrollY = window.scrollY;
                bodyStyleProperties.forEach((property) => {
                    originalBodyStyles[property] = document.body.style[property];
                });
                document.body.style.position = 'fixed';
                document.body.style.top = `-${scrollY}px`;
                document.body.style.left = '0';
                document.body.style.right = '0';
                document.body.style.width = '100%';
            }
            document.body.classList.add('modal-open');
            document.documentElement.classList.add('modal-open');
            locked = true;
            return;
        }

        document.body.classList.remove('modal-open');
        document.documentElement.classList.remove('modal-open');
        if (bodyLocked) {
            bodyStyleProperties.forEach((property) => {
                document.body.style[property] = originalBodyStyles[property];
            });
            window.scrollTo(0, scrollY);
            bodyLocked = false;
        }
        locked = false;
    };
    const observer = new MutationObserver(sync);
    observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    sync();
    return { sync };
})();

// ---------------------------------------------------------
// Toast — brief, non-blocking feedback. Builds its container once, on
// first use, and reuses it after that.
// ---------------------------------------------------------
// The photo field's label follows the "This item was… Lost / Found" choice:
// "Photo of lost item" / "Photo of found item" (and "Replace photo of …" when editing).
function updatePhotoLabels(root) {
    (root || document).querySelectorAll('label[data-photo-prefix]').forEach((label) => {
        const form = label.closest('form');
        const select = form && form.querySelector('select[name="type"]');
        const type = select && select.value ? select.value : 'lost / found';
        label.textContent = label.dataset.photoPrefix + ' ' + type + ' item (optional)';
    });
}
document.addEventListener('change', (e) => {
    if (e.target.matches && e.target.matches('select[name="type"]')) updatePhotoLabels(e.target.closest('form'));
});

// A chat message's time: just the time if it's from today, otherwise with the
// date too (and the year if it isn't this year). Mirrors format_message_time()
// in includes/functions.php, which renders the messages that are there on load.
function formatMessageTime(value) {
    // MySQL DATETIME values have no timezone marker. Treat them as local
    // application time instead of relying on browser-specific parsing rules.
    const normalized = typeof value === 'string' ? value.replace(' ', 'T') : value;
    const d = new Date(normalized);
    if (isNaN(d.getTime())) return '';
    const now = new Date();
    const time = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return time;
    const opts = { month: 'short', day: 'numeric' };
    if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString([], opts) + ', ' + time;
}

// Every search box on the site gets a plain "x" at its right end that clears
// it. Just an x in the text colour — no background — shown only when there's
// something to clear.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input.report-search, input[name="q"]').forEach((input) => {
        if (input.closest('.search-field')) return;
        const wrap = document.createElement('div');
        wrap.className = 'search-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'search-clear';
        x.setAttribute('aria-label', 'Clear search');
        x.innerHTML = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M2 2l8 8M10 2l-8 8"/></svg>';
        wrap.appendChild(x);

        const sync = () => { x.hidden = input.value === ''; };
        input.addEventListener('input', sync);
        x.addEventListener('click', () => {
            input.value = '';
            sync();
            input.dispatchEvent(new Event('input', { bubbles: true })); // runs the live search again
            input.focus();
        });
        sync();
    });
});

// Dropdown buttons whose options are plain links (Manage Reports: Active /
// Deleted Reports). Same look as the Browse All / Lost / Found dropdown; this
// only opens and closes the menu — choosing an option just follows the link.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.type-dropdown[data-nav]').forEach((dd) => {
        const btn = dd.querySelector('.type-dropdown-btn');
        const menu = dd.querySelector('.type-dropdown-menu');
        if (!btn || !menu) return;
        const close = () => { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
        btn.addEventListener('click', () => {
            menu.hidden = !menu.hidden;
            btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
        });
        document.addEventListener('click', (e) => { if (!dd.contains(e.target)) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { close(); btn.focus(); } });
    });
});

// Manage Users: keep "Last Active" / "Online now" current without a reload.
// Asks the server every 5 seconds (while the tab is visible) who is online.
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('report-table');
    if (!table || table.dataset.noun !== 'user') return;

    function paint(cell, user) {
        cell.textContent = '';
        if (user.online) {
            const span = document.createElement('span');
            span.className = 'online-now';
            span.textContent = 'Online now';
            cell.appendChild(span);
        } else if (user.ts) {
            cell.textContent = user.label;
        } else {
            const span = document.createElement('span');
            span.className = 'detail-none';
            span.textContent = 'Never';
            cell.appendChild(span);
        }
        cell.dataset.sortValue = String(user.ts || 0);
    }

    // The live stream pushes a "presence" event whenever somebody goes online or offline (ajax/stream.php,
    // ?watch=users) — no polling and no page refresh needed.
    Live.on('presence', (list) => {
        if (!Array.isArray(list)) return;
        list.forEach((u) => {
            const tr = table.querySelector('tr[data-user-id="' + u.id + '"]');
            const cell = tr && tr.querySelector('.last-active-cell');
            if (!cell) return;
            paint(cell, u);
            tr.dataset.lastActive = String(u.ts || tr.dataset.joined || 0); // keeps the date filter right
        });
    });
});

// Things that finish after the page has already answered (an email that couldn't
// be sent) come back as toasts. Logged-in users get them through the live
// stream; guests (login / register / verify pages) poll for them for a minute.
Live.on('toasts', (list) => {
    (Array.isArray(list) ? list : []).forEach((message) => Toast.show(message, 'error', 8000));
});
document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.guest-nav')) return;
    const prefix = document.body.dataset.prefix || '';
    let polls = 0;
    const timer = setInterval(() => {
        if (++polls > 20) { clearInterval(timer); return; }
        fetch(prefix + 'ajax/toasts.php', { cache: 'no-store' })
            .then((r) => (r.ok ? r.json() : []))
            .then((list) => { (Array.isArray(list) ? list : []).forEach((m) => Toast.show(m, 'error', 8000)); })
            .catch(() => {});
    }, 3000);
});

// ---------------------------------------------------------
// "Who found or claimed it?" — shown when a report is marked Resolved, so the name can be selected
// instead of being typed. The choices are the people the owner has talked to about this report (the server
// checks the chosen one again). Resolves to { by, name } (by = a user id, 'other' or ''), or null if cancelled.
// ---------------------------------------------------------
const ResolverPicker = {
    async pick(reportId) {
        const prefix = document.body.dataset.prefix || '';
        let people = [];
        try {
            const res = await fetch(prefix + 'ajax/report_people.php?id=' + encodeURIComponent(reportId), { cache: 'no-store' });
            const data = await res.json();
            people = (data && data.people) || [];
        } catch (err) { /* no list: the "someone else" and "skip" choices still work */ }

        return new Promise((resolve) => {
            const els = Modal.open({
                title: 'Mark as Resolved',
                message: people.length ? 'Who found or claimed the item? Select a name to save it with the report.' : 'Who found or claimed the item? You can type a name, or skip this.',
                cancelLabel: 'Cancel',
            });
            let done = false;
            const finish = (value) => { if (done) return; done = true; Modal.close(); resolve(value); };
            const wrap = document.createElement('div');
            wrap.className = 'resolver-list';
            let personSelect = null;
            if (people.length) {
                personSelect = document.createElement('select');
                personSelect.className = 'resolver-select';
                personSelect.setAttribute('aria-label', 'Who found or claimed the item?');
                const prompt = document.createElement('option');
                prompt.value = '';
                prompt.textContent = 'Select a person';
                prompt.disabled = true;
                prompt.selected = true;
                personSelect.appendChild(prompt);
                people.forEach((p) => {
                    const option = document.createElement('option');
                    option.value = String(p.id);
                    option.textContent = p.name;
                    personSelect.appendChild(option);
                });
                wrap.appendChild(personSelect);
            }
            const other = document.createElement('div');
            other.className = 'resolver-other';
            const input = document.createElement('input');
            input.type = 'text';
            input.maxLength = 120;
            input.placeholder = 'Someone else — type a name';
            other.appendChild(input);
            const skip = document.createElement('button');
            skip.type = 'button';
            skip.className = 'btn btn-secondary resolver-choice';
            skip.textContent = 'Skip — just mark it Resolved';
            skip.addEventListener('click', () => finish({ by: '', name: '' }));
            wrap.append(other, skip);
            els.inputArea.appendChild(wrap);

            // The modal's own Save button saves the selected person or typed name.
            els.confirmBtn.textContent = 'Save';
            els.confirmBtn.onclick = () => {
                const name = input.value.trim();
                if (personSelect && personSelect.value) {
                    finish({ by: personSelect.value, name: '' });
                    return;
                }
                if (!name) {
                    Toast.show('Type a name first, or pick someone from the list, or skip.', 'error');
                    input.focus();
                    return;
                }
                finish({ by: 'other', name });
            };
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); els.confirmBtn.click(); } });
            input.addEventListener('input', () => {
                if (personSelect) personSelect.value = '';
            });
            if (personSelect) {
                personSelect.addEventListener('change', () => {
                    input.value = '';
                });
            }
            // Clicking outside / Escape asks first if a name has been typed.
            Modal.dirty = () => input.value.trim() !== '';
            // Cancel / Escape / clicking outside close the modal without choosing.
            const watcher = setInterval(() => { if (els.overlay.hidden) { clearInterval(watcher); finish(null); } }, 150);
        });
    },
};

// "Mark as Resolved" on the "Has your report been resolved?" notification card: asks who found it, then
// updates the report right there (update_status.php answers with JSON) instead of making you go to My Reports.
document.addEventListener('click', async (e) => {
    const btn = e.target.closest && e.target.closest('.notice-action[data-action="resolve"]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    const choice = await ResolverPicker.pick(btn.dataset.report);
    if (!choice) return;
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = 'Saving…';
    const prefix = document.body.dataset.prefix || '';
    try {
        const res = await fetch(prefix + 'update_status.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ report_id: btn.dataset.report, status: 'resolved', expected: 'active', resolved_by: choice.by, resolved_by_name: choice.name }),
        });
        const data = await res.json();
        if (data && data.ok) {
            btn.textContent = 'Marked as Resolved ✓';
            btn.classList.add('is-done');
            Toast.show(data.message || 'Marked as Resolved.', 'success');
        } else {
            btn.disabled = false;
            btn.textContent = label;
            Toast.show((data && (data.message || data.error)) || 'Could not update the report.', 'error');
        }
    } catch (err) {
        btn.disabled = false;
        btn.textContent = label;
        Toast.show("Couldn't reach the server. Please try again.", 'error');
    }
});

// Anything that fails without anyone handling it still tells the person something happened
// (at most one toast every few seconds), instead of the page just seeming to do nothing.
let lastUnhandledToast = 0;
window.addEventListener('unhandledrejection', () => {
    if (Date.now() - lastUnhandledToast < 5000) return;
    lastUnhandledToast = Date.now();
    Toast.show('Something went wrong. Please try again.', 'error');
});

// If the browser brings a page back from its instant back/forward cache (e.g. Back after logging
// out), reload it so the server decides what to show — a logged-out visitor lands on the login page.
window.addEventListener('pageshow', (e) => {
    if (e.persisted) window.location.reload();
});

// Logged out in another tab (or the session ended): this page follows to the login screen
// instead of carrying on as if nothing happened.
Live.on('signedout', () => { window.location.href = (document.body.dataset.prefix || '') + 'login.php'; });

// The stream tells pages when reports change (see the watch parameter in ajax/stream.php).
// Tables, the Browse list and an open report all refresh themselves, so nobody keeps acting on stale data.
Live.on('reports', async (p) => {
    if (!p) return;
    if (p.scope === 'item') {
        // A report page (opened directly, e.g. from an email link) for a report that just changed.
        const root = document.querySelector('[data-report-live]');
        if (!root) return;
        if (!p.exists || (p.deleted && document.body.dataset.role !== 'admin')) {
            Toast.show('This report was just deleted.', 'error');
            setTimeout(() => { window.location.href = (document.body.dataset.prefix || '') + 'index.php'; }, 1500);
        } else if (p.status && p.status !== root.dataset.reportStatus) {
            Toast.show('This report was just updated — it is now ' + ({ active: 'Active', resolved: 'Resolved', closed: 'Closed' }[p.status] || p.status) + '.', 'info');
            setTimeout(() => window.location.reload(), 1200);
        }
        return;
    }
    if (window.reportTableRefresh) window.reportTableRefresh();   // Manage Reports / My Reports / Manage Users (the same row-sync)
    if (window.browseRefresh) window.browseRefresh();
    if (window.dashRefresh) window.dashRefresh();

    // A report popup open on top of this page: keep it truthful too.
    const open = Array.from(document.querySelectorAll('#item-modal-overlay:not([hidden]) [data-report-live]'));
    for (const root of open) {
        try {
            const res = await fetch((document.body.dataset.prefix || '') + 'ajax/report_state.php?id=' + encodeURIComponent(root.dataset.reportId), { cache: 'no-store' });
            const s = await res.json();
            if (!s.exists) {
                ItemModal.close();
                Toast.show('That report was just deleted.', 'error');
                return;
            }
            if (s.status !== root.dataset.reportStatus) {
                await ItemModal.refreshQuietly();
                Toast.show('This report was just updated.', 'info');
                return;
            }
        } catch (err) {}
    }
});

// The navigation bar is sticky, so the sticky search/filter bars have to sit
// just below it. Publish its real height as a CSS variable (it changes when
// the header wraps on narrow screens).
document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.site-header');
    if (!header) return;
    const publish = () => document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px');
    publish();
    window.addEventListener('resize', publish);
    window.addEventListener('load', publish);
    if (window.ResizeObserver) new ResizeObserver(publish).observe(header);
});

const Toast = {
    // Toasts currently on screen, keyed by type + message. Showing the same
    // message again shakes the existing toast (and restarts its timer)
    // instead of stacking a duplicate.
    active: new Map(),

    container() {
        let el = document.getElementById('toast-container');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast-container';
            document.body.appendChild(el);
        }
        return el;
    },
    show(message, type = 'info', duration = 4500) {
        const key = type + '|' + message;
        const existing = this.active.get(key);
        if (existing && existing.el.isConnected) {
            existing.shake(duration);
            return;
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'status');

        const text = document.createElement('div');
        text.className = 'toast-message';
        text.textContent = message;
        toast.appendChild(text);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.textContent = '×';
        toast.appendChild(close);

        this.container().appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('toast-show'));

        const active = this.active;
        let timer = setTimeout(() => dismiss(), duration);
        function dismiss() {
            clearTimeout(timer);
            if (active.get(key) && active.get(key).el === toast) active.delete(key);
            toast.classList.remove('toast-show');
            setTimeout(() => toast.remove(), 200);
        }
        close.addEventListener('click', dismiss);

        active.set(key, {
            el: toast,
            shake(newDuration) {
                clearTimeout(timer);
                timer = setTimeout(() => dismiss(), newDuration);
                toast.classList.remove('toast-shake');
                void toast.offsetWidth; // restart the animation if it's already shaking
                toast.classList.add('toast-shake');
            },
        });
    },
};

// Server-rendered pages queue a flash message into window.__pendingToasts
// before this script has loaded (see includes/header.php, and the inline
// queuing on register.php/login.php/verify.php/import_reports.php for
// same-page validation errors that never go through the session flash).
// Drain whatever's already queued, then keep draining anything pushed
// later by swapping in a push that also shows the toast immediately.
(function drainPendingToasts() {
    const queue = window.__pendingToasts || [];
    queue.forEach((t) => Toast.show(t.msg, t.type));
    window.__pendingToasts = {
        push(t) { Toast.show(t.msg, t.type); },
    };
})();

// ---------------------------------------------------------
// Modal — blocking confirmation. Three shapes, all built on one shared
// overlay/dialog pair (created once, reused for every call):
//   Modal.confirm({title, message, confirmLabel, danger, onConfirm})
//     Plain OK/Cancel.
//   Modal.confirmTyped({title, message, requireText, confirmLabel, onConfirm})
//     Confirm button stays disabled until the person types requireText
//     exactly (case-insensitive) — used where a mistake is hard to undo.
//   Modal.confirmPassword({title, message, confirmLabel, verifyUrl, onConfirm})
//     Confirm button POSTs {password} to verifyUrl first; onConfirm only
//     runs if that endpoint replies {ok:true}. Used for admin actions on
//     OTHER people's accounts, where "are you sure" alone is too easy to
//     click through without meaning it.
// None of these submit anything themselves beyond that password check —
// onConfirm is what actually performs the real action (usually submitting
// the form the person triggered the modal from).
// ---------------------------------------------------------
// Closing a popup that has something typed into it (clicking outside,
// pressing Escape, the X button) asks first, so a stray click can't throw
// the input away. Nothing typed -> it just closes.
function closeUnlessDirty(isDirty, closeFn, what) {
    if (!isDirty) { closeFn(); return; }
    Modal.confirm({
        title: 'Cancel ' + what + '?',
        message: "What you've entered won't be saved.",
        confirmLabel: 'Yes, cancel',
        cancelLabel: 'Keep editing',
        danger: true,
        onConfirm: closeFn,
    });
}

// Small "Discard what you've entered?" dialog that sits on top of the shared Modal (which can't ask itself,
// because it is the dialog being closed). Used when a Modal that holds typed input is dismissed by clicking
// outside it or pressing Escape.
const DiscardGuard = {
    overlay: null,
    isOpen() { return !!this.overlay && !this.overlay.hidden; },
    build() {
        if (this.overlay) return this.overlay;
        const overlay = document.createElement('div');
        overlay.id = 'discard-modal-overlay';
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="modal-dialog" role="alertdialog" aria-modal="true">
                <h3>Discard what you've entered?</h3>
                <p class="modal-message">It won't be saved.</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-keep>Keep editing</button>
                    <button type="button" class="btn btn-danger" data-discard>Yes, discard</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.hide(); });
        overlay.querySelector('[data-keep]').addEventListener('click', () => this.hide());
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen()) { e.stopImmediatePropagation(); this.hide(); }
        });
        this.overlay = overlay;
        return overlay;
    },
    hide() { if (this.overlay) this.overlay.hidden = true; },
    ask(onDiscard) {
        const overlay = this.build();
        overlay.querySelector('[data-discard]').onclick = () => { this.hide(); onDiscard(); };
        overlay.hidden = false;
        overlay.querySelector('[data-keep]').focus();
    },
};

const Modal = {
    els: null,
    // Set by a dialog that holds typed input: returns true if there is something to lose.
    dirty: null,

    // Clicking outside / Escape: close, but ask first if something was typed.
    requestClose() {
        if (this.dirty && this.dirty()) { DiscardGuard.ask(() => this.close()); return; }
        this.close();
    },

    build() {
        if (this.els) return this.els;
        const overlay = document.createElement('div');
        overlay.id = 'modal-overlay';
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <h3 id="modal-title"></h3>
                <p class="modal-message" id="modal-message"></p>
                <div id="modal-input-area"></div>
                <p class="modal-error" id="modal-error"></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="modal-cancel">Cancel</button>
                    <button type="button" class="btn modal-confirm-btn" id="modal-confirm">Confirm</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.requestClose(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !overlay.hidden) {
                if (DiscardGuard.isOpen()) return; // the discard question has its own Escape
                // Only this dialog reacts; a popup underneath it must not
                // also see the same keypress.
                e.stopImmediatePropagation();
                this.requestClose();
            }
        });
        overlay.querySelector('#modal-cancel').addEventListener('click', () => this.close());

        this.els = {
            overlay,
            cancelBtn: overlay.querySelector('#modal-cancel'),
            title: overlay.querySelector('#modal-title'),
            message: overlay.querySelector('#modal-message'),
            inputArea: overlay.querySelector('#modal-input-area'),
            error: overlay.querySelector('#modal-error'),
            confirmBtn: overlay.querySelector('#modal-confirm'),
        };
        return this.els;
    },

    close() {
        const els = this.build();
        els.overlay.hidden = true;
        els.inputArea.innerHTML = '';
        els.error.textContent = '';
        els.confirmBtn.onclick = null;
        this.dirty = null;
        DiscardGuard.hide();
        // confirmTyped() disables this button until "yes" is typed; if that dialog
        // was cancelled it must not stay disabled for the next, plain confirmation.
        els.confirmBtn.disabled = false;
        els.confirmBtn.hidden = false;
    },

    open({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
        const els = this.build();
        this.dirty = null;
        els.confirmBtn.disabled = false;
        els.confirmBtn.hidden = false;
        els.cancelBtn.textContent = cancelLabel;
        els.title.textContent = title;
        els.message.textContent = message;
        els.error.textContent = '';
        els.confirmBtn.textContent = confirmLabel;
        els.confirmBtn.className = 'btn modal-confirm-btn' + (danger ? ' btn-danger' : '');
        els.overlay.hidden = false;
        return els;
    },

    confirm({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false, onConfirm }) {
        const els = this.open({ title, message, confirmLabel, cancelLabel, danger });
        els.confirmBtn.onclick = () => { this.close(); onConfirm(); };
    },

    confirmTyped({ title, message, requireText, confirmLabel = 'Confirm', danger = true, onConfirm }) {
        const els = this.open({ title, message, confirmLabel, danger });
        els.confirmBtn.disabled = true;

        const input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.placeholder = `Type "${requireText}" to confirm`;
        els.inputArea.appendChild(input);
        input.focus();

        input.addEventListener('input', () => {
            els.confirmBtn.disabled = input.value.trim().toLowerCase() !== requireText.toLowerCase();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !els.confirmBtn.disabled) els.confirmBtn.click();
        });
        els.confirmBtn.onclick = () => {
            if (els.confirmBtn.disabled) return;
            this.close();
            onConfirm();
        };
    },

    confirmPassword({ title, message, confirmLabel = 'Confirm', verifyUrl, onConfirm }) {
        const els = this.open({ title, message, confirmLabel, danger: true });

        const input = document.createElement('input');
        input.type = 'password';
        input.autocomplete = 'current-password';
        input.placeholder = 'Your password';
        els.inputArea.appendChild(input);
        input.focus();
        this.dirty = () => input.value !== '';

        const attempt = async () => {
            const password = input.value;
            if (!password) { els.error.textContent = 'Enter your password.'; return; }
            els.confirmBtn.disabled = true;
            els.error.textContent = '';
            try {
                const res = await fetch(verifyUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'password=' + encodeURIComponent(password),
                });
                const data = await res.json();
                if (data && data.ok) {
                    this.close();
                    onConfirm(password);
                } else {
                    els.error.textContent = 'Incorrect password.';
                    input.value = '';
                    input.focus();
                }
            } catch (err) {
                els.error.textContent = 'Could not verify — check your connection and try again.';
            } finally {
                els.confirmBtn.disabled = false;
            }
        };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') attempt(); });
        els.confirmBtn.onclick = attempt;
    },
};

// ---------------------------------------------------------
// School email fields — "[ name | @holychild.edu.ph ]": people type only the
// part before the "@"; the domain is fixed text beside the box and gets
// appended on the server (normalize_school_email() in includes/functions.php).
// window.SCHOOL_EMAIL_DOMAIN is set by includes/header.php.
// ---------------------------------------------------------
const SchoolEmail = {
    suffix() { return window.SCHOOL_EMAIL_DOMAIN || ''; },
    field(id) {
        return '<div class="email-field"><input type="text" id="' + id + '" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="name" autocomplete="email">'
             + '<span class="email-suffix">' + this.suffix() + '</span></div>';
    },
    // "name@holychild.edu.ph" -> "name"
    local(email) {
        const s = this.suffix(), v = String(email || '').trim();
        return s && v.toLowerCase().endsWith(s.toLowerCase()) ? v.slice(0, -s.length) : v;
    },
    // "name" -> "name@holychild.edu.ph"
    full(local) {
        const v = String(local || '').trim();
        return v && !v.includes('@') ? v + this.suffix() : v;
    },
};
// Typing or pasting a full address (or an autofill) keeps just the part before the "@".
document.addEventListener('input', (e) => {
    const t = e.target;
    if (!t.matches || !t.matches('.email-field input')) return;
    const clean = t.value.replace(/@.*$/, '').replace(/\s+/g, '');
    if (clean !== t.value) t.value = clean;
});

// ---------------------------------------------------------
// CodeBoxes — the 6-digit verification code as six separate one-digit boxes.
// Turns an empty <div class="code-boxes"> into the boxes and gives that div
// `.value` (get/set) and `.focus()`, so code that used a plain text input
// keeps working. With data-name="code" it also keeps a hidden input of that
// name in sync, for the standalone verify.php form. Handles auto-advance,
// Backspace, arrow keys, and pasting (or an SMS autofill) of the whole code.
// ---------------------------------------------------------
const CodeBoxes = {
    LENGTH: 6,

    init(el) {
        if (!el || el._codeBoxes) return el;
        el._codeBoxes = true;
        const n = this.LENGTH;
        const boxes = [];
        const hidden = el.dataset.name ? document.createElement('input') : null;
        if (hidden) { hidden.type = 'hidden'; hidden.name = el.dataset.name; el.appendChild(hidden); }

        const get = () => boxes.map((b) => b.value).join('');
        const sync = () => {
            boxes.forEach((b) => b.classList.toggle('filled', !!b.value));
            if (hidden) hidden.value = get();
        };
        const fill = (start, digits) => {
            let i = start;
            for (const d of digits) { if (i >= n) break; boxes[i].value = d; i++; }
            sync();
            boxes[Math.min(i, n - 1)].focus();
        };

        for (let i = 0; i < n; i++) {
            const b = document.createElement('input');
            b.type = 'text';
            b.className = 'code-box';
            b.inputMode = 'numeric';
            b.autocomplete = i === 0 ? 'one-time-code' : 'off';
            b.setAttribute('aria-label', 'Digit ' + (i + 1) + ' of ' + n);
            b.setAttribute('pattern', '[0-9]');
            if (el.hasAttribute('data-required')) b.required = true;
            boxes.push(b);
            el.appendChild(b);

            b.addEventListener('focus', () => b.select());
            b.addEventListener('input', () => {
                const digits = b.value.replace(/\D/g, '');
                b.value = '';
                if (!digits) { sync(); return; }
                fill(i, digits);
            });
            b.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !b.value && i > 0) {
                    e.preventDefault();
                    boxes[i - 1].value = '';
                    sync();
                    boxes[i - 1].focus();
                } else if (e.key === 'ArrowLeft' && i > 0) {
                    e.preventDefault(); boxes[i - 1].focus();
                } else if (e.key === 'ArrowRight' && i < n - 1) {
                    e.preventDefault(); boxes[i + 1].focus();
                }
            });
            b.addEventListener('paste', (e) => {
                e.preventDefault();
                const text = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '');
                if (text) fill(text.length >= n ? 0 : i, text);
            });
        }

        Object.defineProperty(el, 'value', {
            get,
            set(v) {
                const d = String(v || '').replace(/\D/g, '').slice(0, n);
                boxes.forEach((b, i) => { b.value = d[i] || ''; });
                sync();
            },
        });
        el.focus = () => { (boxes.find((b) => !b.value) || boxes[n - 1]).focus(); };
        return el;
    },
};
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-code-boxes]').forEach((el) => {
        CodeBoxes.init(el);
        if (el.hasAttribute('data-autofocus')) el.focus();
    });
});

// ---------------------------------------------------------
// VerifyModal — entering the 6-digit email code as a popup over
// register.php (right after signing up) or login.php (if someone tries to
// log in before verifying), instead of navigating to verify.php as its own
// page. Talks to verify.php over AJAX (see $isAjax there); that page still
// works as a normal standalone page too, as a fallback.
// ---------------------------------------------------------
const VerifyModal = {
    els: null,

    build() {
        if (this.els) return this.els;
        const overlay = document.createElement('div');
        overlay.id = 'verify-modal-overlay'; // own id — see CSS, styled the same as #modal-overlay
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <h3>Verify Your Account</h3>
                <p class="modal-message">We sent a 6-digit code to your school email. Enter it below to activate your account.</p>
                <label>School Email
                    ${SchoolEmail.field('verify-email')}
                </label>
                <label>Verification Code
                    <div class="code-boxes" id="verify-code"></div>
                </label>
                <p class="modal-error" id="verify-error"></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="verify-resend">Resend Code</button>
                    <button type="button" class="btn modal-confirm-btn" id="verify-submit">Verify</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.requestClose(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !overlay.hidden && (!Modal.els || Modal.els.overlay.hidden)) this.requestClose();
        });

        const els = {
            overlay,
            email: overlay.querySelector('#verify-email'),
            code: CodeBoxes.init(overlay.querySelector('#verify-code')),
            error: overlay.querySelector('#verify-error'),
            submit: overlay.querySelector('#verify-submit'),
            resend: overlay.querySelector('#verify-resend'),
        };
        this.els = els;

        const post = async (body) => {
            const res = await fetch('verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            return res.json();
        };

        els.submit.addEventListener('click', async () => {
            els.error.textContent = '';
            if (els.code.value.length !== CodeBoxes.LENGTH) { els.error.textContent = 'Enter the 6-digit code.'; return; }
            els.submit.disabled = true;
            try {
                const data = await post('code=' + encodeURIComponent(els.code.value.trim()) + '&email=' + encodeURIComponent(els.email.value.trim()));
                if (data.ok) {
                    // The server also left this message as a flash, so the page we go to next shows it as a toast
                    // (a toast shown here would be wiped by the redirect before anyone saw it).
                    if (!data.redirect) Toast.show(data.message, 'success');
                    this.close();
                    if (data.redirect) window.location.href = data.redirect;
                } else {
                    els.error.textContent = data.message || 'Something went wrong.';
                }
            } catch (err) {
                els.error.textContent = 'Could not reach the server — check your connection and try again.';
            } finally {
                els.submit.disabled = false;
            }
        });
        els.code.addEventListener('keydown', (e) => { if (e.key === 'Enter') els.submit.click(); });

        els.resend.addEventListener('click', async () => {
            els.error.textContent = '';
            els.resend.disabled = true;
            try {
                const data = await post('resend=1&email=' + encodeURIComponent(els.email.value.trim()));
                Toast.show(data.message || 'If that email needs verification, a new code has been sent.', 'info');
            } catch (err) {
                els.error.textContent = 'Could not reach the server — check your connection and try again.';
            } finally {
                els.resend.disabled = false;
            }
        });

        return els;
    },

    close() {
        const els = this.build();
        els.overlay.hidden = true;
    },

    // User-initiated close (outside click / Escape): ask first if a code was typed.
    requestClose() {
        const els = this.build();
        closeUnlessDirty(els.code.value.length > 0, () => this.close(), 'verification');
    },

    open(email) {
        const els = this.build();
        els.email.value = SchoolEmail.local(email);
        els.code.value = '';
        els.error.textContent = '';
        els.overlay.hidden = false;
        els.code.focus();
    },
};

// ---------------------------------------------------------
// ForgotModal — "Forgot your password?" on the login page, as a popup. Two
// steps in one dialog: (1) enter your school email and we email a 6-digit
// code; (2) enter that code and a new password. Talks to forgot_password.php
// over AJAX. Same email-code approach as VerifyModal / registration.
// ---------------------------------------------------------
const ForgotModal = {
    els: null,
    cooldownTimer: null,

    build() {
        if (this.els) return this.els;
        const overlay = document.createElement('div');
        overlay.id = 'forgot-modal-overlay'; // styled with the other modal overlays (see CSS)
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="modal-dialog" role="dialog" aria-modal="true">
                <h3>Reset Your Password</h3>
                <div data-step="1">
                    <p class="modal-message">Enter your school email and we'll send you a 6-digit code.</p>
                    <label>School Email
                        ${SchoolEmail.field('forgot-email')}
                    </label>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="forgot-cancel">Cancel</button>
                        <button type="button" class="btn modal-confirm-btn" id="forgot-send">Send Code</button>
                    </div>
                </div>
                <div data-step="2" hidden>
                    <p class="modal-message" id="forgot-step2-msg"></p>
                    <label>Verification Code
                        <div class="code-boxes" id="forgot-code"></div>
                    </label>
                    <label>New Password
                        <input type="password" id="forgot-password" minlength="8" autocomplete="new-password">
                    </label>
                    <label>Confirm New Password
                        <input type="password" id="forgot-confirm" minlength="8" autocomplete="new-password">
                    </label>
                    <p class="modal-error" id="forgot-error"></p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" id="forgot-resend">Resend Code</button>
                        <button type="button" class="btn modal-confirm-btn" id="forgot-submit">Change Password</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.requestClose(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !overlay.hidden && (!Modal.els || Modal.els.overlay.hidden)) this.requestClose();
        });

        const q = (sel) => overlay.querySelector(sel);
        const els = {
            overlay,
            step1: q('[data-step="1"]'), step2: q('[data-step="2"]'),
            step2Msg: q('#forgot-step2-msg'),
            email: q('#forgot-email'), code: CodeBoxes.init(q('#forgot-code')),
            password: q('#forgot-password'), confirm: q('#forgot-confirm'),
            error: q('#forgot-error'),
            send: q('#forgot-send'), cancel: q('#forgot-cancel'),
            resend: q('#forgot-resend'), submit: q('#forgot-submit'),
        };
        this.els = els;

        const post = async (fields) => {
            const res = await fetch('forgot_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(fields).toString(),
            });
            return res.json();
        };
        const offline = 'Could not reach the server — check your connection and try again.';

        // Step 1 -> email the code.
        const sendCode = async (fromResend) => {
            const email = els.email.value.trim();
            if (!email) { Toast.show('Enter your email address.', 'error'); return; }
            const btn = fromResend ? els.resend : els.send;
            btn.disabled = true;
            try {
                const data = await post({ action: 'send', email, force: fromResend ? '1' : '' });
                if (data.ok) {
                    // reused: a still-valid code was already emailed, so none was sent now.
                    Toast.show(data.message, data.reused ? 'info' : 'success');
                    this.showStep2(email, !!data.reused);
                } else if (fromResend) {
                    els.error.textContent = data.message;
                } else {
                    Toast.show(data.message, 'error');
                }
            } catch (err) {
                if (fromResend) els.error.textContent = offline; else Toast.show(offline, 'error');
            } finally {
                btn.disabled = false;
                if (fromResend) this.startCooldown();
            }
        };
        els.send.addEventListener('click', () => sendCode(false));
        els.email.addEventListener('keydown', (e) => { if (e.key === 'Enter') els.send.click(); });
        els.cancel.addEventListener('click', () => this.close());
        els.resend.addEventListener('click', () => { els.error.textContent = ''; sendCode(true); });

        // Step 2 -> check the code and set the new password.
        els.submit.addEventListener('click', async () => {
            els.error.textContent = '';
            const code = els.code.value.trim();
            if (code.length !== CodeBoxes.LENGTH) { els.error.textContent = 'Enter the 6-digit code from your email.'; return; }
            if (els.password.value.length < 8) { els.error.textContent = 'Password must be at least 8 characters.'; return; }
            if (els.password.value !== els.confirm.value) { els.error.textContent = 'Passwords do not match.'; return; }
            els.submit.disabled = true;
            try {
                const data = await post({
                    action: 'reset', email: els.email.value.trim(), code,
                    password: els.password.value, confirm: els.confirm.value,
                });
                if (data.ok) {
                    Toast.show(data.message, 'success');
                    // Hand the email back to the login form so they can sign straight in.
                    const loginEmail = document.querySelector('form input[name="email"]');
                    if (loginEmail) loginEmail.value = els.email.value.trim();
                    this.close();
                    const loginPassword = document.querySelector('form input[name="password"]');
                    if (loginPassword) loginPassword.focus();
                } else {
                    els.error.textContent = data.message || 'Something went wrong.';
                }
            } catch (err) {
                els.error.textContent = offline;
            } finally {
                els.submit.disabled = false;
            }
        });
        [els.code, els.password, els.confirm].forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') els.submit.click(); }));

        return els;
    },

    showStep2(email, reused) {
        const els = this.els;
        els.step1.hidden = true;
        els.step2.hidden = false;
        els.step2Msg.textContent = 'Enter the 6-digit code we sent to ' + SchoolEmail.full(email) + ', then choose a new password.';
        els.error.textContent = '';
        els.code.focus();
        if (reused) {
            // No email went out just now, so there's nothing to wait out.
            clearInterval(this.cooldownTimer);
            els.resend.disabled = false;
            els.resend.textContent = 'Resend Code';
        } else {
            this.startCooldown();
        }
    },

    // The server won't send another code for a minute; show that on the button.
    startCooldown() {
        const btn = this.els.resend;
        clearInterval(this.cooldownTimer);
        let left = 60;
        btn.disabled = true;
        const tick = () => {
            if (left <= 0) { clearInterval(this.cooldownTimer); btn.disabled = false; btn.textContent = 'Resend Code'; return; }
            btn.textContent = 'Resend Code (' + left + 's)';
            left--;
        };
        tick();
        this.cooldownTimer = setInterval(tick, 1000);
    },

    close() {
        const els = this.build();
        els.overlay.hidden = true;
        clearInterval(this.cooldownTimer);
    },

    // User-initiated close (outside click / Escape): ask first if anything was typed
    // past the first step (code or new password).
    requestClose() {
        const els = this.build();
        const typed = !els.step2.hidden && (els.code.value.length > 0 || els.password.value || els.confirm.value);
        closeUnlessDirty(!!typed, () => this.close(), 'password reset');
    },

    open(email) {
        const els = this.build();
        clearInterval(this.cooldownTimer);
        els.step1.hidden = false;
        els.step2.hidden = true;
        els.email.value = SchoolEmail.local(email);
        els.code.value = els.password.value = els.confirm.value = '';
        els.error.textContent = '';
        els.resend.disabled = false;
        els.resend.textContent = 'Resend Code';
        els.overlay.hidden = false;
        els.email.focus();
    },
};

document.addEventListener('click', (e) => {
    const link = e.target.closest('[data-forgot-password]');
    if (!link) return;
    e.preventDefault();
    const loginEmail = document.querySelector('form input[name="email"]');
    ForgotModal.open(loginEmail ? SchoolEmail.local(loginEmail.value) : '');
});

// ---------------------------------------------------------
// ItemModal — viewing a report opens as a popup over whatever page you're
// on (Browse, My Reports, a chat's possible-match link, Manage Reports...)
// instead of navigating to item.php as a new page. item.php itself detects
// the AJAX request and returns just its content fragment (see $isPartial
// there); this fetches that fragment and drops it into a shared dialog.
// ---------------------------------------------------------
const ItemModal = {
    els: null,

    build() {
        if (this.els) return this.els;
        const overlay = document.createElement('div');
        overlay.id = 'item-modal-overlay';
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="item-modal-dialog" role="dialog" aria-modal="true">
                <button type="button" class="item-modal-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 2l10 10M12 2L2 12"/></svg></button>
                <div class="item-modal-body"></div>
            </div>`;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.close(); });
        overlay.querySelector('.item-modal-close').addEventListener('click', () => this.close());
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !overlay.hidden) this.close();
        });

        this.els = { overlay, dialog: overlay.querySelector('.item-modal-dialog'), body: overlay.querySelector('.item-modal-body') };
        return this.els;
    },

    close() {
        const els = this.build();
        els.overlay.hidden = true;
        els.body.innerHTML = '';
        els.dialog.classList.remove('item-modal-wide');
    },

    // Re-fetch the open report in place (no "Loading…", no flicker) — used when the live stream says it changed.
    async refreshQuietly() {
        if (!this.url) return;
        const els = this.build();
        try {
            const res = await fetch(this.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            els.body.innerHTML = await res.text();
            els.dialog.classList.toggle('item-modal-wide', !!els.body.querySelector('.item-compare'));
        } catch (err) {}
    },

    async open(url) {
        const els = this.build();
        this.url = url;
        // Nothing is shown until the answer arrives, so a report that turns
        // out to be deleted (a toast, below) never flashes an empty dialog.
        // "Loading…" only appears if the server is genuinely slow.
        const loadingTimer = els.overlay.hidden ? setTimeout(() => {
            els.dialog.classList.remove('item-modal-wide');
            els.body.innerHTML = '<p class="item-modal-loading">Loading…</p>';
            els.overlay.hidden = false;
        }, 300) : null;

        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            clearTimeout(loadingTimer);
            if (res.status === 404 || res.status === 410) {
                // The report was deleted (e.g. opened from an old match / edit notification).
                this.close();
                Toast.show('This report has been deleted.', 'error');
                return;
            }
            if (!res.ok) throw new Error('Request failed');
            const html = await res.text();
            els.body.innerHTML = html;
            els.overlay.hidden = false;
            // A possible-match comparison (two reports side by side) needs a wider dialog.
            els.dialog.classList.toggle('item-modal-wide', !!els.body.querySelector('.item-compare'));
        } catch (err) {
            clearTimeout(loadingTimer);
            els.body.innerHTML = '<p class="item-modal-error">Could not load this report. Try again, or open it directly.</p>';
            els.overlay.hidden = false;
        }
    },
};

// Any link to item.php, anywhere on the page (cards on Browse/My Reports/
// Manage Reports, "Link: item.php?id=X" inside a chat message, etc.) opens
// the modal instead of navigating — except when the person is clearly
// asking for normal link behavior (modifier key, middle-click, or the link
// itself declares target="_blank"), which is left alone.
document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a');
    if (!a || !a.href || a.target === '_blank') return;

    let url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (!/\/item\.php$/.test(url.pathname) || !url.searchParams.has('id')) return;

    e.preventDefault();
    ItemModal.open(a.href);
});

// ---------------------------------------------------------
// ReportModal — creating a report (report.php) or editing one
// (edit_report.php) opens as a popup instead of navigating to its own page.
// Those files return just the form on an AJAX GET, and JSON on an AJAX POST
// ({ok, redirect} or {ok:false, errors}), so validation errors show inside
// the popup without losing what was typed. A normal visit still gets the
// full page.
// ---------------------------------------------------------
const ReportModal = {
    els: null,

    build() {
        if (this.els) return this.els;
        const overlay = document.createElement('div');
        overlay.id = 'report-modal-overlay';
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="item-modal-dialog report-modal-dialog" role="dialog" aria-modal="true">
                <button type="button" class="item-modal-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 2l10 10M12 2L2 12"/></svg></button>
                <div class="item-modal-body"></div>
            </div>`;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.requestClose(); });
        overlay.querySelector('.item-modal-close').addEventListener('click', () => this.requestClose());
        document.addEventListener('keydown', (e) => {
            // The confirm dialog (Modal) sits on top and handles its own Escape.
            if (e.key === 'Escape' && !overlay.hidden && (!Modal.els || Modal.els.overlay.hidden)) this.requestClose();
        });

        this.els = { overlay, body: overlay.querySelector('.item-modal-body') };
        return this.els;
    },

    close() {
        const els = this.build();
        els.overlay.hidden = true;
        els.body.innerHTML = '';
        this.baseline = null;
    },

    // What the form currently holds, as one string (files by name + size),
    // so we can tell whether anything was entered or changed since it opened.
    formState(form) {
        const parts = [];
        new FormData(form).forEach((v, k) => {
            parts.push(k + '=' + (v instanceof File ? 'file:' + v.name + ':' + v.size : v));
        });
        return parts.join('&');
    },

    isDirty() {
        const form = this.els && this.els.body.querySelector('form');
        return !!(form && this.baseline !== null && this.baseline !== undefined && this.formState(form) !== this.baseline);
    },

    // User-initiated close (X, click outside, Escape). If the form has
    // unsaved input, ask before throwing it away.
    requestClose() {
        // Word the question after what's open: "Cancel this report?",
        // "Cancel your changes?", "Cancel the import?".
        const heading = this.els && this.els.body.querySelector('.modal-form-title');
        const t = heading ? heading.textContent.toLowerCase() : '';
        let what = 'this form';
        if (t.indexOf('settings') !== -1) what = 'your settings changes';
        else if (t.indexOf('import') !== -1) what = 'the import';
        else if (t.indexOf('edit') !== -1) what = 'your changes';
        else if (t.indexOf('report') !== -1) what = 'this report';
        closeUnlessDirty(this.isDirty(), () => this.close(), what);
    },

    showErrors(errors) {
        const body = this.els.body;
        body.querySelectorAll('.flash-error').forEach((n) => n.remove());
        const title = body.querySelector('.modal-form-title');
        (errors || []).slice().reverse().forEach((msg) => {
            const div = document.createElement('div');
            div.className = 'flash flash-error';
            div.textContent = msg;
            if (title) title.after(div); else body.prepend(div);
        });
        const dialog = this.els.overlay.querySelector('.report-modal-dialog');
        if (dialog) dialog.scrollTop = 0;
    },

    // Export popup: a plain GET form that downloads a file. Let the browser
    // submit it normally (the page stays put, the CSV downloads), but require
    // at least one choice and close the popup once the download starts.
    wireDownloadForm(form) {
        form.addEventListener('submit', (e) => {
            if (!form.querySelectorAll('input[type="checkbox"]:checked').length) {
                e.preventDefault();
                this.showErrors(['Turn on at least one: active reports or deleted reports.']);
                return;
            }
            setTimeout(() => {
                this.close();
                Toast.show('Export started — your CSV is downloading.', 'success', 3000);
            }, 150);
        });
    },

    wireForm(form) {
        initFilePickers(form);
        updatePhotoLabels(form);
        form.addEventListener('submit', async (e) => {
            // Edit form asks "Save these changes?" first. The shared confirm
            // handler (on document) takes over; once approved it re-submits
            // with data-confirmed set, and we land here again.
            if ((form.classList.contains('confirm-form') || form.classList.contains('confirm-form-password')) && !form.dataset.confirmed) return;

            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            try {
                const res = await fetch(form.getAttribute('action') || location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                });
                const data = await res.json();
                if (data.ok) {
                    if (data.close) {
                        // Nothing to reload (e.g. Settings): just close the popup and say it worked.
                        this.close();
                        Toast.show(data.message || 'Saved.', 'success');
                        return;
                    }
                    ScrollKeep.save();
                    window.location.href = data.redirect || location.href;
                    return;
                }
                this.showErrors(data.errors || ['Something went wrong.']);
            } catch (err) {
                this.showErrors(['Could not reach the server — check your connection and try again.']);
            }
            delete form.dataset.confirmed; // so the next attempt asks again
            if (submitBtn) submitBtn.disabled = false;
        });
    },

    async open(url) {
        const els = this.build();
        // Same idea as ItemModal.open(): show nothing until the form (or an
        // error toast) is ready; "Loading…" only if the server is slow.
        const loadingTimer = setTimeout(() => {
            if (typeof ItemModal !== 'undefined') ItemModal.close();
            els.body.innerHTML = '<p class="item-modal-loading">Loading…</p>';
            els.overlay.hidden = false;
        }, 300);

        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            clearTimeout(loadingTimer);
            if (res.status === 403 || res.status === 404) {
                // e.g. "Closed reports can't be edited." / "Report not found."
                let msg = 'Could not open this report for editing.';
                try { const data = await res.json(); if (data.errors && data.errors[0]) msg = data.errors[0]; } catch (e) { /* keep default */ }
                this.close();
                Toast.show(msg, 'error');
                return;
            }
            if (!res.ok) throw new Error('Request failed');
            const html = await res.text();
            if (typeof ItemModal !== 'undefined') ItemModal.close();
            els.body.innerHTML = html;
            els.overlay.hidden = false;
            const form = els.body.querySelector('form');
            this.baseline = null;
            if (form) {
                if (form.dataset.download) this.wireDownloadForm(form);
                else {
                    this.wireForm(form);
                    this.baseline = this.formState(form); // after wiring, so pickers' setup isn't counted as input
                }
            }
        } catch (err) {
            clearTimeout(loadingTimer);
            if (typeof ItemModal !== 'undefined') ItemModal.close();
            els.body.innerHTML = '<p class="item-modal-error">Could not load the form. Try again, or open it directly.</p>';
            els.overlay.hidden = false;
        }
    },
};

// Links to report.php / edit_report.php open the popup, with the same
// "leave modifier-clicks and target=_blank alone" rule as item.php links.
document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a');
    if (!a || !a.href || a.target === '_blank') return;

    let url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (!/\/(report|edit_report|import_reports|export_reports|settings)(\.php)?$/.test(url.pathname)) return;
    if (/\/edit_report(\.php)?$/.test(url.pathname) && !url.searchParams.has('id')) return;

    e.preventDefault();
    ReportModal.open(a.href);
});

// ---------------------------------------------------------
// Links that need a confirmation first (Logout): add class "confirm-link"
// plus data-confirm-title/-message/-label; confirming follows the link.
// ---------------------------------------------------------
document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a.confirm-link');
    if (!a) return;
    e.preventDefault();
    Modal.confirm({
        title: a.dataset.confirmTitle || 'Are you sure?',
        message: a.dataset.confirmMessage || '',
        confirmLabel: a.dataset.confirmLabel || 'Confirm',
        danger: a.dataset.confirmDanger === '1',
        onConfirm: () => { window.location.href = a.href; },
    });
});

// ---------------------------------------------------------
// Generic confirmation wiring for ordinary forms: add class "confirm-form"
// plus data-confirm-* attributes and this handles the rest — no per-form
// JS needed. Three shapes, matching Modal's three functions above:
//   confirm-form            data-confirm-title/-message/-label/-danger
//   confirm-form-typed      + data-confirm-required-text (e.g. "yes")
//   confirm-form-password   + data-confirm-verify-url (admin actions only)
// A form only ever reaches real submission the second time, after
// data-confirmed is set, so this never double-prompts.
// ---------------------------------------------------------
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.confirmed) return; // already approved — let it through

    const isPlain    = form.classList.contains('confirm-form');
    const isTyped     = form.classList.contains('confirm-form-typed');
    const isPassword = form.classList.contains('confirm-form-password');
    if (!isPlain && !isTyped && !isPassword) return;

    e.preventDefault();
    const submitNow = (password) => {
        // A password-confirmed form also carries the password along (as
        // confirm_password) so the server can check it again, not just trust
        // the popup. Other forms simply ignore the extra field.
        if (isPassword && password) {
            let hidden = form.querySelector('input[name="confirm_password"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'confirm_password';
                form.appendChild(hidden);
            }
            hidden.value = password;
        }
        form.dataset.confirmed = '1';
        form.requestSubmit ? form.requestSubmit() : form.submit();
    };

    const opts = {
        title: form.dataset.confirmTitle || 'Are you sure?',
        message: form.dataset.confirmMessage || '',
        confirmLabel: form.dataset.confirmLabel || 'Confirm',
        danger: form.dataset.confirmDanger === '1',
        onConfirm: submitNow,
    };

    if (isTyped) {
        Modal.confirmTyped({ ...opts, requireText: form.dataset.confirmRequiredText || 'yes' });
    } else if (isPassword) {
        Modal.confirmPassword({ ...opts, verifyUrl: form.dataset.confirmVerifyUrl });
    } else {
        Modal.confirm(opts);
    }
});

// ---------------------------------------------------------
// Same idea, for a <select> that auto-submits its form on change (the
// report-status dropdowns) rather than a submit button. Add class
// "confirm-select" plus the same data-confirm-* attributes; a cancelled
// confirmation reverts the select to whatever it was showing before.
// ---------------------------------------------------------
document.addEventListener('change', (e) => {
    const select = e.target;
    if (!select.matches('select.confirm-select')) return;

    const form = select.form;
    // A <select> has no .defaultValue (that's an <input> property), so the
    // old fallback was `undefined` — assigning it back blanked the select
    // when a change was cancelled. The option that was selected in the
    // original markup is .defaultSelected.
    const initialOption = Array.from(select.options).find((o) => o.defaultSelected) || select.options[0];
    const prevValue = select.dataset.prevValue !== undefined ? select.dataset.prevValue : initialOption.value;
    const newValue = select.value;

    // Marking a report Resolved asks who found / claimed it (one click) instead of a plain confirmation.
    if (newValue === 'resolved' && form && form.querySelector('[name="report_id"]')) {
        select.value = prevValue;
        ResolverPicker.pick(form.querySelector('[name="report_id"]').value).then((choice) => {
            if (!choice) return;
            ['resolved_by', 'resolved_by_name'].forEach((n) => {
                let h = form.querySelector('input[name="' + n + '"]');
                if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = n; form.appendChild(h); }
                h.value = n === 'resolved_by' ? choice.by : choice.name;
            });
            select.value = newValue;
            select.dataset.prevValue = newValue;
            form.dataset.confirmed = '1';
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
        return;
    }

    Modal.confirm({
        title: select.dataset.confirmTitle || 'Change status?',
        message: (select.dataset.confirmMessage || '').replace('{value}', select.options[select.selectedIndex].text),
        confirmLabel: select.dataset.confirmLabel || 'Confirm',
        danger: false,
        onConfirm: () => {
            select.value = newValue; // put the chosen value back before submitting
            select.dataset.prevValue = newValue;
            if (form) { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit() : form.submit(); }
        },
    });
    // Revert immediately, while the modal asks; onConfirm (above) re-applies
    // newValue and submits only if the person actually confirms.
    select.value = prevValue;
});

// ---------------------------------------------------------
// Manage Reports / My Reports: double-click anywhere on a row to view that report in the
// same popup the Browse cards use. Clicks on the row's own controls (links,
// the status dropdown, checkboxes, action buttons) are left alone.
// ---------------------------------------------------------
document.addEventListener('dblclick', (e) => {
    const row = e.target.closest('.data-table tbody tr[data-report-id]');
    if (!row || e.target.closest('a, button, input, select, textarea, label, form')) return;
    const sel = window.getSelection && window.getSelection();
    if (sel) sel.removeAllRanges(); // a double-click also selects a word; drop it
    const prefix = document.body.dataset.prefix || '';
    ItemModal.open(prefix + 'item.php?id=' + encodeURIComponent(row.dataset.reportId));
});

// ---------------------------------------------------------
// Missing report images: if a report's image file isn't actually there (the
// uploads folder isn't in git, so copies of the project often lack them),
// swap the browser's broken-image icon for the same "No Image" placeholder
// used when no image was attached. The server already checks the file when
// it renders (report_image_exists() in functions.php); this catches the
// rest, e.g. images added to the page live. `error` doesn't bubble, hence
// the capture phase.
// ---------------------------------------------------------
function showNoImage(img) {
    if (img.dataset.noImageDone) return;
    const inDetail = img.closest('.item-detail-image');
    if (!inDetail && !img.closest('.item-thumb') && !img.classList.contains('preview-img')) return;
    img.dataset.noImageDone = '1';
    const ph = document.createElement('div');
    ph.className = 'no-image' + (inDetail ? ' large' : '') + (img.classList.contains('preview-img') ? ' no-image-small' : '');
    ph.textContent = 'No Image';
    img.replaceWith(ph);
}
document.addEventListener('error', (e) => {
    if (e.target && e.target.tagName === 'IMG') showNoImage(e.target);
}, true);
document.addEventListener('DOMContentLoaded', () => {
    // Anything that already failed before this script was running.
    document.querySelectorAll('.item-thumb img, .item-detail-image img, img.preview-img').forEach((img) => {
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) showNoImage(img);
    });
});

// ---------------------------------------------------------
// ScrollKeep — status changes, deletes, restores and edits all POST and then
// redirect back to the same page, which a browser treats as a brand-new
// navigation and starts at the top. Remember the scroll position when such
// a form goes out, and put it back if the page that loads is the same one
// (and it happens right away, so it never fires on some later, unrelated
// visit). Registered after the confirm handlers above on purpose: a form
// that's still waiting on its "Are you sure?" has defaultPrevented set and
// is skipped; only the real, confirmed submission is saved.
// ---------------------------------------------------------
const ScrollKeep = {
    KEY: 'lf_scroll_keep',
    here() { return location.pathname.replace(/\.php$/, '') + location.search; },
    save() {
        try { sessionStorage.setItem(this.KEY, JSON.stringify({ p: this.here(), y: window.scrollY, t: Date.now() })); } catch (err) {}
    },
    restore() {
        let saved;
        try { saved = JSON.parse(sessionStorage.getItem(this.KEY) || 'null'); sessionStorage.removeItem(this.KEY); } catch (err) { return; }
        if (!saved || Date.now() - saved.t > 10000 || saved.p !== this.here()) return;
        const go = () => window.scrollTo(0, saved.y);
        go();
        window.addEventListener('load', go, { once: true }); // late-loading images shift layout
    },
};
ScrollKeep.restore();

// Messages page: selecting a conversation, deleting one, or deleting a
// message all reload the page, which used to snap the conversation list
// back to the top (and a message delete also dropped the thread to the
// bottom). Remember both scroll positions across that reload.
const MessengerScroll = {
    KEY: 'lf_messenger_scroll',
    save(withThread) {
        const list = document.getElementById('convo-list');
        const chat = document.getElementById('chat-window');
        try {
            sessionStorage.setItem(this.KEY, JSON.stringify({
                list: list ? list.scrollTop : 0,
                chat: (withThread && chat) ? chat.scrollTop : null,
                t: Date.now(),
            }));
        } catch (err) {}
    },
    take() {
        try {
            const saved = JSON.parse(sessionStorage.getItem(this.KEY) || 'null');
            sessionStorage.removeItem(this.KEY);
            if (saved && Date.now() - saved.t < 10000) return saved;
        } catch (err) {}
        return null;
    },
};
const messengerSaved = MessengerScroll.take();

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || (form.method || '').toLowerCase() !== 'post') return;
    ScrollKeep.save();
});

// Tables: cells clip long text with an ellipsis (see .data-table td in the
// stylesheet); give each clipped cell a tooltip with its full text.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.data-table td').forEach((td) => {
        if (td.querySelector('select, button, form, input') || td.title) return;
        if (td.scrollWidth > td.clientWidth) td.title = td.textContent.trim();
    });
});

// ---------------------------------------------------------
// Lost and Found — client-side interactivity
// ---------------------------------------------------------

// "+" button file pickers (chat image, report/edit-report photo, CSV
// import). The real <input type="file"> stays in the form but is visually
// hidden; the same round button doubles as the remove control once a file
// is picked (its glyph flips from "+" to "x"), so there's no separate tiny
// target to miss-click. A picked image also shows a small preview, and a
// picked non-image file shows its name, both already in the page and just
// toggled with `hidden` rather than being created/removed.
//
// On the chat composer specifically, the picker's file input sits right
// after a text input for the message body — only one of the two is ever
// active: picking an image disables (and blanks, storing the file name
// there instead) the text box, so a message is either text or an image,
// never a mix that could get out of sync between what's shown and what's
// actually submitted.
// Wrapped in a function so pickers inside dynamically-loaded content (the
// report/edit modal) can be wired up after they're injected; already-wired
// pickers are skipped via data-picker-ready.
function initFilePickers(root) {
    (root || document).querySelectorAll('[data-file-picker]:not([data-picker-ready])').forEach((picker) => {
        picker.dataset.pickerReady = '1';
        const mode = picker.dataset.filePicker || 'image';
        const btn = picker.querySelector('.file-picker-btn');
        const input = picker.querySelector('input[type="file"]');
        if (!btn || !input) return;

        const thumb = picker.querySelector('.file-picker-thumb');
        const nameEl = picker.querySelector('.file-picker-filename');
        const pairedText = picker.previousElementSibling && picker.previousElementSibling.matches('input[type="text"]')
            ? picker.previousElementSibling
            : null;

        function setPicked(file) {
            picker.classList.toggle('has-file', !!file);
            if (file) {
                btn.textContent = '\u00d7';
                btn.classList.add('is-remove');
                btn.setAttribute('aria-label', mode === 'image' ? 'Remove image' : 'Remove file');
                if (thumb) { thumb.src = URL.createObjectURL(file); thumb.hidden = false; }
                if (nameEl) { nameEl.textContent = file.name; nameEl.hidden = false; }
                if (pairedText) {
                    pairedText.dataset.prevValue = pairedText.value;
                    pairedText.value = file.name;
                    pairedText.disabled = true;
                }
            } else {
                btn.textContent = '+';
                btn.classList.remove('is-remove');
                btn.setAttribute('aria-label', mode === 'image' ? 'Attach image' : 'Choose file');
                if (thumb) { thumb.hidden = true; thumb.removeAttribute('src'); }
                if (nameEl) { nameEl.hidden = true; nameEl.textContent = ''; }
                if (pairedText) {
                    pairedText.disabled = false;
                    pairedText.value = pairedText.dataset.prevValue || '';
                    delete pairedText.dataset.prevValue;
                }
            }
        }

        btn.addEventListener('click', () => {
            if (btn.classList.contains('is-remove')) {
                input.value = '';
                setPicked(null);
            } else {
                input.click();
            }
        });

        input.addEventListener('change', () => setPicked(input.files[0] || null));

        // Exposed so other code (e.g. the chat form's AJAX submit handler)
        // can put this picker back to its empty state after a successful
        // send, without duplicating the reset logic above.
        picker._resetFilePicker = () => { input.value = ''; setPicked(null); };
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initFilePickers(document);

    // Basic client-side validation nudge on the report form.
    const reportForm = document.querySelector('form.form-card');
    if (reportForm) {
        reportForm.addEventListener('submit', (e) => {
            const required = reportForm.querySelectorAll('[required]');
            for (const field of required) {
                if (!field.value) {
                    field.focus();
                    // Let native HTML5 validation handle the message.
                    return;
                }
            }
        });
    }

    // Open conversation thread: new messages / deletions arrive via Live (SSE).
    const chatWindow = document.getElementById('chat-window');
    if (chatWindow) {
        const convoId = chatWindow.dataset.convo;
        const myId = chatWindow.dataset.me;
        const isSystemThread = chatWindow.dataset.isSystem === '1';
        // After deleting a message the page reloads; stay where you were
        // instead of jumping to the bottom.
        chatWindow.scrollTop = (messengerSaved && messengerSaved.chat !== null) ? messengerSaved.chat : chatWindow.scrollHeight;

        function buildDeletedBubble(div) {
            div.classList.add('deleted');
            div.querySelectorAll('p:not(.deleted-msg-text), img.chat-image, .delete-msg-form').forEach((el) => el.remove());
            if (!div.querySelector('.deleted-msg-text')) {
                const p = document.createElement('p');
                p.className = 'deleted-msg-text';
                const em = document.createElement('em');
                em.textContent = 'Deleted message';
                p.appendChild(em);
                const timeEl = div.querySelector('.chat-time');
                div.insertBefore(p, timeEl || null);
            }
        }

        // Builds one message bubble exactly the way messages.php renders it
        // server-side, so a message appended here (right after sending, or
        // picked up by polling) looks identical to one loaded on page load.
        // Does NOT append it or touch scroll — callers handle that.
        // Mirrors includes/functions.php's render_chat_text(): the only kind
        // of link a chat message ever contains is a possible-match reference
        // to item.php?id=123, so that's the only pattern turned into a real
        // <a>; everything else is plain text, inserted safely via
        // textContent/appendChild rather than innerHTML.
        function renderChatText(container, text) {
            const re = /\bitem\.php\?id=(\d+)\b/g;
            let last = 0, match;
            while ((match = re.exec(text))) {
                if (match.index > last) container.appendChild(document.createTextNode(text.slice(last, match.index)));
                const a = document.createElement('a');
                a.href = match[0];
                a.textContent = match[0];
                container.appendChild(a);
                last = match.index + match[0].length;
            }
            if (last < text.length) container.appendChild(document.createTextNode(text.slice(last)));
        }

        // Mirrors includes/functions.php's match_likelihood_bar(): if the
        // text mentions "Likelihood: NN%" (the system's possible-match
        // messages always do), returns the labelled meter (header, bar and
        // a note on what it means). Returns null for any ordinary human
        // message. Keep in step with the PHP version.
        function buildMatchBar(text) {
            const match = text && text.match(/Likelihood:\s*(\d+)%/);
            if (!match) return null;
            const pct = Math.max(0, Math.min(100, parseInt(match[1], 10)));
            const tier = pct >= 75 ? 'high' : (pct >= 50 ? 'medium' : 'low');
            const label = { high: 'High', medium: 'Medium', low: 'Low' }[tier];

            const meter = document.createElement('div');
            meter.className = 'match-meter match-' + tier;
            meter.title = pct + '% likely to be the same item';

            const head = document.createElement('div');
            head.className = 'match-meter-head';
            const left = document.createElement('span');
            left.textContent = 'Match likelihood';
            const right = document.createElement('strong');
            right.textContent = pct + '% \u00b7 ' + label;
            head.append(left, right);

            const bar = document.createElement('div');
            bar.className = 'match-bar';
            const fill = document.createElement('div');
            fill.className = 'match-bar-fill';
            fill.style.width = pct + '%';
            bar.appendChild(fill);

            const note = document.createElement('p');
            note.className = 'match-meter-note';
            note.textContent = 'The fuller the bar, the closer the two reports match on item name, location and date.';

            meter.append(head, bar, note);
            return meter;
        }

        // Mirrors includes/functions.php's render_match_card(): a possible-match
        // system message becomes a compact card (title, the two report names,
        // the likelihood meter, and a "Compare reports" button). Returns null
        // for anything else so the caller shows plain text. Keep in step with
        // the PHP version.
        function buildMatchCard(text) {
            if (!text || !/^Possible Match!/.test(text)) return null;
            const mine = text.match(/^Your Report: "(.*)"\s*$/m);
            const theirs = text.match(/^Matching Report: "(.*)"\s*$/m);
            const link = text.match(/^Link: item\.php\?id=(\d+)(?:&mine=(\d+))?/m);
            const meter = buildMatchBar(text);
            if (!mine || !theirs || !link || !meter) return null;

            const card = document.createElement('div');
            card.className = 'match-card';

            const title = document.createElement('div');
            title.className = 'match-card-title';
            title.textContent = 'Possible match found';
            card.appendChild(title);

            const pair = document.createElement('dl');
            pair.className = 'match-card-pair';
            [['Your report', mine[1]], ['May match', theirs[1]]].forEach(([label, name]) => {
                const row = document.createElement('div');
                const dt = document.createElement('dt');
                dt.textContent = label;
                const dd = document.createElement('dd');
                dd.textContent = name;
                dd.title = name;
                row.append(dt, dd);
                pair.appendChild(row);
            });
            card.appendChild(pair);
            card.appendChild(meter);

            const a = document.createElement('a');
            a.className = 'btn match-card-btn';
            a.href = 'item.php?id=' + link[1] + (link[2] ? '&mine=' + link[2] : '');
            a.textContent = link[2] ? 'Compare reports' : 'View report';
            card.appendChild(a);
            return card;
        }

        // Mirrors includes/functions.php's render_admin_edit_card(): the "An
        // administrator edited your report." system message becomes a card
        // (report name, what changed, "View report" button). Returns null for
        // anything else. Keep in step with the PHP version.
        // Mirrors render_notice_card() in includes/functions.php: the shared card for
        // the other System Notifications (status changes, reopened/closed reports,
        // deleted conversations): title, label/value rows, and a "View report" button
        // when the notice has a link. Keep in step with the PHP version.
        function buildNoticeCard(text) {
            if (!text || text.indexOf('Notice: ') !== 0) return null;
            const title = text.match(/^Notice: (.+)$/m);
            if (!title) return null;
            const rows = [];
            const name = text.match(/^Report: "(.*)"\s*$/m);
            if (name) rows.push(['Report', name[1]]);
            const details = text.match(/^Details: (.*)$/m);
            if (details) {
                details[1].trim().split('; ').forEach((d) => {
                    if (!d) return;
                    const m = d.match(/^([^:]+): (.*)$/);
                    rows.push(m ? [m[1], m[2]] : ['Info', d]);
                });
            }
            const link = text.match(/^Link: item\.php\?id=(\d+)\s*$/m);

            const card = document.createElement('div');
            card.className = 'match-card admin-edit-card';
            const heading = document.createElement('div');
            heading.className = 'match-card-title';
            heading.textContent = title[1].trim();
            card.appendChild(heading);
            if (rows.length) {
                const pair = document.createElement('dl');
                pair.className = 'match-card-pair';
                rows.forEach(([label, value]) => {
                    const row = document.createElement('div');
                    const dt = document.createElement('dt');
                    dt.textContent = label;
                    const dd = document.createElement('dd');
                    dd.textContent = value;
                    dd.title = value;
                    row.append(dt, dd);
                    pair.appendChild(row);
                });
                card.appendChild(pair);
            }
            const claim = text.match(/^Action: (?:claim|resolve):(\d+)\s*$/m);
            if (claim || link) {
                const actions = document.createElement('div');
                actions.className = 'match-card-actions';
                if (claim) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'btn match-card-btn notice-action';
                    b.dataset.action = 'resolve';
                    b.dataset.report = claim[1];
                    b.textContent = 'Mark as Resolved';
                    actions.appendChild(b);
                }
                if (link) {
                    const a = document.createElement('a');
                    a.className = 'btn match-card-btn' + (claim ? ' btn-secondary' : '');
                    a.href = 'item.php?id=' + link[1];
                    a.textContent = 'View report';
                    actions.appendChild(a);
                }
                card.appendChild(actions);
            }
            return card;
        }

        function buildAdminEditCard(text) {
            if (!text || !/^An administrator edited your report\./.test(text)) return null;
            const name = text.match(/^Report: "(.*)"\s*$/m);
            const changes = text.match(/^Changes: (.*)$/m);
            const link = text.match(/^Link: item\.php\?id=(\d+)\s*$/m);
            if (!name || !changes || !link) return null;

            const card = document.createElement('div');
            card.className = 'match-card admin-edit-card';

            const title = document.createElement('div');
            title.className = 'match-card-title';
            title.textContent = 'Report edited by an administrator';
            card.appendChild(title);

            // One row per change, styled exactly like the match card's rows.
            const rows = [['Report', name[1]]];
            changes[1].trim().split('; ').forEach((c) => {
                if (!c) return;
                const m = c.match(/^([^:]+): (.*)$/);
                if (m) rows.push([m[1], m[2]]);
                else if (c === 'Description updated') rows.push(['Info', 'Updated']);
                else if (c === 'Photo replaced') rows.push(['Photo', 'Replaced']);
                else rows.push(['Change', c]);
            });
            const pair = document.createElement('dl');
            pair.className = 'match-card-pair';
            rows.forEach(([label, value]) => {
                const row = document.createElement('div');
                const dt = document.createElement('dt');
                dt.textContent = label;
                const dd = document.createElement('dd');
                dd.textContent = value;
                dd.title = value;
                row.append(dt, dd);
                pair.appendChild(row);
            });
            card.appendChild(pair);

            const a = document.createElement('a');
            a.className = 'btn match-card-btn';
            a.href = 'item.php?id=' + link[1];
            a.textContent = 'View report';
            card.appendChild(a);
            return card;
        }

        function buildMessageBubble(m) {
            const div = document.createElement('div');
            div.dataset.messageId = m.id;
            // Nobody can send into a System Notification thread (see the
            // guard in messages.php's POST handler), so every message in
            // one is, by definition, incoming — never "mine", even for the
            // dedicated system account's own id by some future accident.
            const isMine = !isSystemThread && String(m.sender_id) === String(myId);
            div.className = 'chat-bubble ' + (isMine ? 'mine' : 'theirs');

            const sender = document.createElement('span');
            sender.className = 'chat-sender';
            sender.textContent = m.first_name;
            div.appendChild(sender);

            if (!m.deleted_at) {
                if (m.body) {
                    const matchCard = buildMatchCard(m.body) || buildAdminEditCard(m.body) || buildNoticeCard(m.body);
                    if (matchCard) {
                        div.appendChild(matchCard);
                    } else {
                        const p = document.createElement('p');
                        renderChatText(p, m.body);
                        div.appendChild(p);
                        const bar = buildMatchBar(m.body);
                        if (bar) div.appendChild(bar);
                    }
                }
                if (m.image_path) {
                    const img = document.createElement('img');
                    img.className = 'chat-image';
                    img.loading = 'lazy';
                    img.src = m.image_path;
                    div.appendChild(img);
                }
            }
            const time = document.createElement('span');
            time.className = 'chat-time';
            time.textContent = formatMessageTime(m.created_at);
            div.appendChild(time);

            if (isMine && !m.deleted_at) {
                // Same plain yes/cancel confirm as the server-rendered
                // version in messages.php — the global submit listener (see
                // top of this file) picks this up the same way either way.
                const form = document.createElement('form');
                form.method = 'post';
                form.action = 'delete_message.php';
                form.className = 'delete-msg-form confirm-form';
                form.dataset.confirmTitle = 'Delete this message?';
                form.dataset.confirmMessage = 'This replaces it with "Deleted message" for both of you.';
                form.dataset.confirmLabel = 'Yes';
                form.dataset.confirmDanger = '1';
                form.innerHTML =
                    `<input type="hidden" name="message_id" value="${m.id}">` +
                    `<input type="hidden" name="convo_id" value="${convoId}">` +
                    `<button type="submit" class="delete-msg-btn" title="Delete message">&times;</button>`;
                div.appendChild(form);
            }

            if (m.deleted_at) buildDeletedBubble(div);
            return div;
        }

        // Adds a bubble unless it's already on screen. The stream can hand
        // back a message we just sent ourselves (or re-send after an
        // automatic reconnect), so appending must be idempotent.
        function appendMessage(m) {
            if (chatWindow.querySelector(`[data-message-id="${m.id}"]`)) return false;
            chatWindow.appendChild(buildMessageBubble(m));
            return true;
        }

        // Read receipts. The server no longer marks messages read on its own
        // (a stream can outlive the page that opened it, and a leftover one
        // would mark the next message read before anyone saw it — that was
        // the "first message never notifies" bug). Instead, once messages
        // from the other person are on screen AND the tab is visible, tell
        // the server which ones were seen. If the tab is hidden, wait until
        // it's shown again, so those messages still notify in the meantime.
        const ajaxPrefix = document.body.dataset.prefix || '';
        let pendingAck = 0;
        function acknowledge(upTo) {
            if (!upTo) return;
            if (document.visibilityState !== 'visible') {
                pendingAck = Math.max(pendingAck, upTo);
                return;
            }
            pendingAck = 0;
            fetch(`${ajaxPrefix}ajax/mark_read.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ convo_id: convoId, up_to: String(upTo) }),
                keepalive: true,
            }).catch(() => {});
        }
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && pendingAck) acknowledge(pendingAck);
        });

        Live.on('messages', (rows) => {
            let changed = false;
            let newestIncoming = 0;
            rows.forEach((m) => {
                if (appendMessage(m)) changed = true;
                if (String(m.sender_id) !== String(myId)) newestIncoming = Math.max(newestIncoming, Number(m.id));
            });
            if (changed) chatWindow.scrollTop = chatWindow.scrollHeight;
            acknowledge(newestIncoming);
        });

        Live.on('deleted', (ids) => {
            ids.forEach((id) => {
                const bubble = chatWindow.querySelector(`[data-message-id="${id}"]`);
                if (bubble && !bubble.classList.contains('deleted')) buildDeletedBubble(bubble);
            });
        });

        // The conversation was deleted while open — go back to the list.
        Live.on('gone', () => { window.location.href = 'messages.php?gone=1'; });

        // Sending a message: submit via fetch instead of a normal form post,
        // so the page never reloads/redirects. A full-page reload was the
        // main cause of the chat "jumping" on every send — the browser would
        // navigate away and back, visibly resetting scroll/layout each time.
        // This just drops the new bubble into the already-open thread.
        const chatForm = document.getElementById('chat-form');
        if (chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const bodyInput = chatForm.querySelector('input[name="body"]');
                const fileInput = chatForm.querySelector('input[type="file"]');
                const submitBtn = chatForm.querySelector('button[type="submit"]');
                const picker = chatForm.querySelector('[data-file-picker]');

                if (!bodyInput.value.trim() && !(fileInput && fileInput.files.length)) return;

                if (submitBtn) submitBtn.disabled = true;
                try {
                    const res = await fetch('messages.php?id=' + convoId, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(chatForm),
                    });
                    if (res.status === 404) {
                        // The conversation itself is gone (deleted by the
                        // other party, a stale link, etc.) — go back to the
                        // conversation list rather than leaving the person
                        // stuck typing into a thread that no longer exists.
                        window.location.href = 'messages.php';
                        return;
                    }
                    const data = await res.json();
                    if (!res.ok) {
                        Toast.show((data && (data.error || data.message)) || 'Could not send your message.', 'error');
                        return;
                    }
                    if (data.message) {
                        appendMessage(data.message);
                        chatWindow.scrollTop = chatWindow.scrollHeight;
                    }
                    chatForm.reset();
                    if (picker && picker._resetFilePicker) picker._resetFilePicker();
                    bodyInput.focus();
                } catch (err) {
                    console.error('Send error:', err);
                    Toast.show('Could not send your message — check your connection and try again.', 'error');
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }
    }

    // Click any chat image to view it full-size in a modal.
    const imageModal = document.getElementById('image-modal');
    if (imageModal) {
        const modalImg = imageModal.querySelector('img');
        const modalClose = imageModal.querySelector('.image-modal-close');

        function openImageModal(src, alt) {
            modalImg.src = src;
            modalImg.alt = alt || '';
            imageModal.hidden = false;
        }
        function closeImageModal() {
            imageModal.hidden = true;
            modalImg.removeAttribute('src');
        }

        // Delegated so it also catches chat images added later by polling.
        document.addEventListener('click', (e) => {
            const img = e.target.closest('.chat-image, .zoomable');
            if (img) openImageModal(img.src, img.alt);
        });
        if (modalClose) modalClose.addEventListener('click', closeImageModal);
        imageModal.addEventListener('click', (e) => {
            if (e.target === imageModal) closeImageModal();
        });
        // Capture phase + stopPropagation: Escape closes just the enlarged
        // image, not the report popup underneath it as well.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !imageModal.hidden) { e.stopPropagation(); closeImageModal(); }
        }, true);
    }
});

// ---------------------------------------------------------
// Live filtering on the Browse Items page: typing or changing a filter
// re-queries immediately, so the "Filter" button has nothing left to do —
// it's disabled from the start rather than sitting there waiting to be
// clicked before any filter has actually been applied.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('filter-form');
    const resultsGrid = document.getElementById('results');
    if (!filterForm || !resultsGrid) return;

    const qInput = filterForm.querySelector('input[name="q"]');
    const categorySelect = filterForm.querySelector('select[name="category"]');
    const statusSelect = filterForm.querySelector('select[name="status"]');
    const typeDropdown = document.getElementById('type-dropdown');
    const typeBtn = document.getElementById('type-dropdown-btn');
    const typeMenu = document.getElementById('type-dropdown-menu');
    const clearBtn = document.getElementById('browse-clear');

    const typeSeg = document.getElementById('type-segmented');
    let currentType = typeBtn ? (typeBtn.dataset.type || '') : (typeSeg ? (typeSeg.dataset.type || '') : '');
    let debounceTimer = null;

    // "Clear filters" is only usable while a Category / Status filter is set. The
    // search box has its own x, and the Lost / Found / All choice is not a filter
    // to clear — it's where the person is looking — so neither is touched.
    function updateClear() {
        if (!clearBtn) return;
        clearBtn.disabled = !((categorySelect && categorySelect.value)
            || (statusSelect && statusSelect.value));
    }

    // silent === true: a refresh the live stream asked for — no "N results found" toast.
    async function runSearch(silent) {
        updateClear();
        const params = new URLSearchParams();
        if (currentType) params.set('type', currentType);
        if (qInput && qInput.value.trim()) params.set('q', qInput.value.trim());
        if (categorySelect && categorySelect.value) params.set('category', categorySelect.value);
        if (statusSelect && statusSelect.value) params.set('status', statusSelect.value);

        const shareUrl = 'search.php' + (params.toString() ? '?' + params.toString() : '');
        params.set('ajax', '1');

        try {
            const res = await fetch(`search.php?${params.toString()}`);
            if (!res.ok) return;
            const html = await res.text();
            if (silent === true && html === resultsGrid.__lastHtml) return;   // nothing new: leave the page alone
            resultsGrid.__lastHtml = html;
            resultsGrid.innerHTML = html;
            history.replaceState(null, '', shareUrl);

            // Feedback that the filter actually did something — easy to miss
            // otherwise, since the grid can look the same at a glance.
            const count = resultsGrid.querySelectorAll('.item-card').length;
            if (silent !== true) Toast.show(count === 0 ? 'No matching reports found.' : count + ' result' + (count === 1 ? '' : 's') + ' found.', 'info', 2200);
        } catch (err) {
            console.error('Search error:', err);
        }
    }

    window.browseRefresh = () => runSearch(true);   // called when the live stream says the reports changed

    filterForm.addEventListener('submit', (e) => e.preventDefault());

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            // The search text stays; only the Category / Status filters reset.
            if (categorySelect) categorySelect.value = '';
            if (statusSelect) statusSelect.value = '';
            runSearch();
        });
    }
    updateClear();

    if (qInput) {
        qInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(runSearch, 300);
        });
    }
    if (categorySelect) categorySelect.addEventListener('change', runSearch);
    if (statusSelect) statusSelect.addEventListener('change', runSearch);

    // All / Lost / Found dropdown: the button shows the current choice (in its
    // own colour, at a fixed width); clicking it opens the menu.
    if (typeBtn && typeMenu) {
        const label = typeBtn.querySelector('.type-dropdown-label');
        const options = Array.from(typeMenu.querySelectorAll('.type-option'));
        const closeMenu = () => { typeMenu.hidden = true; typeBtn.setAttribute('aria-expanded', 'false'); };
        const openMenu = () => { typeMenu.hidden = false; typeBtn.setAttribute('aria-expanded', 'true'); };

        typeBtn.addEventListener('click', () => { if (typeMenu.hidden) openMenu(); else closeMenu(); });
        document.addEventListener('click', (e) => { if (!typeDropdown.contains(e.target)) closeMenu(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !typeMenu.hidden) { closeMenu(); typeBtn.focus(); }
        });

        options.forEach((opt) => {
            opt.addEventListener('click', (e) => {
                e.preventDefault();
                const chosen = opt.dataset.type || '';
                closeMenu();
                if (chosen === currentType) return;
                currentType = chosen;
                typeBtn.dataset.type = chosen;
                typeBtn.classList.remove('type-btn-lost', 'type-btn-found');
                if (chosen === 'lost') typeBtn.classList.add('type-btn-lost');
                if (chosen === 'found') typeBtn.classList.add('type-btn-found');
                label.textContent = opt.textContent.trim();
                options.forEach((o) => {
                    o.classList.toggle('selected', o === opt);
                    o.setAttribute('aria-selected', o === opt ? 'true' : 'false');
                });
                runSearch();
            });
        });
    }

    // All / Lost / Found segmented buttons (three-state, one click each).
    if (typeSeg) {
        const segBtns = Array.from(typeSeg.querySelectorAll('[data-type]'));
        segBtns.forEach((b) => {
            b.addEventListener('click', (e) => {
                e.preventDefault();
                const chosen = b.dataset.type || '';
                if (chosen === currentType) return;
                currentType = chosen;
                typeSeg.dataset.type = chosen;
                segBtns.forEach((o) => {
                    o.classList.toggle('selected', o === b);
                    o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
                });
                runSearch();
            });
        });
    }
});

// ---------------------------------------------------------
// Report tables (Manage Reports active/deleted, My Reports): live search +
// Type / Category / Status filters, and — on the admin pages — checkbox
// bulk-selection with a floating bulk-actions bar.
//
// A row is shown only if it matches the search text AND every filter that's
// set. Rows carry data-type / data-category / data-status for the filters.
// Hidden rows are always unchecked, so "select all", "deselect all" and the
// bulk bar only ever act on what's actually visible on screen.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('report-table');
    if (!table) return;

    const form = document.getElementById('bulk-form'); // admin pages only
    const search = document.getElementById('report-search');
    const filterSelects = Array.from(document.querySelectorAll('.report-filter'));
    const resetBtn = document.getElementById('filters-reset');
    const selectAll = document.getElementById('select-all');
    const bar = document.getElementById('bulk-actions-bar');
    const countLabel = document.getElementById('bulk-selected-count');
    const actionInput = document.getElementById('bulk-action-input');
    const tbody = table.querySelector('tbody');
    const noun = table.dataset.noun || 'report'; // "report" on the report tables, "user" on Manage Users
    // Real data rows only (not the server's "nothing here" row).
    let rows = Array.from(tbody.querySelectorAll('tr')).filter((tr) => !tr.querySelector('td[colspan]'));

    // "Nothing matches" row, shown when the search/filters hide every row.
    let noMatch = null;
    function ensureNoMatch() {
        if (noMatch) return;
        noMatch = document.createElement('tr');
        noMatch.className = 'no-match-row';
        noMatch.hidden = true;
        const td = document.createElement('td');
        td.colSpan = table.querySelectorAll('thead th').length;
        td.textContent = 'No ' + noun + 's match your search or filters.';
        noMatch.appendChild(td);
        tbody.appendChild(noMatch);
    }
    if (rows.length) ensureNoMatch();
    let silentFilters = false;   // true while a live refresh re-applies the filters: no "N results" toast

    const visibleRows = () => rows.filter((tr) => !tr.hidden);
    const rowCheckbox = (tr) => tr.querySelector('.row-select');

    function refreshBulkBar() {
        const checked = rows.filter((tr) => rowCheckbox(tr) && rowCheckbox(tr).checked);
        if (bar) bar.hidden = checked.length === 0;
        document.body.classList.toggle('bulk-bar-open', checked.length > 0);
        if (countLabel) countLabel.textContent = checked.length + ' selected';

        if (selectAll) {
            const visible = visibleRows().filter((tr) => rowCheckbox(tr));
            const visibleChecked = visible.filter((tr) => rowCheckbox(tr).checked);
            selectAll.checked = visible.length > 0 && visibleChecked.length === visible.length;
            selectAll.indeterminate = visibleChecked.length > 0 && visibleChecked.length < visible.length;
        }
    }

    // Searchable text for a row: each cell's text (for the Status dropdown,
    // just the selected option, not "PendingResolved"), plus its category,
    // which isn't a visible column.
    function rowText(tr) {
        const parts = Array.from(tr.children).map((td) => {
            const sel = td.querySelector('select');
            return sel ? sel.options[sel.selectedIndex].text : td.textContent;
        });
        parts.push(tr.dataset.category || '');
        return parts.join(' ').toLowerCase();
    }

    // Same feedback as Browse: a brief toast saying what the search/filter
    // found. Debounced so typing doesn't stack a toast per keystroke.
    let toastTimer = null;
    function announceResults(shown) {
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            Toast.show(shown === 0 ? 'No matching ' + noun + 's found.' : shown + ' result' + (shown === 1 ? '' : 's') + ' found.', 'info', 2200);
        }, 300);
    }

    // Manage Users: "last active on or before" a day (end of that day, local time).
    const dateFilter = document.querySelector('.report-date-filter');
    const dateModeBtn = document.getElementById('date-mode');

    function applyFilters() {
        const q = search ? search.value.trim().toLowerCase() : '';
        const active = {};
        filterSelects.forEach((sel) => { if (sel.value) active[sel.dataset.filter] = sel.value; });
        // Manage Users date filter: "Active / Joined" + "before / after" the chosen day
        // (before = up to the end of that day, after = from the start of it).
        const dateMode = dateModeBtn ? dateModeBtn.dataset.mode : 'active-before';
        const dateAfter = dateMode.endsWith('after');
        const dateAttr = dateMode.startsWith('joined') ? 'joined' : 'lastActive';
        let dateLimit = null;
        if (dateFilter && dateFilter.value) {
            const [y, m, d] = dateFilter.value.split('-').map(Number);
            dateLimit = dateAfter
                ? new Date(y, m - 1, d, 0, 0, 0).getTime() / 1000
                : new Date(y, m - 1, d, 23, 59, 59).getTime() / 1000;
        }

        let shown = 0;
        rows.forEach((tr) => {
            const ok = (!q || rowText(tr).includes(q))
                && Object.keys(active).every((key) => (tr.dataset[key] || '') === active[key])
                && (dateLimit === null || (dateAfter
                    ? Number(tr.dataset[dateAttr] || 0) >= dateLimit
                    : Number(tr.dataset[dateAttr] || 0) <= dateLimit));
            tr.hidden = !ok;
            if (ok) {
                shown++;
            } else {
                const cb = rowCheckbox(tr);
                if (cb) cb.checked = false; // hidden rows never stay selected
            }
        });
        if (noMatch) noMatch.hidden = shown > 0;
        // "Clear filters" only concerns the dropdown filters, never the search box.
        if (resetBtn) resetBtn.disabled = !(Object.keys(active).length || dateLimit !== null);
        refreshBulkBar();
        if (!silentFilters) announceResults(shown);
    }

    if (search) search.addEventListener('input', applyFilters);
    filterSelects.forEach((sel) => sel.addEventListener('change', applyFilters));
    if (dateFilter) dateFilter.addEventListener('change', applyFilters);
    const dateModeMenu = document.getElementById('date-mode-menu');
    if (dateModeBtn && dateModeMenu) {
        // The Active before / Active after / Joined before / Joined after dropdown.
        const modeLabel = document.getElementById('date-mode-label');
        const modeOptions = Array.from(dateModeMenu.querySelectorAll('.date-mode-option'));
        const closeModeMenu = () => { dateModeMenu.hidden = true; dateModeBtn.setAttribute('aria-expanded', 'false'); };
        const setMode = (mode) => {
            dateModeBtn.dataset.mode = mode;
            modeOptions.forEach((o) => o.classList.toggle('selected', o.dataset.mode === mode));
            const chosen = modeOptions.find((o) => o.dataset.mode === mode);
            if (chosen) modeLabel.textContent = chosen.textContent.trim();
        };
        dateModeBtn.addEventListener('click', () => {
            dateModeMenu.hidden = !dateModeMenu.hidden;
            dateModeBtn.setAttribute('aria-expanded', dateModeMenu.hidden ? 'false' : 'true');
        });
        document.addEventListener('click', (e) => { if (!dateModeBtn.parentElement.contains(e.target)) closeModeMenu(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !dateModeMenu.hidden) { closeModeMenu(); dateModeBtn.focus(); } });
        modeOptions.forEach((opt) => {
            const choose = () => { setMode(opt.dataset.mode); closeModeMenu(); applyFilters(); };
            opt.addEventListener('click', choose);
            opt.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); } });
        });
        // "Clear filters" also puts the dropdown back to its first option.
        if (resetBtn) resetBtn.addEventListener('click', () => setMode('active-before'));
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            filterSelects.forEach((sel) => { sel.value = ''; });
            if (dateFilter) dateFilter.value = '';
            applyFilters();
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            visibleRows().forEach((tr) => {
                const cb = rowCheckbox(tr);
                if (cb) cb.checked = selectAll.checked;
            });
            refreshBulkBar();
        });
    }

    table.addEventListener('change', (e) => {
        if (e.target.classList.contains('row-select')) refreshBulkBar();
    });

    if (bar && form) {
        bar.querySelectorAll('[data-bulk-action]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const selectedCount = rows.filter((tr) => rowCheckbox(tr) && rowCheckbox(tr).checked).length;
                if (selectedCount === 0) return;
                const submitBulk = (password) => {
                    actionInput.value = btn.dataset.bulkAction;
                    if (password !== undefined) {
                        // the server checks this password again (admin_password_ok) — the pop-up is not the security
                        let h = form.querySelector('input[name="confirm_password"]');
                        if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'confirm_password'; form.appendChild(h); }
                        h.value = password;
                    }
                    ScrollKeep.save();
                    form.submit();
                };
                const confirmMsg = btn.dataset.confirm;
                if (!confirmMsg) { submitBulk(); return; }
                const title = selectedCount + ' ' + noun + (selectedCount === 1 ? '' : 's') + ' selected';
                // Permanently deleting more than one report at once gets the
                // same type-to-confirm treatment as doing it one at a time.
                if (btn.dataset.bulkPassword) {
                    // Account actions (Manage Users) need the admin's password, same as one at a time.
                    Modal.confirmPassword({ title, message: confirmMsg, confirmLabel: btn.textContent.trim(), verifyUrl: bar.dataset.verifyUrl, onConfirm: submitBulk });
                } else if (btn.dataset.bulkAction === 'delete_permanent') {
                    Modal.confirmTyped({ title, message: confirmMsg, confirmLabel: btn.textContent.trim(), requireText: 'yes', onConfirm: submitBulk });
                } else {
                    Modal.confirm({ title, message: confirmMsg, confirmLabel: btn.textContent.trim(), danger: btn.classList.contains('btn-danger'), onConfirm: submitBulk });
                }
            });
        });
    }

    // ---- Live refresh: the stream says the reports changed (someone else resolved one, an admin closed
    // one, a new one arrived, one was deleted). Pull the page again and update only what's different,
    // keeping everything the person has going: the search, filters, sort order, which rows are ticked,
    // and their scroll position. Waits while a popup is open or a dropdown is being used.
    const rowKey = (tr) => {
        const id = tr.querySelector('input[name="report_id"]') || tr.querySelector('.row-select');
        return id ? id.value : '';
    };
    const rowSignature = (tr) => [
        ...Object.entries(tr.dataset).filter(([k]) => k !== 'origIndex').map(([k, v]) => k + '=' + v),
        tr.textContent.replace(/\s+/g, ' ').trim(),
        ...Array.from(tr.querySelectorAll('select')).map((s) => { const o = s.querySelector('option[selected]'); return o ? o.value : s.value; }),
        ...Array.from(tr.querySelectorAll('input[name="expected"]')).map((i) => i.value),
    ].join('|');
    const popupOpen = () => ['modal-overlay', 'report-modal-overlay', 'item-modal-overlay', 'verify-modal-overlay', 'forgot-modal-overlay']
        .some((id) => { const el = document.getElementById(id); return el && !el.hidden; });
    const usingDropdown = () => document.activeElement && document.activeElement.matches && document.activeElement.matches('table select');

    let syncing = false;
    let syncQueued = 0;
    async function syncFromServer(attempt = 0) {
        if (syncing) { syncQueued = 1; return; }
        if ((popupOpen() || usingDropdown()) && attempt < 20) { setTimeout(() => syncFromServer(attempt + 1), 1000); return; }
        syncing = true;
        try {
            const res = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Live-Refresh': '1' }, cache: 'no-store' });
            if (!res.ok) return;
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const freshBody = doc.querySelector('#report-table tbody');
            if (!freshBody) return;
            const freshRows = Array.from(freshBody.querySelectorAll('tr')).filter((tr) => !tr.querySelector('td[colspan]'));

            const current = new Map();
            rows.forEach((tr) => { const k = rowKey(tr); if (k) current.set(k, tr); });
            const seen = new Set();
            const ordered = [];
            const touched = [];
            freshRows.forEach((fr) => {
                const k = rowKey(fr);
                if (!k) return;
                seen.add(k);
                const cur = current.get(k);
                if (!cur) {
                    const added = document.importNode(fr, true);
                    ordered.push(added);
                    touched.push(added);
                } else {
                    if (rowSignature(cur) !== rowSignature(fr)) {
                        // Update in place: the row's own element (and its tick box) stays; its details change.
                        Object.keys(cur.dataset).forEach((key) => { if (key !== 'origIndex') delete cur.dataset[key]; });
                        Object.entries(fr.dataset).forEach(([key, v]) => { cur.dataset[key] = v; });
                        Array.from(fr.children).forEach((td, i) => {
                            if (td.classList.contains('select-col') || !cur.children[i]) return;
                            cur.children[i].replaceWith(document.importNode(td, true));
                        });
                        touched.push(cur);
                    }
                    ordered.push(cur);
                }
            });
            const gone = rows.filter((tr) => { const k = rowKey(tr); return k && !seen.has(k); });
            if (!touched.length && !gone.length) return;

            gone.forEach((tr) => tr.remove());
            // The server's own "nothing here" row goes away once there are rows, and comes back when there are none.
            Array.from(tbody.querySelectorAll('td[colspan]')).forEach((td) => { if (!td.parentElement.classList.contains('no-match-row')) td.parentElement.remove(); });
            if (!freshRows.length) {
                Array.from(freshBody.querySelectorAll('tr')).forEach((tr) => tbody.insertBefore(document.importNode(tr, true), noMatch));
            }
            if (freshRows.length) ensureNoMatch();
            ordered.forEach((tr, i) => { tr.dataset.origIndex = String(i); tbody.insertBefore(tr, noMatch); });   // the server's order, unless sorted below
            rows = Array.from(tbody.querySelectorAll('tr')).filter((tr) => !tr.querySelector('td[colspan]') && !tr.classList.contains('no-match-row'));
            if (window.__reapplySort) window.__reapplySort();

            silentFilters = true;
            applyFilters();
            silentFilters = false;
            refreshBulkBar();

            touched.forEach((tr) => { tr.classList.remove('row-updated'); void tr.offsetWidth; tr.classList.add('row-updated'); });
            Toast.show(gone.length && !touched.length ? 'A ' + noun + ' was removed from this list.' : 'This list was just updated by someone else.', 'info', 2500);
        } catch (err) {
            // A failed refresh is not worth bothering anyone about; the next change will try again.
        } finally {
            syncing = false;
            if (syncQueued) { syncQueued = 0; syncFromServer(); }
        }
    }
    window.reportTableRefresh = () => syncFromServer();
});

// ---------------------------------------------------------
// Sortable tables — any table with .sortable-th headers (Manage Reports,
// My Reports). Click a header to sort by it; click again to reverse. Uses
// each cell's data-sort-value when the column sets one (Status, and any
// date column — raw timestamp/status, not the rendered text, since a
// <select>'s own textContent is every option glued together and a
// formatted date doesn't sort chronologically as a string) and falls back
// to the cell's own text otherwise. Reorders the real rows in the DOM, so
// search/selection code that holds references to the rows keeps working.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('table').forEach((table) => {
        const headerRow = table.querySelector('thead tr');
        const tbody = table.querySelector('tbody');
        if (!headerRow || !tbody) return;
        const sortableHeaders = Array.from(headerRow.querySelectorAll('.sortable-th'));
        if (!sortableHeaders.length) return;

        // Rows that are real data (not an empty-state "nothing here" row).
        const dataRows = () => Array.from(tbody.querySelectorAll('tr')).filter((tr) => !tr.querySelector('td[colspan]'));

        function cellValue(tr, colIndex) {
            const td = tr.children[colIndex];
            if (!td) return '';
            return td.dataset.sortValue !== undefined ? td.dataset.sortValue : td.textContent.trim().toLowerCase();
        }

        function sortRows(colIndex, asc) {
            const sorted = dataRows().sort((a, b) => {
                const va = cellValue(a, colIndex), vb = cellValue(b, colIndex);
                const numA = parseFloat(va), numB = parseFloat(vb);
                const bothNumeric = va !== '' && vb !== '' && !isNaN(numA) && !isNaN(numB);
                const cmp = bothNumeric ? numA - numB : va.localeCompare(vb);
                return asc ? cmp : -cmp;
            });
            sorted.forEach((tr) => tbody.appendChild(tr));
        }

        // Remember the order the server gave, so sorting can be cleared again.
        dataRows().forEach((tr, i) => { tr.dataset.origIndex = i; });

        sortableHeaders.forEach((th) => {
            th.title = 'Click to sort · click again to reverse · a third click clears the sorting';
            th.addEventListener('click', () => {
                const colIndex = Array.from(headerRow.children).indexOf(th);
                const prev = th.dataset.sortDir;
                sortableHeaders.forEach((h) => { h.dataset.sortDir = ''; h.classList.remove('sort-asc', 'sort-desc'); });
                if (prev === 'desc') {
                    // Third click on the same header: back to the default order.
                    dataRows().sort((a, b) => Number(a.dataset.origIndex) - Number(b.dataset.origIndex)).forEach((tr) => tbody.appendChild(tr));
                    return;
                }
                const asc = prev !== 'asc';
                th.dataset.sortDir = asc ? 'asc' : 'desc';
                th.classList.add(asc ? 'sort-asc' : 'sort-desc');

                sortRows(colIndex, asc);
            });
        });

        // After a live refresh the rows may have changed: put them back in the order the person had chosen.
        if (table.id === 'report-table') {
            window.__reapplySort = () => {
                const th = sortableHeaders.find((h) => h.dataset.sortDir === 'asc' || h.dataset.sortDir === 'desc');
                if (!th) return false;
                sortRows(Array.from(headerRow.children).indexOf(th), th.dataset.sortDir === 'asc');
                return true;
            };
        }
    });
});

// ---------------------------------------------------------
// Messages page: the stream pushes fresh conversation-list markup whenever
// unread counts, previews or ordering change.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const convoList = document.getElementById('convo-list');
    if (!convoList) return;

    // Keep the list where it was across the page reload that follows
    // selecting a conversation, deleting one, or deleting a message.
    if (messengerSaved) convoList.scrollTop = messengerSaved.list;
    convoList.addEventListener('click', (e) => {
        if (e.target.closest('a.convo-row')) MessengerScroll.save(false);
    });
    document.addEventListener('submit', (e) => {
        const f = e.target;
        if (!f.matches) return;
        if (f.matches('.convo-delete-form')) MessengerScroll.save(false);
        else if (f.matches('.delete-msg-form')) MessengerScroll.save(true);
    }, true);

    // Search box above the list: filters by person, report / subject and last message. The live updates
    // below replace the list's markup, so the filter is re-applied after each one.
    const searchInput = document.getElementById('convo-search');
    const applyConvoFilter = () => {
        const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        let shown = 0;
        convoList.querySelectorAll('.convo-row-wrap').forEach((row) => {
            const match = q === '' || row.textContent.toLowerCase().replace(/\s+/g, ' ').includes(q)
                || (row.querySelector('.convo-row') || row).title.toLowerCase().includes(q);
            row.hidden = !match;
            if (match) shown++;
        });
        let none = convoList.querySelector('.convo-nomatch');
        if (q !== '' && shown === 0 && convoList.querySelector('.convo-row-wrap')) {
            if (!none) {
                none = document.createElement('p');
                none.className = 'convo-empty convo-nomatch';
                convoList.appendChild(none);
            }
            none.textContent = 'No conversations match “' + searchInput.value.trim() + '”.';
        } else if (none) {
            none.remove();
        }
        const clear = document.getElementById('convo-search-clear');
        if (clear) clear.hidden = q === '';
    };
    if (searchInput) {
        searchInput.addEventListener('input', applyConvoFilter);
        searchInput.addEventListener('keydown', (e) => { if (e.key === 'Escape' && searchInput.value) { searchInput.value = ''; applyConvoFilter(); } });
        const clear = document.getElementById('convo-search-clear');
        if (clear) clear.addEventListener('click', () => { searchInput.value = ''; applyConvoFilter(); searchInput.focus(); });
    }

    Live.on('convolist', (data) => {
        // Swapping innerHTML resets scrollTop to 0 — save and restore it so
        // the list stays visually still when an update lands.
        const scrollTop = convoList.scrollTop;
        convoList.innerHTML = data.html;
        convoList.scrollTop = scrollTop;
        applyConvoFilter();
    });
});

// ---------------------------------------------------------
// Home page greeting: follows the visitor's own clock, not the server's.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const el = document.querySelector('[data-greeting]');
    if (!el) return;
    const update = () => {
        const h = new Date().getHours();
        el.textContent = (h >= 5 && h < 12) ? 'Good morning' : (h >= 12 && h < 18) ? 'Good afternoon' : 'Good evening';
    };
    update();
    setInterval(update, 60000);
});

// ---------------------------------------------------------
// Site-wide notifications: the stream pushes two separate groups — the
// bell (System Notification / possible-match alerts: a plain dot, no
// number) and Chats (real conversations with people: a number, like
// before). Keeps the header icons, the side menu's "Messages" badge, and
// both dropdown lists current on whatever page the person is on.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const notifBadge    = document.getElementById('notif-badge');   // dot
    const chatBadge     = document.getElementById('chat-badge');    // number
    const sidemenuBadge = document.getElementById('sidemenu-badge'); // number (= chat)
    const notifList     = document.getElementById('notif-list');
    const notifSeeAll   = document.getElementById('notif-see-all');
    const chatList      = document.getElementById('chat-list');
    if (!notifBadge && !chatBadge && !sidemenuBadge && !notifList && !chatList) return;

    const prefix = document.body.dataset.prefix || '';

    function setDot(el, count) {
        if (el) el.hidden = count <= 0;
    }
    function setCount(el, count) {
        if (!el) return;
        el.hidden = count <= 0;
        el.textContent = count > 99 ? '99+' : String(count);
    }
    function fillList(el, notifs, emptyText) {
        if (!el) return;
        el.innerHTML = '';
        if (notifs && notifs.length) {
            notifs.forEach((n) => {
                const a = document.createElement('a');
                a.className = 'notif-item';
                a.href = `${prefix}chat.php?id=${n.convo_id}`;

                const strong = document.createElement('strong');
                strong.textContent = `${n.first_name} ${n.last_name}`;
                a.appendChild(strong);

                const p = document.createElement('p');
                p.textContent = n.snippet;
                a.appendChild(p);

                el.appendChild(a);
            });
        } else {
            const p = document.createElement('p');
            p.className = 'dropdown-empty';
            p.textContent = emptyText;
            el.appendChild(p);
        }
    }

    // Messages in the conversation that's open on screen are about to be
    // marked read (see the read receipts in the thread code above), so
    // while the tab is visible they shouldn't flash up in the badges.
    const openThread = document.getElementById('chat-window');
    const openConvoId = openThread ? Number(openThread.dataset.convo) : 0;
    function withoutOpenThread(group) {
        if (!openConvoId || document.visibilityState !== 'visible') return group;
        const kept = (group.notifs || []).filter((n) => Number(n.convo_id) !== openConvoId);
        const dropped = (group.notifs || []).length - kept.length;
        return { unread: Math.max(0, group.unread - dropped), notifs: kept };
    }

    Live.on('notifications', (raw) => {
        const system = withoutOpenThread(raw.system);
        const chat = withoutOpenThread(raw.chat);

        setDot(notifBadge, system.unread);
        fillList(notifList, system.notifs, 'No new notifications.');
        // "See all messages" in the bell opens the System Notification thread itself.
        if (notifSeeAll && system.notifs && system.notifs.length) {
            notifSeeAll.href = `${prefix}messages.php?id=${system.notifs[0].convo_id}`;
        }

        setCount(chatBadge, chat.unread);
        setCount(sidemenuBadge, chat.unread);
        fillList(chatList, chat.notifs, 'No new messages.');
    });
});

// ---------------------------------------------------------
// Side menu + notification/account dropdowns (Facebook-style icon bar)
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const menuToggle = document.getElementById('menu-toggle');
    const menuClose  = document.getElementById('menu-close');
    const sideMenu   = document.getElementById('side-menu');
    const overlay    = document.getElementById('side-overlay');

    function openMenu() {
        if (!sideMenu) return;
        sideMenu.classList.add('open');
        overlay.classList.add('open');
        document.body.classList.add('menu-open');
        menuToggle.setAttribute('aria-expanded', 'true');
    }
    function closeMenu() {
        if (!sideMenu) return;
        sideMenu.classList.remove('open');
        overlay.classList.remove('open');
        document.body.classList.remove('menu-open');
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    }
    if (menuToggle) menuToggle.addEventListener('click', openMenu);
    if (menuClose) menuClose.addEventListener('click', closeMenu);
    if (overlay) overlay.addEventListener('click', closeMenu);

    // Facebook-style icon dropdowns: click to toggle, click elsewhere to close,
    // and opening one closes the other.
    const dropdowns = [
        { toggle: document.getElementById('notif-toggle'), panel: document.getElementById('notif-panel') },
        { toggle: document.getElementById('chat-toggle'), panel: document.getElementById('chat-panel') },
        { toggle: document.getElementById('user-toggle'), panel: document.getElementById('user-panel') },
    ].filter((d) => d.toggle && d.panel);

    function closeAllDropdowns(except) {
        dropdowns.forEach((d) => {
            if (d.panel !== except) {
                d.panel.classList.remove('open');
                d.toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    dropdowns.forEach((d) => {
        d.toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = d.panel.classList.contains('open');
            closeAllDropdowns(null);
            if (!isOpen) {
                d.panel.classList.add('open');
                d.toggle.setAttribute('aria-expanded', 'true');
            } else {
                d.panel.classList.remove('open');
                d.toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });

    document.addEventListener('click', () => closeAllDropdowns(null));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllDropdowns(null);
            closeMenu();
        }
    });
});

// ---------------------------------------------------------
// Dashboard (index.php): the "Recent Reports" cards follow the live stream, so a report that is deleted,
// restored or added appears / disappears without a refresh (the Browse page does the same, see runSearch).
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('recent-results');
    if (!grid) return;
    let busy = false;
    window.browseRefresh = async () => {
        if (busy) return;
        busy = true;
        try {
            const res = await fetch((document.body.dataset.prefix || '') + 'index.php?ajax=1', { cache: 'no-store' });
            if (!res.ok) return;
            const html = await res.text();
            if (html.trim() !== grid.dataset.lastHtml) {
                grid.dataset.lastHtml = html.trim();
                grid.innerHTML = html;
            }
        } catch (err) { /* try again on the next change */ }
        finally { busy = false; }
    };
    grid.dataset.lastHtml = grid.innerHTML.trim();
});

// ---------------------------------------------------------
// Admin dashboard: the numbers follow the live stream (a report added / deleted / resolved, a user who
// registers or is deleted, a conversation started or removed) — the page asks for itself again and swaps in the new figures.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const box = document.getElementById('dash-live');
    if (!box) return;
    let busy = false;
    window.dashRefresh = async () => {
        if (busy) return;
        busy = true;
        try {
            const res = await fetch(location.href, { headers: { 'X-Live-Refresh': '1' }, cache: 'no-store' });
            if (!res.ok) return;
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const fresh = doc.getElementById('dash-live');
            if (fresh && fresh.innerHTML.trim() !== box.innerHTML.trim()) box.innerHTML = fresh.innerHTML;
        } catch (err) { /* the next change will try again */ }
        finally { busy = false; }
    };
});

// ---------------------------------------------------------
// Three-dot menu (conversation list rows and the chat's title bar). The menu is built from the button's
// data-* attributes and lives on <body>, so the conversation list re-rendering underneath it (the live
// stream replaces the list) can't close it. It shows the other person's email (click to email them), a link
// to the report (opens in the same popup as everywhere else), and Delete conversation (typed confirmation).
// ---------------------------------------------------------
const ConvoMenu = {
    menu: null,
    btn: null,

    close() {
        if (this.menu) this.menu.remove();
        if (this.btn) this.btn.setAttribute('aria-expanded', 'false');
        this.menu = null;
        this.btn = null;
    },

    open(btn) {
        this.close();
        const prefix = document.body.dataset.prefix || '';
        const d = btn.dataset;
        const menu = document.createElement('div');
        menu.className = 'kebab-menu';
        menu.setAttribute('role', 'menu');

        const name = document.createElement('div');
        name.className = 'kebab-menu-name';
        name.textContent = d.name || 'Conversation';
        menu.appendChild(name);

        if (d.email) {
            const mail = document.createElement('a');
            mail.className = 'kebab-item';
            mail.href = 'mailto:' + d.email;
            mail.setAttribute('role', 'menuitem');
            mail.title = 'Send an email to ' + d.email;
            mail.innerHTML = '<small>Email</small>';
            mail.appendChild(document.createTextNode(d.email));
            menu.appendChild(mail);
        }
        if (d.report) {
            const rep = document.createElement('a');
            rep.className = 'kebab-item';
            rep.href = prefix + 'item.php?id=' + encodeURIComponent(d.report);
            rep.setAttribute('role', 'menuitem');
            rep.textContent = 'View report' + (d.reportName ? ' — ' + d.reportName : '');
            menu.appendChild(rep);
        }
        const sep = document.createElement('div');
        sep.className = 'kebab-sep';
        menu.appendChild(sep);

        const form = document.createElement('form');
        form.method = 'post';
        form.action = prefix + 'delete_conversation.php';
        form.className = 'convo-delete-form confirm-form-typed';
        form.dataset.confirmTitle = 'Delete this conversation?';
        form.dataset.confirmMessage = 'This deletes the entire conversation and every message in it, for both people. This cannot be undone. Type yes to confirm.';
        form.dataset.confirmLabel = 'Delete';
        form.dataset.confirmRequiredText = 'yes';
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'convo_id';
        hidden.value = d.convo;
        const del = document.createElement('button');
        del.type = 'submit';
        del.className = 'kebab-item kebab-danger';
        del.setAttribute('role', 'menuitem');
        del.textContent = 'Delete conversation';
        form.append(hidden, del);
        menu.appendChild(form);

        document.body.appendChild(menu);
        // Under the button, right edges lined up; flipped above it / pulled in if it would leave the screen.
        const r = btn.getBoundingClientRect();
        const w = menu.offsetWidth, h = menu.offsetHeight;
        let left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
        let top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        btn.setAttribute('aria-expanded', 'true');
        this.menu = menu;
        this.btn = btn;
    },
};
document.addEventListener('click', (e) => {
    const btn = e.target.closest && e.target.closest('.convo-menu-btn');
    if (btn) {
        e.preventDefault();
        e.stopPropagation();
        if (ConvoMenu.btn === btn) ConvoMenu.close(); else ConvoMenu.open(btn);
        return;
    }
    // Any click elsewhere closes it (a click on one of its own items runs first, then closes it).
    // (The delete button is the exception: its form must stay in the page until the typed confirmation is done.)
    if (ConvoMenu.menu && !(e.target.closest && e.target.closest('.kebab-menu form button'))) setTimeout(() => ConvoMenu.close(), 0);
}, true);
const confirmOpen = () => { const o = document.getElementById('modal-overlay'); return !!o && !o.hidden; };
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !confirmOpen()) ConvoMenu.close(); });
window.addEventListener('resize', () => { if (!confirmOpen()) ConvoMenu.close(); });
document.addEventListener('scroll', () => { if (!confirmOpen()) ConvoMenu.close(); }, true);

// ---------------------------------------------------------
// Open the stream. Registered last so every Live.on() above is in place
// before the first event can arrive. EventSource reconnects on its own if
// the connection drops or the server recycles it (Last-Event-ID keeps it
// from re-sending messages already delivered).
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    // Only logged-in pages have the bell / list / thread to update.
    const chatWindow = document.getElementById('chat-window');
    const convoList = document.getElementById('convo-list');
    if (!document.getElementById('notif-list') && !chatWindow && !convoList) return;
    if (typeof EventSource === 'undefined') return;

    const prefix = document.body.dataset.prefix || '';
    const params = new URLSearchParams();
    if (chatWindow) {
        params.set('convo', chatWindow.dataset.convo);
        params.set('after', chatWindow.dataset.lastId || '0');
        params.set('since_deleted', chatWindow.dataset.sinceDeleted || '0');
    }
    if (convoList) params.set('list', '1');
    if (document.body.dataset.watch) params.set('watch', document.body.dataset.watch);   // reports this page shows: tell me when they change

    const es = new EventSource(`${prefix}ajax/stream.php?${params}`);
    ['notifications', 'convolist', 'messages', 'deleted', 'gone', 'toasts', 'reports', 'presence', 'signedout'].forEach((name) => {
        es.addEventListener(name, (e) => {
            try { Live.emit(name, JSON.parse(e.data)); }
            catch (err) { console.error('Bad stream event:', name, err); }
        });
    });
    // The server tells this page which live connection it is (it is recycled about every minute, so
    // keep the latest). Needed for the "I'm leaving" beacon below.
    let conn = '';
    es.addEventListener('hello', (e) => { try { conn = JSON.parse(e.data).conn || ''; } catch (err) { /* ignore */ } });
    es.addEventListener('problem', (e) => console.error('Live stream server error:', e.data));
    es.onerror = () => {
        console.warn('Live stream connection issue (readyState ' + es.readyState + '); the browser will retry unless this is a permanent failure.');
        // A refused stream (401: the login ended while this page sat open) never retries. Ask the server
        // (an authenticated endpoint) and, if the login really is gone, go to the login screen.
        if (es.readyState === EventSource.CLOSED) {
            fetch(prefix + 'ajax/report_state.php?id=0', { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => { if (r.status === 401) window.location.href = prefix + 'login.php'; })
                .catch(() => {});
        }
    };
    // If nothing at all arrives, the stream is being buffered somewhere.
    let gotAny = false;
    ['notifications', 'convolist', 'messages'].forEach((n) => es.addEventListener(n, () => { gotAny = true; }));
    setTimeout(() => { if (!gotAny) console.warn('Live stream is open but silent after 8s. Open /ajax/stream_check.php to test for buffering.'); }, 8000);

    // Free the server worker right away when leaving the page — and tell the server this page is gone
    // (navigator.sendBeacon survives the page closing). That is what makes "closing the tab" take effect
    // at once: the person shows offline within seconds, and if no other page of this login is open the
    // server ends the login after a short grace (see is_logged_in() in includes/functions.php). A page
    // that is only being frozen for the back/forward cache (e.persisted) is not leaving. The server-side
    // timeout stays as the safety net if this never arrives. This is a signal only: the server decides.
    window.addEventListener('pagehide', (e) => {
        es.close();
        if (!e.persisted && conn && navigator.sendBeacon) {
            const fd = new FormData();
            fd.append('conn', conn);
            navigator.sendBeacon(prefix + 'ajax/leave.php', fd);
        }
    });
});

// ---------------------------------------------------------
// Report table toolbars: All / Lost / Found buttons set the hidden type select
// (the table filter listens to its "change"), and follow it when "Clear filters" resets it.
// ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.filter-seg[data-for]').forEach((seg) => {
        const toolbar = seg.closest('.table-toolbar') || document;
        const select = toolbar.querySelector('select.report-filter[data-filter="' + seg.dataset.for + '"]');
        if (!select) return;
        const btns = Array.from(seg.querySelectorAll('.seg-btn'));
        const paint = () => btns.forEach((b) => {
            const on = (b.dataset.value || '') === select.value;
            b.classList.toggle('selected', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        btns.forEach((b) => b.addEventListener('click', () => {
            if (select.value === (b.dataset.value || '')) return;
            select.value = b.dataset.value || '';
            paint();
            select.dispatchEvent(new Event('change'));
        }));
        const reset = toolbar.querySelector('#filters-reset');
        if (reset) reset.addEventListener('click', () => setTimeout(paint, 0));
        paint();
    });
});

/* v7: guests who open a report get a "log in first" pop-up instead of the report. */
document.addEventListener('click', function (e) {
    if (window.IS_LOGGED_IN) return;
    const card = e.target.closest('a.item-card, a[href*="item.php"]');
    if (!card) return;
    e.preventDefault(); e.stopImmediatePropagation();
    const pre = window.SITE_PREFIX || '';
    let o = document.getElementById('guest-login-overlay');
    if (!o) {
        o = document.createElement('div');
        o.id = 'guest-login-overlay';
        o.innerHTML = '<div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="guest-login-title">'
            + '<h3 id="guest-login-title">Log in to view this report</h3>'
            + '<p class="modal-message">You need an account to see report details and contact the owner.</p>'
            + '<div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button>'
            + '<a class="btn btn-secondary" href="' + pre + 'register.php">Register</a>'
            + '<a class="btn" href="' + pre + 'login.php">Log in</a></div></div>';
        o.addEventListener('click', function (ev) { if (ev.target === o || ev.target.closest('[data-close]')) o.hidden = true; });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') o.hidden = true; });
        document.body.appendChild(o);
    }
    o.hidden = false;
    const login = o.querySelector('a[href$="login.php"]'); if (login) login.focus();
}, true);

/* v7: Manage Reports — switching Active/Deleted keeps the search, type, status and date filters. */
(function () {
    const KEY = 'rf-state:' + location.pathname;
    const fields = () => Array.from(document.querySelectorAll('.report-filters input, .report-filters select'));
    const id = (el, i) => el.id || el.dataset.filter || el.name || ('f' + i);
    document.addEventListener('click', function (e) {
        const link = e.target.closest('.view-seg a.seg-btn');
        if (!link) return;
        const st = {};
        fields().forEach((el, i) => { st[id(el, i)] = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value; });
        try { sessionStorage.setItem(KEY, JSON.stringify(st)); } catch (_) {}
    }, true);
    window.addEventListener('load', function () {
        let st; try { st = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY); } catch (_) {}
        if (!st) return;
        setTimeout(function () {
            fields().forEach((el, i) => {
                const k = id(el, i); if (!(k in st)) return;
                if (el.type === 'checkbox' || el.type === 'radio') el.checked = st[k]; else el.value = st[k];
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
            // keep the All/Lost/Found buttons in step with the restored hidden select
            document.querySelectorAll('.filter-seg').forEach(seg => {
                const sel = document.querySelector('.report-filters select.report-filter[data-filter="' + seg.dataset.for + '"]');
                if (!sel) return;
                seg.querySelectorAll('.seg-btn').forEach(b => {
                    const on = b.dataset.value === sel.value;
                    b.classList.toggle('selected', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
                });
            });
        }, 0);
    });
})();

/* v9: sliding highlight behind the selected button of every segmented control */
(function () {
    function place(seg, instant) {
        let ind = seg.querySelector(':scope > .seg-indicator');
        if (!ind) { ind = document.createElement('span'); ind.className = 'seg-indicator'; seg.prepend(ind); seg.classList.add('has-ind'); }
        const sel = seg.querySelector('.seg-btn.selected');
        if (!sel) { ind.style.width = '0'; return; }
        if (instant) seg.classList.add('no-anim');
        ind.style.width = sel.offsetWidth + 'px';
        ind.style.transform = 'translateX(' + sel.offsetLeft + 'px)';
        if (instant) requestAnimationFrame(() => requestAnimationFrame(() => seg.classList.remove('no-anim')));
    }
    function init() {
        document.querySelectorAll('.segmented').forEach(seg => {
            place(seg, true);
            new MutationObserver(() => place(seg)).observe(seg, { subtree: true, attributes: true, attributeFilter: ['class'] });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    window.addEventListener('load', () => document.querySelectorAll('.segmented').forEach(s => place(s, true)));
    window.addEventListener('resize', () => document.querySelectorAll('.segmented').forEach(s => place(s, true)));
})();
