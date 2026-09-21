<?php

use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Laravel\Sanctum\Sanctum;

function cvcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function cvcCustomerUser(): User
{
    return User::factory()->create(['role_id' => cvcRole(5, 'Customer')->id, 'status' => 'active']);
}

function cvcTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'CVC Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function cvcVehicleType(string $category, TruckType $truckType, string $status = 'active'): VehicleType
{
    return VehicleType::create([
        'name' => 'CVC Vehicle ' . fake()->unique()->word(),
        'category' => $category,
        'required_truck_type_id' => $truckType->id,
        'status' => $status,
    ]);
}

it('rejects an unauthenticated vehicle categories request', function () {
    test()->getJson('/api/v1/vehicle-categories')->assertStatus(401);
});

it('returns owner-managed categories ordered by name', function () {
    Sanctum::actingAs(cvcCustomerUser(), ['*']);
    $truckType = cvcTruckType();
    VehicleCategory::create(['slug' => 'cvc_trucks', 'name' => 'Trucks']);
    VehicleCategory::create(['slug' => 'cvc_cars', 'name' => 'Cars & SUVs']);
    cvcVehicleType('cvc_trucks', $truckType);
    cvcVehicleType('cvc_cars', $truckType);

    $response = test()->getJson('/api/v1/vehicle-categories');

    $response->assertOk();
    expect($response->json())->toBe([
        ['slug' => 'cvc_cars', 'name' => 'Cars & SUVs'],
        ['slug' => 'cvc_trucks', 'name' => 'Trucks'],
    ]);
});

it('excludes a category with no active vehicle types', function () {
    Sanctum::actingAs(cvcCustomerUser(), ['*']);
    $truckType = cvcTruckType();
    VehicleCategory::create(['slug' => 'cvc_empty', 'name' => 'Empty Category']);
    VehicleCategory::create(['slug' => 'cvc_populated', 'name' => 'Populated Category']);
    cvcVehicleType('cvc_populated', $truckType);

    $response = test()->getJson('/api/v1/vehicle-categories');

    $response->assertOk();
    $slugs = collect($response->json())->pluck('slug')->all();
    expect($slugs)->toContain('cvc_populated');
    expect($slugs)->not->toContain('cvc_empty');
});

it('excludes a category whose only vehicle types are inactive', function () {
    Sanctum::actingAs(cvcCustomerUser(), ['*']);
    $truckType = cvcTruckType();
    VehicleCategory::create(['slug' => 'cvc_inactive_only', 'name' => 'Inactive Only']);
    cvcVehicleType('cvc_inactive_only', $truckType, 'inactive');

    $response = test()->getJson('/api/v1/vehicle-categories');

    $response->assertOk();
    $slugs = collect($response->json())->pluck('slug')->all();
    expect($slugs)->not->toContain('cvc_inactive_only');
});

it('reflects a newly created category without any code changes', function () {
    Sanctum::actingAs(cvcCustomerUser(), ['*']);
    $truckType = cvcTruckType();
    VehicleCategory::create(['slug' => 'cvc_brand_new', 'name' => 'Brand New Category']);
    cvcVehicleType('cvc_brand_new', $truckType);

    $response = test()->getJson('/api/v1/vehicle-categories');

    $response->assertOk();
    expect(collect($response->json())->pluck('slug')->all())->toContain('cvc_brand_new');
});

it('reflects a renamed category label without changing its slug', function () {
    Sanctum::actingAs(cvcCustomerUser(), ['*']);
    $truckType = cvcTruckType();
    $category = VehicleCategory::create(['slug' => 'cvc_renameable', 'name' => 'Old Name']);
    cvcVehicleType('cvc_renameable', $truckType);

    $category->update(['name' => 'New Name']);

    $response = test()->getJson('/api/v1/vehicle-categories');

    $response->assertOk();
    $match = collect($response->json())->firstWhere('slug', 'cvc_renameable');
    expect($match['name'])->toBe('New Name');
});
