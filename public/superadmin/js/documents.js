// Print PDF: load the stored official PDF (same-origin, authenticated) in a hidden frame and
// open the browser's own PDF print workflow. Falls back to opening the PDF in a new tab.
(function () {
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-print-pdf]');
        if (!button) return;

        var url = button.getAttribute('data-print-pdf');
        var previous = document.getElementById('dv-print-frame');
        if (previous) previous.remove();

        var frame = document.createElement('iframe');
        frame.id = 'dv-print-frame';
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('tabindex', '-1');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none';

        frame.addEventListener('load', function () {
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (error) {
                window.open(url, '_blank', 'noopener');
            }
        });

        frame.src = url;
        document.body.appendChild(frame);
    });
})();
