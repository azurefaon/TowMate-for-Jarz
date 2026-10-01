<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/api/__prod/boom', fn () => throw new RuntimeException('SQLSTATE[23000] secret /var/www/app/Secret.php'));
    Route::get('/__prod/boom', fn () => throw new RuntimeException('SQLSTATE[23000] secret /var/www/app/Secret.php'));
});

it('returns a safe json 500 without stack details when debug is disabled', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/__prod/boom');

    $response->assertStatus(500)->assertJson(['success' => false]);
    $body = $response->getContent();
    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('/var/www')
        ->and($body)->not->toContain('trace')
        ->and($response->json())->not->toHaveKeys(['exception', 'file', 'line', 'trace']);
});

it('returns the branded html 500 without stack details when debug is disabled', function () {
    config(['app.debug' => false]);

    $response = $this->get('/__prod/boom');

    $response->assertStatus(500)->assertSee("We couldn't complete your request right now. Please try again.");
    $body = $response->getContent();
    expect($body)->not->toContain('SQLSTATE')
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('/var/www')
        ->and($body)->not->toContain('Stack trace');
});

it('ships safe production defaults in source', function () {
    expect(file_get_contents(config_path('app.php')))
        ->toContain("'debug' => (bool) env('APP_DEBUG', false)")
        ->toContain("'env' => env('APP_ENV', 'production')");

    $example = file_get_contents(base_path('.env.example'));
    expect($example)->toContain('APP_ENV=production')->toContain('APP_DEBUG=false');
});

it('does not hardcode debug behaviour outside the config flag', function () {
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)->not->toContain("config(['app.debug' => true])")
        ->and($bootstrap)->not->toContain('APP_DEBUG=true');
});
