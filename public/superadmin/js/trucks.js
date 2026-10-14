/* Owner Trucks: server-side filters, row menus, and accessible add/edit/view/confirm dialogs. */
(function () {
    'use strict';

    const page = document.getElementById('trucksPage');
    if (!page) return;

    const filters = document.getElementById('truckFilters');
    const searchInput = filters.querySelector('input[name="search"]');
    const statusSelect = filters.querySelector('select[name="status"]');

    const formModal = document.getElementById('truckModal');
    const form = document.getElementById('truckForm');
    const typeSelect = form.querySelector('select[name="truck_type_id"]');
    const viewModal = document.getElementById('truckView');
    const confirmModal = document.getElementById('truckConfirm');
    const confirmForm = document.getElementById('truckConfirmForm');
    const blockedModal = document.getElementById('truckBlocked');
    const serviceForm = document.getElementById('truckServiceForm');

    const FOCUSABLE = ['a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])']
        .map((selector) => '.tt-dialog ' + selector)
        .join(', ');

    const icons = () => {
        if (window.lucide) window.lucide.createIcons();
    };

    const urlFor = (key, id) => page.dataset[key].replace('__ID__', id);

    /* ---------- Results (search, status, pagination) ---------- */

    let request = 0;

    async function loadResults(url, push) {
        const card = document.getElementById('truckResults');
        const ticket = ++request;
        card.classList.add('is-loading');
        card.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (ticket !== request) return;

            const next = new DOMParser().parseFromString(html, 'text/html').getElementById('truckResults');
            if (!next) throw new Error('missing results');

            card.replaceWith(next);
            refreshStatusCounts(next);
            icons();
            history[push ? 'pushState' : 'replaceState']({ trucks: true }, '', response.url || url);
        } catch (error) {
            window.location.href = url;
        }
    }

    // Counts depend on the search, so the status menu is rebuilt from each response.
    function refreshStatusCounts(results) {
        const template = results.querySelector('template[data-status-options]');
        if (!template) return;
        const value = statusSelect.value;
        statusSelect.replaceChildren(template.content.cloneNode(true));
        statusSelect.value = value;
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
        returnFocus = null;
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

    /* ---------- Add / edit form ---------- */

    function clearFormErrors() {
        form.querySelectorAll('.has-error').forEach((field) => field.classList.remove('has-error'));
        form.querySelectorAll('.tt-error').forEach((error) => error.remove());
        form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
        form.elements.namedItem('plate_number').setAttribute('aria-describedby', 'tkPlateHelp');
        typeSelect.removeAttribute('aria-describedby');
        form.querySelector('[data-form-alert]').hidden = true;
    }

    function openForm(mode, opener) {
        clearFormErrors();
        form.reset();
        typeSelect.querySelectorAll('option[data-extra]').forEach((option) => option.remove());

        const editing = mode === 'edit';
        const data = editing ? opener.dataset : {};

        form.action = editing ? urlFor('updateUrl', data.id) : page.dataset.storeUrl;
        form.querySelector('input[name="_method"]').disabled = !editing;
        form.querySelector('input[name="_modal"]').value = editing ? 'edit' : 'add';
        form.querySelector('input[name="_edit_id"]').value = editing ? data.id : '';
        document.getElementById('truckModalTitle').textContent = editing ? 'Edit truck' : 'Add truck';
        document.getElementById('truckModalDesc').textContent = editing ? data.name : 'The unit name is assigned automatically.';
        form.querySelector('[data-unit-name-field]').hidden = editing;
        form.querySelector('[data-submit-label]').textContent = editing ? 'Save' : 'Add';

        // form.reset() restores server-rendered old() values, so set every field explicitly.
        form.elements.namedItem('plate_number').value = editing ? data.plate : '';

        // Keep a truck's current type selectable even if that type has since been disabled.
        if (editing && data.typeId && !typeSelect.querySelector(`option[value="${data.typeId}"]`)) {
            const option = new Option(data.typeLabel || 'Current truck type', data.typeId);
            option.dataset.extra = '';
            typeSelect.add(option);
        }
        typeSelect.value = editing ? (data.typeId || '') : '';

        showModal(formModal, opener, form.elements.namedItem('plate_number'));
    }

    // Typing in a field clears its own error; the banner stays until the next save.
    form.addEventListener('input', (event) => {
        const field = event.target.closest('.tt-field');
        if (!field || !field.classList.contains('has-error')) return;
        field.classList.remove('has-error');
        field.querySelector('.tt-error')?.remove();
        field.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    });

    form.addEventListener('submit', () => {
        form.querySelector('button[type="submit"]').disabled = true;
    });

    /* ---------- View ---------- */

    let viewing = null;

    function openView(trigger) {
        const template = document.getElementById('tkView' + trigger.dataset.view);
        if (!template) return;

        viewing = trigger.dataset.view;
        document.getElementById('tkViewTitle').textContent = template.dataset.title;
        document.getElementById('tkViewSubtitle').textContent = template.dataset.subtitle;
        viewModal.querySelector('[data-view-body]').replaceChildren(template.content.cloneNode(true));

        showModal(viewModal, trigger, viewModal.querySelector('.tt-dialog-close'));
    }

    viewModal.querySelector('[data-view-edit]').addEventListener('click', () => {
        const edit = document.querySelector(`[data-edit][data-id="${viewing}"]`);
        const opener = returnFocus;
        hideModal(false);
        if (edit) {
            openForm('edit', edit);
            returnFocus = opener;
        }
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
        if (menu.getBoundingClientRect().bottom > window.innerHeight - 8) menu.classList.add('is-up');

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

    /* ---------- Maintenance / archive ---------- */

    const ACTIONS = {
        maintenance: {
            title: (name) => `Move ${name} to maintenance?`,
            text: 'It can\'t be dispatched until you return it to service.',
            button: 'Move to maintenance',
            url: 'toggleUrl',
            blockedTitle: (name) => `${name} can't go to maintenance`,
        },
        archive: {
            title: (name) => `Archive ${name}?`,
            text: 'It leaves the active fleet and can\'t be dispatched. You can restore it from Archived trucks.',
            button: 'Archive',
            url: 'archiveUrl',
            blockedTitle: (name) => `${name} can't be archived`,
        },
    };

    function runAction(item) {
        const name = item.dataset.name;
        const toggle = document.querySelector(`[aria-controls="${item.closest('[role="menu"]').id}"]`);

        if (item.dataset.action === 'service') {
            closeMenus();
            serviceForm.action = urlFor('toggleUrl', item.dataset.id);
            serviceForm.submit();
            return;
        }

        const action = ACTIONS[item.dataset.action];

        if (item.getAttribute('aria-disabled') === 'true') {
            const why = document.getElementById(item.getAttribute('aria-describedby'));
            document.getElementById('tkBlockedTitle').textContent = action.blockedTitle(name);
            document.getElementById('tkBlockedText').textContent = why ? why.textContent.trim() : '';
            showModal(blockedModal, toggle);
            return;
        }

        // Mention the job the truck is tied to right now, as the status column shows it.
        const current = item.dataset.current ? ` Right now: ${item.dataset.current}.` : '';
        document.getElementById('tkConfirmTitle').textContent = action.title(name);
        document.getElementById('tkConfirmText').textContent = action.text + current;
        confirmForm.action = urlFor(action.url, item.dataset.id);
        confirmForm.querySelector('[data-confirm-submit]').textContent = action.button;

        showModal(confirmModal, toggle, confirmForm.querySelector('[data-close-modal]'));
    }

    confirmForm.addEventListener('submit', () => {
        confirmForm.querySelector('[data-confirm-submit]').disabled = true;
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

        const view = target.closest('[data-view]');
        if (view) {
            openView(view);
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
        const firstInvalid = form.querySelector('.has-error input:not([type="hidden"]), .has-error select');
        showModal(formModal, opener, firstInvalid || form.elements.namedItem('plate_number'));
    }
})();
