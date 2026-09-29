<?php

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\BookingService;
use App\Services\DocumentGenerationService;

function opdsRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function opdsOwner(): User
{
    opdsRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function opdsDispatcher(): User
{
    opdsRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

it('1: Payment Details fields render with their real field names', function () {
    $response = test()->actingAs(opdsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Payment Details');
    $response->assertSee('name="settings[bank_name]"', false);
    $response->assertSee('name="settings[bank_account_name]"', false);
    $response->assertSee('name="settings[bank_account_number]"', false);
    $response->assertSee('name="settings[gcash_name]"', false);
    $response->assertSee('name="settings[gcash_number]"', false);
    $response->assertSee('name="settings[payment_terms]"', false);
});

it('2: existing configured payment values populate the form', function () {
    SystemSetting::setValue('bank_name', 'BPI');
    SystemSetting::setValue('bank_account_name', 'JARZ Towing Services Inc.');
    SystemSetting::setValue('bank_account_number', '1234-5678-90');
    SystemSetting::setValue('gcash_name', 'JARZ Towing');
    SystemSetting::setValue('gcash_number', '09171234567');

    $response = test()->actingAs(opdsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('value="BPI"', false);
    $response->assertSee('value="JARZ Towing Services Inc."', false);
    $response->assertSee('value="1234-5678-90"', false);
    $response->assertSee('value="JARZ Towing"', false);
    $response->assertSee('value="09171234567"', false);
});

it('3: Owner can update Payment Details', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'bank_name' => 'Metrobank',
            'bank_account_name' => 'JARZ Towing Services',
            'bank_account_number' => '9876543210',
            'gcash_name' => 'JARZ Towing',
            'gcash_number' => '09991234567',
            'payment_terms' => 'Due upon receipt',
        ],
    ])->assertRedirect();

    expect(SystemSetting::getValue('bank_name'))->toBe('Metrobank')
        ->and(SystemSetting::getValue('bank_account_name'))->toBe('JARZ Towing Services')
        ->and(SystemSetting::getValue('bank_account_number'))->toBe('9876543210')
        ->and(SystemSetting::getValue('gcash_name'))->toBe('JARZ Towing')
        ->and(SystemSetting::getValue('gcash_number'))->toBe('09991234567')
        ->and(SystemSetting::getValue('payment_terms'))->toBe('Due upon receipt');
});

it('4: Discount Settings fields render with their real field names', function () {
    $response = test()->actingAs(opdsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Discount Settings');
    $response->assertSee('name="settings[discount_percentage]"', false);
    $response->assertSee('name="settings[discount_reason]"', false);
});

it('5: existing configured discount values populate the form', function () {
    SystemSetting::setValue('discount_percentage', '20');
    SystemSetting::setValue('discount_reason', 'PWD/Senior Citizen discount');

    $response = test()->actingAs(opdsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('value="20"', false);
    $response->assertSee('value="PWD/Senior Citizen discount"', false);
});

it('6: Owner can update Discount Settings', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'discount_percentage' => '15',
            'discount_reason' => 'Statutory discount',
        ],
    ])->assertRedirect();

    expect(SystemSetting::getValue('discount_percentage'))->toBe('15')
        ->and(SystemSetting::getValue('discount_reason'))->toBe('Statutory discount');
});

it('7: dispatcher pricing limit settings render with their real field names', function () {
    $response = test()->actingAs(opdsOwner())->get(route('superadmin.settings.index'));

    $response->assertOk();
    $response->assertSee('Price Adjustment Settings');
    $response->assertSee('name="settings[dispatcher_discount_enabled]"', false);
    $response->assertSee('name="settings[max_dispatcher_discount_percentage]"', false);
    $response->assertSee('name="settings[dispatcher_discount_require_reason]"', false);
    $response->assertSee('name="settings[price_adjustment_form]"', false);

    $response->assertSee('Additional Charge Settings');
    $response->assertSee('name="settings[max_additional_charge]"', false);
    $response->assertSee('name="settings[additional_charge_require_reason]"', false);
    $response->assertSee('name="settings[additional_charge_form]"', false);
});

it('8: Owner can update dispatcher pricing limit settings', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'price_adjustment_form' => '1',
            'max_dispatcher_discount_percentage' => '12',
            'dispatcher_discount_enabled' => '1',
            'dispatcher_discount_require_reason' => '1',
        ],
    ])->assertRedirect();

    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'additional_charge_form' => '1',
            'max_additional_charge' => '750',
            'additional_charge_require_reason' => '1',
        ],
    ])->assertRedirect();

    expect(SystemSetting::getValue('max_dispatcher_discount_percentage'))->toBe('12')
        ->and(SystemSetting::getValue('dispatcher_discount_enabled'))->toBe('1')
        ->and(SystemSetting::getValue('dispatcher_discount_require_reason'))->toBe('1')
        ->and(SystemSetting::getValue('max_additional_charge'))->toBe('750')
        ->and(SystemSetting::getValue('additional_charge_require_reason'))->toBe('1');
});

it('9: an invalid discount percentage is rejected', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => ['discount_percentage' => '150'],
    ])->assertSessionHasErrors(['settings.discount_percentage']);

    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'price_adjustment_form' => '1',
            'max_dispatcher_discount_percentage' => '-5',
        ],
    ])->assertSessionHasErrors(['settings.max_dispatcher_discount_percentage']);
});

it('10: an invalid monetary limit is rejected', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'additional_charge_form' => '1',
            'max_additional_charge' => '-100',
        ],
    ])->assertSessionHasErrors(['settings.max_additional_charge']);
});

it('11: a dispatcher cannot update these settings', function () {
    $this->actingAs(opdsDispatcher())->post(route('superadmin.settings.update'), [
        'settings' => ['bank_name' => 'Should Not Save'],
    ])->assertForbidden();

    expect(SystemSetting::getValue('bank_name'))->not->toBe('Should Not Save');
});

it('12: saved settings are actually consumed by DocumentGenerationService and BookingService', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'bank_name' => 'Union Bank',
            'gcash_number' => '09170001111',
        ],
    ])->assertRedirect();

    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => [
            'price_adjustment_form' => '1',
            'dispatcher_discount_enabled' => '1',
            'max_dispatcher_discount_percentage' => '8',
        ],
    ])->assertRedirect();

    $documentSettings = app(DocumentGenerationService::class)->documentSettings();
    expect($documentSettings['bank_name'])->toBe('Union Bank')
        ->and($documentSettings['gcash_number'])->toBe('09170001111');

    $limitError = app(BookingService::class)->checkDispatcherDiscountLimit(10.0, null);
    expect($limitError)->not->toBeNull()
        ->and($limitError)->toContain('8.00%');
});

it('13: no duplicate SystemSetting rows are created when a value is saved repeatedly', function () {
    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => ['bank_name' => 'First Save'],
    ])->assertRedirect();

    $this->actingAs(opdsOwner())->post(route('superadmin.settings.update'), [
        'settings' => ['bank_name' => 'Second Save'],
    ])->assertRedirect();

    expect(SystemSetting::where('key', 'bank_name')->count())->toBe(1)
        ->and(SystemSetting::getValue('bank_name'))->toBe('Second Save');
});
