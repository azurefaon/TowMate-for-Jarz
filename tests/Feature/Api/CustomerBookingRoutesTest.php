<?php

// Regression: `Route::apiResource('bookings', BookingController::class)` registered index/show/update/
// destroy routes for a controller that only defines store(), so GET /api/v1/bookings (and friends)
// answered 500. Those routes were removed; the routes the customer app really uses must still work.

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function cbrCustomerUser(): User
{
    Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });

    $user = User::factory()->create(['role_id' => 5, 'status' => 'active']);
    Customer::create([
        'user_id' => $user->id,
        'full_name' => 'CBR Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'cbr-' . uniqid() . '@example.com',
    ]);

    return $user;
}

it('no longer routes the dead bookings resource endpoints to a missing controller method', function () {
    Sanctum::actingAs(cbrCustomerUser(), ['*']);

    foreach ([['getJson', '/api/v1/bookings'], ['getJson', '/api/v1/bookings/TM-0001'], ['putJson', '/api/v1/bookings/TM-0001'], ['patchJson', '/api/v1/bookings/TM-0001'], ['deleteJson', '/api/v1/bookings/TM-0001']] as [$method, $url]) {
        $status = $this->{$method}($url)->getStatusCode();

        expect($status)->toBeIn([404, 405], "{$method} {$url} returned {$status}");
    }
});

it('keeps the booking routes the customer mobile app actually calls', function () {
    Sanctum::actingAs(cbrCustomerUser(), ['*']);

    $this->getJson('/api/v1/bookings/current')->assertOk();
    $this->getJson('/api/v1/bookings/history')->assertOk();
    $this->getJson('/api/v1/bookings/TM-UNKNOWN/detail')->assertStatus(404);
    // create is still routed (an empty body fails validation instead of 404/405/500)
    $this->postJson('/api/v1/bookings', [])->assertStatus(422);
});

it('still requires authentication for the booking routes', function () {
    $this->getJson('/api/v1/bookings/current')->assertUnauthorized();
    // only POST exists on this URL now, so a GET is 405 before auth runs — never a 500
    expect($this->getJson('/api/v1/bookings')->getStatusCode())->toBeIn([401, 404, 405]);
});
