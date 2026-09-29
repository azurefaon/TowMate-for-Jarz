<?php

use App\Contracts\GoogleIdTokenVerifier;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function ctaRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function ctaCustomer(array $overrides = []): User
{
    ctaRole(5, 'Customer');

    return User::factory()->create(array_merge([
        'role_id'  => 5,
        'status'   => 'active',
        'password' => Hash::make('CorrectHorse!9Battery'),
    ], $overrides));
}

function ctaRegisterPayload(string $email, array $overrides = []): array
{
    return array_merge([
        'first_name'             => 'Terms',
        'last_name'              => 'Tester',
        'email'                  => $email,
        'phone'                  => '+639' . random_int(100000000, 999999999),
        'password'               => 'ValidPass!2024xy',
        'password_confirmation'  => 'ValidPass!2024xy',
        'accept_terms'           => true,
    ], $overrides);
}

function ctaClaims(array $overrides = []): array
{
    return array_merge([
        'sub'            => 'google-sub-' . uniqid(),
        'email'          => 'ctagoogle-' . uniqid() . '@example.com',
        'email_verified' => true,
        'given_name'     => 'Terri',
        'family_name'    => 'Accepts',
    ], $overrides);
}

function ctaBindVerifier(?array $claims): void
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

beforeEach(function () {
    ctaRole(5, 'Customer');
});

// ── Email registration ──────────────────────────────────────────────────

it('1: registration without accept_terms is rejected', function () {
    $email = 'cta1-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $payload = ctaRegisterPayload($email);
    unset($payload['accept_terms']);

    $response = test()->postJson('/api/register', $payload);

    $response->assertStatus(422);
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('2: registration with accept_terms=false is rejected', function () {
    $email = 'cta2-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', ctaRegisterPayload($email, ['accept_terms' => false]));

    $response->assertStatus(422);
    expect(User::where('email', $email)->exists())->toBeFalse();
});

it('3: registration with accept_terms=true creates the account', function () {
    $email = 'cta3-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    $response = test()->postJson('/api/register', ctaRegisterPayload($email));

    $response->assertStatus(201);
    expect(User::where('email', $email)->exists())->toBeTrue();
});

it('4-6: the created account stores the current terms_version, privacy_version and terms_accepted_at', function () {
    $email = 'cta456-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    test()->postJson('/api/register', ctaRegisterPayload($email))->assertStatus(201);

    $user = User::where('email', $email)->first();
    expect($user->terms_version)->toBe(config('towmate.current_terms_version'));
    expect($user->privacy_version)->toBe(config('towmate.current_privacy_version'));
    expect($user->terms_accepted_at)->not->toBeNull();
});

// ── Google registration ─────────────────────────────────────────────────

it('7: Google phone completion without acceptance is rejected', function () {
    $claims = ctaClaims();
    ctaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234580',
    ]);

    $response->assertStatus(422);
    expect(User::where('email', $claims['email'])->exists())->toBeFalse();
});

it('8: Google phone completion with acceptance creates the account', function () {
    $claims = ctaClaims();
    ctaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234581',
        'accept_terms'     => true,
    ]);

    $response->assertStatus(201);
    expect(User::where('email', $claims['email'])->exists())->toBeTrue();
});

it('9: the created Google account stores the current terms_version, privacy_version and terms_accepted_at', function () {
    $claims = ctaClaims();
    ctaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234582',
        'accept_terms'     => true,
    ])->assertStatus(201);

    $user = User::where('email', $claims['email'])->first();
    expect($user->terms_version)->toBe(config('towmate.current_terms_version'));
    expect($user->privacy_version)->toBe(config('towmate.current_privacy_version'));
    expect($user->terms_accepted_at)->not->toBeNull();
});

// ── Existing password accounts ──────────────────────────────────────────

it('10: a login for an account with null terms flags the response as requiring terms acceptance', function () {
    $user = ctaCustomer([
        'email'             => 'cta10@example.com',
        'terms_version'     => null,
        'privacy_version'   => null,
        'terms_accepted_at' => null,
    ]);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(200);
    expect($response->json('requires_terms_acceptance'))->toBeTrue();
    expect($response->json('data.token'))->not->toBeEmpty();
});

it('10b: a login for an account with an outdated terms_version flags the response as requiring terms acceptance', function () {
    $user = ctaCustomer([
        'email'             => 'cta10b@example.com',
        'terms_version'     => '0.9',
        'privacy_version'   => config('towmate.current_privacy_version'),
        'terms_accepted_at' => now()->subYear(),
    ]);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(200);
    expect($response->json('requires_terms_acceptance'))->toBeTrue();
});

