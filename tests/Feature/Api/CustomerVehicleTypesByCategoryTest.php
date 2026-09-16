<?php

use App\Models\VehicleType;

it('returns active vehicle types for a real category without requiring authentication', function () {
    VehicleType::create([
        'name' => 'Test Motorcycle',
        'category' => '2_wheeler',
        'status' => 'active',
    ]);
    VehicleType::create([
        'name' => 'Test Inactive Scooter',
        'category' => '2_wheeler',
        'status' => 'inactive',
    ]);

    $response = test()->getJson('/api/v1/vehicle-types/by-category/2_wheeler');

    $response->assertOk();
    $names = collect($response->json('vehicleTypes'))->pluck('name');
    expect($names)->toContain('Test Motorcycle');
    expect($names)->not->toContain('Test Inactive Scooter');
});

it('returns an empty list for a category with no active vehicle types, not an error', function () {
    $response = test()->getJson('/api/v1/vehicle-types/by-category/nonexistent_category');

    $response->assertOk();
    expect($response->json('vehicleTypes'))->toBe([]);
});

it('the lightweight route and the legacy route return the same data for a real category', function () {
    VehicleType::create([
        'name' => 'Test Sedan',
        'category' => '4_wheeler',
        'status' => 'active',
    ]);

    $legacy = test()->getJson('/api/vehicle-types/by-category/4_wheeler');
    $lightweight = test()->getJson('/api/v1/vehicle-types/by-category/4_wheeler');

    $legacy->assertOk();
    $lightweight->assertOk();
    expect($lightweight->json('vehicleTypes'))->toBe($legacy->json('vehicleTypes'));
});
