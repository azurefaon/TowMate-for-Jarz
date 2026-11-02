<?php

/*
 * History: PayMongo was removed from TowMate (commit 79864f63) and this file originally forbade any
 * PayMongo route, service or configuration. That blanket ban is deliberately REPLACED here, with the
 * project owner's approval, by explicit guards for the one approved architecture: an isolated,
 * disabled-by-default, TEST-MODE-ONLY sandbox. Everything about the removed integration that is
 * still true (no PayMongo columns on bookings, no services.paymongo config, no PayMongoService,
 * the manual payment flow intact) is still asserted. The behaviour tests live in
 * tests/Feature/Payments/PayMongoSandboxTest.php.
 */

use App\Models\User;
use App\Services\OnlinePayments\SandboxGuard;
use Illuminate\Support\Facades\Route;

it('the only PayMongo-named route is the signature-checked sandbox webhook', function () {
    $paymongoRoutes = collect(Route::getRoutes())->filter(function ($route) {
        return str_contains(strtolower($route->uri()), 'paymongo')
            || str_contains(strtolower((string) $route->getActionName()), 'paymongo');
    });

    expect($paymongoRoutes->map(fn ($route) => $route->methods()[0] . ' ' . $route->uri())->values()->all())
        ->toBe(['POST api/webhooks/paymongo']);
});

it('the old PayMongoService class file still does not exist', function () {
    expect(file_exists(app_path('Services/PayMongoService.php')))->toBeFalse();
});

it('no live application file references the old PayMongoService class', function () {
    $matches = collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
        ->filter(fn ($file) => str_contains(file_get_contents($file->getPathname()), 'PayMongoService'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values();

    expect($matches->all())->toBe([]);
});

it('the removed integration\'s services.paymongo configuration is still absent', function () {
    expect(config('services.paymongo'))->toBeNull();
});

it('the bookings table still has none of the old PayMongo-only columns', function () {
    foreach (['paymongo_link_id', 'paymongo_checkout_url', 'paymongo_intent_id', 'paymongo_client_key'] as $column) {
        expect(\Schema::hasColumn('bookings', $column))->toBeFalse();
    }
});

it('the manual payment flow columns survived', function () {
    foreach (['payment_method', 'preferred_payment_method', 'payment_proof_path', 'payment_submitted_at', 'cash_received'] as $column) {
        expect(\Schema::hasColumn('bookings', $column))->toBeTrue();
    }
});

it('the confirm-payment route still exists and is not named or described as PayMongo/online payment', function () {
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'admin.jobs.confirm-payment');

    expect($route)->not->toBeNull();
    expect(strtolower($route->uri()))->not->toContain('paymongo');
});

it('the sandbox is disabled by default and ships with no credentials', function () {
    expect(config('paymongo.enabled'))->toBeFalse();
    expect(config('paymongo.secret_key'))->toBeNull();
    expect(config('paymongo.webhook_secret'))->toBeNull();
    expect(config('paymongo.test_user_ids'))->toBe([]);
    expect(config('paymongo.test_user_emails'))->toBe([]);
    expect((new SandboxGuard())->enabled())->toBeFalse();
});

it('production is never on the default sandbox environment list', function () {
    expect(config('paymongo.allowed_environments'))->not->toContain('production');

    config(['paymongo.enabled' => true, 'paymongo.allowed_environments' => ['local', 'testing', 'staging']]);
    app()->detectEnvironment(fn () => 'production');

    expect((new SandboxGuard())->enabled())->toBeFalse();
});

it('the sandbox guard turns away anything but a test secret key', function (?string $key, ?string $problem) {
    config(['paymongo.secret_key' => $key]);

    expect((new SandboxGuard())->credentialsProblem())->toBe($problem);
})->with([
    'no key' => [null, 'missing_secret_key'],
    'live key' => ['sk_live_abcdefghijkl', 'live_or_unknown_key_rejected'],
    'public key' => ['pk_test_abcdefghijkl', 'live_or_unknown_key_rejected'],
    'test key' => ['sk_test_abcdefghijkl', null],
]);

it('only allow-listed test users pass the sandbox guard', function () {
    $guard = new SandboxGuard();
    $allowed = User::factory()->create();
    $stranger = User::factory()->create();
    config(['paymongo.test_user_ids' => [$allowed->id], 'paymongo.test_user_emails' => []]);

    expect($guard->userAllowed($allowed))->toBeTrue();
    expect($guard->userAllowed($stranger))->toBeFalse();
    expect($guard->userAllowed(null))->toBeFalse();
});
