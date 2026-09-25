<?php

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function gaiRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function gaiClaims(array $overrides = []): array
{
    return array_merge([
        'sub' => 'google-sub-' . uniqid(),
        'email' => 'googleavatar-' . uniqid() . '@example.com',
        'email_verified' => true,
        'given_name' => 'Ava',
        'family_name' => 'Torres',
        'picture' => 'https://lh3.googleusercontent.com/a/avatar-' . uniqid(),
    ], $overrides);
}

function gaiBindVerifier(?array $claims): void
{
    $fake = new class($claims) implements GoogleIdTokenVerifier {
        public function __construct(private ?array $claims)
        {
        }

        public function verify(string $idToken): ?array
        {
            return $this->claims;
        }
    };

    app()->instance(GoogleIdTokenVerifier::class, $fake);
}

function gaiFakeImageBytes(): string
{
    return UploadedFile::fake()->image('avatar.png', 12, 12)->get();
}

function gaiOversizedImageBytes(): string
{
    $realImageBytes = UploadedFile::fake()->image('huge.jpg', 12, 12)->get();

    return $realImageBytes . str_repeat('0', 6 * 1024 * 1024);
}

beforeEach(function () {
    gaiRole(5, 'Customer');
    Storage::fake('profile_images');
});

it('downloads and stores the Google avatar on first signup when no profile image exists', function () {
    $claims = gaiClaims();
    Http::fake([
        $claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234567',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->not->toBeNull();
    Storage::disk('profile_images')->assertExists($user->profile_image);
});

it('serves the imported Google avatar through the existing authenticated profile-image endpoint', function () {
    $claims = gaiClaims();
    Http::fake([
        $claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234568',
        'accept_terms' => true,
    ])->assertStatus(201);

    $user = User::where('email', $claims['email'])->first();

    $response = test()->actingAs($user, 'sanctum')->getJson('/api/v1/profile');
    $response->assertJsonPath('data.has_profile_image', true);

    $image = test()->actingAs($user, 'sanctum')->get('/api/v1/profile/image');
    $image->assertOk();
    expect($image->headers->get('content-type'))->toContain('image');
});

it('never overwrites an existing profile image on a later Google login', function () {
    $sub = 'google-sub-' . uniqid();
    $existingPath = 'seed/existing-avatar.png';
    Storage::disk('profile_images')->put($existingPath, gaiFakeImageBytes());

    $user = User::factory()->create([
        'role_id' => 5,
        'status' => 'active',
        'password' => null,
        'auth_provider' => 'google',
        'google_sub' => $sub,
        'profile_image' => $existingPath,
    ]);

    $claims = gaiClaims(['sub' => $sub, 'email' => $user->email]);
    Http::fake([$claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png'])]);
    gaiBindVerifier($claims);

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(200);
    expect($user->fresh()->profile_image)->toBe($existingPath);
    Http::assertNotSent(fn ($request) => $request->url() === $claims['picture']);
});

it('does not overwrite a manually uploaded photo on a later Google login', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id' => 5,
        'status' => 'active',
        'password' => null,
        'auth_provider' => 'google',
        'google_sub' => $sub,
    ]);

    test()->actingAs($user, 'sanctum')->post('/api/v1/profile/image', [
        'profile_image' => UploadedFile::fake()->image('manual.jpg', 200, 200),
    ])->assertOk();
    $manualPath = $user->fresh()->profile_image;
    expect($manualPath)->not->toBeNull();

    $claims = gaiClaims(['sub' => $sub, 'email' => $user->email]);
    Http::fake([$claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png'])]);
    gaiBindVerifier($claims);

    test()->postJson('/api/auth/google', ['id_token' => 'valid-token'])->assertStatus(200);

    expect($user->fresh()->profile_image)->toBe($manualPath);
});

it('does not fail Google authentication when the avatar download errors out', function () {
    $claims = gaiClaims();
    Http::fake([
        $claims['picture'] => function () {
            throw new ConnectionException('Connection timed out');
        },
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234569',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    expect($response->json('data.token'))->not->toBeEmpty();
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
});

it('does not fail Google authentication when the avatar host returns an HTTP error', function () {
    $claims = gaiClaims();
    Http::fake([$claims['picture'] => Http::response('', 500)]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234570',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
});

it('does not create a broken profile image from a non-image response', function () {
    $claims = gaiClaims();
    Http::fake([
        $claims['picture'] => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/png']),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234571',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
});

it('retries the avatar import on a later Google login after the first attempt failed', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id' => 5,
        'status' => 'active',
        'password' => null,
        'auth_provider' => 'google',
        'google_sub' => $sub,
        'profile_image' => null,
    ]);

    $claims = gaiClaims(['sub' => $sub, 'email' => $user->email]);

    Http::fake([
        $claims['picture'] => Http::sequence()
            ->push('', 500)
            ->push(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    gaiBindVerifier($claims);

    test()->postJson('/api/auth/google', ['id_token' => 'valid-token'])->assertStatus(200);
    expect($user->fresh()->profile_image)->toBeNull();

    test()->postJson('/api/auth/google', ['id_token' => 'valid-token'])->assertStatus(200);

    expect($user->fresh()->profile_image)->not->toBeNull();
    Storage::disk('profile_images')->assertExists($user->fresh()->profile_image);
});

it('imports the avatar from a valid HTTPS googleusercontent.com host', function () {
    $claims = gaiClaims(['picture' => 'https://lh4.googleusercontent.com/a/avatar-' . uniqid()]);
    Http::fake([
        $claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234580',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->not->toBeNull();
});

it('ignores a Google avatar URL served over plain http', function () {
    $claims = gaiClaims(['picture' => 'http://lh3.googleusercontent.com/a/avatar-' . uniqid()]);
    Http::fake([$claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png'])]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234581',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
    Http::assertNotSent(fn ($request) => true);
});

it('ignores a picture URL pointing at an arbitrary non-Google HTTPS host', function () {
    $claims = gaiClaims(['picture' => 'https://evil-attacker.example.com/avatar.png']);
    Http::fake([$claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png'])]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234582',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
    Http::assertNotSent(fn ($request) => true);
});

it('ignores a picture URL pointing at a loopback/private-style host', function () {
    $claims = gaiClaims(['picture' => 'https://127.0.0.1/avatar.png']);
    Http::fake([$claims['picture'] => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png'])]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234583',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
    Http::assertNotSent(fn ($request) => true);
});

it('rejects an avatar larger than 5MB reported via Content-Length without failing authentication', function () {
    $claims = gaiClaims();
    Http::fake([
        $claims['picture'] => Http::response(gaiOversizedImageBytes(), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) (6 * 1024 * 1024),
        ]),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234584',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
});

it('rejects an oversized avatar body even when Content-Length is missing', function () {
    $claims = gaiClaims();
    Http::fake([
        $claims['picture'] => Http::response(gaiOversizedImageBytes(), 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234585',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
});

it('does not follow a redirect from the allowlisted Google host to an external destination', function () {
    $claims = gaiClaims();
    $redirectTarget = 'https://evil-redirect-target.example.com/payload.png';

    Http::fake([
        $claims['picture'] => Http::response('', 302, ['Location' => $redirectTarget]),
        $redirectTarget => Http::response(gaiFakeImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);
    gaiBindVerifier($claims);

    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone' => '+639171234587',
        'accept_terms' => true,
    ]);

    $response->assertStatus(201);
    $user = User::where('email', $claims['email'])->first();
    expect($user->profile_image)->toBeNull();
    Http::assertNotSent(fn ($request) => $request->url() === $redirectTarget);
});
