/*
 * Live filters for Owner Finance list pages (progressive enhancement; the form still works without JavaScript).
 *
 * A <form data-fin-live data-fin-target="#regionId"> applies itself automatically:
 *   - dropdowns and "Date by" apply on change
 *   - the search box applies 350 ms after typing stops (cleared box restores the other filters)
 *   - the date range applies once both dates are valid and in order
 * Results are fetched from the same URL and the target region is swapped in place, so the form (and the search
 * text and focus) is never re-rendered. Older responses never replace newer ones, identical requests are skipped,
 * and the URL is kept in sync so Back / Forward restore earlier filters.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 350;

    function queryFor(form) {
        var params = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            var v = typeof value === 'string' ? value.trim() : value;
            if (v !== '' && v !== null) { params.append(key, v); }
        });
        params.sort();
        return params.toString();
    }

    function rangeIsValid(form) {
        var from = form.querySelector('input[name="from"]');
        var to = form.querySelector('input[name="to"]');
        if (!from || !to) { return true; }
        if (!from.value || !to.value) { return false; }
        return from.value <= to.value;
    }

    function init(form) {
        var target = document.querySelector(form.dataset.finTarget);
        if (!target) { return; }
        var status = form.querySelector('[data-fin-live-status]');
        var search = form.querySelector('[data-fin-live-search]');
        var current = queryFor(form);   // what the page is showing now
        var timer = null;
        var controller = null;
        var sequence = 0;
        var searchHistoryOpen = false;  // one history entry per typing session

        function setBusy(busy) {
            target.classList.toggle('is-loading', busy);
            target.setAttribute('aria-busy', busy ? 'true' : 'false');
            if (status) { status.textContent = busy ? 'Updating results…' : ''; }
        }

        function apply(options) {
            options = options || {};
            if (!rangeIsValid(form)) { return; }
            var query = queryFor(form);
            if (query === current && !options.force) { return; }   // nothing changed: no duplicate request

            var mine = ++sequence;
            if (controller) { controller.abort(); }
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var url = form.action.split('?')[0] + (query ? '?' + query : '');
            setBusy(true);

            fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                if (response.redirected && /\/login/.test(response.url)) { window.location.href = url; return null; }
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.text();
            }).then(function (html) {
                if (html === null || mine !== sequence) { return; }   // a newer request has been made: ignore this one
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector(form.dataset.finTarget);
                if (!fresh) { window.location.href = url; return; }
                target.innerHTML = fresh.innerHTML;
                var label = doc.querySelector('[data-oa-range] .oa-range-label');
                var ownLabel = form.querySelector('[data-oa-range] .oa-range-label');
                if (label && ownLabel) { ownLabel.textContent = label.textContent; }
                current = query;
                if (!options.fromHistory) {
                    if (options.source === 'search' && searchHistoryOpen) {
                        history.replaceState({ finLive: true }, '', url);
                    } else {
                        history.pushState({ finLive: true }, '', url);
                    }
                    searchHistoryOpen = options.source === 'search';
                }
                if (window.lucide && typeof window.lucide.createIcons === 'function') { window.lucide.createIcons(); }
                target.dispatchEvent(new CustomEvent('fin:region-updated', { bubbles: true }));
                setBusy(false);
            }).catch(function (error) {
                if (error && error.name === 'AbortError') { return; }
                if (mine !== sequence) { return; }
                setBusy(false);
                if (status) { status.textContent = 'Could not update the results. Check your connection and try again.'; }
            });
        }

        form.addEventListener('change', function (event) {
            var el = event.target;
            if (el === search) { return; }   // handled by the debounce below
            if (el.name === 'from' || el.name === 'to') {
                if (rangeIsValid(form)) {
                    var wrap = el.closest('[data-oa-range]');
                    if (wrap && wrap.querySelector('input[name="from"]').value && wrap.querySelector('input[name="to"]').value) {
                        apply({ source: 'range' });
                    }
                }
                return;
            }
            searchHistoryOpen = false;
            apply({ source: 'select' });
        });

        if (search) {
            search.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { apply({ source: 'search' }); }, DEBOUNCE_MS);
            });
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {   // apply immediately instead of reloading the page
                    event.preventDefault();
                    clearTimeout(timer);
                    apply({ source: 'search' });
                }
            });
            search.addEventListener('blur', function () { searchHistoryOpen = false; });
        }

        // The range popover's own Apply (or Enter) also updates in place.
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) { return; }   // e.g. a reversed range was refused by analytics.js
            event.preventDefault();
            clearTimeout(timer);
            var open = form.querySelector('[data-oa-range].is-open');
            if (open) {
                open.classList.remove('is-open');
                var pop = open.querySelector('.oa-range-pop');
                if (pop) { pop.hidden = true; }
                var trigger = open.querySelector('.oa-range-trigger');
                if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
            }
            apply({ source: 'submit' });
        });

        // Back / Forward: restore the form from the URL and load those results.
        window.addEventListener('popstate', function () {
            var params = new URLSearchParams(window.location.search);
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name || el.type === 'submit' || el.type === 'button') { return; }
                var value = params.get(el.name);
                if (el.tagName === 'SELECT') {
                    if (value === null) { el.selectedIndex = 0; } else { el.value = value; }
                } else if (el.name === 'from' || el.name === 'to') {
                    if (value !== null) { el.value = value; } else { el.value = el.defaultValue; }
                } else {
                    el.value = value !== null ? value : '';
                }
            });
            searchHistoryOpen = false;
            apply({ fromHistory: true, force: queryFor(form) !== current });
        });

        history.replaceState({ finLive: true }, '', window.location.href);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-fin-live]').forEach(init);
    });
})();
