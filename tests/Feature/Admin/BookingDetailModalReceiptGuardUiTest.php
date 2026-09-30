<?php

use Illuminate\Support\Facades\Process;

it('runs the real booking-detail-modal.js renderInvoice() and hides Correct Invoice once a receipt exists', function () {
    $script = base_path('tests/js/booking-detail-modal-receipt-guard.test.cjs');
    $result = Process::run(['node', $script]);

    expect($result->successful())
        ->toBeTrue("node exited with failures:\n" . $result->output() . $result->errorOutput());
});
