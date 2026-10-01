<?php

use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

function ercRoles(): void
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver', 5 => 'Customer'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }
}

function ercCustomerUser(): array
{
    ercRoles();
    $user = User::factory()->create(['role_id' => 5, 'status' => 'active']);
    $customer = Customer::create(['full_name' => 'Erc Customer', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'erc-' . uniqid() . '@example.com', 'user_id' => $user->id]);

    return [$user, $customer];
}

function ercQuotation(Customer $customer): Quotation
{
    return Quotation::create([
        'quotation_number' => 'Q-ERC-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => TruckType::create(['name' => 'Erc Truck ' . uniqid(), 'base_rate' => 1000, 'per_km_rate' => 50])->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 3,
        'estimated_price' => 1500,
        'status' => 'sent',
        'sent_at' => now(),
        'is_current' => true,
    ]);
}

it('returns a safe 404 for a missing or foreign notification and leaves it untouched', function () {
    [$user] = ercCustomerUser();
    [$other] = ercCustomerUser();
    $foreign = CustomerNotification::create([
        'user_id' => $other->id, 'type' => 'booking_update', 'title' => 'T', 'body' => 'B', 'is_read' => false,
    ]);
    Sanctum::actingAs($user);

    $foreignResponse = $this->postJson("/api/v1/notifications/{$foreign->id}/read");
    $missingResponse = $this->postJson('/api/v1/notifications/99999999/read');

    foreach ([$foreignResponse, $missingResponse] as $response) {
        $response->assertStatus(404)->assertJson(['success' => false, 'message' => 'Notification not found.']);
    }
    expect($foreign->fresh()->is_read)->toBeFalsy();
});

it('still marks the owners own notification as read', function () {
    [$user] = ercCustomerUser();
    $own = CustomerNotification::create(['user_id' => $user->id, 'type' => 'booking_update', 'title' => 'T', 'body' => 'B', 'is_read' => false]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/notifications/{$own->id}/read")->assertOk()->assertJson(['success' => true]);

    expect($own->fresh()->is_read)->toBeTruthy();
});

it('keeps quotation authorization enforced with a safe consistent message', function () {
    [$user] = ercCustomerUser();
    [, $otherCustomer] = ercCustomerUser();
    $quotation = ercQuotation($otherCustomer);
    Sanctum::actingAs($user);

    foreach (['accept', 'reject'] as $action) {
        $response = $this->postJson("/api/v1/quotations/{$quotation->id}/{$action}", ['reason' => 'no']);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have access to this quotation.',
        ]);
        expect($response->json('message'))->not->toBe('Forbidden.');
    }

    expect($quotation->fresh()->status)->toBe('sent');
});

it('uses one stable must-change-password body for api and web json responses', function () {
    ercRoles();
    $leader = User::factory()->create(['role_id' => 3, 'status' => 'active', 'must_change_password' => true]);
    Sanctum::actingAs($leader);

    $api = $this->getJson('/api/v1/team-leader/task');

    $api->assertStatus(403)->assertJson([
        'success' => false,
        'message' => 'You must change your password before continuing.',
        'must_change_password' => true,
    ]);

    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => true]);
    $web = $this->actingAs($dispatcher)->getJson(route('admin.dashboard'));

    $web->assertStatus(403)->assertJson([
        'success' => false,
        'message' => 'You must change your password before continuing.',
        'must_change_password' => true,
    ])->assertJsonStructure(['redirect']);
});

it('hides framework default text in json errors but keeps intentional business messages', function () {
    Route::get('/api/__erc/forbidden', fn () => abort(403));
    Route::get('/api/__erc/missing', fn () => abort(404));
    Route::get('/api/__erc/conflict', fn () => abort(409, 'This task was already accepted.'));
    Route::get('/api/__erc/model', fn () => Customer::findOrFail(99999999));

    $this->getJson('/api/__erc/forbidden')->assertStatus(403)->assertJson(['message' => "You don't have permission to do this."]);
    $this->getJson('/api/__erc/missing')->assertStatus(404)->assertJson(['message' => "We couldn't find what you were looking for."]);
    $this->getJson('/api/__erc/model')->assertStatus(404)->assertJson(['message' => "We couldn't find what you were looking for."]);
    $this->getJson('/api/__erc/conflict')->assertStatus(409)->assertJson(['message' => 'This task was already accepted.']);
});

it('keeps rate limiting in place and returns a user-safe 429 with retry guidance', function () {
    Route::get('/api/__erc/limited', fn () => response()->json(['ok' => true]))->middleware('throttle:1,1');

    $this->getJson('/api/__erc/limited')->assertOk();
    $limited = $this->getJson('/api/__erc/limited');

    $limited->assertStatus(429)->assertJson(['message' => 'Too many attempts. Please wait a moment and try again.']);
    expect($limited->headers->has('Retry-After'))->toBeTrue();
    expect($limited->json('message'))->not->toContain('Too Many Requests');
});

it('no longer contains the mojibake plate separator in the team leader task controller', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/TeamLeader/TLTaskController.php'));

    expect($source)->not->toContain('Â·')->and($source)->toContain("'· '");
});
