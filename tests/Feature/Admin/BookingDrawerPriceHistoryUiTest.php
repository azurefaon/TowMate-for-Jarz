<?php

use Illuminate\Support\Facades\Process;

it('runs the real booking-drawer.js price-history UI logic and confirms the dynamic count and labels', function () {
    $script = base_path('tests/js/booking-drawer-price-history.test.cjs');
    $result = Process::run(['node', $script]);

    expect($result->successful())
        ->toBeTrue("node exited with failures:\n" . $result->output() . $result->errorOutput());
});
