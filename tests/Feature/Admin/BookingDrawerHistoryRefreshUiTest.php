<?php

use Illuminate\Support\Facades\Process;

it('runs the real booking-drawer.js history-refresh logic and confirms every lifecycle action refreshes in place', function () {
    $script = base_path('tests/js/booking-drawer-history-refresh.test.cjs');
    $result = Process::run(['node', $script]);

    expect($result->successful())
        ->toBeTrue("node exited with failures:\n" . $result->output() . $result->errorOutput());
});
