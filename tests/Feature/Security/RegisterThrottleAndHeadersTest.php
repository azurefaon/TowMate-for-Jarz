<?php

use Illuminate\Support\Facades\Route;

it('throttles public web registration per client', function () {
    $route = Route::getRoutes()->match(\Illuminate\Http\Request::create('/register', 'POST'));

    expect($route->gatherMiddleware())->toContain('throttle:10,60');
});

it('answers repeated registration attempts with 429 after the cap', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post('/register', ['email' => 'not-an-email']);
    }

    $this->post('/register', ['email' => 'not-an-email'])->assertStatus(429);
});

it('does not expose the PHP version header', function () {
    $response = $this->get('/login');

    expect($response->headers->has('X-Powered-By'))->toBeFalse();
});

it('no longer exposes the unused /api/test route', function () {
    $this->getJson('/api/test')->assertNotFound();
});