it('11: a login for an account already on the current terms versions logs in normally', function () {
    $user = ctaCustomer([
        'email'             => 'cta11@example.com',
        'terms_version'     => config('towmate.current_terms_version'),
        'privacy_version'   => config('towmate.current_privacy_version'),
        'terms_accepted_at' => now(),
    ]);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(200);
    expect($response->json('requires_terms_acceptance'))->toBeFalse();
    expect($response->json('data.token'))->not->toBeEmpty();
});

it('a locked account is still rejected before any terms check runs', function () {
    $user = ctaCustomer([
        'email'  => 'cta-locked@example.com',
        'status' => 'locked',
        'terms_version' => null,
    ]);

    $response = test()->postJson('/api/login', [
        'email'    => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);

    $response->assertStatus(423);
    expect($response->json('requires_terms_acceptance'))->toBeNull();
});

// ── Existing Google accounts ────────────────────────────────────────────

it('12: Google sign-in for an existing account with outdated terms flags the response as requiring terms acceptance', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'           => 5,
        'status'            => 'active',
        'password'          => null,
        'auth_provider'     => 'google',
        'google_sub'        => $sub,
        'terms_version'     => null,
        'privacy_version'   => null,
        'terms_accepted_at' => null,
    ]);

    ctaBindVerifier(ctaClaims(['sub' => $sub, 'email' => $user->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(200);
    expect($response->json('requires_terms_acceptance'))->toBeTrue();
    expect($response->json('data.token'))->not->toBeEmpty();
});

it('13: Google sign-in for an existing account already on the current terms versions logs in normally', function () {
    $sub = 'google-sub-' . uniqid();
    $user = User::factory()->create([
        'role_id'           => 5,
        'status'            => 'active',
        'password'          => null,
        'auth_provider'     => 'google',
        'google_sub'        => $sub,
        'terms_version'     => config('towmate.current_terms_version'),
        'privacy_version'   => config('towmate.current_privacy_version'),
        'terms_accepted_at' => now(),
    ]);

    ctaBindVerifier(ctaClaims(['sub' => $sub, 'email' => $user->email]));

    $response = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);

    $response->assertStatus(200);
    expect($response->json('requires_terms_acceptance'))->toBeFalse();
});

it('a freshly completed Google signup never requires terms acceptance on the same response', function () {
    $claims = ctaClaims();
    ctaBindVerifier($claims);
    $start = test()->postJson('/api/auth/google', ['id_token' => 'valid-token']);
    $token = $start->json('completion_token');

    $response = test()->postJson('/api/auth/google/complete', [
        'completion_token' => $token,
        'phone'            => '+639171234583',
        'accept_terms'     => true,
    ]);

    $response->assertStatus(201);
    expect($response->json('requires_terms_acceptance'))->toBeFalse();
});

// ── Accept-terms endpoint ────────────────────────────────────────────────

it('14: an authenticated customer can accept the current terms', function () {
    $user = ctaCustomer([
        'email'         => 'cta14@example.com',
        'terms_version' => null,
    ]);

    Sanctum::actingAs($user, ['*']);
    $response = test()->postJson('/api/auth/accept-terms');

    $response->assertOk();
    expect($response->json('success'))->toBeTrue();

    $user->refresh();
    expect($user->terms_version)->toBe(config('towmate.current_terms_version'));
    expect($user->privacy_version)->toBe(config('towmate.current_privacy_version'));
    expect($user->terms_accepted_at)->not->toBeNull();
});

it('15: the server ignores any version values the client attempts to submit', function () {
    $user = ctaCustomer(['email' => 'cta15@example.com', 'terms_version' => null]);

    Sanctum::actingAs($user, ['*']);
    $response = test()->postJson('/api/auth/accept-terms', [
        'terms_version'   => '99.0',
        'privacy_version' => '99.0',
    ]);

    $response->assertOk();
    $user->refresh();
    expect($user->terms_version)->toBe(config('towmate.current_terms_version'));
    expect($user->privacy_version)->toBe(config('towmate.current_privacy_version'));
    expect($user->terms_version)->not->toBe('99.0');
});

it('16: an unauthenticated request cannot call accept-terms', function () {
    $response = test()->postJson('/api/auth/accept-terms');

    $response->assertStatus(401);
});

it('17: acceptance updates an existing acceptance timestamp/version correctly', function () {
    $user = ctaCustomer([
        'email'             => 'cta17@example.com',
        'terms_version'     => '0.5',
        'privacy_version'   => '0.5',
        'terms_accepted_at' => now()->subYears(2),
    ]);
    $oldTimestamp = $user->terms_accepted_at;

    test()->travel(1)->minutes();
    Sanctum::actingAs($user, ['*']);
    test()->postJson('/api/auth/accept-terms')->assertOk();

    $user->refresh();
    expect($user->terms_version)->toBe(config('towmate.current_terms_version'));
    expect($user->terms_accepted_at->greaterThan($oldTimestamp))->toBeTrue();
});

