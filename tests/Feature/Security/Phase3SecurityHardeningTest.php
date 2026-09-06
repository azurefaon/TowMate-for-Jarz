<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function phase3TlRole(): Role
{
    return Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });
}

function phase3TlWithBooking(): array
{
    $tl = User::factory()->create(['role_id' => phase3TlRole()->id, 'must_change_password' => false]);
    $truckType = TruckType::create(['name' => 'P3 Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'P3 Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $tl->id,
        'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'P3 Customer', 'phone' => '09170000002', 'email' => 'p3-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'accepted', 'assigned_at' => now(),
    ]);

    return [$tl, $booking->fresh()];
}

it('1: a normal web response includes X-Content-Type-Options nosniff', function () {
    $response = test()->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('2: a normal web response includes a Referrer-Policy', function () {
    $response = test()->get('/login');

    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('3: frame protection is present via X-Frame-Options and CSP frame-ancestors', function () {
    $response = test()->get('/login');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
});

it('4: a Content-Security-Policy header exists and restricts default-src to self', function () {
    $response = test()->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("default-src 'self'");
});

it('5: HSTS is not sent over a non-HTTPS request', function () {
    $response = test()->get('/login');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('6: an unauthorized browser origin does not receive CORS allow-origin headers', function () {
    $response = test()->getJson('/api/v1/customer/content', ['Origin' => 'https://evil-example.com']);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('8: CORS does not enable credentials support', function () {
    expect(config('cors.supports_credentials'))->toBeFalse();
});

it('9: an unauthenticated request to a protected file URL without a valid signature is rejected', function () {
    Storage::disk('local')->put('task-photos/secret.jpg', 'fake-image-bytes');

    $response = test()->get('/protected-storage/task-photos/secret.jpg');

    $response->assertStatus(403);

    Storage::disk('local')->delete('task-photos/secret.jpg');
});

it('15: uploaded task photos are no longer exposed via the raw public /storage URL', function () {
    [$tl, $booking] = phase3TlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    $response = test()->postJson("/api/v1/team-leader/task/{$booking->booking_code}/photo", [
        'photo' => UploadedFile::fake()->image('arrival.jpg'),
        'type' => 'arrival',
    ]);
    $response->assertOk();

    $path = $response->json('path');

    expect(Storage::disk('local')->exists($path))->toBeTrue();
    expect(Storage::disk('public')->exists($path))->toBeFalse();

    Storage::disk('local')->delete($path);
});

it('16: an expired signed protected-file URL is rejected', function () {
    Storage::disk('local')->put('task-photos/expiring.jpg', 'fake-image-bytes');

    $url = protected_file_url('task-photos/expiring.jpg', 1);

    test()->travel(2)->minutes();

    $response = test()->get($url);

    $response->assertStatus(403);

    Storage::disk('local')->delete('task-photos/expiring.jpg');
});

it('a valid, unexpired signed protected-file URL is served successfully', function () {
    Storage::disk('local')->put('task-photos/valid.jpg', 'fake-image-bytes');

    $url = protected_file_url('task-photos/valid.jpg', 30);

    $response = test()->get($url);

    $response->assertOk();

    Storage::disk('local')->delete('task-photos/valid.jpg');
});

it('17: a disallowed file extension is rejected on task photo upload', function () {
    [$tl, $booking] = phase3TlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    $response = test()->postJson("/api/v1/team-leader/task/{$booking->booking_code}/photo", [
        'photo' => UploadedFile::fake()->create('malicious.php', 10, 'application/x-php'),
        'type' => 'arrival',
    ]);

    $response->assertStatus(422);
});

it('18: an oversized task photo upload is rejected', function () {
    [$tl, $booking] = phase3TlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    $response = test()->postJson("/api/v1/team-leader/task/{$booking->booking_code}/photo", [
        'photo' => UploadedFile::fake()->image('too-big.jpg')->size(6000),
        'type' => 'arrival',
    ]);

    $response->assertStatus(422);
});

it('19: an SVG task photo upload is rejected', function () {
    [$tl, $booking] = phase3TlWithBooking();
    Sanctum::actingAs($tl, ['*']);

    $response = test()->postJson("/api/v1/team-leader/task/{$booking->booking_code}/photo", [
        'photo' => UploadedFile::fake()->create('image.svg', 10, 'image/svg+xml'),
        'type' => 'arrival',
    ]);

    $response->assertStatus(422);
});

it('20/21: an unexpected server exception on an API route returns a generic response with no internal detail', function () {
    config(['app.debug' => false]);

    \Illuminate\Support\Facades\Route::middleware('api')->get('/api/__phase3_boom', function () {
        throw new \RuntimeException('leaked internal filesystem path /var/www/secret.php and SQL detail');
    });

    $response = test()->getJson('/api/__phase3_boom');

    $response->assertStatus(500);
    $response->assertJson(['success' => false, 'message' => 'Something went wrong. Please try again later.']);
    expect($response->getContent())->not->toContain('secret.php');
    expect($response->getContent())->not->toContain('RuntimeException');
});

it('22: a normal validation failure still returns a proper 422 with useful errors', function () {
    $response = test()->postJson('/api/register', []);

    $response->assertStatus(422);
});

it('23: 401/403/404/429 semantics remain intact for authorization failures', function () {
    test()->getJson('/api/v1/bookings/current')->assertStatus(401);

    [$tl, $booking] = phase3TlWithBooking();
    [$otherTl] = phase3TlWithBooking();
    Sanctum::actingAs($otherTl, ['*']);
    test()->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", ['status' => 'on_the_way'])
        ->assertStatus(403);
});
