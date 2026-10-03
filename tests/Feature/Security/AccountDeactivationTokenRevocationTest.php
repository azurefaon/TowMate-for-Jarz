<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * H2 — a deactivated / archived / pending-deletion account must lose API
 * access immediately: its Sanctum tokens are revoked, and every protected
 * API request re-checks the account's current state.
 */

function h2Role(int $id, string $name): Role
{
    if ($existing = Role::find($id)) {
        return $existing;
    }

    $role = tap(new Role(['name' => $name]), function ($role) use ($id) {
        $role->id = $id;
        $role->save();
    });

    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), GREATEST((SELECT MAX(id) FROM roles), 1))");
    }

    return $role;
}

function h2Roles(): void
{
    h2Role(1, 'Owner');
    h2Role(2, 'Admin');
    h2Role(3, 'Team Leader');
    h2Role(4, 'Driver');
    h2Role(5, 'Customer');
    h2Role(6, 'System Admin');
}

function h2Admin(): User
{
    h2Roles();

    return User::factory()->create(['role_id' => 6, 'status' => 'active', 'must_change_password' => false]);
}

function h2Customer(): array
{
    h2Roles();
    $user = User::factory()->create(['role_id' => 5, 'status' => 'active']);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => $user->name,
        'phone'     => '+63917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
    ]);

    return [$user, $customer];
}

function h2TeamLeader(): array
{
    h2Roles();
    $tl = User::factory()->create(['role_id' => 3, 'status' => 'active', 'must_change_password' => false]);
    $truck = TruckType::create([
        'name' => 'H2 Truck ' . fake()->unique()->numerify('###'),
        'base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active',
    ]);
    $unit = Unit::create([
        'name' => 'H2 Unit ' . fake()->unique()->numerify('###'),
        'plate_number' => fake()->unique()->bothify('H2-####'),
        'truck_type_id' => $truck->id,
        'team_leader_id' => $tl->id,
        'status' => 'available',
    ]);

    return [$tl, $unit, $truck];
}

function h2Booking(Customer $customer, TruckType $truck, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'H2 Pickup, Quezon City',
        'pickup_lat' => 14.676, 'pickup_lng' => 121.043,
        'dropoff_address' => 'H2 Dropoff, Makati City',
        'dropoff_lat' => 14.554, 'dropoff_lng' => 121.024,
        'distance_km' => 12, 'base_rate' => 1500, 'per_km_rate' => 60,
        'final_total' => 2217.60,
        'status' => 'completed',
        'service_type' => 'book_now',
    ], $overrides))->fresh();
}

/**
 * Each API call starts from a clean auth state: Sanctum checks the web guard
 * before tokens and guards are cached per app, so a preceding actingAs($admin)
 * or token request would otherwise leak into this one.
 */
function h2Api(string $token, string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
        ->json($method, $uri, $data);
}

function h2AsAdmin(User $admin)
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => ''])->actingAs($admin);
}

function h2Deactivate(User $admin, User $user): void
{
    h2AsAdmin($admin)->patch(route('system-admin.users.toggle', $user->id))->assertRedirect();
    expect($user->fresh()->status)->toBe('inactive');
}

function h2Archive(User $admin, User $user): void
{
    h2AsAdmin($admin)
        ->patch(route('system-admin.users.archive', $user->id), ['reason' => 'H2 test'])
        ->assertRedirect(route('system-admin.users.index'));
    expect($user->fresh()->archived_at)->not->toBeNull();
}

function h2Login(string $email, string $password = 'password')
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => ''])->postJson('/api/login', ['email' => $email, 'password' => $password]);
}

// 1-3, 9
it('revokes a customer token when the account is deactivated', function () {
    $admin = h2Admin();
    [$user] = h2Customer();
    $token = $user->createToken('mobile')->plainTextToken;

    h2Api($token, 'GET', '/api/v1/bookings/history')->assertOk();
    h2Api($token, 'GET', '/api/user')->assertOk();

    h2Deactivate($admin, $user);

    expect($user->tokens()->count())->toBe(0);
    h2Api($token, 'GET', '/api/v1/bookings/history')->assertUnauthorized();
    h2Api($token, 'GET', '/api/user')->assertUnauthorized();
    h2Api($token, 'GET', '/api/v1/profile')->assertUnauthorized();
});

// 4
it('revokes a customer token when the account is archived', function () {
    $admin = h2Admin();
    [$user] = h2Customer();
    $token = $user->createToken('mobile')->plainTextToken;

    h2Api($token, 'GET', '/api/v1/bookings/history')->assertOk();

    h2Archive($admin, $user);

    expect($user->tokens()->count())->toBe(0);
    h2Api($token, 'GET', '/api/v1/bookings/history')->assertUnauthorized();
});

