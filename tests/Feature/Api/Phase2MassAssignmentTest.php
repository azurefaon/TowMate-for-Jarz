<?php

use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// 26 — customer cannot modify role/status/security-state fields ─────────────
//
// User::$fillable intentionally still includes role_id/status/archived_at/
// anonymized_at/pending_delete_at (see Phase 2 report — removing them would
// require converting ~15 legitimate internal SuperAdmin\UserManagementController
// call sites to forceFill(), a broad refactor out of this phase's scope).
// Defense in depth instead: prove that the only customer-facing endpoint
// that mutates a User row (profile update) only ever writes its own
// explicitly whitelisted fields, so mass-assignability is never reachable
// from outside.
it('26: a customer cannot modify role_id/status/archived_at/anonymized_at/pending_delete_at via their own profile update', function () {
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
    $user = User::factory()->create(['role_id' => $role->id, 'status' => 'active']);
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/profile/update', [
        'first_name' => 'Still',
        'last_name'  => 'Customer',
        'role_id'         => 1,
        'status'          => 'inactive',
        'archived_at'     => now()->toDateTimeString(),
        'anonymized_at'   => now()->toDateTimeString(),
        'pending_delete_at' => now()->toDateTimeString(),
    ])->assertOk();

    $fresh = $user->fresh();
    expect((int) $fresh->role_id)->toBe($role->id);
    expect($fresh->status)->toBe('active');
    expect($fresh->archived_at)->toBeNull();
    expect($fresh->anonymized_at)->toBeNull();
    expect($fresh->pending_delete_at)->toBeNull();
});

it('26b: a customer cannot escalate role_id/status through the registration endpoint', function () {
    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
    $ownerRole = Role::find(1) ?: tap(new Role(['name' => 'Super Admin']), function ($r) {
        $r->id = 1;
        $r->save();
    });

    $email = 'mass-assign-reg@example.com';
    \Illuminate\Support\Facades\Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $this->postJson('/api/register', [
        'first_name' => 'Sneaky',
        'last_name'  => 'Attacker',
        'email'      => $email,
        'phone'      => '09171234599',
        'password'   => 'Xk7!TowMateSecure92',
        'password_confirmation' => 'Xk7!TowMateSecure92',
        'role_id'    => $ownerRole->id,
        'status'     => 'active',
    ])->assertCreated();

    $created = User::where('email', $email)->first();
    expect((int) $created->role_id)->toBe($role->id);
});
