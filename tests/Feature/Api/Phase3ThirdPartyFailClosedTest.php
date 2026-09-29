<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function phase3CustomerRole(): Role
{
    return Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
}

it('26: a Google/geo timeout falls back to a safe estimated route instead of failing the request', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
    });

    $user = User::factory()->create(['role_id' => phase3CustomerRole()->id]);
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/geo/route', [
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'drop_lat' => 14.6091,
        'drop_lng' => 121.0223,
    ]);

    $response->assertOk();
    $response->assertJsonPath('is_fallback', true);
    expect((float) $response->json('distance_km'))->toBeGreaterThan(0);
});

it('27: a malformed geo route response does not become a valid zero-distance route', function () {
    Http::fake([
        '*openrouteservice.org*' => Http::response(['features' => []], 200),
        '*router.project-osrm.org*' => Http::response(['routes' => []], 200),
    ]);

    $user = User::factory()->create(['role_id' => phase3CustomerRole()->id]);
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/geo/route', [
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'drop_lat' => 14.6091,
        'drop_lng' => 121.0223,
    ]);

    $response->assertOk();
    $response->assertJsonPath('is_fallback', true);
    expect((float) $response->json('distance_km'))->toBeGreaterThan(0);
});

it('27b: a 500 from the geo provider does not become a valid zero-distance route', function () {
    Http::fake([
        '*openrouteservice.org*' => Http::response('Internal Server Error', 500),
        '*router.project-osrm.org*' => Http::response('Internal Server Error', 500),
    ]);

    $user = User::factory()->create(['role_id' => phase3CustomerRole()->id]);
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson('/api/v1/geo/route', [
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'drop_lat' => 14.6091,
        'drop_lng' => 121.0223,
    ]);

    $response->assertOk();
    $response->assertJsonPath('is_fallback', true);
    expect((float) $response->json('distance_km'))->toBeGreaterThan(0);
});
