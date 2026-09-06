<?php

it('the unauthenticated debug-availability route no longer exists', function () {
    test()->getJson('/api/debug-availability')->assertStatus(404);
});

it('the retired Breeze password-reset-link and new-password controllers are no longer reachable', function () {
    expect(class_exists(\App\Http\Controllers\Auth\PasswordResetLinkController::class))->toBeFalse();
    expect(class_exists(\App\Http\Controllers\Auth\NewPasswordController::class))->toBeFalse();
});

it('no route resolves to the retired Breeze password-reset controllers', function () {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())->map(function ($route) {
        return $route->getActionName();
    });

    expect($routes->contains(fn($action) => str_contains($action, 'PasswordResetLinkController')))->toBeFalse();
    expect($routes->contains(fn($action) => str_contains($action, 'NewPasswordController')))->toBeFalse();
});

it('the staff forgot-password flow still works end to end after Breeze cleanup', function () {
    test()->get('/forgot-password')->assertOk();
    test()->get('/reset-password')->assertRedirect(route('password.request'));
});
