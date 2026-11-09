/*
 * Owner Finance pages: panel toggles, one-click submission, and live hints on the expense form.
 * The hints are convenience only. The server re-checks every amount exactly.
 * Money is handled in integer centavos parsed from the typed text, never as floating-point pesos.
 */
(function () {
    'use strict';

    function toCentavos(text) {
        var clean = String(text || '').replace(/[,\s₱]/g, '');
        if (!/^\d{1,13}(\.\d{1,2})?$/.test(clean)) { return null; }
        var parts = clean.split('.');
        var fraction = ((parts[1] || '') + '00').slice(0, 2);
        return parseInt(parts[0], 10) * 100 + parseInt(fraction, 10);
    }

    function formatPeso(centavos) {
        var negative = centavos < 0;
        var abs = Math.abs(centavos);
        var whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var fraction = String(abs % 100).padStart(2, '0');
        return (negative ? '-' : '') + '₱' + whole + '.' + fraction;
    }

    function initPanels() {
        var panels = document.querySelectorAll('[data-fin-panel]');
        document.querySelectorAll('[data-fin-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                panels.forEach(function (panel) { panel.hidden = panel.dataset.finPanel !== btn.dataset.finOpen; });
                var target = document.querySelector('[data-fin-panel="' + btn.dataset.finOpen + '"]');
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    var first = target.querySelector('select, input:not([type=hidden])');
                    if (first) { first.focus(); }
                }
            });
        });
        document.querySelectorAll('[data-fin-close]').forEach(function (btn) {
            btn.addEventListener('click', function () { btn.closest('[data-fin-panel]').hidden = true; });
        });
    }

    function initOnce() {
        document.querySelectorAll('form[data-fin-once]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented) { return; }
                if (form.dataset.submitted === '1') { event.preventDefault(); return; }
                form.dataset.submitted = '1';
                form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
            });
        });
    }

    function initExpenseForm(container) {
        var form = container.closest('form');
        var amount = container.querySelector('[data-fin-amount]');
        var fundings = container.querySelectorAll('[data-fin-funding]');
        var status = container.querySelector('[data-fin-funding-status]');
        var overspend = container.querySelector('[data-fin-overspend]');
        var unitSelect = container.querySelector('[data-fin-expense-unit]');

        container.querySelectorAll('[data-fin-scope]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                container.querySelectorAll('[data-fin-scope-show]').forEach(function (el) {
                    el.hidden = el.dataset.finScopeShow !== radio.value;
                });
            });
        });

        function update() {
            var total = toCentavos(amount.value);
            var sum = 0;
            var filled = 0;
            var unitPortion = 0;
            fundings.forEach(function (input) {
                var c = toCentavos(input.value);
                if (c !== null && c > 0) {
                    sum += c;
                    filled += 1;
                    if (input.dataset.finFunding === 'unit_fund') { unitPortion = c; }
                }
            });

            if (status) {
                if (total === null) {
                    status.textContent = '';
                } else if (filled === 0) {
                    status.textContent = 'Enter how much was paid from each source.';
                } else if (sum === total) {
                    status.textContent = 'Funding matches the amount.';
                    status.className = 'fin-help fin-ok';
                } else {
                    status.textContent = 'Funding totals ' + formatPeso(sum) + '. ' + (sum < total ? formatPeso(total - sum) + ' still to assign.' : formatPeso(sum - total) + ' too much.');
                    status.className = 'fin-help fin-mismatch';
                }
            }

            if (overspend && unitSelect) {
                var option = unitSelect.selectedOptions[0];
                var available = option && option.value ? toCentavos(option.dataset.available) : null;
                if (available === null && option && option.dataset.available && option.dataset.available.charAt(0) === '-') {
                    available = 0;
                }
                if (available !== null && unitPortion > Math.max(available, 0)) {
                    overspend.hidden = false;
                    overspend.textContent = 'This unit has ' + formatPeso(Math.max(available, 0)) + ' of issued funds available after pending expenses. '
                        + 'The unit-fund part is ' + formatPeso(unitPortion - Math.max(available, 0)) + ' more than that. You can still record it as entered. '
                        + 'If the extra was paid with personal money or by the company, put that part under the right source.';
                } else {
                    overspend.hidden = true;
                    overspend.textContent = '';
                }
            }
        }

        // With a single source filled in, typing the amount fills it in for you.
        amount.addEventListener('input', function () {
            var filledInputs = Array.prototype.filter.call(fundings, function (i) { return i.value.trim() !== ''; });
            if (filledInputs.length === 1 && filledInputs[0].dataset.autofill === '1') {
                filledInputs[0].value = amount.value;
            }
            update();
        });
        fundings.forEach(function (input) {
            input.addEventListener('input', function () { input.dataset.autofill = '0'; update(); });
            input.addEventListener('focus', function () {
                var others = Array.prototype.some.call(fundings, function (i) { return i !== input && i.value.trim() !== ''; });
                if (!others && input.value.trim() === '' && amount.value.trim() !== '') {
                    input.value = amount.value;
                    input.dataset.autofill = '1';
                    update();
                }
            });
        });
        if (unitSelect) { unitSelect.addEventListener('change', update); }
        if (form) { form.addEventListener('reset', function () { setTimeout(update, 0); }); }
        update();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPanels();
        initOnce();
        document.querySelectorAll('[data-fin-expense-form]').forEach(initExpenseForm);
    });
})();
