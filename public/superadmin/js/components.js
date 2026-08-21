(function () {
    'use strict';

    var CHEVRON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    var CALENDAR_ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="3"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';

    function closeAllPanels(except) {
        document.querySelectorAll('.custom-select.is-open, .date-range-picker.is-open').forEach(function (el) {
            if (el !== except) el.classList.remove('is-open');
        });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.custom-select') && !e.target.closest('.date-range-picker')) {
            closeAllPanels(null);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllPanels(null);
    });

    // ───────────────────────── Custom select ─────────────────────────

    function enhanceSelects() {
        document.querySelectorAll('select[data-custom]').forEach(function (select) {
            if (select.dataset.enhanced) return;
            select.dataset.enhanced = '1';

            var wrap = document.createElement('div');
            wrap.className = 'custom-select';

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'custom-select-trigger';

            var label = document.createElement('span');
            label.className = 'custom-select-label';

            var chevron = document.createElement('span');
            chevron.className = 'custom-select-chevron';
            chevron.innerHTML = CHEVRON;

            trigger.appendChild(label);
            trigger.appendChild(chevron);

            var menu = document.createElement('div');
            menu.className = 'custom-select-menu';

            function optionText(opt) {
                return opt.textContent.trim();
            }

            function render() {
                label.textContent = optionText(select.options[select.selectedIndex] || select.options[0]);
                menu.innerHTML = '';
                Array.prototype.forEach.call(select.options, function (opt) {
                    var row = document.createElement('div');
                    row.className = 'custom-select-option' + (opt.selected ? ' is-selected' : '');
                    row.textContent = optionText(opt);
                    row.tabIndex = -1;
                    row.addEventListener('click', function () {
                        select.value = opt.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        render();
                        wrap.classList.remove('is-open');
                    });
                    menu.appendChild(row);
                });
            }

            trigger.addEventListener('click', function () {
                var willOpen = !wrap.classList.contains('is-open');
                closeAllPanels(wrap);
                wrap.classList.toggle('is-open', willOpen);
            });

            trigger.addEventListener('keydown', function (e) {
                var options = Array.prototype.slice.call(menu.children);
                var focused = menu.querySelector('.is-focused');
                var idx = options.indexOf(focused);

                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    wrap.classList.add('is-open');
                    if (idx === -1) idx = select.selectedIndex;
                    idx = e.key === 'ArrowDown' ? Math.min(idx + 1, options.length - 1) : Math.max(idx - 1, 0);
                    options.forEach(function (o) { o.classList.remove('is-focused'); });
                    options[idx].classList.add('is-focused');
                    options[idx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter' && focused) {
                    e.preventDefault();
                    focused.click();
                }
            });

            select.parentNode.insertBefore(wrap, select);
            wrap.appendChild(trigger);
            wrap.appendChild(menu);
            wrap.appendChild(select);
            select.classList.add('custom-select-native');

            select.addEventListener('change', render);

            render();
        });
    }

    // ───────────────────────── Date range picker ─────────────────────────

    var WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    function toIso(date) {
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function parseIso(value) {
        if (!value) return null;
        var parts = value.split('-');
        if (parts.length !== 3) return null;
        var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        return isNaN(d.getTime()) ? null : d;
    }

    function sameDay(a, b) {
        return !!a && !!b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function formatShort(date) {
        return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function enhanceDateRangePickers() {
        document.querySelectorAll('[data-range-picker]').forEach(function (wrap) {
            if (wrap.dataset.enhanced) return;
            wrap.dataset.enhanced = '1';

            var fromInput = wrap.querySelector('[data-role="from"]');
            var toInput = wrap.querySelector('[data-role="to"]');
            if (!fromInput || !toInput) return;

            fromInput.classList.add('date-range-native');
            toInput.classList.add('date-range-native');

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'date-range-trigger';
            trigger.innerHTML = '<span class="date-range-trigger-icon">' + CALENDAR_ICON + '</span><span class="date-range-trigger-label"></span>';
            var labelEl = trigger.querySelector('.date-range-trigger-label');

            var popup = document.createElement('div');
            popup.className = 'date-picker-popup';

            wrap.appendChild(trigger);
            wrap.appendChild(popup);

            var rangeStart = parseIso(fromInput.value);
            var rangeEnd = parseIso(toInput.value);
            var viewDate = rangeEnd || rangeStart || new Date();
            viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
            var hoverDate = null;

            function updateTriggerLabel() {
                if (rangeStart && rangeEnd) {
                    labelEl.textContent = formatShort(rangeStart) + ' – ' + formatShort(rangeEnd);
                } else if (rangeStart) {
                    labelEl.textContent = formatShort(rangeStart) + ' – …';
                } else {
                    labelEl.textContent = 'Select date range';
                }
            }

            function statusText() {
                if (rangeStart && rangeEnd) return 'Range selected';
                if (rangeStart) return 'Now pick an end date';
                return 'Pick a start date';
            }

            function commitRange() {
                fromInput.value = rangeStart ? toIso(rangeStart) : '';
                toInput.value = rangeEnd ? toIso(rangeEnd) : '';
                fromInput.dispatchEvent(new Event('change', { bubbles: true }));
                toInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function resetRange() {
                rangeStart = null;
                rangeEnd = null;
                hoverDate = null;
                updateTriggerLabel();
                commitRange();
                renderCalendar();
            }

            function inCurrentRange(day) {
                var start = rangeStart;
                var end = rangeEnd || (rangeStart && hoverDate);
                if (!start || !end) return false;
                var lo = start < end ? start : end;
                var hi = start < end ? end : start;
                return day > lo && day < hi;
            }

            /**
             * Live hover preview while a start date is picked but no end date
             * yet. Only toggles a class on the buttons already in the DOM —
             * never touches innerHTML, so the button under the cursor is
             * never destroyed mid-interaction (that was the actual cause of
             * end-date clicks silently failing: renderCalendar() on every
             * mouseenter kept replacing the button the user was about to
             * click with a fresh element while the mouse was moving).
             */
            function previewRangeShading(hovered) {
                popup.querySelectorAll('.date-picker-day').forEach(function (btn) {
                    var d = parseIso(btn.dataset.date);
                    var inRange = false;
                    if (rangeStart && hovered) {
                        var lo = rangeStart < hovered ? rangeStart : hovered;
                        var hi = rangeStart < hovered ? hovered : rangeStart;
                        inRange = d > lo && d < hi;
                    }
                    btn.classList.toggle('is-in-range', inRange);
                });
            }

            function renderCalendar() {
                var year = viewDate.getFullYear();
                var month = viewDate.getMonth();
                var firstOfMonth = new Date(year, month, 1);
                var startOffset = (firstOfMonth.getDay() + 6) % 7; // Monday-first
                var gridStart = new Date(year, month, 1 - startOffset);
                var today = new Date();

                var html = '';
                html += '<div class="date-picker-header">';
                html += '  <button type="button" class="date-picker-nav" data-nav="-1" aria-label="Previous month">‹</button>';
                html += '  <span class="date-picker-month-label">' + firstOfMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) + '</span>';
                html += '  <button type="button" class="date-picker-nav" data-nav="1" aria-label="Next month">›</button>';
                html += '</div>';
                html += '<div class="date-picker-status">' + statusText() + '</div>';
                html += '<div class="date-picker-weekdays">';
                WEEKDAYS.forEach(function (w) { html += '<span>' + w + '</span>'; });
                html += '</div>';
                html += '<div class="date-picker-grid">';

                for (var i = 0; i < 42; i++) {
                    var day = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
                    var classes = ['date-picker-day'];
                    if (day.getMonth() !== month) classes.push('is-muted');
                    if (sameDay(day, today)) classes.push('is-today');
                    if (sameDay(day, rangeStart) || sameDay(day, rangeEnd)) classes.push('is-selected');
                    if (inCurrentRange(day)) classes.push('is-in-range');

                    html += '<button type="button" class="' + classes.join(' ') + '" data-date="' + toIso(day) + '">' + day.getDate() + '</button>';
                }

                html += '</div>';
                html += '<div class="date-picker-footer">';
                html += '  <button type="button" class="date-picker-reset" data-reset' + (rangeStart || rangeEnd ? '' : ' disabled') + '>Reset</button>';
                html += '</div>';
                popup.innerHTML = html;

                popup.querySelectorAll('[data-nav]').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + parseInt(btn.dataset.nav, 10), 1);
                        renderCalendar();
                    });
                });

                var resetBtn = popup.querySelector('[data-reset]');
                if (resetBtn) {
                    resetBtn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        resetRange();
                    });
                }

                popup.querySelectorAll('.date-picker-day').forEach(function (btn) {
                    var date = parseIso(btn.dataset.date);

                    btn.addEventListener('mouseenter', function () {
                        if (rangeStart && !rangeEnd) {
                            hoverDate = date;
                            previewRangeShading(date);
                        }
                    });

                    btn.addEventListener('click', function (e) {
                        e.stopPropagation();

                        if (!rangeStart || (rangeStart && rangeEnd)) {
                            rangeStart = date;
                            rangeEnd = null;
                            hoverDate = null;
                            updateTriggerLabel();
                            renderCalendar();
                            return;
                        }

                        if (date < rangeStart) {
                            rangeStart = date;
                            rangeEnd = null;
                            hoverDate = null;
                            updateTriggerLabel();
                            renderCalendar();
                            return;
                        }

                        rangeEnd = date;
                        hoverDate = null;
                        updateTriggerLabel();
                        renderCalendar();
                        // Give the browser a moment to actually paint the completed
                        // range (both endpoints + shaded band) before commitRange()
                        // dispatches change events that may synchronously submit the
                        // form and navigate away — otherwise the confirmation is
                        // invisible, since navigation can pre-empt the paint.
                        window.setTimeout(commitRange, 260);
                    });
                });
            }

            trigger.addEventListener('click', function () {
                var willOpen = !wrap.classList.contains('is-open');
                closeAllPanels(wrap);
                wrap.classList.toggle('is-open', willOpen);
                if (willOpen) renderCalendar();
            });

            updateTriggerLabel();
        });
    }

    function init() {
        enhanceSelects();
        enhanceDateRangePickers();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