it('revokes tokens when an account is queued for deletion', function () {
    $admin = h2Admin();
    [$user] = h2Customer();
    $token = $user->createToken('mobile')->plainTextToken;

    h2AsAdmin($admin)
        ->delete(route('system-admin.users.queue-for-deletion', $user->id), ['reason' => 'H2 test'])
        ->assertRedirect();

    h2Api($token, 'GET', '/api/v1/profile')->assertUnauthorized();
});

// 5-7
it('cuts off every Team Leader endpoint for the same token once deactivated', function () {
    $admin = h2Admin();
    [$tl, $unit, $truck] = h2TeamLeader();
    [, $customer] = h2Customer();
    $booking = h2Booking($customer, $truck, ['status' => 'on_the_way', 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $tl->id]);
    $token = $tl->createToken('mobile')->plainTextToken;

    h2Api($token, 'GET', '/api/v1/team-leader/task')->assertOk();
    h2Api($token, 'PUT', '/api/v1/team-leader/location', ['lat' => 14.6, 'lng' => 121.0])->assertOk();

    h2Deactivate($admin, $tl);

    expect($tl->tokens()->count())->toBe(0);
    h2Api($token, 'GET', '/api/v1/team-leader/task')->assertUnauthorized();
    h2Api($token, 'PUT', '/api/v1/team-leader/location', ['lat' => 14.6, 'lng' => 121.0])->assertUnauthorized();
    h2Api($token, 'POST', "/api/v1/team-leader/task/{$booking->id}/photo", [
        'photo' => UploadedFile::fake()->image('p.jpg'), 'type' => 'arrival',
    ])->assertUnauthorized();
    h2Api($token, 'POST', "/api/v1/team-leader/task/{$booking->id}/complete", [
        'signature' => UploadedFile::fake()->image('s.png'), 'payment_method' => 'gcash',
    ])->assertUnauthorized();

    // The booking/assignment itself is untouched by the fix.
    expect($booking->fresh()->status)->toBe('on_the_way');
    expect((int) $booking->fresh()->assigned_team_leader_id)->toBe($tl->id);
});

// 8
it('revokes a Team Leader token when the account is archived', function () {
    $admin = h2Admin();
    [$tl] = h2TeamLeader();
    $token = $tl->createToken('mobile')->plainTextToken;

    h2Api($token, 'GET', '/api/v1/team-leader/task')->assertOk();

    h2Archive($admin, $tl);

    expect($tl->tokens()->count())->toBe(0);
    h2Api($token, 'GET', '/api/v1/team-leader/task')->assertUnauthorized();
});

// 10
it('denies a stale token left behind for a disabled account and discards it', function (array $state) {
    [$user] = h2Customer();
    $token = $user->createToken('mobile')->plainTextToken;

    // Query-builder update: bypasses model events, simulating a missed
    // revocation path. The token row is still there.
    User::whereKey($user->id)->update($state);
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(1);

    $response = h2Api($token, 'GET', '/api/v1/profile');

    $response->assertUnauthorized();
    $response->assertJson(['success' => false, 'message' => 'Account is inactive. Please contact support.']);
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
})->with([
    'inactive'         => [['status' => 'inactive']],
    'auto-locked'      => [['status' => 'locked']],
    'archived'         => [['archived_at' => now()]],
    'pending deletion' => [['pending_delete_at' => now()]],
    'anonymized'       => [['anonymized_at' => now()]],
]);

it('returns the same generic response without archive details', function () {
    $admin = h2Admin();
    [$user] = h2Customer();
    $token = $user->createToken('mobile')->plainTextToken;
    User::whereKey($user->id)->update(['archived_at' => now(), 'archived_reason' => 'Secret admin reason']);

    $body = h2Api($token, 'GET', '/api/v1/profile')->assertUnauthorized()->getContent();

    expect($body)->not->toContain('Secret admin reason');
    expect($body)->not->toContain('archiv');
    expect($body)->not->toContain('delet');
});

it('does not treat the temporary login lockout as a disabled account', function () {
    [$user] = h2Customer();
    $token = $user->createToken('mobile')->plainTextToken;
    $user->update(['locked_until' => now()->addMinutes(15), 'failed_login_attempts' => 5]);

    h2Api($token, 'GET', '/api/v1/profile')->assertOk();
});

// 11-12
it('does not revive the old token on reactivation, but a fresh login works', function () {
    $admin = h2Admin();
    [$user] = h2Customer();

    $tokenA = h2Login($user->email)->assertOk()->json('data.token');
    h2Api($tokenA, 'GET', '/api/v1/profile')->assertOk();

    h2Deactivate($admin, $user);
    h2Api($tokenA, 'GET', '/api/v1/profile')->assertUnauthorized();

    h2AsAdmin($admin)->patch(route('system-admin.users.toggle', $user->id))->assertRedirect();
    expect($user->fresh()->status)->toBe('active');

    h2Api($tokenA, 'GET', '/api/v1/profile')->assertUnauthorized();

    $tokenB = h2Login($user->email)->assertOk()->json('data.token');
    expect($tokenB)->not->toBe($tokenA);
    h2Api($tokenB, 'GET', '/api/v1/profile')->assertOk();
});

