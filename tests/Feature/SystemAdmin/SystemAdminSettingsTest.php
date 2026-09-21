<?php

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;

function saSettingsPinRole(int $id, string $name): Role
{
    if ($existing = Role::find($id)) {
        return $existing;
    }

    $role = tap(new Role(['name' => $name]), function ($role) use ($id) {
        $role->id = $id;
        $role->save();
    });

    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql') {
        \Illuminate\Support\Facades\DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }

    return $role;
}

function saSettingsAdmin(): User
{
    saSettingsPinRole(1, 'Owner');
    saSettingsPinRole(6, 'System Admin');

    return User::factory()->create([
        'role_id' => 6,
        'status' => 'active',
        'must_change_password' => false,
    ]);
}

it('renders the system settings page with only technical fields', function () {
    $admin = saSettingsAdmin();

    $html = $this->actingAs($admin)->get(route('system-admin.settings.index'))->getContent();

    expect($html)->toContain('deleted_retention_days');
    expect($html)->toContain('customer_inactivity_lock_days');
    expect($html)->toContain('max_team_leaders');
    expect($html)->not->toContain('name="bank_name"');
    expect($html)->not->toContain('name="gcash_number"');
});

it('validates and persists technical settings', function () {
    $admin = saSettingsAdmin();

    $this->actingAs($admin)->post(route('system-admin.settings.update'), [
        'deleted_retention_days' => 45,
        'customer_inactivity_lock_days' => 120,
        'max_team_leaders' => 15,
    ])->assertRedirect()->assertSessionHas('success');

    expect(SystemSetting::getValue('deleted_retention_days'))->toBe('45');
    expect(SystemSetting::getValue('customer_inactivity_lock_days'))->toBe('120');
    expect(SystemSetting::getValue('max_team_leaders'))->toBe('15');
});

it('rejects invalid technical setting values', function () {
    $admin = saSettingsAdmin();

    $this->actingAs($admin)->post(route('system-admin.settings.update'), [
        'deleted_retention_days' => 0,
        'customer_inactivity_lock_days' => 90,
        'max_team_leaders' => 10,
    ])->assertSessionHasErrors(['deleted_retention_days']);
});

it('never renders environment secrets on the settings page', function () {
    $admin = saSettingsAdmin();

    $html = $this->actingAs($admin)->get(route('system-admin.settings.index'))->getContent();

    expect($html)->not->toContain(config('app.key'));
    expect($html)->not->toContain('DB_PASSWORD');
    expect($html)->not->toContain('MAIL_PASSWORD');
    expect($html)->not->toContain('PAYMONGO');
});

it('uploads a replacement apk with validation intact', function () {
    $admin = saSettingsAdmin();

    $file = UploadedFile::fake()->create('towmate.apk', 500);

    $this->actingAs($admin)->post(route('system-admin.settings.upload-apk'), [
        'apk_file' => $file,
    ])->assertRedirect()->assertSessionHas('apk_success');
});

it('rejects a non-apk file upload', function () {
    $admin = saSettingsAdmin();

    $file = UploadedFile::fake()->create('not-an-apk.txt', 10);

    $this->actingAs($admin)->post(route('system-admin.settings.upload-apk'), [
        'apk_file' => $file,
    ])->assertSessionHasErrors(['apk_file']);
});

it('does not expose business pricing, discount, or payment policy fields', function () {
    $admin = saSettingsAdmin();

    $html = $this->actingAs($admin)->get(route('system-admin.settings.index'))->getContent();

    expect($html)->not->toContain('name="bank_account_number"');
    expect($html)->not->toContain('name="gcash_name"');
    expect($html)->not->toContain('discount_percentage');
    expect($html)->not->toContain('max_dispatcher_discount_percentage');
    expect($html)->not->toContain('max_additional_charge');
    expect($html)->not->toContain('Price Adjustment Settings');
    expect($html)->not->toContain('Additional Charge Settings');
});

it('forbids owner from the system admin technical settings routes', function () {
    $owner = User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($owner)
        ->get(route('system-admin.settings.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('system-admin.settings.update'), [
            'deleted_retention_days' => 30,
            'customer_inactivity_lock_days' => 90,
            'max_team_leaders' => 10,
        ])
        ->assertForbidden();
});
