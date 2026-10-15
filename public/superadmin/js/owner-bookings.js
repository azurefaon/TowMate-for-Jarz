/* Owner Bookings (Revision 3): filters, list refresh and the details modal. */
(function () {
    'use strict';

    const page = document.getElementById('ownerBookings');
    if (!page) {
        return;
    }

    const form = document.getElementById('bookingFilters');
    const results = document.getElementById('bookingResults');
    const indexUrl = page.dataset.indexUrl;
    const showUrl = page.dataset.showUrl;
    const searchInput = form.querySelector('input[name="search"]');
    const hidden = (name) => form.querySelector(`input[type="hidden"][name="${name}"]`);
    const clearLink = form.querySelector('[data-clear]');

    const icons = () => window.lucide?.createIcons();

    function el(tag, attrs = {}, children = []) {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([key, value]) => {
            if (value === null || value === undefined || value === false) return;
            if (key === 'class') node.className = value;
            else if (key === 'text') node.textContent = value;
            else node.setAttribute(key, value === true ? '' : value);
        });
        (Array.isArray(children) ? children : [children]).forEach((child) => {
            if (child === null || child === undefined || child === false) return;
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        });
        return node;
    }

    const icon = (name) => el('i', { 'data-lucide': name, 'aria-hidden': 'true' });

    // ------------------------------------------------------------------
    // List refresh
    // ------------------------------------------------------------------

    let listController = null;
    let lastUrl = window.location.href;

    function filterUrl() {
        const params = new URLSearchParams();
        const search = searchInput.value.trim();
        const status = hidden('status').value;
        const date = hidden('date').value;

        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (date && date !== 'all') {
            params.set('date', date);
            if (date === 'custom') {
                params.set('from', hidden('from').value);
                params.set('to', hidden('to').value);
            }
        }

        const query = params.toString();
        return query ? `${indexUrl}?${query}` : indexUrl;
    }

    function skeletonCard() {
        const rows = [];
        for (let i = 0; i < 5; i++) {
            const cell = (pill) => el('td', {}, pill
                ? el('span', { class: 'ob-skeleton is-pill' })
                : [el('span', { class: 'ob-skeleton', style: 'width:72%' }), el('span', { class: 'ob-skeleton', style: 'width:48%' })]);
            rows.push(el('tr', {}, [cell(), cell(), cell(), cell(), cell(), cell(true), el('td')]));
        }

        const head = ['Booking', 'Customer', 'Service', 'Route', 'Amount', 'Status', ''];
        const cols = ['booking', 'customer', 'service', 'route', 'amount', 'status', 'open'];

        return el('div', { class: 'ob-card', 'aria-busy': 'true' }, [
            el('div', { class: 'ob-table-wrap' }, el('table', { class: 'ob-table' }, [
                el('thead', {}, el('tr', {}, head.map((label, i) => el('th', { class: `ob-col-${cols[i]}` }, label)))),
                el('tbody', {}, rows),
            ])),
            el('p', { class: 'ob-loading-note', text: 'Loading bookings…' }),
        ]);
    }

    function errorCard(url) {
        const retry = el('button', { type: 'button', class: 'ob-btn' }, [icon('refresh-cw'), 'Try again']);
        retry.addEventListener('click', () => refresh(url));

        return el('div', { class: 'ob-card' }, el('div', { class: 'ob-state', role: 'alert' }, [
            el('span', { class: 'ob-state-icon is-error' }, icon('circle-alert')),
            el('p', { class: 'ob-state-title', text: "Couldn't load bookings" }),
            el('p', { class: 'ob-state-text', text: 'Check your connection, then try again. Your filters are kept.' }),
            retry,
        ]));
    }

    function syncControls() {
        const card = results.querySelector('[data-results]');
        if (!card) return;

        hidden('status').value = card.dataset.status;
        hidden('date').value = card.dataset.date;
        hidden('from').value = card.dataset.from;
        hidden('to').value = card.dataset.to;

        selects.status.setLabel(card.dataset.statusLabel, card.dataset.status !== '');
        selects.status.setSelected(card.dataset.status);
        selects.date.setLabel(card.dataset.dateLabel, card.dataset.date !== 'all');
        selects.date.setSelected(card.dataset.date);
        rangeFrom.value = card.dataset.from;
        rangeTo.value = card.dataset.to;
        rangeBox.hidden = card.dataset.date !== 'custom';
        clearLink.hidden = card.dataset.hasFilters !== '1';
    }

    async function refresh(url) {
        listController?.abort();
        const controller = new AbortController();
        listController = controller;
        lastUrl = url;

        results.replaceChildren(skeletonCard());

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const html = await response.text();
            if (controller !== listController) return;

            results.innerHTML = html;
            icons();
            syncControls();
            window.history.replaceState({}, '', url);
        } catch (error) {
            if (error.name === 'AbortError') return;
            results.replaceChildren(errorCard(url));
            icons();
        }
    }

    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => refresh(filterUrl()), 300);
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(searchTimer);
        refresh(filterUrl());
    });

    function clearFilters(event) {
        event.preventDefault();
        searchInput.value = '';
        hidden('status').value = '';
        hidden('date').value = 'all';
        hidden('from').value = '';
        hidden('to').value = '';
        refresh(indexUrl);
    }

    clearLink.addEventListener('click', clearFilters);

    results.addEventListener('click', (event) => {
        const clear = event.target.closest('[data-clear]');
        if (clear) {
            clearFilters(event);
            return;
        }

        const pageLink = event.target.closest('[data-page-link]');
        if (pageLink) {
            event.preventDefault();
            refresh(pageLink.href);
            results.scrollIntoView({ block: 'start', behavior: 'auto' });
            return;
        }

        // One action per booking: the code button. A click elsewhere on the row
        // is a mouse shortcut for the same action, never a second request.
        const code = event.target.closest('.ob-code');
        const row = event.target.closest('.ob-row');
        if (!row) return;
        if (!code && window.getSelection()?.toString()) return;

        const button = code ?? row.querySelector('.ob-code');
        openBooking(button.dataset.booking, button);
    });

    // ------------------------------------------------------------------
    // Filter menus (listbox pattern)
    // ------------------------------------------------------------------

    function createSelect(root, onPick) {
        const trigger = root.querySelector('.ob-select-trigger');
        const panel = root.querySelector('.ob-menu');
        const list = root.querySelector('[role="listbox"]');
        const label = trigger.querySelector('[data-label]');
        const options = () => [...list.querySelectorAll('[role="option"]')];
        let focusIndex = -1;

        function setFocus(index) {
            const all = options();
            all.forEach((option) => option.classList.remove('is-focused'));
            focusIndex = (index + all.length) % all.length;
            const option = all[focusIndex];
            option.classList.add('is-focused');
            if (!option.id) option.id = `${list.id}-opt-${focusIndex}`;
            list.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        }

        function open() {
            Object.values(selects).forEach((select) => select !== api && select.close(false));
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            const selected = options().findIndex((o) => o.getAttribute('aria-selected') === 'true');
            setFocus(Math.max(selected, 0));
            list.focus();
        }

        function close(returnFocus = true) {
            if (panel.hidden) return;
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            options().forEach((option) => option.classList.remove('is-focused'));
            if (returnFocus) trigger.focus();
        }

        trigger.addEventListener('click', () => (panel.hidden ? open() : close()));
        trigger.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                open();
            }
        });

        list.addEventListener('keydown', (event) => {
            const all = options();
            if (event.key === 'ArrowDown') { event.preventDefault(); setFocus(focusIndex + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); setFocus(focusIndex - 1); }
            else if (event.key === 'Home') { event.preventDefault(); setFocus(0); }
            else if (event.key === 'End') { event.preventDefault(); setFocus(all.length - 1); }
            else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                onPick(all[focusIndex].dataset.value, api);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();
            } else if (event.key === 'Tab') {
                close(false);
            }
        });

        list.addEventListener('click', (event) => {
            const option = event.target.closest('[role="option"]');
            if (option) onPick(option.dataset.value, api);
        });

        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();
            }
        });

        const api = {
            root,
            open,
            close,
            isOpen: () => !panel.hidden,
            setLabel(text, active) {
                label.textContent = text;
                trigger.classList.toggle('is-active', active);
            },
            setSelected(value) {
                options().forEach((option) => option.setAttribute('aria-selected', String(option.dataset.value === value)));
            },
        };

        return api;
    }

    const rangeBox = form.querySelector('[data-range]');
    const rangeFrom = form.querySelector('[data-range-from]');
    const rangeTo = form.querySelector('[data-range-to]');
    const rangeError = form.querySelector('[data-range-error]');

    const selects = {};
    selects.status = createSelect(form.querySelector('[data-select="status"]'), (value, select) => {
        hidden('status').value = value;
        select.setSelected(value);
        select.close();
        refresh(filterUrl());
    });

    selects.date = createSelect(form.querySelector('[data-select="date"]'), (value, select) => {
        select.setSelected(value);
        if (value === 'custom') {
            rangeBox.hidden = false;
            rangeError.hidden = true;
            rangeFrom.focus();
            return;
        }
        rangeBox.hidden = true;
        hidden('date').value = value;
        select.close();
        refresh(filterUrl());
    });

    form.querySelector('[data-range-apply]').addEventListener('click', () => {
        const from = rangeFrom.value;
        const to = rangeTo.value;
        if (!from || !to || from > to) {
            rangeError.hidden = false;
            (from ? rangeTo : rangeFrom).focus();
            return;
        }
        rangeError.hidden = true;
        hidden('date').value = 'custom';
        hidden('from').value = from;
        hidden('to').value = to;
        selects.date.close();
        refresh(filterUrl());
    });

    document.addEventListener('pointerdown', (event) => {
        Object.values(selects).forEach((select) => {
            if (select.isOpen() && !select.root.contains(event.target)) select.close(false);
        });
    });

    // ------------------------------------------------------------------
    // Booking details modal
    // ------------------------------------------------------------------

    const modal = document.getElementById('bookingModal');
    const dialog = modal.querySelector('[role="dialog"]');
    const title = document.getElementById('bookingModalTitle');
    const meta = modal.querySelector('[data-modal-meta]');
    const body = modal.querySelector('[data-modal-body]');
    const closeButton = modal.querySelector('.ob-modal-close');
    let modalController = null;
    let loadingCode = null;
    let returnFocusTo = null;

    const badge = (status) => el('span', { class: `ob-badge tone-${status.tone}`, text: status.label });

    function dl(rows) {
        const list = el('dl', { class: 'ob-dl' });
        rows.filter(Boolean).forEach(([label, value, note, extraClass]) => {
            list.append(el('dt', { text: label }));
            list.append(el('dd', { class: extraClass || null }, [value, note ? el('small', { text: note }) : null]));
        });
        return list;
    }

    function section(heading, content, aside) {
        return el('section', { class: 'ob-section' }, [
            el('div', { class: 'ob-section-head' }, [el('h3', { text: heading }), aside ? el('span', { text: aside }) : null]),
            ...(Array.isArray(content) ? content : [content]),
        ]);
    }

    const invoiceBadge = {
        current: ['Current', '#166534'],
        replaced: ['Replaced', '#6D28D9'],
        voided: ['Voided', '#B91C1C'],
        issued: ['Issued', '#525252'],
    };

    const docBadge = (state) => {
        const [label, color] = invoiceBadge[state] || invoiceBadge.issued;
        return el('span', { class: 'ob-badge', style: `background:${color}`, text: label });
    };

    // Read-only access: the complete invoice, its version history and the receipt
    // open in dedicated viewers. Void, replace and payment confirmation stay with the Dispatcher.
    function documentsSection(docs) {
        const content = [];
        const invoices = docs.invoices;

        if (!invoices.length) {
            content.push(el('p', { class: 'ob-doc-empty', text: 'No invoice has been issued for this booking yet.' }));
        } else {
            const shown = invoices.find((inv) => inv.state === 'current') || invoices[0];
            content.push(dl([
                ['Invoice', el('span', { class: 'ob-doc-id' }, [shown.number, ' ', docBadge(shown.state)]), invoices.length > 1 ? `${invoices.length} versions on record` : null],
                ['Amount', shown.amount],
                ['Issued', shown.issued || '—'],
            ]));
            content.push(el('div', { class: 'ob-doc-actions' }, [
                el('a', { class: 'ob-download', href: shown.url }, [icon('file-text'), 'View invoice']),
                invoices.length > 1 ? el('a', { class: 'ob-link', href: shown.url }, 'Invoice history') : null,
            ]));
        }

        const pay = docs.payment;
        content.push(el('div', { class: 'ob-section-rule', 'aria-hidden': 'true' }), dl([
            ['Payment', pay.value, pay.note],
            pay.cash_received ? ['Cash received', pay.cash_received] : null,
            pay.confirmed_by ? ['Confirmed by', pay.confirmed_by] : null,
            ['Receipt', docs.receipt ? docs.receipt.number || docs.receipt.code : 'No receipt issued yet', docs.receipt ? docs.receipt.issued : null, docs.receipt ? null : 'is-muted'],
        ]));

        if (docs.receipt) {
            content.push(el('div', { class: 'ob-doc-actions' }, el('a', { class: 'ob-download', href: docs.receipt.url }, [icon('receipt'), 'View receipt'])));
        }

        return section('Invoice & Receipt', content, 'Read-only');
    }

    function pricingSection(pricing) {
        const lines = [
            ['Quotation', pricing.quotation.value, pricing.quotation.note],
            [pricing.kind === 'group' ? 'Group Payment' : 'Payment', pricing.payment.value, pricing.payment.note],
        ];

        if (pricing.kind === 'single') {
            const total = pricing.total;
            return section('Quotation & Payment', [
                dl([[total.label, total.muted ? total.amount : el('span', { class: 'ob-total-value', text: total.amount }), total.note, total.muted ? 'is-muted' : null], ...lines]),
            ]);
        }

        if (pricing.kind === 'legacy') {
            return section('Quotation & Payment', [
                dl([['Amount', pricing.unavailable, pricing.unavailable_note], ...lines]),
            ]);
        }

        const aside = { quoted: 'Not yet accepted', provisional: 'Estimate before quotation' }[pricing.state] || null;
        const content = [];

        if (pricing.unavailable) {
            content.push(dl([['Amount', pricing.unavailable, null], ...lines]));
        } else {
            const money = el('dl', { class: 'ob-money' });
            pricing.rows.forEach((row) => {
                money.append(el('dt', { text: row.label }));
                money.append(el('dd', {}, [row.amount, row.note ? el('small', { text: row.note }) : null]));
            });
            if (pricing.rows.length) money.append(el('div', { class: 'ob-money-rule', 'aria-hidden': 'true' }));
            money.append(el('dt', { text: pricing.total.label }));
            money.append(el('dd', {}, [el('span', { class: 'ob-total-value', text: pricing.total.amount }), pricing.total.note ? el('small', { text: pricing.total.note }) : null]));
            content.push(money, el('div', { class: 'ob-section-rule', 'aria-hidden': 'true' }), dl(lines));
        }

        return section('Group Pricing', content, aside);
    }

    function codeCell(row) {
        if (row.is_current) {
            return el('td', {}, [row.code, el('small', { class: 'ob-sub', text: 'This booking' })]);
        }
        return el('td', {}, el('button', { type: 'button', class: 'ob-code', 'data-modal-booking': row.code, 'aria-label': `View booking ${row.code}`, text: row.code }));
    }

    const vehicleCell = (row) => el('td', {}, [row.vehicle || '—', row.vehicle_note ? el('small', { class: 'ob-sub', text: row.vehicle_note }) : null]);

    function vehiclesSection(vehicles) {
        const head = ['Booking', 'Vehicle', 'Status'];
        if (vehicles.show_amounts) head.push(vehicles.amount_label);

        const rows = vehicles.rows.map((row) => el('tr', {}, [
            codeCell(row),
            vehicleCell(row),
            el('td', {}, badge(row.status)),
            vehicles.show_amounts ? el('td', { class: `is-amount${row.not_charged ? ' is-not-charged' : ''}`, text: row.amount }) : null,
        ]));

        return section('Vehicles in This Request', el('div', { class: 'ob-table-wrap' }, el('table', { class: 'ob-vehicles' }, [
            el('thead', {}, el('tr', {}, head.map((label, i) => el('th', { class: i === 3 ? 'is-amount' : null, text: label })))),
            el('tbody', {}, rows),
        ])), vehicles.note);
    }

    function relatedSection(related) {
        const rows = related.map((row) => el('tr', {}, [codeCell(row), vehicleCell(row), el('td', {}, badge(row.status))]));
        return section('Related Bookings', el('table', { class: 'ob-vehicles' }, [
            el('thead', {}, el('tr', {}, ['Booking', 'Vehicle', 'Status'].map((label) => el('th', { text: label })))),
            el('tbody', {}, rows),
        ]), 'Same request');
    }

    function render(data) {
        title.textContent = `Booking ${data.code}`;
        meta.replaceChildren(badge(data.status), data.created ? el('span', { text: `Created ${data.created}` }) : null);

        const service = data.service;
        const assignment = data.assignment;
        const route = data.route;
        const customer = data.customer;

        const left = el('div', { class: 'ob-col' }, [
            section('Customer Information', dl([
                ['Name', customer.name || '—'],
                ['Phone', customer.phone || '—'],
                ['Email', customer.email || '—'],
            ])),
            section('Service & Vehicle', dl([
                ['Service', service.type, service.scheduled_for ? `For ${service.scheduled_for}` : null],
                ['Truck type', service.truck_type || '—'],
                service.vehicle_type ? ['Vehicle type', service.vehicle_type] : null,
            ])),
            section('Assignment', dl(assignment ? [
                ['Unit', assignment.unit, assignment.plate ? `Plate ${assignment.plate}` : null],
                assignment.team_leader ? ['Team leader', assignment.team_leader] : null,
                assignment.assigned_at ? ['Assigned', assignment.assigned_at] : null,
            ] : [['Unit', 'Not assigned', null, 'is-muted']])),
        ]);

        const right = el('div', { class: 'ob-col' }, [
            section('Route Details', dl([
                ['Pickup', route.pickup || '—', route.pickup_note ? `Note: ${route.pickup_note}` : null],
                ['Drop-off', route.dropoff || '—'],
                route.distance ? ['Distance', route.distance] : null,
            ])),
            pricingSection(data.pricing),
        ]);

        const grid = el('div', { class: 'ob-grid' }, [left, right]);
        if (data.documents) grid.append(el('div', { class: 'ob-full' }, documentsSection(data.documents)));
        if (data.vehicles) grid.append(el('div', { class: 'ob-full' }, vehiclesSection(data.vehicles)));
        if (data.related?.length) grid.append(el('div', { class: 'ob-full' }, relatedSection(data.related)));
        if (data.notes) grid.append(el('div', { class: 'ob-full' }, section('Additional Notes', el('p', { class: 'ob-notes', text: data.notes }))));

        body.replaceChildren(grid);
        body.scrollTop = 0;
        icons();
    }

    function renderLoading(code) {
        title.textContent = `Booking ${code}`;
        meta.replaceChildren(el('span', { class: 'ob-skeleton', style: 'width:200px' }));
        const bars = [80, 340, 260, 300, 'gap', 380, 230].map((w) => (w === 'gap'
            ? el('span', { class: 'ob-skeleton is-gap' })
            : el('span', { class: 'ob-skeleton', style: `width:${w}px` })));
        body.replaceChildren(
            el('div', { class: 'ob-modal-skeleton', 'aria-busy': 'true' }, bars),
            el('p', { class: 'ob-loading-note', style: 'border:0;padding:24px 0 0', text: 'Loading booking details…' }),
        );
    }

    function renderError(code) {
        meta.replaceChildren();
        const retry = el('button', { type: 'button', class: 'ob-btn', text: 'Try again' });
        retry.addEventListener('click', () => loadBooking(code, true));
        body.replaceChildren(el('div', { class: 'ob-state', role: 'alert' }, [
            el('span', { class: 'ob-state-icon is-error' }, icon('circle-alert')),
            el('p', { class: 'ob-state-title', text: "Couldn't load booking details" }),
            el('p', { class: 'ob-state-text', text: 'Please try again.' }),
            retry,
        ]));
        icons();
        retry.focus();
    }

    async function loadBooking(code, moveFocus) {
        if (loadingCode === code) return;
        modalController?.abort();
        const controller = new AbortController();
        modalController = controller;
        loadingCode = code;
        renderLoading(code);

        try {
            const response = await fetch(showUrl.replace('__CODE__', encodeURIComponent(code)), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (controller !== modalController) return;
            render(data);
            if (moveFocus) title.focus();
        } catch (error) {
            if (error.name === 'AbortError') return;
            renderError(code);
        } finally {
            if (controller === modalController) loadingCode = null;
        }
    }

    function openBooking(code, trigger) {
        if (!code) return;
        if (modal.hidden) {
            returnFocusTo = trigger || document.activeElement;
            modal.hidden = false;
            document.body.classList.add('ob-modal-open');
            closeButton.focus();
        }
        loadBooking(code, false);
    }

    function closeModal() {
        if (modal.hidden) return;
        modalController?.abort();
        loadingCode = null;
        modal.hidden = true;
        document.body.classList.remove('ob-modal-open');

        let target = returnFocusTo;
        if (!target || !document.contains(target)) {
            const code = returnFocusTo?.dataset?.booking;
            target = code ? results.querySelector(`.ob-code[data-booking="${CSS.escape(code)}"]`) : null;
        }
        target?.focus();
        returnFocusTo = null;
    }

    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-modal-close]')) {
            closeModal();
            return;
        }
        const sibling = event.target.closest('[data-modal-booking]');
        if (sibling) loadBooking(sibling.dataset.modalBooking, true);
    });

    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.key !== 'Tab') return;

        const focusable = [...dialog.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter((node) => node.offsetParent !== null);
        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement) || document.activeElement === title)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    // Back to Booking from the invoice / receipt viewers reopens the booking details.
    const requestedBooking = new URLSearchParams(window.location.search).get('booking');
    if (requestedBooking) openBooking(requestedBooking, null);
})();
