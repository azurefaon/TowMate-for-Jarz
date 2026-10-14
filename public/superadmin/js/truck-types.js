/* Owner Truck Types & Rates: server-side filters, row menus, and accessible modals. */
(function () {
    'use strict';

    const page = document.getElementById('truckTypesPage');
    if (!page) return;

    const filters = document.getElementById('truckTypeFilters');
    const searchInput = filters.querySelector('input[name="search"]');
    const statusSelect = filters.querySelector('select[name="status"]');

    const formModal = document.getElementById('truckTypeModal');
    const form = document.getElementById('truckTypeForm');
    const confirmModal = document.getElementById('truckTypeConfirm');
    const confirmForm = document.getElementById('truckTypeConfirmForm');
    const blockedModal = document.getElementById('truckTypeBlocked');

    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    const icons = () => {
        if (window.lucide) window.lucide.createIcons();
    };

    const urlFor = (key, id) => page.dataset[key].replace('__ID__', id);

    /* ---------- Results (search, status, pagination) ---------- */

    let request = 0;

    async function loadResults(url, push) {
        const card = document.getElementById('truckTypeResults');
        const ticket = ++request;
        card.classList.add('is-loading');

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (ticket !== request) return;

            const next = new DOMParser().parseFromString(html, 'text/html').getElementById('truckTypeResults');
            if (!next) throw new Error('missing results');

            card.replaceWith(next);
            icons();
            history[push ? 'pushState' : 'replaceState']({ truckTypes: true }, '', response.url || url);
        } catch (error) {
            window.location.href = url;
        }
    }

    function filterUrl() {
        const url = new URL(page.dataset.indexUrl, window.location.origin);
        const search = searchInput.value.trim();
        if (search) url.searchParams.set('search', search);
        if (statusSelect.value) url.searchParams.set('status', statusSelect.value);
        return url.toString();
    }

    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadResults(filterUrl(), false), 300);
    });

    statusSelect.addEventListener('change', () => loadResults(filterUrl(), false));

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
        closeMenus();
        if (openModal && openModal !== modal) hideModal(false);

        returnFocus = opener || document.activeElement;
        openModal = modal;
        modal.hidden = false;
        document.body.classList.add('tt-modal-open');
        icons();

        const target = focusTarget || modal.querySelector('.tt-dialog ' + FOCUSABLE.split(',').join(', .tt-dialog '));
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
        returnFocus = null;
    }

    function trapFocus(event) {
        const items = [...openModal.querySelectorAll('.tt-dialog ' + FOCUSABLE.split(',').join(', .tt-dialog '))]
            .filter((el) => el.offsetParent !== null);
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

    /* ---------- Add / edit form ---------- */

    function clearFormErrors() {
        form.querySelectorAll('.has-error').forEach((field) => field.classList.remove('has-error'));
        form.querySelectorAll('.tt-error').forEach((error) => error.remove());
        form.querySelectorAll('[aria-invalid]').forEach((input) => {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        });
        form.querySelector('fieldset').setAttribute('aria-describedby', 'ttClassHelp');
        form.querySelector('[data-form-alert]').hidden = true;
    }

    function setClassOptional(optional) {
        form.querySelector('[data-class-req]').hidden = optional;
        form.querySelector('[data-class-optional]').hidden = !optional;
        form.querySelector('[data-class-note]').hidden = !optional;
    }

    const formatAmount = (value) => {
        if (value === '' || value === undefined || value === null) return '';
        const number = Number(value);
        return Number.isFinite(number) ? number.toFixed(2) : value;
    };

    function openForm(mode, opener) {
        clearFormErrors();
        form.reset();

        const editing = mode === 'edit';
        const data = editing ? opener.dataset : {};

        form.action = editing ? urlFor('updateUrl', data.id) : page.dataset.storeUrl;
        form.querySelector('input[name="_method"]').disabled = !editing;
        form.querySelector('input[name="_modal"]').value = editing ? 'edit' : 'add';
        form.querySelector('input[name="_edit_id"]').value = editing ? data.id : '';
        document.getElementById('truckTypeModalTitle').textContent = editing ? 'Edit truck type' : 'Add truck type';

        // form.reset() restores server-rendered old() values, so set every field explicitly.
        form.elements.name.value = editing ? data.name : '';
        form.elements.description.value = editing ? data.description : '';
        form.elements.base_rate.value = editing ? formatAmount(data.base) : '';
        form.elements.per_km_rate.value = editing ? formatAmount(data.km) : '';
        form.elements.max_tonnage.value = editing ? data.weight : '';
        form.querySelectorAll('input[name="class"]').forEach((radio) => {
            radio.checked = editing && radio.value === data.class;
        });
        setClassOptional(editing && !data.class);

        showModal(formModal, opener, form.elements.name);
    }

    // Typing in a field clears its own error; the banner stays until the next save.
    form.addEventListener('input', (event) => {
        const field = event.target.closest('.tt-field');
        if (!field || !field.classList.contains('has-error')) return;
        field.classList.remove('has-error');
        field.querySelector('.tt-error')?.remove();
        field.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    });

    /* ---------- Row menus ---------- */

    function closeMenus(except) {
        document.querySelectorAll('[data-menu-toggle][aria-expanded="true"]').forEach((toggle) => {
            if (toggle === except) return;
            toggle.setAttribute('aria-expanded', 'false');
            const menu = document.getElementById(toggle.getAttribute('aria-controls'));
            if (menu) menu.hidden = true;
        });
    }

    function openMenu(toggle, focusLast) {
        closeMenus(toggle);
        const menu = document.getElementById(toggle.getAttribute('aria-controls'));
        toggle.setAttribute('aria-expanded', 'true');
        menu.hidden = false;

        menu.classList.remove('is-up');
        const rect = menu.getBoundingClientRect();
        if (rect.bottom > window.innerHeight - 8) menu.classList.add('is-up');

        const items = menu.querySelectorAll('[role="menuitem"]');
        items[focusLast ? items.length - 1 : 0]?.focus();
    }

    function menuKeys(event) {
        const menu = event.target.closest('[role="menu"]');
        if (!menu) return false;

        const items = [...menu.querySelectorAll('[role="menuitem"]')];
        const index = items.indexOf(document.activeElement);
        const toggle = document.querySelector(`[aria-controls="${menu.id}"]`);

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            items[(index + step + items.length) % items.length].focus();
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            items[event.key === 'Home' ? 0 : items.length - 1].focus();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeMenus();
            toggle?.focus();
        } else if (event.key === 'Tab') {
            closeMenus();
        } else {
            return false;
        }
        return true;
    }

    /* ---------- Enable / disable / delete ---------- */

    const ACTIONS = {
        disable: {
            title: (name) => `Disable ${name}?`,
            text: 'It won\'t be offered for new trucks, vehicle types, or bookings. You can enable it again at any time.',
            button: 'Disable',
            danger: false,
            method: 'PATCH',
            url: 'toggleUrl',
            blockedTitle: (name) => `${name} can't be disabled`,
            blockedText: 'Move or remove these first, or wait for the bookings to finish.',
        },
        enable: {
            title: (name) => `Enable ${name}?`,
            text: 'It will be offered again for new trucks, vehicle types, and bookings.',
            button: 'Enable',
            danger: false,
            method: 'PATCH',
            url: 'toggleUrl',
        },
        delete: {
            title: (name) => `Delete ${name}?`,
            text: 'This permanently removes the truck type and its rates. This can\'t be undone.',
            button: 'Delete',
            danger: true,
            method: 'DELETE',
            url: 'destroyUrl',
            blockedTitle: (name) => `${name} can't be deleted`,
            blockedText: 'Truck types that have been used keep their history. You can disable it instead.',
        },
    };

    function runAction(item) {
        const action = ACTIONS[item.dataset.action];
        const name = item.dataset.name;
        const toggle = document.querySelector(`[aria-controls="${item.closest('[role="menu"]').id}"]`);

        if (item.getAttribute('aria-disabled') === 'true') {
            const why = document.getElementById(item.getAttribute('aria-describedby'));
            document.getElementById('ttBlockedTitle').textContent = action.blockedTitle(name);
            // The server's guidance names only the steps that apply to this truck type.
            const guidance = why?.dataset.guidance || action.blockedText;
            document.getElementById('ttBlockedText').textContent = `${why ? why.textContent.trim() : ''} ${guidance}`.trim();
            showModal(blockedModal, toggle);
            return;
        }

        document.getElementById('ttConfirmTitle').textContent = action.title(name);
        document.getElementById('ttConfirmText').textContent = action.text;
        confirmForm.action = urlFor(action.url, item.dataset.id);
        confirmForm.querySelector('input[name="_method"]').value = action.method;

        const submit = confirmForm.querySelector('[data-confirm-submit]');
        submit.textContent = action.button;
        submit.classList.toggle('tt-btn--danger', action.danger);
        submit.classList.toggle('tt-btn--primary', !action.danger);

        showModal(confirmModal, toggle, confirmForm.querySelector('[data-close-modal]'));
    }

    confirmForm.addEventListener('submit', () => {
        confirmForm.querySelector('[data-confirm-submit]').disabled = true;
    });

    form.addEventListener('submit', () => {
        form.querySelector('button[type="submit"]').disabled = true;
    });

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
            statusSelect.value = '';
            loadResults(page.dataset.indexUrl, false);
            return;
        }

        const add = target.closest('[data-open-add]');
        if (add) {
            openForm('add', add);
            return;
        }

        const edit = target.closest('[data-edit]');
        if (edit) {
            openForm('edit', edit);
            return;
        }

        const menuToggle = target.closest('[data-menu-toggle]');
        if (menuToggle) {
            if (menuToggle.getAttribute('aria-expanded') === 'true') closeMenus();
            else openMenu(menuToggle);
            return;
        }

        const item = target.closest('[role="menuitem"][data-action]');
        if (item) {
            runAction(item);
            return;
        }

        if (target.closest('[data-close-modal]')) {
            hideModal();
            return;
        }

        if (!target.closest('[role="menu"]')) closeMenus();
    });

    document.addEventListener('keydown', (event) => {
        if (openModal) {
            if (event.key === 'Escape') {
                event.preventDefault();
                hideModal();
            } else if (event.key === 'Tab') {
                trapFocus(event);
            }
            return;
        }

        if (menuKeys(event)) return;

        const toggle = event.target.closest?.('[data-menu-toggle]');
        if (toggle && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            openMenu(toggle, event.key === 'ArrowUp');
        }
    });

    /* ---------- Reopen the form after a failed save ---------- */

    if (page.dataset.reopen) {
        const editId = form.querySelector('input[name="_edit_id"]').value;
        const opener = (editId && document.querySelector(`[data-edit][data-id="${editId}"]`))
            || page.querySelector('[data-open-add]');
        const firstInvalid = form.querySelector('.has-error input:not([type="hidden"])');
        showModal(formModal, opener, firstInvalid || form.elements.name);
    }
})();