// ── Audit log ────────────────────────────────────────────────────────────

it('18: accepting terms creates a customer_terms_accepted audit event', function () {
    $user = ctaCustomer(['email' => 'cta18@example.com', 'terms_version' => null]);

    Sanctum::actingAs($user, ['*']);
    test()->postJson('/api/auth/accept-terms')->assertOk();

    $log = AuditLog::where('user_id', $user->id)
        ->where('action', 'customer_terms_accepted')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->new_value['terms_version'])->toBe(config('towmate.current_terms_version'));
    expect($log->new_value['privacy_version'])->toBe(config('towmate.current_privacy_version'));
});

// ── Owner-managed versions (SystemSetting overrides config) ────────────────

it('19: SystemSetting::currentTermsVersion falls back to config if no version row exists at all', function () {
    SystemSetting::setValue('terms_of_use_version', null);
    SystemSetting::setValue('privacy_policy_version', null);

    expect(SystemSetting::currentTermsVersion())->toBe(config('towmate.current_terms_version'));
    expect(SystemSetting::currentPrivacyVersion())->toBe(config('towmate.current_privacy_version'));
});

it('20: SystemSetting::currentTermsVersion prefers the Owner-published version over config', function () {
    SystemSetting::setValue('terms_of_use_version', '7.7');
    SystemSetting::setValue('privacy_policy_version', '8.8');

    expect(SystemSetting::currentTermsVersion())->toBe('7.7');
    expect(SystemSetting::currentPrivacyVersion())->toBe('8.8');
    expect(SystemSetting::currentTermsVersion())->not->toBe(config('towmate.current_terms_version'));
});

it('21: a customer accepted against the old config default is required to re-accept once the Owner publishes a new version', function () {
    $user = ctaCustomer([
        'email' => 'cta21@example.com',
        'terms_version' => config('towmate.current_terms_version'),
        'privacy_version' => config('towmate.current_privacy_version'),
        'terms_accepted_at' => now(),
    ]);

    $before = test()->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);
    expect($before->json('requires_terms_acceptance'))->toBeFalse();

    SystemSetting::setValue('terms_of_use_version', 'owner-published-2.0');

    $after = test()->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'CorrectHorse!9Battery',
    ]);
    expect($after->json('requires_terms_acceptance'))->toBeTrue();
});

it('22: registering a new account after the Owner publishes a version stamps that Owner-published version, not the config default', function () {
    SystemSetting::setValue('terms_of_use_version', 'owner-published-3.0');
    SystemSetting::setValue('privacy_policy_version', 'owner-published-privacy-3.0');

    $email = 'cta22-' . uniqid() . '@gmail.com';
    Cache::put('reg_verified_' . $email, true, now()->addMinutes(15));

    test()->postJson('/api/register', ctaRegisterPayload($email))->assertStatus(201);

    $user = User::where('email', $email)->first();
    expect($user->terms_version)->toBe('owner-published-3.0');
    expect($user->privacy_version)->toBe('owner-published-privacy-3.0');
});

// ── Public legal content endpoint ───────────────────────────────────────────

it('23: the public customer content endpoint exposes the Owner-published Terms/Privacy version and text', function () {
    SystemSetting::setValue('terms_of_use_version', '4.4');
    SystemSetting::setValue('terms_of_use_content', 'Owner-authored terms body.');
    SystemSetting::setValue('privacy_policy_version', '5.5');
    SystemSetting::setValue('privacy_policy_content', 'Owner-authored privacy body.');

    $response = test()->getJson('/api/v1/customer/content');

    $response->assertOk();
    expect($response->json('terms_of_use.version'))->toBe('4.4');
    expect($response->json('terms_of_use.content'))->toBe('Owner-authored terms body.');
    expect($response->json('privacy_policy.version'))->toBe('5.5');
    expect($response->json('privacy_policy.content'))->toBe('Owner-authored privacy body.');
});

it('24: the public customer content endpoint falls back to the config version when the version row is empty', function () {
    SystemSetting::setValue('terms_of_use_version', null);
    SystemSetting::setValue('terms_of_use_content', null);

    $response = test()->getJson('/api/v1/customer/content');

    $response->assertOk();
    expect($response->json('terms_of_use.version'))->toBe(config('towmate.current_terms_version'));
    expect($response->json('terms_of_use.content'))->toBeNull();
});
