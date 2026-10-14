/*
 * Owner Home Assignments: server-side filters and the Edit Home Team dialog.
 *
 * Saving sends the whole team in one request (PUT home-assignments/{unit}).
 * The server applies it in a single transaction with the existing assignment
 * rules, so a save either fully succeeds or changes nothing. The dialog sends
 * a token for the team it opened with; the server answers 409 if the team
 * changed in the meantime.
 */
(function () {
    'use strict';

    const page = document.getElementById('homeAssignmentsPage');
    if (!page) return;

    const data = JSON.parse(document.getElementById('homeTeamData').textContent);
    const people = data.people;
    const unitNames = data.units;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const filters = document.getElementById('homeFilters');
    const searchInput = filters.querySelector('input[name="search"]');
    const showSelect = filters.querySelector('select[name="show"]');

    const modal = document.getElementById('teamModal');
    const saveButton = modal.querySelector('[data-save]');
    const pending = modal.querySelector('[data-pending]');
    const result = modal.querySelector('[data-result]');
    const personFilter = modal.querySelector('[data-person-filter]');

    const ROLE_NOUN = { team_leader: 'team leader', driver: 'driver', crew: 'crew member' };
    const FOCUSABLE = ['a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])', '[tabindex]:not([tabindex="-1"])']
        .map((selector) => '.tt-dialog ' + selector)
        .join(', ');

    const icons = () => {
        if (window.lucide) window.lucide.createIcons();
    };

    /* ---------- Summary carried across the reload after saving ---------- */

    try {
        const summary = sessionStorage.getItem('homeTeamSummary');
        if (summary) {
            sessionStorage.removeItem('homeTeamSummary');
            const box = page.querySelector('[data-save-summary]');
            box.querySelector('span').textContent = summary;
            box.hidden = false;
        }
    } catch (error) { /* storage unavailable: the reloaded roster still shows the result */ }

    /* ---------- Results ---------- */

    let request = 0;

    async function loadResults(url) {
        const card = document.getElementById('homeResults');
        const ticket = ++request;
        card.classList.add('is-loading');
        card.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(response.status);
            const html = await response.text();
            if (ticket !== request) return;

            const next = new DOMParser().parseFromString(html, 'text/html').getElementById('homeResults');
            if (!next) throw new Error('missing results');

            card.replaceWith(next);
            const options = next.querySelector('template[data-show-options]');
            if (options) {
                const value = showSelect.value;
                showSelect.replaceChildren(options.content.cloneNode(true));
                showSelect.value = value;
            }
            icons();
            history.replaceState({ homeAssignments: true }, '', response.url || url);
        } catch (error) {
            window.location.href = url;
        }
    }

    function filterUrl() {
        const url = new URL(page.dataset.indexUrl, window.location.origin);
        const search = searchInput.value.trim();
        if (search) url.searchParams.set('search', search);
        if (showSelect.value) url.searchParams.set('show', showSelect.value);
        return url.toString();
    }

    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadResults(filterUrl()), 300);
    });
    showSelect.addEventListener('change', () => loadResults(filterUrl()));
    filters.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(searchTimer);
        loadResults(filterUrl());
    });

    /* ---------- Dialog ---------- */

    let unitId = null;
    let opener = null;
    let saving = false;
    let teamToken = '';        // the team as this dialog opened it; the server answers 409 if it changed
    let reloadOnClose = false;

    const homeLabel = (person) => {
        if (person.home === unitId) return 'Home unit here';
        return person.home ? `Home: ${unitNames[person.home] || 'another unit'}` : 'No home unit';
    };

    /*
     * A team leader on a truck other than their home unit was put there by a
     * dispatcher. Changing their home unit would take them off that truck, so
     * the server refuses it — except making that truck their home unit.
     */
    const onOtherTruck = (person) => person.role === 'team_leader' && person.current && person.current !== person.home;
    const lockedElsewhere = (person) => onOtherTruck(person) && person.current !== unitId;

    const statusLabel = (person) => {
        if (lockedElsewhere(person)) {
            return `On ${unitNames[person.current] || 'another truck'} today · can't change until a dispatcher returns them`;
        }
        if (onOtherTruck(person)) {
            return `On this truck today · ${person.home ? `Home: ${unitNames[person.home] || 'another unit'}` : 'No home unit'}`;
        }
        return homeLabel(person);
    };

    function renderOptions() {
        modal.querySelectorAll('[data-role-group]').forEach((group) => {
            const role = group.dataset.roleGroup;
            const list = group.querySelector('[data-options]');
            const candidates = people
                .filter((person) => person.role === role)
                .sort((a, b) => (b.home === unitId) - (a.home === unitId) || a.name.localeCompare(b.name));

            list.replaceChildren(...candidates.map((person) => {
                const item = document.createElement('li');
                item.dataset.name = person.name.toLowerCase();
                const id = `ha-${person.key}`;
                item.innerHTML = `<label class="ha-option" for="${id}">
                        <input type="checkbox" id="${id}">
                        <span class="ha-option-text"><span class="ha-option-name"></span><span class="ha-option-meta"></span></span>
                    </label>`;
                const input = item.querySelector('input');
                input.checked = person.home === unitId;
                input.dataset.key = person.key;
                // The server refuses these too; locking here avoids a failed save.
                input.disabled = lockedElsewhere(person);
                item.querySelector('.ha-option-name').textContent = person.name;
                item.querySelector('.ha-option-meta').textContent = statusLabel(person) + (person.active ? '' : ' · Inactive');
                return item;
            }));

            if (!candidates.length) {
                const empty = document.createElement('li');
                empty.className = 'ha-options-empty';
                empty.textContent = `No ${ROLE_NOUN[role]}s yet.`;
                list.append(empty);
            }
        });
        applyPersonFilter();
        refreshPending();
    }

    function changes() {
        return [...modal.querySelectorAll('[data-options] input[type="checkbox"]')]
            .map((input) => {
                const person = people.find((p) => p.key === input.dataset.key);
                const isHere = person.home === unitId;
                if (input.checked === isHere) return null;
                return { person, input, to: input.checked ? unitId : null };
            })
            .filter(Boolean);
    }

    function refreshPending() {
        const list = changes();

        modal.querySelectorAll('.ha-option').forEach((label) => {
            const input = label.querySelector('input');
            const person = people.find((p) => p.key === input.dataset.key);
            const changed = input.checked !== (person.home === unitId);
            label.classList.toggle('is-changed', changed);
            label.querySelector('.ha-option-meta').textContent = (changed
                ? (input.checked ? `Moves here${person.home ? ` from ${unitNames[person.home] || 'another unit'}` : ''}` : 'Home unit will be cleared')
                : statusLabel(person)) + (person.active ? '' : ' · Inactive');
        });

        pending.textContent = list.length ? `${list.length} ${list.length === 1 ? 'change' : 'changes'}` : 'No changes';
        saveButton.disabled = saving || !list.length;
    }

    function applyPersonFilter() {
        const needle = personFilter.value.trim().toLowerCase();
        modal.querySelectorAll('[data-options] li[data-name]').forEach((item) => {
            item.hidden = needle !== '' && !item.dataset.name.includes(needle);
        });
    }

    modal.addEventListener('change', (event) => {
        if (event.target.matches('[data-options] input')) refreshPending();
    });
    personFilter.addEventListener('input', applyPersonFilter);

    function openDialog(button) {
        unitId = parseInt(button.dataset.unitId, 10);
        opener = button;
        teamToken = button.dataset.teamToken || '';
        reloadOnClose = false;
        document.getElementById('haTitle').textContent = `Edit home team · ${button.dataset.unitName}`;
        result.hidden = true;
        personFilter.value = '';
        renderOptions();

        modal.hidden = false;
        document.body.classList.add('tt-modal-open');
        icons();
        personFilter.focus();
    }

    function closeDialog() {
        if (saving) return;
        modal.hidden = true;
        document.body.classList.remove('tt-modal-open');
        // After a conflict or an unconfirmed result, show the latest roster.
        if (reloadOnClose) {
            window.location.reload();
            return;
        }
        if (opener && document.body.contains(opener)) opener.focus();
    }

    /* ---------- Saving ---------- */

    const describe = (item) => item.change === 'added'
        ? `${item.name} added as ${ROLE_NOUN[item.role]}`
        : `${item.name} removed`;

    function showFailure(message) {
        const text = /Nothing was saved/.test(message) ? message : `Nothing was saved. ${message}`;
        result.replaceChildren(Object.assign(document.createElement('p'), { textContent: text }));
        result.hidden = false;
        result.focus();
    }

    /*
     * One request for the whole team. The server applies every change in a
     * single transaction with the existing assignment rules, so either all
     * of it is saved or none of it is — there is nothing partial to report.
     */
    saveButton.addEventListener('click', async () => {
        const list = changes();
        if (!list.length) return;

        const team = { team_leader: [], driver: [], crew: [] };
        modal.querySelectorAll('[data-role-group]').forEach((group) => {
            group.querySelectorAll('[data-options] input:checked').forEach((input) => team[group.dataset.roleGroup].push(input.dataset.key));
        });
        // The home unit the dialog showed for each person being changed.
        const expected = Object.fromEntries(list.map((change) => [change.person.key, change.person.home ?? null]));

        saving = true;
        saveButton.textContent = 'Saving…';
        result.hidden = true;
        refreshPending();

        let response = null;
        let payload = {};
        try {
            response = await fetch(page.dataset.saveUrl.replace('__ID__', unitId), {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ token: teamToken, ...team, expected }),
            });
            payload = await response.json().catch(() => ({}));
        } catch (error) {
            response = null;
        }

        saving = false;
        saveButton.textContent = 'Save';
        refreshPending();

        if (response && response.ok) {
            // The transaction has committed; only now confirm.
            try {
                sessionStorage.setItem('homeTeamSummary', `Home team for ${unitNames[unitId]} saved: ${(payload.saved || []).map(describe).join('; ')}.`);
            } catch (error) { /* the reloaded roster still shows the result */ }
            window.location.reload();
            return;
        }

        if (!response) {
            // The request may or may not have reached the server.
            reloadOnClose = true;
            showFailure("The connection failed before the server answered, so the save can't be confirmed. Close the dialog to see the latest team.");
            return;
        }

        if (response.status === 409) reloadOnClose = true;

        // Field validation errors mean the request itself was malformed (e.g. stale page data).
        showFailure(payload.errors
            ? "Some of the choices weren't accepted. Close the dialog and open it again."
            : (payload.message || "The server couldn't save this team."));
    });

    /* ---------- Event wiring ---------- */

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (target.closest('[data-clear-filters]')) {
            event.preventDefault();
            searchInput.value = '';
            showSelect.value = '';
            loadResults(page.dataset.indexUrl);
            return;
        }

        const edit = target.closest('[data-edit-team]');
        if (edit) {
            openDialog(edit);
            return;
        }

        if (target.closest('[data-close-modal]') && !modal.hidden) closeDialog();
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
        } else if (event.key === 'Tab') {
            const items = [...modal.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
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
    });
})();
