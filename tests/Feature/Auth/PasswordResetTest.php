<?php

use App\Models\Role;
use App\Models\User;

function seedManagedRoles(): void
{
    Role::firstOrCreate(['id' => 1], ['name' => 'Super Admin']);
    Role::firstOrCreate(['id' => 2], ['name' => 'Dispatcher']);
    Role::firstOrCreate(['id' => 3], ['name' => 'Team Leader']);
}

test('forgot password request screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

// Password recovery is now self-service only via the OTP flow
// (StaffPasswordResetController — see StaffPasswordResetTest.php). The old
// manual "account access request" workflow (Owner reviewing/approving a
// request, setDefaultPassword(), resolvePasswordRequest()) has been fully
// retired: no route can submit one, and the Owner's Users index no longer
// shows an "Account Access Requests" panel or any per-row pending badge.
// The historical users.password_request_* columns are left untouched as
// legacy data — nothing reads or writes them in the live application anymore.

test('no route exists to submit a manual account access request anymore', function () {
    expect(\Illuminate\Support\Facades\Route::has('superadmin.users.password-request.set-password'))->toBeFalse();
    expect(\Illuminate\Support\Facades\Route::has('superadmin.users.password-request.resolve'))->toBeFalse();
});

test('the Users index no longer shows the Account Access Requests panel', function () {
    seedManagedRoles();

    $superAdmin = User::factory()->create(['role_id' => 1]);

    // A historical pending row is preserved data, not something the UI acts on anymore.
    User::factory()->create([
        'role_id' => 2,
        'password_request_status' => 'pending',
        'password_requested_at' => now(),
        'password_request_note' => 'Historical, pre-cleanup request.',
    ]);

    $this->actingAs($superAdmin)
        ->get(route('superadmin.users.index'))
        ->assertOk()
        ->assertDontSeeText('Account Access Requests')
        ->assertDontSeeText('Password request pending');
});
