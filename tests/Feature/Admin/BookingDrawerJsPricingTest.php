<?php

use Illuminate\Support\Facades\Process;

it('runs the real booking-drawer.js pricing logic and confirms no double VAT and correct labels', function () {
    $script = base_path('tests/js/booking-drawer-pricing.test.cjs');
    $result = Process::run(['node', $script]);

    expect($result->successful())
        ->toBeTrue("node exited with failures:\n" . $result->output() . $result->errorOutput());
});
