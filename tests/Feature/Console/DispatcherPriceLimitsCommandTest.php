<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\BookingService;

it('only reports and changes nothing when no option is given', function () {
    $this->artisan('towmate:price-limits')
        ->expectsOutputToContain('NOT SET (no limit enforced)')
        ->assertSuccessful();

    expect(SystemSetting::getValue('max_dispatcher_discount_percentage'))->toBeNull()
        ->and(SystemSetting::getValue('max_additional_charge'))->toBeNull();
});

it('sets only the limits it is given and audits the change', function () {
    $this->artisan('towmate:price-limits', ['--max-discount' => '10'])->assertSuccessful();

    expect((float) SystemSetting::getValue('max_dispatcher_discount_percentage'))->toBe(10.0)
        ->and(SystemSetting::getValue('max_additional_charge'))->toBeNull()
        ->and(AuditLog::where('action', 'price_limit_updated')->count())->toBe(1);
});

it('rejects out-of-range values without saving them', function () {
    $this->artisan('towmate:price-limits', ['--max-discount' => '150'])->assertFailed();
    $this->artisan('towmate:price-limits', ['--max-extra-charge' => '-5'])->assertFailed();

    expect(SystemSetting::getValue('max_dispatcher_discount_percentage'))->toBeNull()
        ->and(SystemSetting::getValue('max_additional_charge'))->toBeNull();
});

it('makes BookingService enforce the configured limits and none when unset', function () {
    $service = app(BookingService::class);

    expect($service->checkDispatcherDiscountLimit(50.0, 'x'))->toBeNull()
        ->and($service->checkDispatcherAdditionalChargeLimit(99999.0, 'x'))->toBeNull();

    $this->artisan('towmate:price-limits', ['--max-discount' => '10', '--max-extra-charge' => '500'])->assertSuccessful();

    expect($service->checkDispatcherDiscountLimit(10.0, 'x'))->toBeNull()
        ->and($service->checkDispatcherDiscountLimit(11.0, 'x'))->toContain('exceeds')
        ->and($service->checkDispatcherAdditionalChargeLimit(500.0, 'x'))->toBeNull()
        ->and($service->checkDispatcherAdditionalChargeLimit(501.0, 'x'))->toContain('exceeds');

    $this->artisan('towmate:price-limits', ['--clear' => true])->assertSuccessful();

    expect($service->checkDispatcherDiscountLimit(50.0, 'x'))->toBeNull();
});
