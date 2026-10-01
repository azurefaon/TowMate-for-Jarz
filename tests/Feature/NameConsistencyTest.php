<?php

use App\Models\Customer;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function ncRoles(): void
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver', 5 => 'Customer', 6 => 'System Admin'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }
}

function ncUser(int $roleId, array $attrs = []): User
{
    ncRoles();

    return User::factory()->create(array_merge(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false], $attrs));
}

function ncCustomerWithProfile(array $names): array
{
    $user = ncUser(5, $names);
    $customer = Customer::create([
        'user_id' => $user->id, 'first_name' => $user->first_name, 'middle_name' => $user->middle_name,
        'last_name' => $user->last_name, 'full_name' => $user->full_name,
        'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'nc-' . uniqid() . '@example.com',
    ]);

    return [$user, $customer];
}

function ncCreatePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
        'email' => 'nc' . uniqid() . '@gmail.com', 'phone' => '091' . random_int(10000000, 99999999),
        'password' => 'Password@123', 'password_confirmation' => 'Password@123', 'role_id' => 2,
    ], $overrides);
}

it('composes display names without double spaces or null', function () {
    expect(build_full_name('Juan', null, 'Cruz'))->toBe('Juan Cruz')
        ->and(build_full_name(' Juan ', ' Santos ', ' Dela Cruz '))->toBe('Juan Santos Dela Cruz')
        ->and(build_full_name('Juan', '', 'Cruz'))->toBe('Juan Cruz');

    $user = ncUser(5, ['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Cruz']);
    expect($user->full_name)->toBe('Juan Cruz')->and($user->name)->toBe('Juan Cruz');

    $withMiddle = ncUser(5, ['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Cruz']);
    expect($withMiddle->full_name)->toBe('Juan Santos Cruz');

    $record = new Personnel(['first_name' => 'Rico', 'middle_name' => null, 'last_name' => 'Santos']);
    expect($record->full_name)->toBe('Rico Santos');
});

it('requires first and last name but not a middle name when a system admin creates a user', function () {
    $admin = ncUser(6);

    $this->actingAs($admin)->post(route('system-admin.users.store'), ncCreatePayload(['first_name' => '']))
        ->assertSessionHasErrors('first_name');
    $this->actingAs($admin)->post(route('system-admin.users.store'), ncCreatePayload(['last_name' => '']))
        ->assertSessionHasErrors('last_name');

    $email = 'nomiddle' . uniqid() . '@gmail.com';
    $this->actingAs($admin)->post(route('system-admin.users.store'), ncCreatePayload(['middle_name' => null, 'email' => $email]))
        ->assertSessionHasNoErrors();

    $created = User::where('email', $email)->first();
    expect($created->middle_name)->toBeNull()->and($created->name)->toBe('Juan Dela Cruz');
});

it('preserves the middle name on edit and clears it to null without touching first or last', function () {
    $admin = ncUser(6);
    $target = ncUser(2, ['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Cruz']);

    $this->actingAs($admin)->putJson(route('system-admin.users.update', $target->id), [
        'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Cruz',
    ])->assertSuccessful();
    expect($target->fresh()->middle_name)->toBe('Santos');

    $this->actingAs($admin)->putJson(route('system-admin.users.update', $target->id), [
        'first_name' => 'Juan', 'middle_name' => '', 'last_name' => 'Cruz',
    ])->assertSuccessful();

    $fresh = $target->fresh();
    expect($fresh->middle_name)->toBeNull()
        ->and($fresh->first_name)->toBe('Juan')
        ->and($fresh->last_name)->toBe('Cruz')
        ->and($fresh->name)->toBe('Juan Cruz');
});

it('shows separate first, middle and last name fields and no full name field on user and profile forms', function () {
    $admin = ncUser(6);
    $target = ncUser(2, ['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Cruz']);

    $pages = [
        $this->actingAs($admin)->get(route('system-admin.users.create'))->assertOk(),
        $this->actingAs($admin)->get(route('system-admin.users.edit', $target->id))->assertOk(),
        $this->actingAs($admin)->get(route('system-admin.profile.edit'))->assertOk(),
        $this->actingAs($target)->get(route('profile.edit'))->assertOk(),
    ];

    foreach ($pages as $response) {
        $response->assertSee('name="first_name"', false)
            ->assertSee('name="middle_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="full_name"', false)
            ->assertDontSee('Full Name');
    }
});

it('stores and edits personnel first, middle and last names', function () {
    $owner = ncUser(1);

    $this->actingAs($owner)->post(route('superadmin.personnel-records.store'), [
        'first_name' => 'Rico', 'middle_name' => 'Dela', 'last_name' => 'Santos', 'role' => 'crew',
    ])->assertSessionHasNoErrors();

    $record = Personnel::where('first_name', 'Rico')->firstOrFail();
    expect($record->middle_name)->toBe('Dela')->and($record->full_name)->toBe('Rico Dela Santos');

    $this->actingAs($owner)->put(route('superadmin.personnel-records.update', $record), [
        'first_name' => 'Rico', 'middle_name' => 'Dela', 'last_name' => 'Reyes', 'role' => 'crew',
    ])->assertSessionHasNoErrors();
    expect($record->fresh()->middle_name)->toBe('Dela')->and($record->fresh()->last_name)->toBe('Reyes');

    $this->actingAs($owner)->put(route('superadmin.personnel-records.update', $record), [
        'first_name' => 'Rico', 'middle_name' => '', 'last_name' => 'Reyes', 'role' => 'crew',
    ])->assertSessionHasNoErrors();
    expect($record->fresh()->middle_name)->toBeNull()->and($record->fresh()->full_name)->toBe('Rico Reyes');
});

