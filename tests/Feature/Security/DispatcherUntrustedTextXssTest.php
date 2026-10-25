<?php

/*
 * Customer-controlled text (a customer chooses their own name and writes inquiry messages) must reach
 * the dispatcher UI as text, never as HTML. The behaviour lives in browser JavaScript, so this runs the
 * Node regression test that loads the real dispatch.js / layout source against a recording DOM stub, and
 * adds source-level guards so the unsafe pattern cannot quietly return.
 */

function dispatcherXssNode(): ?string
{
    foreach (['node', 'nodejs'] as $candidate) {
        $path = trim((string) @shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where ' : 'command -v ') . $candidate . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
        if ($path !== '') {
            return strtok($path, "\r\n") ?: $path;
        }
    }

    return null;
}

it('renders customer names and notification text as text in the dispatcher UI', function () {
    $node = dispatcherXssNode();
    if ($node === null) {
        $this->markTestSkipped('Node.js is not available.');
    }

    $script = base_path('tests/js/dispatcher-untrusted-text-xss.test.cjs');
    $output = [];
    exec('"' . $node . '" "' . $script . '" 2>&1', $output, $exit);

    expect($exit)->toBe(0, implode("\n", $output));
});

it('does not put raw booking values into the View Full Booking panel HTML', function () {
    $js = file_get_contents(public_path('dispatcher/js/dispatch.js'));

    expect($js)
        ->toContain('nameEl.textContent = cbpDecode(customer)')
        ->toContain('phoneEl.textContent = cbpDecode(phone)')
        ->toContain('cbpEscape(b.booking_code)')
        ->toContain('cbpEscape(b.truck_type')
        ->toContain('cbpEscape(sc.t)')
        ->not->toContain("' + customer + '</div>")
        ->not->toContain("' + phone + '</div>")
        ->not->toContain("' + b.booking_code + '</span>")
        ->not->toContain("' + sc.t + '</span>");
});

it('escapes dispatcher notification text before it is written as HTML', function () {
    $layout = file_get_contents(resource_path('views/admin-dashboard/layouts/app.blade.php'));

    expect($layout)
        ->toContain('this.escape(payload.title')
        ->toContain('this.escape(payload.body')
        ->toContain('this.escape(payload.time')
        ->not->toContain('${payload.title ||')
        ->not->toContain('${payload.body ||');
});
