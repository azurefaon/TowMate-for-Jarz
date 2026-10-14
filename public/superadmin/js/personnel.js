/* Owner Personnel: server-side filters/pagination and accessible add/edit dialogs. */
(function () {
    'use strict';

    const page = document.getElementById('personnelPage');
    if (!page) return;

    const filters = document.getElementById('personnelFilters');
    const searchInput = filters.querySelector('input[name="search"]');
    const selects = [...filters.querySelectorAll('select')];

    const recordModal = document.getElementById('recordModal');
    const recordForm = document.getElementById('recordForm');
    const recordStatus = recordModal.querySelector('[data-record-status]');
    const accountModal = document.getElementById('accountModal');
    const accountForm = document.getElementById('accountForm');
    const confirmModal = document.getElementById('personnelConfirm');
    const confirmForm = document.getElementById('personnelConfirmForm');

    const FOCUSABLE = ['a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])']
        .map((selector) => '.tt-dialog ' + selector)
        .join(', ');

    const icons = () => {
        if (window.lucide) window.lucide.createIcons();
    };

    /* ---------- Results (search, filters, pagination) ---------- */

    let request = 0;

    async function loadResults(url, push) {
        const card = document.getElementById('personnelResults');
        const ticket = ++request;
        card.classList.add('is-loading');
        card.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (ticket !== request) return;

            const next = new DOMParser().parseFromString(html, 'text/html').getElementById('personnelResults');
            if (!next) throw new Error('missing results');

            card.replaceWith(next);
            icons();
            history[push ? 'pushState' : 'replaceState']({ personnel: true }, '', response.url || url);
        } catch (error) {
            window.location.href = url;
        }
    }

    function filterUrl() {
        const url = new URL(page.dataset.indexUrl, window.location.origin);
        const search = searchInput.value.trim();
        if (search) url.searchParams.set('search', search);
        selects.forEach((select) => {
            if (select.value) url.searchParams.set(select.name, select.value);
        });
        return url.toString();
    }

    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadResults(filterUrl(), false), 300);
    });

    selects.forEach((select) => select.addEventListener('change', () => loadResults(filterUrl(), false)));

    filters.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(searchTimer);
        loadResults(filterUrl(), false);
    });

    window.addEventListener('popstate', () => window.location.reload());

    /* ---------- Modal manager ---------- */

    let openModal = null;
    let returnFocus = null;

    function showModal(modal, opener, focusTarget) {
        if (openModal && openModal !== modal) hideModal(false);

        if (opener !== undefined) returnFocus = opener || document.activeElement;
        openModal = modal;
        modal.hidden = false;
        document.body.classList.add('tt-modal-open');
        icons();

        const target = focusTarget || modal.querySelector(FOCUSABLE);
        if (target) target.focus();
    }

    function hideModal(restore = true) {
        if (!openModal) return;
        openModal.hidden = true;
        openModal = null;
        document.body.classList.remove('tt-modal-open');

        if (restore && returnFocus && document.body.contains(returnFocus)) {
            returnFocus.focus();
        } else if (restore) {
            page.querySelector('[data-open-add]')?.focus();
        }
        if (restore) returnFocus = null;
    }

    function trapFocus(event) {
        const items = [...openModal.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
        if (!items.length) return;

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function clearErrors(form) {
        form.querySelectorAll('.has-error').forEach((field) => field.classList.remove('has-error'));
        form.querySelectorAll('.tt-error').forEach((error) => error.remove());
        form.querySelectorAll('[aria-invalid]').forEach((input) => {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        });
        const alert = form.querySelector('[data-form-alert]');
        if (alert) alert.hidden = true;
    }

    // Typing in a field clears its own error; the banner stays until the next save.
    [recordForm, accountForm].forEach((form) => {
        form.addEventListener('input', (event) => {
            const field = event.target.closest('.tt-field');
            if (!field || !field.classList.contains('has-error')) return;
            field.classList.remove('has-error');
            field.querySelector('.tt-error')?.remove();
            field.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
        });
        form.addEventListener('submit', () => {
            document.querySelector(`[form="${form.id}"][type="submit"]`).disabled = true;
        });
    });

    /* ---------- Records (no app account) ---------- */

    function setRecordStatus(data) {
        recordStatus.hidden = !data;
        if (!data) return;

        const active = data.status === 'Active';
        const badge = recordStatus.querySelector('[data-status-badge]');
        badge.textContent = data.status;
        badge.className = 'tt-badge ' + (active ? 'is-active' : 'is-inactive');
        recordStatus.querySelector('[data-status-text]').textContent = active
            ? 'Dispatchers can place this person on a unit.'
            : "Dispatchers can't place this person on a unit.";

        const button = recordStatus.querySelector('[data-status-action]');
        button.textContent = active ? 'Deactivate' : 'Activate';
        button.dataset.active = active ? '1' : '';
        button.dataset.name = data.name;
        button.dataset.url = data.toggleUrl;
    }

    function openRecord(opener) {
        const editing = !!opener?.dataset.id;
        const data = editing ? opener.dataset : {};

        clearErrors(recordForm);
        recordForm.reset();

        recordForm.action = editing ? data.actionUrl : page.dataset.storeUrl;
        recordForm.querySelector('input[name="_method"]').disabled = !editing;
        recordForm.querySelector('input[name="_modal"]').value = editing ? 'record' : 'add';
        recordForm.querySelector('input[name="_edit_id"]').value = editing ? data.id : '';
        document.getElementById('ppRecordTitle').textContent = editing ? `Edit ${data.name}` : 'Add driver or crew member';
        recordModal.querySelector('[data-submit-label]').textContent = editing ? 'Save' : 'Add';

        // form.reset() restores server-rendered old() values, so set every field explicitly.
        const set = (name, value) => { recordForm.elements.namedItem(name).value = value || ''; };
        set('first_name', data.firstName);
        set('middle_name', data.middleName);
        set('last_name', data.lastName);
        set('role', data.role);
        set('home_unit_id', data.homeUnitId);

        setRecordStatus(editing ? data : null);
        showModal(recordModal, opener, recordForm.elements.namedItem('first_name'));
    }

    recordStatus.querySelector('[data-status-action]').addEventListener('click', (event) => {
        const button = event.currentTarget;
        const active = button.dataset.active === '1';

        document.getElementById('ppConfirmTitle').textContent = `${active ? 'Deactivate' : 'Activate'} ${button.dataset.name}?`;
        document.getElementById('ppConfirmText').textContent = active
            ? "Dispatchers won't be able to place them on a unit. It doesn't remove them from a unit they're on now."
            : 'Dispatchers will be able to place them on a unit again.';
        confirmForm.action = button.dataset.url;
        confirmForm.querySelector('[data-confirm-submit]').textContent = active ? 'Deactivate' : 'Activate';

        // The edit dialog closes; Cancel returns focus to the row's Edit button.
        showModal(confirmModal, undefined, confirmForm.querySelector('[data-close-modal]'));
    });

    confirmForm.addEventListener('submit', () => {
        confirmForm.querySelector('[data-confirm-submit]').disabled = true;
    });

    /* ---------- People with app accounts ---------- */

    function openAccount(opener) {
        const data = opener.dataset;
        const leader = data.teamLeader === '1';

        clearErrors(accountForm);
        accountForm.reset();
        accountForm.action = data.actionUrl;
        accountForm.querySelector('input[name="_edit_id"]').value = data.id;
        document.getElementById('ppAccountTitle').textContent = `Edit ${data.name}`;
        accountModal.querySelector('[data-account-role]').textContent = data.roleLabel;
        accountModal.querySelector('[data-account-reference]').textContent = data.reference || 'Not set';
        accountModal.querySelector('[data-account-status]').textContent = data.status;
        accountModal.querySelector('[data-leader-note]').hidden = !leader;
        accountModal.querySelector('[data-driver-note]').hidden = leader;

        // A leader on a truck other than their home unit was put there by a
        // dispatcher; only that truck can become their home until they return.
        const elsewhere = accountModal.querySelector('[data-elsewhere-note]');
        const onOtherTruck = leader && data.currentUnitId && data.currentUnitId !== data.homeUnitId;
        if (elsewhere) {
            elsewhere.hidden = !onOtherTruck;
            elsewhere.textContent = onOtherTruck
                ? `${data.name} is on ${data.currentUnitName} today, not their home unit. Until a dispatcher returns them in Units & Leaders, their home unit can only be set to ${data.currentUnitName}.`
                : '';
        }
        accountForm.elements.namedItem('home_unit_id').value = data.homeUnitId || '';

        showModal(accountModal, opener, accountForm.elements.namedItem('home_unit_id'));
    }

    /* ---------- Event wiring ---------- */

    document.addEventListener('click', (event) => {
        const target = event.target;

        const pageLink = target.closest('[data-page-link]');
        if (pageLink) {
            event.preventDefault();
            loadResults(pageLink.href, true);
            return;
        }

        if (target.closest('[data-clear-filters]')) {
            event.preventDefault();
            searchInput.value = '';
            selects.forEach((select) => { select.value = ''; });
            loadResults(page.dataset.indexUrl, false);
            return;
        }

        const add = target.closest('[data-open-add]');
        if (add) {
            openRecord(null);
            returnFocus = add;
            return;
        }

        const edit = target.closest('[data-edit]');
        if (edit) {
            if (edit.dataset.type === 'account') openAccount(edit);
            else openRecord(edit);
            return;
        }

        if (target.closest('[data-close-modal]')) hideModal();
    });

    document.addEventListener('keydown', (event) => {
        if (!openModal) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            hideModal();
        } else if (event.key === 'Tab') {
            trapFocus(event);
        }
    });

    /* ---------- Reopen a dialog after a failed save ---------- */

    const reopen = page.dataset.reopen;
    if (reopen) {
        const editButton = page.dataset.reopenId
            ? document.querySelector(`[data-edit][data-type="${reopen === 'account' ? 'account' : 'record'}"][data-id="${page.dataset.reopenId}"]`)
            : null;
        const opener = editButton || page.querySelector('[data-open-add]');

        if (reopen === 'account') {
            showModal(accountModal, opener, accountForm.querySelector('.has-error select') || accountForm.elements.namedItem('home_unit_id'));
        } else {
            setRecordStatus(reopen === 'record' && editButton ? editButton.dataset : null);
            const firstInvalid = recordForm.querySelector('.has-error input, .has-error select');
            showModal(recordModal, opener, firstInvalid || recordForm.elements.namedItem('first_name'));
        }
    }
})();