it('does not revive an archived Team Leader token on restore', function () {
    $admin = h2Admin();
    [$tl] = h2TeamLeader();
    $tokenA = $tl->createToken('mobile')->plainTextToken;

    h2Archive($admin, $tl);
    h2AsAdmin($admin)->patch(route('system-admin.users.restore', $tl->id))->assertRedirect();
    expect($tl->fresh()->archived_at)->toBeNull();

    h2Api($tokenA, 'GET', '/api/v1/team-leader/task')->assertUnauthorized();

    $tokenB = h2Login($tl->email)->assertOk()->json('data.token');
    h2Api($tokenB, 'GET', '/api/v1/team-leader/task')->assertOk();
});

// 13
it('refuses a new token at login for disabled accounts', function (array $state) {
    [$user] = h2Customer();
    User::whereKey($user->id)->update($state);

    h2Login($user->email)->assertStatus(403)->assertJsonMissingPath('data.token');
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(0);
})->with([
    'inactive'         => [['status' => 'inactive']],
    'archived'         => [['status' => 'inactive', 'archived_at' => now()]],
    'pending deletion' => [['pending_delete_at' => now()]],
    'anonymized'       => [['anonymized_at' => now()]],
]);

it('keeps the existing inactivity-lock login message', function () {
    [$user] = h2Customer();
    User::whereKey($user->id)->update(['status' => 'locked']);

    h2Login($user->email)->assertStatus(423)->assertJsonMissingPath('data.token');
});

// 14
it("leaves other active users' tokens working", function () {
    $admin = h2Admin();
    [$target] = h2Customer();
    [$bystander] = h2Customer();
    [$tl] = h2TeamLeader();
    $targetToken = $target->createToken('mobile')->plainTextToken;
    $bystanderToken = $bystander->createToken('mobile')->plainTextToken;
    $tlToken = $tl->createToken('mobile')->plainTextToken;

    h2Deactivate($admin, $target);

    h2Api($targetToken, 'GET', '/api/v1/profile')->assertUnauthorized();
    h2Api($bystanderToken, 'GET', '/api/v1/profile')->assertOk();
    h2Api($tlToken, 'GET', '/api/v1/team-leader/task')->assertOk();
    expect($bystander->tokens()->count())->toBe(1);
    expect($tl->tokens()->count())->toBe(1);
});

// 15
it('deletes no bookings, assignments, invoices, receipts, audit history or the user record', function () {
    $admin = h2Admin();
    [$user, $customer] = h2Customer();
    [$tl, $unit, $truck] = h2TeamLeader();
    $booking = h2Booking($customer, $truck, ['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $tl->id]);
    Invoice::create(['booking_id' => $booking->id, 'subtotal' => 1980, 'total' => 2217.60, 'status' => 'issued', 'is_current' => true]);
    Receipt::create(['booking_id' => $booking->id, 'generated_by' => $tl->id, 'receipt_number' => 'R-H2-0001']);
    $user->createToken('mobile');
    $tl->createToken('mobile');
    $auditBefore = AuditLog::count();

    h2Deactivate($admin, $user);
    h2Archive($admin, $tl);

    expect(User::find($user->id))->not->toBeNull();
    expect(User::find($tl->id))->not->toBeNull();
    expect(Customer::find($customer->id))->not->toBeNull();
    $fresh = $booking->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBe('completed');
    expect((int) $fresh->assigned_team_leader_id)->toBe($tl->id);
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
    expect(Receipt::where('booking_id', $booking->id)->count())->toBe(1);
    // Only the two admin actions' own audit entries were added; nothing removed.
    expect(AuditLog::count())->toBeGreaterThanOrEqual($auditBefore + 2);
    expect(AuditLog::where('action', 'user_status_toggled')->where('entity_id', $user->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'user_archived')->where('entity_id', $tl->id)->count())->toBe(1);
});

it('never writes token values into the audit log', function () {
    $admin = h2Admin();
    [$user] = h2Customer();
    $plain = $user->createToken('mobile')->plainTextToken;

    h2Deactivate($admin, $user);
    h2Api($plain, 'GET', '/api/v1/profile');

    $secret = explode('|', $plain, 2)[1];
    foreach (AuditLog::all() as $log) {
        expect((string) $log->description . json_encode($log->old_value) . json_encode($log->new_value))->not->toContain($secret);
    }
});
