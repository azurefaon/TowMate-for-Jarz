<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\PayMongoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

it('the CSP contains no bare wildcard source', function () {
    $csp = test()->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull();
    foreach (explode(';', $csp) as $directive) {
        $tokens = preg_split('/\s+/', trim($directive));
        foreach ($tokens as $token) {
            expect($token)->not->toBe('*');
        }
    }
});

it('the CSP does not include unsafe-eval', function () {
    $csp = test()->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('unsafe-eval');
});

it('the CSP restricts object-src to none and base-uri to self', function () {
    $csp = test()->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("object-src 'none'");
    expect($csp)->toContain("base-uri 'self'");
});

it('security headers are present and non-conflicting on Dispatcher, Owner, and Customer-facing web routes', function () {
    foreach (['/login', '/track-booking'] as $path) {
        $response = test()->get($path);

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
        expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
        expect(count($response->headers->all('X-Content-Type-Options')))->toBe(1);
        expect(count($response->headers->all('Content-Security-Policy')))->toBe(1);
    }
});

it('an arbitrary malicious Origin is never reflected back in CORS headers', function () {
    $response = test()->getJson('/api/v1/customer/content', ['Origin' => 'https://attacker.example']);

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://attacker.example');
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('a wildcard origin is never returned even when Origin is present', function () {
    $response = test()->getJson('/api/v1/customer/content', ['Origin' => 'https://random-site.example']);

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
});

it('a CORS preflight from an unauthorized origin grants no access-control-allow-origin', function () {
    $response = test()->call('OPTIONS', '/api/v1/customer/content', [], [], [], [
        'HTTP_Origin' => 'https://attacker.example',
        'HTTP_Access-Control-Request-Method' => 'GET',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('a configured allowed origin is granted access when CORS_ALLOWED_ORIGINS provides one', function () {
    config(['cors.allowed_origins' => ['https://allowed.example.com']]);

    $response = test()->getJson('/api/v1/customer/content', ['Origin' => 'https://allowed.example.com']);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://allowed.example.com');
});

it('an unexpected exception carrying fake sensitive values never leaks them in the API response', function () {
    config(['app.debug' => false]);

    Route::middleware('api')->get('/api/__phase4_leak_test', function () {
        throw new \RuntimeException(
            'DSN=pgsql://towmate_user:S3cretPassw0rd@10.0.0.5:5432/towmate_prod; '
            . 'API_KEY=sk_live_FAKE1234567890; '
            . 'path=/var/www/towmate/storage/app/secret.key; '
            . 'SQL: SELECT * FROM users WHERE email = \'victim@example.com\''
        );
    });

    $response = test()->getJson('/api/__phase4_leak_test');

    $response->assertStatus(500);
    $body = $response->getContent();

    foreach (['S3cretPassw0rd', 'sk_live_FAKE1234567890', '/var/www/towmate', 'SELECT * FROM users', 'victim@example.com'] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('validation errors remain informative even in production mode', function () {
    config(['app.debug' => false]);

    $response = test()->postJson('/api/register', ['email' => 'not-an-email']);

    $response->assertStatus(422);
    expect($response->json('message'))->not->toBeNull();
});

it('audit log entries for the OTP lifecycle never contain the OTP, hash, or reset token', function () {
    $user = User::factory()->create(['email' => 'p4-logcheck@example.com']);

    \Illuminate\Support\Facades\Mail::fake();
    $sendResp = test()->postJson('/api/password/forgot', ['email' => $user->email]);
    $sendResp->assertOk();

    $otp = null;
    \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\PasswordResetOtpMail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => '000000']);
    $verify = test()->postJson('/api/password/verify-otp', ['email' => $user->email, 'otp' => $otp]);
    $rawToken = $verify->json('reset_token');

    test()->postJson('/api/password/reset', [
        'email' => $user->email,
        'reset_token' => $rawToken,
        'password' => 'LogIntegrityCheck123!',
        'password_confirmation' => 'LogIntegrityCheck123!',
    ])->assertOk();

    $logs = AuditLog::where('user_id', $user->id)->get();
    expect($logs)->not->toBeEmpty();

    $secrets = [$otp, $rawToken, hash('sha256', $rawToken), 'LogIntegrityCheck123!'];
    foreach ($logs as $log) {
        foreach ($secrets as $secret) {
            expect((string) $log->action)->not->toContain($secret);
            expect((string) $log->description)->not->toContain($secret);
            expect(json_encode($log->old_value))->not->toContain($secret);
            expect(json_encode($log->new_value))->not->toContain($secret);
        }
    }
});

it('PayMongo a valid HTTP status with malformed JSON is treated as not paid', function () {
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
    Http::fake(['*/links/*' => Http::response('{not valid json', 200)]);

    expect(app(PayMongoService::class)->isLinkPaid('link_bad_json'))->toBeFalse();
});

it('PayMongo a response missing the attributes structure entirely is treated as not paid', function () {
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
    Http::fake(['*/payment_intents/*' => Http::response(['data' => []], 200)]);

    expect(app(PayMongoService::class)->isIntentPaid('pi_no_attrs', 'ck_x'))->toBeFalse();
});

it('PayMongo a fake "paid" value placed outside the expected structure is not trusted', function () {
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
    Http::fake(['*/links/*' => Http::response(['paid' => true, 'data' => ['attributes' => ['status' => 'unpaid']]], 200)]);

    expect(app(PayMongoService::class)->isLinkPaid('link_spoofed'))->toBeFalse();
});

it('PayMongo a status value outside the expected enum is treated as not paid', function () {
    config(['services.paymongo.secret_key' => 'sk_test_dummy']);
    Http::fake(['*/links/*' => Http::response(['data' => ['attributes' => ['status' => 'refunded']]], 200)]);

    expect(app(PayMongoService::class)->isLinkPaid('link_weird_status'))->toBeFalse();
});

it('Geo a route response with null/invalid coordinate types falls back safely', function () {
    Http::fake([
        '*openrouteservice.org*' => Http::response([
            'features' => [[
                'properties' => ['summary' => ['distance' => 'not-a-number', 'duration' => null]],
                'geometry' => ['coordinates' => null],
            ]],
        ], 200),
        '*router.project-osrm.org*' => Http::response(['routes' => []], 200),
    ]);

    $role = Role::find(5) ?: tap(new Role(['name' => 'Customer']), function ($r) {
        $r->id = 5;
        $r->save();
    });
    $user = User::factory()->create(['role_id' => $role->id]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/geo/route', [
        'pickup_lat' => 14.5995, 'pickup_lng' => 120.9842,
        'drop_lat' => 14.6091, 'drop_lng' => 121.0223,
    ]);

    $response->assertOk();
    $response->assertJsonPath('is_fallback', true);
    expect((float) $response->json('distance_km'))->toBeGreaterThan(0);
});
