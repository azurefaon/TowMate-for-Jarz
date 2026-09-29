<?php

use Illuminate\Support\Facades\Route;

it('no route in the application points at a PayMongo action', function () {
    $paymongoRoutes = collect(Route::getRoutes())->filter(function ($route) {
        return str_contains(strtolower($route->uri()), 'paymongo')
            || str_contains(strtolower((string) $route->getActionName()), 'paymongo');
    });

    expect($paymongoRoutes)->toHaveCount(0);
});

it('the PayMongoService class file no longer exists in the application', function () {
    expect(file_exists(app_path('Services/PayMongoService.php')))->toBeFalse();
});

it('no live application file references the PayMongoService class', function () {
    $matches = collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
        ->filter(fn ($file) => str_contains(file_get_contents($file->getPathname()), 'PayMongoService'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values();

    expect($matches->all())->toBe([]);
});

it('no PayMongo configuration remains registered', function () {
    expect(config('services.paymongo'))->toBeNull();
});

it('the bookings table no longer has any PayMongo-only columns', function () {
    foreach (['paymongo_link_id', 'paymongo_checkout_url', 'paymongo_intent_id', 'paymongo_client_key'] as $column) {
        expect(\Schema::hasColumn('bookings', $column))->toBeFalse();
    }
});

it('the manual payment flow columns survived the PayMongo cleanup', function () {
    foreach (['payment_method', 'payment_proof_path', 'payment_submitted_at', 'cash_received'] as $column) {
        expect(\Schema::hasColumn('bookings', $column))->toBeTrue();
    }
});

it('the confirm-payment route still exists and is not named or described as PayMongo/online payment', function () {
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'admin.jobs.confirm-payment');

    expect($route)->not->toBeNull();
    expect(strtolower($route->uri()))->not->toContain('paymongo');
});
