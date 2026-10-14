/* Owner Vehicle Types: server-side filters, add/edit dialog, categories, and keyboard reordering. */
(function () {
    'use strict';

    const page = document.getElementById('vehicleTypesPage');
    if (!page) return;

    const filters = document.getElementById('vehicleFilters');
    const searchInput = filters.querySelector('input[name="search"]');
    const selects = [...filters.querySelectorAll('select')];

    const formModal = document.getElementById('vehicleModal');
    const form = document.getElementById('vehicleForm');
    const truckSelect = form.querySelector('select[name="required_truck_type_id"]');
    const manage = formModal.querySelector('[data-edit-only]');
    const categoriesModal = document.getElementById('categoriesModal');
    const confirmModal = document.getElementById('vehicleConfirm');
    const confirmForm = document.getElementById('vehicleConfirmForm');
    const csrf = confirmForm.querySelector('input[name="_token"]').value;

    const FOCUSABLE = ['a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])']
        .map((selector) => '.tt-dialog ' + selector)
        .join(', ');

    const icons = () => {
        if (window.lucide) window.lucide.createIcons();
    };

    const urlFor = (key, id) => page.dataset[key].replace('__ID__', id);

    /* ---------- Results (search, category, status) ---------- */

    let request = 0;

    async function loadResults(url) {
        const results = document.getElementById('vehicleResults');
        const ticket = ++request;
        results.classList.add('is-loading');
        results.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (ticket !== request) return;

            const doc = new DOMParser().parseFromString(html, 'text/html');
            const next = doc.getElementById('vehicleResults');
            if (!next) throw new Error('missing results');

            results.replaceWith(next);
            ordering = null; // unsaved order changes belonged to the replaced cards
            icons();
            history.replaceState({ vehicleTypes: true }, '', response.url || url);
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
        searchTimer = setTimeout(() => loadResults(filterUrl()), 300);
    });

    selects.forEach((select) => select.addEventListener('change', () => loadResults(filterUrl())));

    filters.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(searchTimer);
        loadResults(filterUrl());
    });

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
        const closing = openModal;
        openModal.hidden = true;
        openModal = null;
        document.body.classList.remove('tt-modal-open');

        if (closing === categoriesModal && categoriesChanged) {
            window.location.reload();
            return;
        }

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

    /* ---------- Add / edit ---------- */

    function clearFormErrors() {
        form.querySelectorAll('.has-error').forEach((field) => field.classList.remove('has-error'));
        form.querySelectorAll('.tt-error').forEach((error) => error.remove());
        form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
        form.querySelector('#vtName').removeAttribute('aria-describedby');
        form.querySelector('#vtCategory').removeAttribute('aria-describedby');
        form.querySelector('#vtWeight').removeAttribute('aria-describedby');
        form.querySelector('#vtDescription').setAttribute('aria-describedby', 'vtDescriptionHelp');
        truckSelect.setAttribute('aria-describedby', 'vtTruckHelp');
        form.querySelector('[data-form-alert]').hidden = true;
    }

    // Fills the Status and Delete rows of the edit dialog from the row's Edit button.
    function setManage(data) {
        manage.hidden = !data;
        if (!data) return;

        const active = data.status === 'active';
        const badge = manage.querySelector('[data-status-badge]');
        badge.textContent = active ? 'Active' : 'Inactive';
        badge.className = 'tt-badge ' + (active ? 'is-active' : 'is-inactive');
        manage.querySelector('[data-status-text]').textContent = active
            ? `Shown to customers as number ${data.rank} in ${data.categoryName}${data.truckName ? ` under ${data.truckName}` : ''}.`
            : 'Hidden from customers. Enabling it adds it last in its category.';

        const statusButton = manage.querySelector('[data-status-action]');
        statusButton.textContent = active ? 'Disable' : 'Enable';
        statusButton.dataset.action = active ? 'disable' : 'enable';
        statusButton.dataset.id = data.id;
        statusButton.dataset.name = data.name;

        const bookings = parseInt(data.bookings || '0', 10);
        const deleteButton = manage.querySelector('[data-delete-action]');
        deleteButton.dataset.id = data.id;
        deleteButton.dataset.name = data.name;
        if (bookings > 0) {
            deleteButton.setAttribute('aria-disabled', 'true');
            manage.querySelector('[data-delete-text]').textContent = `Used by ${bookings} ${bookings === 1 ? 'booking' : 'bookings'}, so it can't be deleted. Disable it to hide it from customers.`;
        } else {
            deleteButton.removeAttribute('aria-disabled');
            manage.querySelector('[data-delete-text]').textContent = 'Not possible once bookings use it.';
        }
    }

    function openForm(mode, opener) {
        clearFormErrors();
        form.reset();
        truckSelect.querySelectorAll('option[data-extra]').forEach((option) => option.remove());

        const editing = mode === 'edit';
        const data = editing ? opener.dataset : {};

        form.action = editing ? urlFor('updateUrl', data.id) : page.dataset.storeUrl;
        form.querySelector('input[name="_method"]').disabled = !editing;
        form.querySelector('input[name="_modal"]').value = editing ? 'edit' : 'add';
        form.querySelector('input[name="_edit_id"]').value = editing ? data.id : '';
        document.getElementById('vtModalTitle').textContent = editing ? 'Edit vehicle type' : 'Add vehicle type';
        document.getElementById('vtModalDesc').textContent = editing ? data.name : 'New vehicle types are shown to customers last in their category.';
        formModal.querySelector('[data-submit-label]').textContent = editing ? 'Save' : 'Add';

        // form.reset() restores server-rendered old() values, so set every field explicitly.
        form.elements.namedItem('name').value = editing ? data.name : '';
        form.elements.namedItem('category').value = editing ? data.category : '';
        form.elements.namedItem('weight_kg').value = editing ? data.weight : '';
        form.elements.namedItem('description').value = editing ? data.description : '';

        // Keep the current required truck type selectable even if it was disabled since.
        if (editing && data.truckId && !truckSelect.querySelector(`option[value="${data.truckId}"]`)) {
            const option = new Option(data.truckLabel || 'Current truck type', data.truckId);
            option.dataset.extra = '';
            truckSelect.add(option);
        }
        truckSelect.value = editing ? (data.truckId || '') : '';

        setManage(editing ? data : null);
        showModal(formModal, opener, form.elements.namedItem('name'));
    }

    form.addEventListener('input', (event) => {
        const field = event.target.closest('.tt-field');
        if (!field || !field.classList.contains('has-error')) return;
        field.classList.remove('has-error');
        field.querySelector('.tt-error')?.remove();
        field.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    });

    form.addEventListener('submit', () => {
        formModal.querySelector('[data-submit-label]').disabled = true;
    });

    /* ---------- Enable / disable / delete (from the edit dialog) ---------- */

    const ACTIONS = {
        disable: {
            title: (name) => `Disable ${name}?`,
            text: 'Customers won\'t see it when booking. Existing bookings keep it. Enabling it later adds it last in its category.',
            button: 'Disable', method: 'PATCH', url: 'toggleUrl', danger: false,
        },
        enable: {
            title: (name) => `Enable ${name}?`,
            text: 'Customers will see it again, last in its category. You can change its order afterwards.',
            button: 'Enable', method: 'PATCH', url: 'toggleUrl', danger: false,
        },
        delete: {
            title: (name) => `Delete ${name}?`,
            text: 'This permanently removes the vehicle type. This can\'t be undone.',
            button: 'Delete', method: 'DELETE', url: 'destroyUrl', danger: true,
        },
    };

    function confirmAction(button) {
        const action = ACTIONS[button.dataset.action || 'delete'];

        document.getElementById('vtConfirmTitle').textContent = action.title(button.dataset.name);
        document.getElementById('vtConfirmText').textContent = action.text;
        confirmForm.action = urlFor(action.url, button.dataset.id);
        confirmForm.querySelector('input[name="_method"]').value = action.method;

        const submit = confirmForm.querySelector('[data-confirm-submit]');
        submit.textContent = action.button;
        submit.classList.toggle('tt-btn--danger', action.danger);
        submit.classList.toggle('tt-btn--primary', !action.danger);

        // The edit dialog closes; Cancel returns focus to the row's Edit button.
        showModal(confirmModal, undefined, confirmForm.querySelector('[data-close-modal]'));
    }

    manage.querySelector('[data-status-action]').addEventListener('click', (event) => confirmAction(event.currentTarget));
    manage.querySelector('[data-delete-action]').addEventListener('click', (event) => {
        const button = event.currentTarget;
        if (button.getAttribute('aria-disabled') === 'true') {
            manage.querySelector('[data-delete-text]').focus?.();
            return;
        }
        button.dataset.action = 'delete';
        confirmAction(button);
    });

    confirmForm.addEventListener('submit', () => {
        confirmForm.querySelector('[data-confirm-submit]').disabled = true;
    });

    /* ---------- Categories ---------- */

    let categoriesChanged = false;

    async function sendCategory(url, method, name) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ name }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data?.errors?.name?.[0] || data?.message || 'Couldn\'t save the category. Try again.');
        }
        return data.category;
    }

    function showCategoryError(scope, input, message) {
        const error = scope.querySelector('[data-category-error]');
        error.textContent = message;
        error.hidden = !message;
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
    }

    function setRenaming(item, renaming) {
        const view = item.querySelector('.vt-category-view');
        const renameForm = item.querySelector('[data-category-rename]');
        view.hidden = renaming;
        renameForm.hidden = !renaming;
        const input = renameForm.elements.namedItem('name');
        if (renaming) {
            input.value = item.querySelector('[data-category-name]').textContent;
            showCategoryError(renameForm, input, '');
            input.focus();
            input.select();
        } else {
            view.querySelector('[data-category-edit]').focus();
        }
    }

    categoriesModal.addEventListener('click', (event) => {
        const edit = event.target.closest('[data-category-edit]');
        if (edit) setRenaming(edit.closest('[data-category-id]'), true);

        const cancel = event.target.closest('[data-category-cancel]');
        if (cancel) setRenaming(cancel.closest('[data-category-id]'), false);

        if (event.target.closest('[data-categories-done]')) hideModal();
    });

    categoriesModal.addEventListener('submit', async (event) => {
        event.preventDefault();
        const scope = event.target;
        const input = scope.elements.namedItem('name');
        const name = input.value.trim();
        const submit = scope.querySelector('button[type="submit"]');

        if (!name) {
            showCategoryError(scope, input, 'Enter a category name.');
            input.focus();
            return;
        }

        submit.disabled = true;
        try {
            if (scope.matches('[data-category-rename]')) {
                const item = scope.closest('[data-category-id]');
                const category = await sendCategory(urlFor('categoryUpdateUrl', item.dataset.categoryId), 'PUT', name);
                item.querySelector('[data-category-name]').textContent = category.name;
                item.querySelector('[data-category-edit]').setAttribute('aria-label', `Rename ${category.name}`);
                setRenaming(item, false);
            } else {
                const category = await sendCategory(page.dataset.categoryStoreUrl, 'POST', name);
                const list = categoriesModal.querySelector('[data-category-list]');
                const item = document.createElement('li');
                item.className = 'vt-category-item';
                item.innerHTML = '<div class="vt-category-view"><span class="vt-category-name"></span><span class="vt-card-meta">0 vehicle types · added</span></div>';
                item.querySelector('.vt-category-name').textContent = category.name;
                list.append(item);
                input.value = '';
                input.focus();
            }
            showCategoryError(scope, input, '');
            categoriesChanged = true;
        } catch (error) {
            showCategoryError(scope, input, error.message);
            input.focus();
        } finally {
            submit.disabled = false;
        }
    });

    /* ---------- Keyboard reordering ---------- */

    let ordering = null; // { card, snapshot }

    const activeRows = (tbody) => [...tbody.querySelectorAll('.vt-row[data-status="active"]')];

    function refreshRanks(card) {
        card.querySelectorAll('[data-category-group]').forEach((tbody) => {
            const rows = activeRows(tbody);
            rows.forEach((row, index) => {
                row.querySelector('[data-rank]').textContent = index + 1;
                const up = row.querySelector('[data-move="up"]');
                const down = row.querySelector('[data-move="down"]');
                if (up) up.disabled = index === 0;
                if (down) down.disabled = index === rows.length - 1;
            });
        });
    }

    function feedback(card, message, isError = false) {
        const el = card.querySelector('[data-order-feedback]');
        el.textContent = message;
        el.hidden = !message;
        el.classList.toggle('is-error', isError);
    }

    function setOrdering(card, on) {
        card.classList.toggle('is-ordering', on);
        card.querySelectorAll('[data-move-controls]').forEach((el) => { el.hidden = !on; });
        card.querySelectorAll('[data-edit]').forEach((el) => { el.hidden = on; });
        card.querySelector('[data-edit-order]').hidden = on;
        card.querySelector('[data-order-cancel]').hidden = !on;
        card.querySelector('[data-order-save]').hidden = !on;
        card.querySelectorAll('.vt-row.is-moved').forEach((row) => row.classList.remove('is-moved'));
    }

    function startOrdering(card) {
        if (ordering) stopOrdering(true);
        const snapshot = [...card.querySelectorAll('[data-category-group]')].map((tbody) => [...tbody.querySelectorAll('.vt-row')]);
        ordering = { card, snapshot };
        setOrdering(card, true);
        refreshRanks(card);
        feedback(card, 'Use the arrow buttons to change the order within each category, then save.');
        card.querySelector('[data-move]:not(:disabled)')?.focus();
    }

    function stopOrdering(restore) {
        if (!ordering) return;
        const { card, snapshot } = ordering;
        if (restore) {
            card.querySelectorAll('[data-category-group]').forEach((tbody, index) => {
                snapshot[index].forEach((row) => tbody.append(row));
            });
        }
        setOrdering(card, false);
        refreshRanks(card);
        ordering = null;
        return card;
    }

    function move(button) {
        const row = button.closest('.vt-row');
        const tbody = row.closest('[data-category-group]');
        const rows = activeRows(tbody);
        const index = rows.indexOf(row);
        const target = button.dataset.move === 'up' ? rows[index - 1] : rows[index + 1];
        if (!target) return;

        if (button.dataset.move === 'up') target.before(row);
        else target.after(row);

        row.classList.add('is-moved');
        refreshRanks(ordering.card);

        const name = row.querySelector('[data-vehicle-name]').textContent;
        const category = tbody.querySelector('.vt-category-row th').firstChild.textContent.trim();
        feedback(ordering.card, `${name} moved to number ${rows.indexOf(target) + 1} of ${rows.length} in ${category}. Save to apply.`);

        // Keep focus on the same control; fall back to its sibling at the edges.
        (button.disabled ? row.querySelector('[data-move]:not(:disabled)') : button).focus();
    }

    async function saveOrdering() {
        const { card } = ordering;
        const groups = [...card.querySelectorAll('[data-category-group]')]
            .map((tbody) => ({
                required_truck_type_id: parseInt(card.dataset.truckTypeId, 10),
                category: tbody.dataset.category,
                vehicle_ids: activeRows(tbody).map((row) => parseInt(row.dataset.vehicleId, 10)),
            }))
            .filter((group) => group.category && group.vehicle_ids.length > 0);

        const save = card.querySelector('[data-order-save]');
        save.disabled = true;
        feedback(card, 'Saving…');

        try {
            const response = await fetch(page.dataset.reorderUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ groups }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data?.errors?.groups?.[0] || data?.message || 'Couldn\'t save the order. Try again.');

            stopOrdering(false);
            feedback(card, 'Order saved. Customers now see this order.');
            card.querySelector('[data-edit-order]').focus();
        } catch (error) {
            feedback(card, error.message, true);
        } finally {
            save.disabled = false;
        }
    }

    /* ---------- Event wiring ---------- */

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (target.closest('[data-clear-filters]')) {
            event.preventDefault();
            searchInput.value = '';
            selects.forEach((select) => { select.value = ''; });
            loadResults(page.dataset.indexUrl);
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

        const categories = target.closest('[data-open-categories]');
        if (categories) {
            showModal(categoriesModal, categories, categoriesModal.querySelector('.tt-dialog-close'));
            return;
        }

        const editOrder = target.closest('[data-edit-order]');
        if (editOrder) {
            const card = editOrder.closest('[data-group]');
            if (editOrder.getAttribute('aria-disabled') === 'true') {
                feedback(card, 'Clear search and filters to edit the order, so every vehicle in each category is included.', true);
                return;
            }
            startOrdering(card);
            return;
        }

        const moveButton = target.closest('[data-move]');
        if (moveButton && ordering) {
            move(moveButton);
            return;
        }

        if (target.closest('[data-order-cancel]') && ordering) {
            const card = stopOrdering(true);
            feedback(card, '');
            card.querySelector('[data-edit-order]').focus();
            return;
        }

        if (target.closest('[data-order-save]') && ordering) {
            saveOrdering();
            return;
        }

        if (target.closest('[data-close-modal]')) hideModal();
    });

    document.addEventListener('keydown', (event) => {
        if (!openModal) return;

        if (event.key === 'Escape') {
            const renaming = event.target.closest?.('[data-category-rename]');
            event.preventDefault();
            if (renaming) setRenaming(renaming.closest('[data-category-id]'), false);
            else hideModal();
        } else if (event.key === 'Tab') {
            trapFocus(event);
        }
    });

    /* ---------- Reopen the form after a failed save ---------- */

    if (page.dataset.reopen) {
        const editId = form.querySelector('input[name="_edit_id"]').value;
        const editButton = editId ? document.querySelector(`[data-edit][data-id="${editId}"]`) : null;
        setManage(editButton ? editButton.dataset : null);
        const firstInvalid = form.querySelector('.has-error input:not([type="hidden"]), .has-error select');
        showModal(formModal, editButton || page.querySelector('[data-open-add]'), firstInvalid || form.elements.namedItem('name'));
    }
})();
