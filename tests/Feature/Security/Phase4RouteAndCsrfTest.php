<?php

it('the web middleware group enforces CSRF verification (statically confirmed, not via HTTP)', function () {
    $middleware = app(\Illuminate\Routing\Router::class)->getMiddlewareGroups()['web'] ?? [];

    $hasCsrf = collect($middleware)->contains(function ($entry) {
        return is_string($entry) && str_contains($entry, 'ValidateCsrfToken');
    });

    expect($hasCsrf)->toBeTrue();
});

it('the signed public quotation-accept link rejects an unsigned/tampered request', function () {
    $customer = \App\Models\Customer::create(['full_name' => 'Route Customer', 'phone' => '09170000006', 'email' => 'route-' . uniqid() . '@example.com']);
    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'Q-P4ROUTE-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => \App\Models\TruckType::create(['name' => 'Route Truck ' . uniqid(), 'base_rate' => 1000, 'per_km_rate' => 50])->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 3,
        'estimated_price' => 1500,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    test()->get("/quotation/{$quotation->id}/accept")->assertStatus(403);

    expect($quotation->fresh()->status)->toBe('sent');
});
