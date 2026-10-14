/*
 * Owner analytics pages: the date-range popover (From / To / Apply) used by
 * Revenue, Reports and Business Activity. Everything else on these pages is
 * plain server-rendered HTML and the shared custom dropdowns in components.js.
 */
(function () {
    function close(wrap) {
        var pop = wrap.querySelector('.oa-range-pop');
        var trigger = wrap.querySelector('.oa-range-trigger');
        wrap.classList.remove('is-open');
        if (pop) { pop.hidden = true; }
        if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
    }

    function open(wrap) {
        document.querySelectorAll('[data-oa-range].is-open').forEach(function (other) {
            if (other !== wrap) { close(other); }
        });
        var pop = wrap.querySelector('.oa-range-pop');
        var trigger = wrap.querySelector('.oa-range-trigger');
        wrap.classList.add('is-open');
        if (pop) { pop.hidden = false; }
        if (trigger) { trigger.setAttribute('aria-expanded', 'true'); }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.oa-range-trigger');

        if (trigger) {
            var wrap = trigger.closest('[data-oa-range]');
            if (wrap.classList.contains('is-open')) { close(wrap); } else { open(wrap); }
            return;
        }

        document.querySelectorAll('[data-oa-range].is-open').forEach(function (wrap) {
            if (!wrap.contains(event.target)) { close(wrap); }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('[data-oa-range].is-open').forEach(close);
        }
    });

    // A reversed range cannot be applied; the server would silently fall back to the default period.
    document.addEventListener('submit', function (event) {
        var wrap = event.target.querySelector('[data-oa-range]');
        if (!wrap) { return; }

        var from = wrap.querySelector('input[name="from"]');
        var to = wrap.querySelector('input[name="to"]');

        if (from && to && from.value && to.value && from.value > to.value) {
            event.preventDefault();
            to.setCustomValidity('The end date must be on or after the start date.');
            to.reportValidity();
            to.addEventListener('input', function () { to.setCustomValidity(''); }, { once: true });
        }
    });
})();