it('registers a customer through the api with first, optional middle and last name', function () {
    ncRoles();
    $email = 'ncreg' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $this->postJson('/api/register', [
        'first_name' => 'Maria', 'middle_name' => 'Luz', 'last_name' => 'Reyes',
        'email' => $email, 'phone' => '+639' . random_int(100000000, 999999999),
        'password' => 'ValidPass!2024xy', 'password_confirmation' => 'ValidPass!2024xy', 'accept_terms' => true,
    ])->assertStatus(201);

    $user = User::where('email', $email)->first();
    $customer = Customer::where('user_id', $user->id)->first();
    expect($user->middle_name)->toBe('Luz')->and($user->name)->toBe('Maria Luz Reyes')
        ->and($customer->middle_name)->toBe('Luz')->and($customer->full_name)->toBe('Maria Luz Reyes');

    $noMiddle = 'ncreg' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $noMiddle, true, now()->addMinutes(15));
    $this->postJson('/api/register', [
        'first_name' => 'Ana', 'last_name' => 'Lim',
        'email' => $noMiddle, 'phone' => '+639' . random_int(100000000, 999999999),
        'password' => 'ValidPass!2024xy', 'password_confirmation' => 'ValidPass!2024xy', 'accept_terms' => true,
    ])->assertStatus(201);
    expect(User::where('email', $noMiddle)->first()->middle_name)->toBeNull();
});

it('updates the customer profile name parts independently through the api', function () {
    [$user, $customer] = ncCustomerWithProfile(['first_name' => 'Maria', 'middle_name' => 'Luz', 'last_name' => 'Reyes']);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/profile/update', ['first_name' => 'Mariana', 'last_name' => 'Reyes'])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Mariana')
        ->assertJsonPath('data.middle_name', 'Luz')
        ->assertJsonPath('data.name', 'Mariana Luz Reyes');
    expect($user->fresh()->middle_name)->toBe('Luz');

    $this->postJson('/api/v1/profile/update', ['first_name' => 'Mariana', 'middle_name' => 'Marie', 'last_name' => 'Santos'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Mariana Marie Santos');

    $this->postJson('/api/v1/profile/update', ['first_name' => 'Mariana', 'middle_name' => null, 'last_name' => 'Santos'])
        ->assertOk()
        ->assertJsonPath('data.middle_name', null)
        ->assertJsonPath('data.name', 'Mariana Santos');

    $fresh = $user->fresh();
    expect($fresh->middle_name)->toBeNull()->and($fresh->first_name)->toBe('Mariana')->and($fresh->last_name)->toBe('Santos');
    expect($customer->fresh()->full_name)->toBe('Mariana Santos')->and($customer->fresh()->middle_name)->toBeNull();

    $this->postJson('/api/v1/profile/update', ['first_name' => '', 'last_name' => 'Santos'])->assertStatus(422);
});

it('exposes first, middle and last names with a composed display name on the profile endpoint', function () {
    [$user] = ncCustomerWithProfile(['first_name' => 'Maria', 'middle_name' => 'Luz', 'last_name' => 'Reyes']);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Maria')
        ->assertJsonPath('data.middle_name', 'Luz')
        ->assertJsonPath('data.last_name', 'Reyes')
        ->assertJsonPath('data.name', 'Maria Luz Reyes');
});

it('updates the web profile name parts and syncs the customer record', function () {
    [$user, $customer] = ncCustomerWithProfile(['first_name' => 'Maria', 'middle_name' => 'Luz', 'last_name' => 'Reyes']);

    $this->actingAs($user)->patch('/profile', [
        'first_name' => 'Maria', 'middle_name' => '', 'last_name' => 'Reyes-Lim', 'email' => $user->email,
        'name' => 'Ignored Legacy Name',
    ])->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->middle_name)->toBeNull()->and($fresh->name)->toBe('Maria Reyes-Lim')
        ->and($customer->fresh()->full_name)->toBe('Maria Reyes-Lim');

    $this->actingAs($user)->patch('/profile', ['first_name' => '', 'last_name' => 'X', 'email' => $user->email])
        ->assertSessionHasErrors('first_name');
});

it('registers a web user with canonical split names', function () {
    ncRoles();
    $email = 'ncweb' . uniqid() . '@example.com';

    $this->post('/register', [
        'first_name' => 'Web', 'middle_name' => 'Mid', 'last_name' => 'User',
        'email' => $email, 'password' => 'password-123-ABC', 'password_confirmation' => 'password-123-ABC',
    ])->assertSessionHasNoErrors();

    $user = User::where('email', $email)->first();
    expect($user->first_name)->toBe('Web')->and($user->middle_name)->toBe('Mid')->and($user->name)->toBe('Web Mid User');
});
